<?php
declare(strict_types=1);

/**
 * MwasinMarket — Payments
 * Deposits via PayHero STK (with math-captcha + rate limiting + deposit-pause),
 * manual deposit claims, withdrawals (manual disbursement w/ transaction code),
 * admin credit, payment reports, payment history.
 * Requires config.php + bootstrap.php + payment_stk_deposit.php.
 */

// ============================================================
// DEPOSIT CAPTCHA (anti STK-spam)
// ============================================================

function handle_deposit_captcha(): void {
    require_auth();
    ok(captcha_issue(), 'Solve the math challenge, then send captcha_token + captcha_answer with your deposit.');
}

// ============================================================
// DEPOSIT (PayHero STK Push)
// ============================================================

function handle_deposit_request(array $body): void {
    $auth = require_auth();

    // 1. Deposit-pause gate (admin can disable deposits without full maintenance)
    $dp = deposits_paused();
    if ($dp['paused']) {
        fail($dp['message'] ?: 'Deposits are temporarily unavailable. Please try again shortly.', 503);
    }

    // 2. Anti-spam: math captcha is mandatory before we ever hit PayHero
    require_fields($body, ['amount', 'phone', 'captcha_token', 'captcha_answer']);
    if (!captcha_verify($body['captcha_token'], $body['captcha_answer'])) {
        fail('Captcha incorrect or expired. Request a new challenge from ?route=deposit_captcha.', 422, ['captcha_answer']);
    }

    // 3. Validate money + phone
    $amount = strict_positive_amount($body['amount'], 'amount', DEPOSIT_MIN, DEPOSIT_MAX);
    $phone  = validate_phone_ke($body['phone']);
    $phoneLocal = phone_to_local($phone);

    // 4. Per-user STK rate limit — don't let one user hammer prompts (protects API + MSISDN)
    $cutoff = gmdate('Y-m-d H:i:s', time() - STK_WINDOW_SECONDS);
    $rl = db()->prepare("SELECT COUNT(*) c FROM deposits WHERE user_id = :uid AND created_at >= :cut AND status = 'pending'");
    $rl->execute([':uid' => $auth['user_id'], ':cut' => $cutoff]);
    if ((int)$rl->fetch()['c'] >= STK_MAX_PER_WINDOW) {
        fail('Too many pending deposit prompts. Complete or cancel an existing one before trying again.', 429);
    }

    // 5. Create the pending deposit row first so we have a stable client reference
    $clientRef = 'MWDEP' . strtoupper(bin2hex(random_bytes(5)));
    $ins = db()->prepare("INSERT INTO deposits (user_id, amount, phone, status, provider, client_reference, created_at) VALUES (:uid, :amt, :ph, 'pending', 'payhero', :cr, NOW())");
    $ins->execute([':uid' => $auth['user_id'], ':amt' => $amount, ':ph' => $phone, ':cr' => $clientRef]);
    $depositId = (int)db()->lastInsertId();

    // 6. Customer name for the prompt
    $un = db()->prepare("SELECT full_name, username FROM users WHERE id = :uid");
    $un->execute([':uid' => $auth['user_id']]);
    $urow = $un->fetch();
    $custName = trim((string)($urow['full_name'] ?? '')) ?: (string)($urow['username'] ?? 'Customer');

    // 7. Fire the STK push
    $stk = payhero_stk_push($phoneLocal, $amount, $clientRef, $custName);
    if (!$stk['ok']) {
        $upd = db()->prepare("UPDATE deposits SET status='failed', note=:n WHERE id=:id");
        $upd->execute([':n' => mb_substr((string)($stk['error'] ?? 'STK error'), 0, 250, 'UTF-8'), ':id' => $depositId]);
        fail('Could not initiate M-Pesa prompt: ' . ($stk['error'] ?? 'unknown'), 502);
    }

    $upd = db()->prepare("UPDATE deposits SET checkout_request_id=:cri, payhero_reference=:pr WHERE id=:id");
    $upd->execute([':cri' => $stk['checkout_request_id'], ':pr' => $stk['reference'], ':id' => $depositId]);

    ok([
        'deposit_id'          => $depositId,
        'client_reference'    => $clientRef,
        'checkout_request_id' => $stk['checkout_request_id'],
        'amount'              => $amount,
        'phone'               => $phone,
        'status'              => 'pending',
        'message'             => 'Check your phone for the M-Pesa prompt and enter your PIN.',
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

// ============================================================
// PAYMENT HISTORY (deposits + withdrawals + manual claims)
// ============================================================

function handle_payment_history(): void {
    $auth = require_auth();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $uid = (int)$auth['user_id'];

    // Union of deposits + withdrawals, newest first.
    $sql = "
        SELECT * FROM (
            SELECT id, 'deposit' AS kind, amount, phone, status, external_reference AS reference, note, created_at
            FROM deposits WHERE user_id = :uid1
            UNION ALL
            SELECT id, 'withdrawal' AS kind, amount, phone, status, external_reference AS reference, rejected_reason AS note, created_at
            FROM withdrawals WHERE user_id = :uid2
        ) AS h
        ORDER BY created_at DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':uid1', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['amount'] = (float)$r['amount']; }
    unset($r);

    $cd = db()->prepare("SELECT COUNT(*) c FROM deposits WHERE user_id = :uid");    $cd->execute([':uid' => $uid]);
    $cw = db()->prepare("SELECT COUNT(*) c FROM withdrawals WHERE user_id = :uid"); $cw->execute([':uid' => $uid]);
    $total = (int)$cd->fetch()['c'] + (int)$cw->fetch()['c'];

    ok([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

// ============================================================
// MANUAL DEPOSIT CLAIM (when STK/callback failed but money was sent)
// ============================================================

function handle_submit_manual_claim(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);
    require_fields($body, ['amount', 'phone', 'transaction_code']);
    $amount = strict_positive_amount($body['amount'], 'amount', DEPOSIT_MIN, DEPOSIT_MAX);
    $phone  = validate_phone_ke($body['phone']);
    $code   = is_string($body['transaction_code']) ? strtoupper(trim($body['transaction_code'])) : '';
    if (!preg_match('/^[A-Z0-9]{8,20}$/', $code)) {
        fail('Invalid transaction code. Enter the M-Pesa confirmation code exactly (e.g. SAE3YULR0Y).', 422, ['transaction_code']);
    }
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    // Reject if this code was already used by a completed deposit or an existing claim.
    $dupDep = db()->prepare("SELECT id FROM deposits WHERE external_reference = :c LIMIT 1");
    $dupDep->execute([':c' => $code]);
    if ($dupDep->fetch()) fail('That transaction code has already been credited.', 409);

    try {
        $ins = db()->prepare("INSERT INTO manual_deposit_claims (user_id, amount, phone, transaction_code, note, status, created_at) VALUES (:uid, :amt, :ph, :code, :note, 'pending', NOW())");
        $ins->execute([':uid' => $auth['user_id'], ':amt' => $amount, ':ph' => $phone, ':code' => $code, ':note' => $note]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) fail('A claim with that transaction code already exists.', 409);
        throw $e;
    }
    $claimId = (int)db()->lastInsertId();
    create_notification('manual_claim', "Manual deposit claim KES " . number_format($amount, 2) . " (code {$code}) needs review.", $claimId);
    audit_log('submit_manual_claim', 0, 'user', (int)$auth['user_id'], ['claim_id' => $claimId, 'amount' => $amount, 'code' => $code]);

    ok([
        'claim_id'         => $claimId,
        'amount'           => $amount,
        'transaction_code' => $code,
        'status'           => 'pending',
    ], 'Claim submitted. An admin will verify and credit your account if the payment is genuine.', 201);
}

// ============================================================
// WITHDRAWAL REQUEST
// ============================================================

function handle_withdrawal_request(array $body): void {
    $auth = require_auth();
    require_fields($body, ['amount', 'phone']);
    $amount = strict_positive_amount($body['amount'], 'amount', WITHDRAWAL_MIN, WITHDRAWAL_MAX);
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
// ADMIN — PENDING WITHDRAWALS (full user vetting via bootstrap)
// ============================================================

function handle_admin_pending_withdrawals(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $count = (int)db()->query("SELECT COUNT(*) c FROM withdrawals WHERE status = 'pending'")->fetch()['c'];

    $stmt = db()->prepare("
        SELECT w.id AS withdrawal_id, w.amount, w.phone, w.status, w.created_at AS requested_at,
               w.user_id
        FROM withdrawals w
        WHERE w.status = 'pending'
        ORDER BY w.created_at ASC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $userProfile = _admin_user_vetting((int)$r['user_id']);
        $items[] = [
            'withdrawal_id' => (int)$r['withdrawal_id'],
            'amount'        => (float)$r['amount'],
            'phone'         => $r['phone'],
            'status'        => $r['status'],
            'requested_at'  => $r['requested_at'],
            'user'          => $userProfile,
        ];
    }

    ok([
        'data' => $items,
        'meta' => ['total' => $count, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $count) / $limit)],
    ]);
}

// ============================================================
// ADMIN — APPROVE WITHDRAWAL (manual disbursement; requires M-Pesa code)
// ============================================================

function handle_admin_approve_withdrawal(array $body): void {
    $admin = require_admin();
    require_fields($body, ['withdrawal_id', 'transaction_code']);
    $wid  = strict_positive_int($body['withdrawal_id'], 'withdrawal_id');
    $code = is_string($body['transaction_code']) ? strtoupper(trim($body['transaction_code'])) : '';
    if (!preg_match('/^[A-Z0-9]{8,20}$/', $code)) {
        fail('Invalid transaction_code. Enter the M-Pesa confirmation code you used to pay the user (e.g. SAE3YULR0Y).', 422, ['transaction_code']);
    }
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    // The receipt must be unique across all withdrawals.
    $dupe = db()->prepare("SELECT id FROM withdrawals WHERE external_reference = :c AND id != :id LIMIT 1");
    $dupe->execute([':c' => $code, ':id' => $wid]);
    if ($dupe->fetch()) fail('That transaction_code is already recorded against another withdrawal.', 409);

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

        // Funds were reserved at request time in locked_balance. Now remove them
        // from both balance and locked_balance (the cash left the platform).
        $upd = $pdo->prepare("UPDATE users SET balance = balance - :amt, locked_balance = locked_balance - :amt, updated_at = NOW() WHERE id = :uid AND balance >= :amt AND locked_balance >= :amt");
        $upd->execute([':amt' => $amount, ':uid' => $userId]);
        if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient locked balance to settle this withdrawal.', 409); }

        $wupd = $pdo->prepare("UPDATE withdrawals SET status='completed', external_reference=:code, approved_by=:ab, approved_at=NOW(), completed_at=NOW() WHERE id=:id");
        $wupd->execute([':code' => $code, ':ab' => $admin['user_id'], ':id' => $wid]);

        $pdo->commit();

        record_balance_tx($userId, 'withdrawal', -$amount, $balBefore, $balBefore - $amount, $code, null, $note);
        audit_log('approve_withdrawal', (int)$admin['user_id'], 'user', $userId, ['withdrawal_id' => $wid, 'amount' => $amount, 'transaction_code' => $code]);
        send_sms_now((string)$w['phone'], "Your withdrawal of KES " . number_format($amount, 2) . " has been sent. M-Pesa ref: {$code}.", $userId, null);

        ok([
            'withdrawal_id'    => $wid,
            'status'           => 'completed',
            'amount'           => $amount,
            'phone'            => $w['phone'],
            'transaction_code' => $code,
            'approved_at'      => gmdate('Y-m-d H:i:s'),
            'note'             => $note,
            'user'             => _admin_user_vetting($userId),
        ], 'Withdrawal approved and marked paid');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'approve_withdrawal');
    }
}

// ============================================================
// ADMIN — REJECT WITHDRAWAL
// ============================================================

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
            'phone'         => $w['phone'],
            'reason'        => $reason,
            'rejected_at'   => gmdate('Y-m-d H:i:s'),
            'user'          => _admin_user_vetting($userId),
        ], 'Withdrawal rejected');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'reject_withdrawal');
    }
}

