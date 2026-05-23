<?php
declare(strict_types=1);

/**
 * MwasinMarket API — Payments
 * Deposits, withdrawals, M-Pesa, admin credit, payment reports.
 * Assumes bootstrap.php is loaded.
 */

// ============================================================
// SMS HELPER (used here and in social.php)
// ============================================================

if (!function_exists('send_sms_now')) {
    function send_sms_now(string $phone, string $message, ?int $userId = null, ?int $sentBy = null): array {
        $phone = trim($phone);
        $message = mb_substr($message, 0, 160, 'UTF-8');

        $logIns = db()->prepare("INSERT INTO sms_log (user_id, phone, message, status, provider, sent_by, created_at) VALUES (:uid, :ph, :msg, 'queued', :prov, :sb, NOW())");
        $logIns->execute([':uid' => $userId, ':ph' => $phone, ':msg' => $message, ':prov' => SMS_PROVIDER, ':sb' => $sentBy]);
        $logId = (int)db()->lastInsertId();

        if (SMS_PROVIDER === 'africastalking' && SMS_API_KEY !== '' && SMS_USERNAME !== '') {
            $url = 'https://api.africastalking.com/version1/messaging';
            $postData = http_build_query([
                'username' => SMS_USERNAME,
                'to'       => $phone,
                'message'  => $message,
                'from'     => SMS_SENDER_ID,
            ]);
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\napiKey: " . SMS_API_KEY . "\r\n",
                    'content' => $postData,
                    'timeout' => 8,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            $providerId = null;
            $status = 'sent';
            $error = null;
            if ($response === false) {
                $status = 'failed'; $error = 'No response from provider';
            } else {
                $data = json_decode($response, true);
                if (is_array($data) && isset($data['SMSMessageData']['Recipients'][0]['messageId'])) {
                    $providerId = (string)$data['SMSMessageData']['Recipients'][0]['messageId'];
                } else {
                    $status = 'failed'; $error = mb_substr((string)$response, 0, 300, 'UTF-8');
                }
            }
            $upd = db()->prepare("UPDATE sms_log SET status = :st, provider_id = :pid, error = :err WHERE id = :id");
            $upd->execute([':st' => $status, ':pid' => $providerId, ':err' => $error, ':id' => $logId]);
            return ['id' => $logId, 'status' => $status, 'provider_id' => $providerId, 'error' => $error];
        }

        // No provider configured: leave as queued, return as queued
        return ['id' => $logId, 'status' => 'queued', 'provider_id' => null, 'error' => null];
    }
}

// ============================================================
// M-PESA HELPERS
// ============================================================

function mpesa_access_token(): ?string {
    if (MPESA_CONSUMER_KEY === '' || MPESA_CONSUMER_SECRET === '') return null;
    $url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $auth = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Basic {$auth}\r\nAccept: application/json\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

function mpesa_stk_push(string $phone, float $amount, string $reference, string $description): array {
    $token = mpesa_access_token();
    if (!$token) return ['ok' => false, 'error' => 'M-Pesa unavailable'];
    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    $msisdn = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($msisdn, '0') === 0) $msisdn = '254' . substr($msisdn, 1);
    if (strpos($msisdn, '+') === 0) $msisdn = substr($msisdn, 1);

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => (int)round($amount),
        'PartyA'            => $msisdn,
        'PartyB'            => MPESA_SHORTCODE,
        'PhoneNumber'       => $msisdn,
        'CallBackURL'       => MPESA_CALLBACK_URL,
        'AccountReference'  => mb_substr($reference, 0, 20, 'UTF-8'),
        'TransactionDesc'   => mb_substr($description, 0, 60, 'UTF-8'),
    ];
    $url = 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'STK request failed'];
    $data = json_decode($resp, true);
    if (isset($data['ResponseCode']) && (string)$data['ResponseCode'] === '0') {
        return ['ok' => true, 'checkout_request_id' => (string)$data['CheckoutRequestID']];
    }
    return ['ok' => false, 'error' => $data['errorMessage'] ?? 'Unknown M-Pesa error'];
}

