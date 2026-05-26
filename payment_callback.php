<?php
declare(strict_types=1);

/**
 * MwasinMarket — PayHero payment callback receiver.
 * PayHero POSTs the STK result here (URL configured as PAYHERO_CALLBACK_URL).
 * This file is a SEPARATE public endpoint — point PayHero at:
 *   https://yourdomain.com/payment_callback.php
 * It never requires a bearer token. It is protected by an optional shared
 * secret (PAYHERO_CALLBACK_SECRET) and always responds HTTP 200 so PayHero
 * does not retry indefinitely.
 *
 * Idempotent: the M-Pesa receipt (deposits.external_reference) is UNIQUE, so a
 * duplicate callback can never double-credit a user.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function _cb_done(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Optional shared-secret check (query ?s=... or header X-Callback-Secret).
if (PAYHERO_CALLBACK_SECRET !== '') {
    $supplied = $_GET['s'] ?? ($_SERVER['HTTP_X_CALLBACK_SECRET'] ?? '');
    if (!is_string($supplied) || !hash_equals(PAYHERO_CALLBACK_SECRET, $supplied)) {
        _cb_done(['status' => false, 'error' => 'forbidden'], 403);
    }
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    _cb_done(['status' => false, 'error' => 'bad request'], 200);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    _cb_done(['status' => true, 'received' => false]);
}

// PayHero wraps the result under "response".
$r = $data['response'] ?? $data;
if (!is_array($r)) {
    _cb_done(['status' => true, 'received' => false]);
}

$externalRef = (string)($r['ExternalReference'] ?? '');
$checkoutId  = (string)($r['CheckoutRequestID'] ?? '');
$resultCode  = isset($r['ResultCode']) ? (int)$r['ResultCode'] : 1;
$receipt     = strtoupper(trim((string)($r['MpesaReceiptNumber'] ?? '')));
$statusText  = (string)($r['Status'] ?? '');

if ($externalRef === '' && $checkoutId === '') {
    _cb_done(['status' => true, 'received' => false]);
}

$pdo = db();
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM deposits WHERE (client_reference = :ext AND :ext <> '') OR (checkout_request_id = :cri AND :cri <> '') LIMIT 1 FOR UPDATE");
    $stmt->execute([':ext' => $externalRef, ':cri' => $checkoutId]);
    $dep = $stmt->fetch();

    if (!$dep) {
        $pdo->commit();
        _cb_done(['status' => true, 'received' => true, 'matched' => false]);
    }
    if ($dep['status'] === 'completed') {
        $pdo->commit();
        _cb_done(['status' => true, 'received' => true, 'idempotent' => true]);
    }

    $userId = (int)$dep['user_id'];
    $amount = (float)$dep['amount'];

    $success = ($resultCode === 0) || (strcasecmp($statusText, 'Success') === 0);

    if ($success && $receipt !== '') {
        // Receipt must be globally unique.
        $dupe = $pdo->prepare("SELECT id FROM deposits WHERE external_reference = :rcp AND id != :id LIMIT 1");
        $dupe->execute([':rcp' => $receipt, ':id' => $dep['id']]);
        if ($dupe->fetch()) {
            $u = $pdo->prepare("UPDATE deposits SET status='failed', note='Duplicate receipt' WHERE id=:id");
            $u->execute([':id' => $dep['id']]);
            $pdo->commit();
            _cb_done(['status' => true, 'received' => true, 'duplicate' => true]);
        }

        $ust = $pdo->prepare("SELECT balance FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $userId]);
        $urow = $ust->fetch();
        $balBefore = $urow ? (float)$urow['balance'] : 0.0;

        $u1 = $pdo->prepare("UPDATE users SET balance = balance + :amt, updated_at = NOW() WHERE id = :uid");
        $u1->execute([':amt' => $amount, ':uid' => $userId]);

        $u2 = $pdo->prepare("UPDATE deposits SET status='completed', external_reference=:rcp, completed_at=NOW() WHERE id=:id");
        $u2->execute([':rcp' => $receipt, ':id' => $dep['id']]);

        $pdo->commit();

        record_balance_tx($userId, 'deposit', $amount, $balBefore, $balBefore + $amount, $receipt, null, 'PayHero deposit');
        audit_log('payhero_deposit_confirmed', 0, 'user', $userId, ['deposit_id' => (int)$dep['id'], 'amount' => $amount, 'receipt' => $receipt]);
        create_notification('deposit_completed', "Deposit of KES " . number_format($amount, 2) . " confirmed.", $userId);
        send_sms_now((string)$dep['phone'], "Your deposit of KES " . number_format($amount, 2) . " has been confirmed. Ref: {$receipt}.", $userId, null);

        _cb_done(['status' => true, 'received' => true, 'credited' => true]);
    }

    // Failure / cancellation path
    $desc = mb_substr((string)($r['ResultDesc'] ?? ($statusText ?: 'Failed')), 0, 200, 'UTF-8');
    $uf = $pdo->prepare("UPDATE deposits SET status='failed', note=:n WHERE id=:id");
    $uf->execute([':n' => $desc, ':id' => $dep['id']]);
    $pdo->commit();
    _cb_done(['status' => true, 'received' => true, 'failed' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[MwasinMarket][PayHero callback] ' . $e->getMessage());
    _cb_done(['status' => true, 'received' => true, 'error' => 'processing']);
}