// ============================================================
// ADMIN — MANUAL CREDIT/DEBIT
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

// ============================================================
// ADMIN — PAYMENT REPORT
// ============================================================

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

// ============================================================
// ADMIN — MANUAL DEPOSIT CLAIMS
// ============================================================

function handle_admin_manual_claims(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = isset($_GET['status']) ? (string)$_GET['status'] : 'pending';
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        fail('Invalid status filter.', 422, ['status']);
    }

    $where = '';
    $params = [];
    if ($status !== 'all') { $where = 'WHERE c.status = :st'; $params[':st'] = $status; }

    $countQ = db()->prepare("SELECT COUNT(*) c FROM manual_deposit_claims c {$where}");
    $countQ->execute($params);
    $total = (int)$countQ->fetch()['c'];

    $sql = "SELECT c.id AS claim_id, c.user_id, c.amount, c.phone, c.transaction_code, c.note,
                   c.status, c.review_note, c.reviewed_by, c.reviewed_at, c.created_at,
                   u.username
            FROM manual_deposit_claims c
            JOIN users u ON u.id = c.user_id
            {$where}
            ORDER BY c.created_at ASC
            LIMIT :lim OFFSET :off";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'claim_id'         => (int)$r['claim_id'],
            'amount'           => (float)$r['amount'],
            'phone'            => $r['phone'],
            'transaction_code' => $r['transaction_code'],
            'note'             => $r['note'],
            'status'           => $r['status'],
            'review_note'      => $r['review_note'],
            'reviewed_at'      => $r['reviewed_at'],
            'created_at'       => $r['created_at'],
            'user'             => _admin_user_vetting((int)$r['user_id']),
        ];
    }

    ok([
        'data' => $items,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_admin_approve_manual_claim(array $body): void {
    $admin = require_admin();
    require_fields($body, ['claim_id']);
    $claimId = strict_positive_int($body['claim_id'], 'claim_id');
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $cst = $pdo->prepare("SELECT * FROM manual_deposit_claims WHERE id = :id FOR UPDATE");
        $cst->execute([':id' => $claimId]);
        $claim = $cst->fetch();
        if (!$claim) { $pdo->rollBack(); fail('Claim not found.', 404); }
        if ($claim['status'] !== 'pending') { $pdo->rollBack(); fail('Claim already reviewed.', 409); }

        $code = (string)$claim['transaction_code'];
        // Guard: the code must not already be credited via a deposit.
        $dupe = $pdo->prepare("SELECT id FROM deposits WHERE external_reference = :c LIMIT 1");
        $dupe->execute([':c' => $code]);
        if ($dupe->fetch()) { $pdo->rollBack(); fail('That transaction code was already credited via a deposit.', 409); }

        $userId = (int)$claim['user_id'];
        $amount = (float)$claim['amount'];

        $ust = $pdo->prepare("SELECT balance FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $userId]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }
        $balBefore = (float)$u['balance'];

        $upd = $pdo->prepare("UPDATE users SET balance = balance + :amt, updated_at = NOW() WHERE id = :uid");
        $upd->execute([':amt' => $amount, ':uid' => $userId]);

        // Record a completed deposit so the receipt is now globally unique & visible in history.
        $dins = $pdo->prepare("INSERT INTO deposits (user_id, amount, phone, status, provider, external_reference, note, created_at, completed_at) VALUES (:uid, :amt, :ph, 'completed', 'manual', :ref, :note, NOW(), NOW())");
        $dins->execute([':uid' => $userId, ':amt' => $amount, ':ph' => $claim['phone'], ':ref' => $code, ':note' => 'Manual claim #' . $claimId]);

        $cupd = $pdo->prepare("UPDATE manual_deposit_claims SET status='approved', reviewed_by=:by, review_note=:n, reviewed_at=NOW() WHERE id=:id");
        $cupd->execute([':by' => $admin['user_id'], ':n' => $note, ':id' => $claimId]);

        $pdo->commit();

        record_balance_tx($userId, 'deposit', $amount, $balBefore, $balBefore + $amount, $code, null, 'Manual claim approved');
        audit_log('approve_manual_claim', (int)$admin['user_id'], 'user', $userId, ['claim_id' => $claimId, 'amount' => $amount, 'code' => $code]);
        send_sms_now((string)$claim['phone'], "Your deposit of KES " . number_format($amount, 2) . " has been credited. Ref: {$code}.", $userId, null);

        ok([
            'claim_id'      => $claimId,
            'status'        => 'approved',
            'amount'        => $amount,
            'credited_to'   => $userId,
            'balance_after' => round($balBefore + $amount, 2),
        ], 'Claim approved and balance credited');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'approve_manual_claim');
    }
}

function handle_admin_reject_manual_claim(array $body): void {
    $admin = require_admin();
    require_fields($body, ['claim_id', 'reason']);
    $claimId = strict_positive_int($body['claim_id'], 'claim_id');
    $reason = validate_text($body['reason'], 'reason', 3, 300);

    $cst = db()->prepare("SELECT id, user_id, status FROM manual_deposit_claims WHERE id = :id LIMIT 1");
    $cst->execute([':id' => $claimId]);
    $claim = $cst->fetch();
    if (!$claim) fail('Claim not found.', 404);
    if ($claim['status'] !== 'pending') fail('Claim already reviewed.', 409);

    $upd = db()->prepare("UPDATE manual_deposit_claims SET status='rejected', reviewed_by=:by, review_note=:n, reviewed_at=NOW() WHERE id=:id");
    $upd->execute([':by' => $admin['user_id'], ':n' => $reason, ':id' => $claimId]);
    audit_log('reject_manual_claim', (int)$admin['user_id'], 'user', (int)$claim['user_id'], ['claim_id' => $claimId, 'reason' => $reason]);

    ok(['claim_id' => $claimId, 'status' => 'rejected', 'reason' => $reason], 'Claim rejected');
}

// ============================================================
// ADMIN — DEPOSIT PAUSE TOGGLE
// ============================================================

function handle_admin_deposit_pause(array $body): void {
    $admin = require_admin();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST' || empty($body)) {
        $dp = deposits_paused();
        ok(['deposits_paused' => $dp['paused'], 'message' => $dp['message']]);
    }

    require_fields($body, ['paused']);
    $paused = $body['paused'];
    if (is_string($paused)) $paused = filter_var($paused, FILTER_VALIDATE_BOOLEAN);
    if (!is_bool($paused) && $paused !== 0 && $paused !== 1) fail('paused must be boolean.', 422, ['paused']);
    $val = $paused ? '1' : '0';
    $message = isset($body['message']) ? validate_text($body['message'], 'message', 0, 300) : '';
    if ($message === '' && $paused) $message = 'Deposits are temporarily unavailable. Please try again shortly.';

    $exists = db()->query("SELECT id FROM system_settings WHERE `key`='deposits_paused' LIMIT 1")->fetch();
    if ($exists) {
        $u = db()->prepare("UPDATE system_settings SET value=:v, message=:m, updated_by=:ub, updated_at=NOW() WHERE id=:id");
        $u->execute([':v' => $val, ':m' => $message, ':ub' => $admin['user_id'], ':id' => $exists['id']]);
    } else {
        $i = db()->prepare("INSERT INTO system_settings (`key`, value, message, updated_by, updated_at) VALUES ('deposits_paused', :v, :m, :ub, NOW())");
        $i->execute([':v' => $val, ':m' => $message, ':ub' => $admin['user_id']]);
    }
    audit_log('deposit_pause', (int)$admin['user_id'], 'system', null, ['paused' => (bool)$paused, 'message' => $message]);
    ok(['deposits_paused' => (bool)$paused, 'message' => $message], $paused ? 'Deposits paused' : 'Deposits resumed');
}