function mpesa_b2c(string $phone, float $amount, string $reference): array {
    $token = mpesa_access_token();
    if (!$token) return ['ok' => false, 'error' => 'M-Pesa unavailable'];
    $msisdn = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($msisdn, '0') === 0) $msisdn = '254' . substr($msisdn, 1);
    if ($msisdn === '' || strlen($msisdn) < 9) return ['ok' => false, 'error' => 'Invalid phone'];
    $url = MPESA_B2C_URL !== '' ? MPESA_B2C_URL : 'https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest';
    $payload = [
        'InitiatorName'      => 'mwasin',
        'SecurityCredential' => '',
        'CommandID'          => 'BusinessPayment',
        'Amount'             => (int)round($amount),
        'PartyA'             => MPESA_SHORTCODE,
        'PartyB'             => $msisdn,
        'Remarks'            => mb_substr($reference, 0, 60, 'UTF-8'),
        'QueueTimeOutURL'    => MPESA_CALLBACK_URL,
        'ResultURL'          => MPESA_CALLBACK_URL,
        'Occasion'           => mb_substr($reference, 0, 20, 'UTF-8'),
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'B2C request failed'];
    $data = json_decode($resp, true);
    if (isset($data['ResponseCode']) && (string)$data['ResponseCode'] === '0') {
        return ['ok' => true, 'conversation_id' => $data['ConversationID'] ?? null];
    }
    return ['ok' => false, 'error' => $data['errorMessage'] ?? 'B2C failed'];
}

function ip_in_whitelist(string $ip, string $list): bool {
    if ($list === '') return true;
    $allowed = array_map('trim', explode(',', $list));
    foreach ($allowed as $entry) {
        if ($entry === '') continue;
        if (strpos($entry, '/') !== false) {
            if (cidr_match($ip, $entry)) return true;
        } else {
            if ($ip === $entry) return true;
        }
    }
    return false;
}

function cidr_match(string $ip, string $cidr): bool {
    [$subnet, $bits] = explode('/', $cidr);
    $bits = (int)$bits;
    $ipL = ip2long($ip);
    $subL = ip2long($subnet);
    if ($ipL === false || $subL === false) return false;
    $mask = -1 << (32 - $bits);
    return ($ipL & $mask) === ($subL & $mask);
}

// ============================================================
// ROUTE DISPATCH
// ============================================================

function dispatch_payments_route(string $route, array $body): void {
    switch ($route) {
        case 'deposit_request':           handle_deposit_request($body); return;
        case 'payment_status':            handle_payment_status(); return;
        case 'withdrawal_request':        handle_withdrawal_request($body); return;
        case 'mpesa_webhook':             handle_mpesa_webhook($body); return;
        case 'admin_credit_user':         handle_admin_credit_user($body); return;
        case 'admin_pending_withdrawals': handle_admin_pending_withdrawals(); return;
        case 'admin_approve_withdrawal':  handle_admin_approve_withdrawal($body); return;
        case 'admin_reject_withdrawal':   handle_admin_reject_withdrawal($body); return;
        case 'admin_payment_report':      handle_admin_payment_report(); return;
    }
    fail('Route not found', 404);
}

// ============================================================
// USER-FACING PAYMENTS
// ============================================================

function handle_deposit_request(array $body): void {
    $auth = require_auth();
    require_fields($body, ['amount', 'phone']);
    $amount = strict_positive_amount($body['amount'], 'amount', 10.0, 300000.0);
    $phone  = validate_phone_ke($body['phone']);

    $ins = db()->prepare("INSERT INTO deposits (user_id, amount, phone, status, provider, created_at) VALUES (:uid, :amt, :ph, 'pending', 'mpesa', NOW())");
    $ins->execute([':uid' => $auth['user_id'], ':amt' => $amount, ':ph' => $phone]);
    $depositId = (int)db()->lastInsertId();

    $reference = 'DEP' . $depositId;
    $stk = mpesa_stk_push($phone, $amount, $reference, 'MwasinMarket deposit');
    if (!$stk['ok']) {
        $upd = db()->prepare("UPDATE deposits SET status='failed', note=:n WHERE id=:id");
        $upd->execute([':n' => mb_substr((string)($stk['error'] ?? 'M-Pesa error'), 0, 200, 'UTF-8'), ':id' => $depositId]);
        fail('Could not initiate M-Pesa payment: ' . ($stk['error'] ?? 'unknown'), 502);
    }
    $checkoutId = $stk['checkout_request_id'];
    $upd = db()->prepare("UPDATE deposits SET checkout_request_id=:cri WHERE id=:id");
    $upd->execute([':cri' => $checkoutId, ':id' => $depositId]);

    ok([
        'deposit_id'          => $depositId,
        'checkout_request_id' => $checkoutId,
        'amount'              => $amount,
        'phone'               => $phone,
        'status'              => 'pending',
        'message'             => 'Check your phone for the M-Pesa prompt',
    ], 'Deposit initiated');
}

function handle_payment_status(): void {
    $auth = require_auth();
    $cri = $_GET['checkout_request_id'] ?? '';
    if (!is_string($cri) || trim($cri) === '') fail('checkout_request_id required.', 422, ['checkout_request_id']);
    $cri = trim($cri);
    $stmt = db()->prepare("SELECT id, user_id, amount, phone, status, external_reference, completed_at, created_at, note FROM deposits WHERE checkout_request_id = :cri AND user_id = :uid LIMIT 1");
    $stmt->execute([':cri' => $cri, ':uid' => $auth['user_id']]);
    $dep = $stmt->fetch();
    if (!$dep) fail('Deposit not found.', 404);

    $balanceAfter = null;
    if ($dep['status'] === 'completed') {
        $ust = db()->prepare("SELECT balance FROM users WHERE id = :uid");
        $ust->execute([':uid' => $auth['user_id']]);
        $u = $ust->fetch();
        if ($u) $balanceAfter = (float)$u['balance'];
    }
    ok([
        'deposit_id'         => (int)$dep['id'],
        'amount'             => (float)$dep['amount'],
        'phone'              => $dep['phone'],
        'status'             => $dep['status'],
        'external_reference' => $dep['external_reference'],
        'completed_at'       => $dep['completed_at'],
        'created_at'         => $dep['created_at'],
        'note'               => $dep['note'],
        'balance_after'      => $balanceAfter,
    ]);
}

function handle_withdrawal_request(array $body): void {
    $auth = require_auth();
    require_fields($body, ['amount', 'phone']);
    $amount = strict_positive_amount($body['amount'], 'amount', 10.0, 150000.0);
    $phone  = validate_phone_ke($body['phone']);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ust = $pdo->prepare("SELECT id, balance, locked_balance, is_suspended FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $auth['user_id']]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }
        if ((int)$u['is_suspended'] === 1) { $pdo->rollBack(); fail('Your account has been suspended.', 403); }

        $lock = $pdo->prepare("UPDATE users SET locked_balance = locked_balance + :amt, updated_at = NOW() WHERE id = :uid AND (balance - locked_balance) >= :amt");
        $lock->execute([':amt' => $amount, ':uid' => $auth['user_id']]);
        if ($lock->rowCount() === 0) {
            $pdo->rollBack();
            fail('Insufficient available balance.', 402);
        }

        $ins = $pdo->prepare("INSERT INTO withdrawals (user_id, amount, phone, status, provider, created_at) VALUES (:uid, :amt, :ph, 'pending', 'mpesa', NOW())");
        $ins->execute([':uid' => $auth['user_id'], ':amt' => $amount, ':ph' => $phone]);
        $wid = (int)$pdo->lastInsertId();
        $pdo->commit();

        send_sms_now($phone, "Your withdrawal request of KES " . number_format($amount, 2) . " has been received and is being processed.", (int)$auth['user_id'], null);
        audit_log('withdrawal_requested', 0, 'user', (int)$auth['user_id'], ['withdrawal_id' => $wid, 'amount' => $amount]);
        create_notification('withdrawal_pending', "New withdrawal request of KES " . number_format($amount, 2), $wid);

        ok([
            'withdrawal_id' => $wid,
            'amount'        => $amount,
            'phone'         => $phone,
            'status'        => 'pending',
        ], 'Withdrawal request received');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'withdrawal_request');
    }
}

// ============================================================
// M-PESA WEBHOOK
// ============================================================

function handle_mpesa_webhook(array $body): void {
    $ip = client_ip();
    if (MPESA_IP_WHITELIST !== '' && !ip_in_whitelist($ip, MPESA_IP_WHITELIST)) {
        http_response_code(403);
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
        exit;
    }
    if (MPESA_WEBHOOK_SECRET !== '') {
        $supplied = $_SERVER['HTTP_X_MPESA_SECRET'] ?? '';
        if (!hash_equals(MPESA_WEBHOOK_SECRET, $supplied)) {
            http_response_code(403);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid signature']);
            exit;
        }
    }

    $callback = $body['Body']['stkCallback'] ?? null;
    if (!is_array($callback)) {
        http_response_code(200);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }
    $resultCode = (int)($callback['ResultCode'] ?? 1);
    $checkoutId = (string)($callback['CheckoutRequestID'] ?? '');
    if ($checkoutId === '') {
        http_response_code(200);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM deposits WHERE checkout_request_id = :c FOR UPDATE");
        $st->execute([':c' => $checkoutId]);
        $dep = $st->fetch();
        if (!$dep) {
            $pdo->commit();
            http_response_code(200);
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            exit;
        }
        if ($dep['status'] === 'completed') {
            $pdo->commit();
            http_response_code(200);
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
            exit;
        }
        $userId = (int)$dep['user_id'];
        $amount = (float)$dep['amount'];

        if ($resultCode === 0) {
            $items = $callback['CallbackMetadata']['Item'] ?? [];
            $receipt = null; $callbackAmount = null;
            foreach ($items as $it) {
                if (($it['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = (string)($it['Value'] ?? '');
                if (($it['Name'] ?? '') === 'Amount') $callbackAmount = (float)($it['Value'] ?? 0);
            }
            if ($receipt === null || $receipt === '') {
                $upd = $pdo->prepare("UPDATE deposits SET status='failed', note='Missing receipt' WHERE id=:id");
                $upd->execute([':id' => $dep['id']]);
                $pdo->commit();
                http_response_code(200);
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                exit;
            }

            // Idempotency: external_reference is UNIQUE
            $dupe = $pdo->prepare("SELECT id FROM deposits WHERE external_reference = :ref AND id != :id LIMIT 1");
            $dupe->execute([':ref' => $receipt, ':id' => $dep['id']]);
            if ($dupe->fetch()) {
                $upd = $pdo->prepare("UPDATE deposits SET status='failed', note='Duplicate receipt' WHERE id=:id");
                $upd->execute([':id' => $dep['id']]);
                $pdo->commit();
                http_response_code(200);
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                exit;
            }

            $ust = $pdo->prepare("SELECT balance FROM users WHERE id = :uid FOR UPDATE");
            $ust->execute([':uid' => $userId]);
            $u = $ust->fetch();
            $balBefore = $u ? (float)$u['balance'] : 0.0;

            $upd1 = $pdo->prepare("UPDATE users SET balance = balance + :amt, updated_at = NOW() WHERE id = :uid");
            $upd1->execute([':amt' => $amount, ':uid' => $userId]);

            $upd2 = $pdo->prepare("UPDATE deposits SET status='completed', external_reference=:ref, completed_at=NOW() WHERE id=:id");
            $upd2->execute([':ref' => $receipt, ':id' => $dep['id']]);

            $pdo->commit();

            record_balance_tx($userId, 'deposit', $amount, $balBefore, $balBefore + $amount, $receipt, null, 'M-Pesa deposit');
            audit_log('mpesa_deposit_confirmed', 0, 'user', $userId, ['deposit_id' => (int)$dep['id'], 'amount' => $amount, 'receipt' => $receipt]);
            create_notification('deposit_completed', "Deposit of KES " . number_format($amount, 2) . " confirmed.", $userId);
            send_sms_now((string)$dep['phone'], "Your deposit of KES " . number_format($amount, 2) . " has been confirmed. Ref: {$receipt}.", $userId, null);
        } else {
            $desc = mb_substr((string)($callback['ResultDesc'] ?? 'Failed'), 0, 200, 'UTF-8');
            $upd = $pdo->prepare("UPDATE deposits SET status='failed', note=:n WHERE id=:id");
            $upd->execute([':n' => $desc, ':id' => $dep['id']]);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[MwasinMarket] mpesa_webhook error: ' . $e->getMessage());
    }

    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ============================================================
// ADMIN PAYMENTS
// ============================================================

function handle_admin_credit_user(array $body): void {
    $admin = require_admin();
    require_fields($body, ['user_id', 'amount', 'type']);
    $userId = strict_positive_int($body['user_id'], 'user_id');
    $type   = validate_enum($body['type'], ['deposit', 'bonus', 'withdrawal', 'adjustment'], 'type');
    $note   = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    // amount can be negative; cannot be 0; abs ≤ 10,000,000
    $amount = $body['amount'];
    if (is_bool($amount) || is_array($amount) || is_object($amount) || $amount === null) fail('Invalid amount.', 422, ['amount']);
    if (is_string($amount)) {
        $amount = trim($amount);
        if ($amount === '' || preg_match('/[eE]/', $amount) || !is_numeric($amount)) fail('Invalid amount.', 422, ['amount']);
    }
    if (!is_numeric($amount)) fail('Invalid amount.', 422, ['amount']);
    $amt = (float)$amount;
    if (!is_finite($amt) || $amt === 0.0) fail('Amount must be a non-zero number.', 422, ['amount']);
    if (abs($amt) > 10000000.0) fail('Amount magnitude too large.', 422, ['amount']);
    $amt = round($amt, 2);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ust = $pdo->prepare("SELECT id, balance, bonus_balance FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $userId]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }

        if ($type === 'bonus') {
            $balBefore = (float)$u['bonus_balance'];
            if ($amt < 0) {
                $upd = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :amt, updated_at=NOW() WHERE id = :uid AND bonus_balance >= :neg");
                $upd->execute([':amt' => $amt, ':neg' => -$amt, ':uid' => $userId]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :amt, updated_at=NOW() WHERE id = :uid");
                $upd->execute([':amt' => $amt, ':uid' => $userId]);
            }
            if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient bonus balance for debit.', 402); }
            $balAfter = $balBefore + $amt;
        } else {
            $balBefore = (float)$u['balance'];
            if ($amt < 0) {
                $upd = $pdo->prepare("UPDATE users SET balance = balance + :amt, updated_at=NOW() WHERE id = :uid AND balance >= :neg");
                $upd->execute([':amt' => $amt, ':neg' => -$amt, ':uid' => $userId]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET balance = balance + :amt, updated_at=NOW() WHERE id = :uid");
                $upd->execute([':amt' => $amt, ':uid' => $userId]);
            }
            if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient balance for debit.', 402); }
            $balAfter = $balBefore + $amt;
        }

        $pdo->commit();
        record_balance_tx($userId, $type, $amt, $balBefore, $balAfter, null, null, $note);
        audit_log('admin_credit_user', (int)$admin['user_id'], 'user', $userId, ['amount' => $amt, 'type' => $type, 'note' => $note]);
        ok([
            'user_id'        => $userId,
            'type'           => $type,
            'amount'         => $amt,
            'balance_before' => $balBefore,
            'balance_after'  => round($balAfter, 2),
            'note'           => $note,
        ], $amt > 0 ? 'User credited' : 'User debited');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'admin_credit_user');
    }
}

function handle_admin_pending_withdrawals(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $count = (int)db()->query("SELECT COUNT(*) c FROM withdrawals WHERE status = 'pending'")->fetch()['c'];

    $stmt = db()->prepare("
        SELECT w.id, w.amount, w.phone, w.status, w.created_at,
               u.id AS user_id, u.username, u.balance, u.locked_balance, u.is_suspended
        FROM withdrawals w
        JOIN users u ON u.id = w.user_id
        WHERE w.status = 'pending'
        ORDER BY w.created_at ASC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    ok([
        'data' => $rows,
        'meta' => ['total' => $count, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil($count / $limit)],
    ]);
}

function handle_admin_approve_withdrawal(array $body): void {
    $admin = require_admin();
    require_fields($body, ['withdrawal_id']);
    $wid = strict_positive_int($body['withdrawal_id'], 'withdrawal_id');
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $wst = $pdo->prepare("SELECT * FROM withdrawals WHERE id = :id FOR UPDATE");
        $wst->execute([':id' => $wid]);
        $w = $wst->fetch();
        if (!$w) { $pdo->rollBack(); fail('Withdrawal not found.', 404); }
        if ($w['status'] !== 'pending') { $pdo->rollBack(); fail('Withdrawal is not pending.', 409); }

        $userId = (int)$w['user_id'];
        $amount = (float)$w['amount'];

        $ust = $pdo->prepare("SELECT balance, locked_balance FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $userId]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }
        $balBefore = (float)$u['balance'];

        $upd = $pdo->prepare("UPDATE users SET balance = balance - :amt, locked_balance = locked_balance - :amt, updated_at = NOW() WHERE id = :uid AND balance >= :amt AND locked_balance >= :amt");
        $upd->execute([':amt' => $amount, ':uid' => $userId]);
        if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient locked balance to disburse.', 409); }

        $wupd = $pdo->prepare("UPDATE withdrawals SET status='processing', approved_by=:ab, approved_at=NOW() WHERE id=:id");
        $wupd->execute([':ab' => $admin['user_id'], ':id' => $wid]);

        $pdo->commit();

        // Attempt B2C disbursement
        $b2c = mpesa_b2c((string)$w['phone'], $amount, 'WDR' . $wid);
        if ($b2c['ok']) {
            $fin = db()->prepare("UPDATE withdrawals SET status='completed', external_reference=:r, completed_at=NOW() WHERE id=:id");
            $fin->execute([':r' => $b2c['conversation_id'] ?? null, ':id' => $wid]);
            $status = 'completed';
        } else {
            $status = 'processing';
        }

        record_balance_tx($userId, 'withdrawal', -$amount, $balBefore, $balBefore - $amount, 'WDR' . $wid, null, $note);
        audit_log('approve_withdrawal', (int)$admin['user_id'], 'user', $userId, ['withdrawal_id' => $wid, 'amount' => $amount, 'b2c' => $b2c]);
        send_sms_now((string)$w['phone'], "Your withdrawal of KES " . number_format($amount, 2) . " has been processed.", $userId, null);

        ok([
            'withdrawal_id' => $wid,
            'status'        => $status,
            'amount'        => $amount,
            'b2c'           => $b2c,
        ], 'Withdrawal approved');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'approve_withdrawal');
    }
}

function handle_admin_reject_withdrawal(array $body): void {
    $admin = require_admin();
    require_fields($body, ['withdrawal_id', 'reason']);
    $wid = strict_positive_int($body['withdrawal_id'], 'withdrawal_id');
    $reason = validate_text($body['reason'], 'reason', 3, 500);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $wst = $pdo->prepare("SELECT * FROM withdrawals WHERE id = :id FOR UPDATE");
        $wst->execute([':id' => $wid]);
        $w = $wst->fetch();
        if (!$w) { $pdo->rollBack(); fail('Withdrawal not found.', 404); }
        if ($w['status'] !== 'pending') { $pdo->rollBack(); fail('Only pending withdrawals can be rejected.', 409); }

        $userId = (int)$w['user_id'];
        $amount = (float)$w['amount'];

        $unl = $pdo->prepare("UPDATE users SET locked_balance = GREATEST(0, locked_balance - :amt), updated_at = NOW() WHERE id = :uid");
        $unl->execute([':amt' => $amount, ':uid' => $userId]);

        $wupd = $pdo->prepare("UPDATE withdrawals SET status='rejected', rejected_reason=:r, approved_by=:ab, approved_at=NOW() WHERE id=:id");
        $wupd->execute([':r' => $reason, ':ab' => $admin['user_id'], ':id' => $wid]);

        $pdo->commit();

        audit_log('reject_withdrawal', (int)$admin['user_id'], 'user', $userId, ['withdrawal_id' => $wid, 'amount' => $amount, 'reason' => $reason]);
        send_sms_now((string)$w['phone'], "Your withdrawal request of KES " . number_format($amount, 2) . " has been declined. Reason: {$reason}", $userId, null);

        ok([
            'withdrawal_id' => $wid,
            'status'        => 'rejected',
            'amount'        => $amount,
            'reason'        => $reason,
        ], 'Withdrawal rejected');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'reject_withdrawal');
    }
}

function handle_admin_payment_report(): void {
    require_admin();
    $pdo = db();

    $depAgg = $pdo->query("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_amount,
        SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN status='failed'    THEN 1 ELSE 0 END) AS failed_count
        FROM deposits")->fetch();

    $wAgg = $pdo->query("SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_amount,
        SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status='completed'  THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing_count,
        SUM(CASE WHEN status='rejected'   THEN 1 ELSE 0 END) AS rejected_count
        FROM withdrawals")->fetch();

    $topDeps = $pdo->query("SELECT u.id AS user_id, u.username, COALESCE(SUM(d.amount),0) AS total
        FROM deposits d JOIN users u ON u.id = d.user_id
        WHERE d.status = 'completed'
        GROUP BY u.id, u.username
        ORDER BY total DESC
        LIMIT 10")->fetchAll();

    $dailyDeps = $pdo->query("SELECT DATE(completed_at) AS day, COUNT(*) AS deposits, COALESCE(SUM(amount),0) AS volume
        FROM deposits
        WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(completed_at)
        ORDER BY day DESC")->fetchAll();

    $dailyWith = $pdo->query("SELECT DATE(completed_at) AS day, COUNT(*) AS withdrawals, COALESCE(SUM(amount),0) AS volume
        FROM withdrawals
        WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(completed_at)
        ORDER BY day DESC")->fetchAll();

    ok([
        'deposits'    => $depAgg,
        'withdrawals' => $wAgg,
        'top_depositors_30d' => $topDeps,
        'daily_deposits_30d' => $dailyDeps,
        'daily_withdrawals_30d' => $dailyWith,
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ]);
}
