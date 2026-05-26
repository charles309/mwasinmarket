<?php
declare(strict_types=1);

/**
 * MwasinMarket — PayHero M-Pesa STK Push library
 * Pure-PHP HTTP (no cURL/Composer dependency). Requires config.php + bootstrap.php.
 *
 * payhero_stk_push() initiates a single STK prompt. It does NOT touch the
 * database — the caller (handle_deposit_request) owns the deposit row and
 * idempotency. The result/confirmation arrives asynchronously at
 * payment_callback.php.
 */

function payhero_stk_push(string $phoneLocal, float $amount, string $externalRef, string $customerName): array {
    if (PAYHERO_AUTH_TOKEN === '' || PAYHERO_CHANNEL_ID <= 0) {
        return ['ok' => false, 'error' => 'PayHero not configured'];
    }
    if (PAYHERO_CALLBACK_URL === '') {
        return ['ok' => false, 'error' => 'PayHero callback URL not configured'];
    }

    $payload = json_encode([
        'amount'             => (int)round($amount),
        'phone_number'       => $phoneLocal,
        'channel_id'         => PAYHERO_CHANNEL_ID,
        'provider'           => PAYHERO_PROVIDER,
        'external_reference' => $externalRef,
        'customer_name'      => mb_substr($customerName, 0, 60, 'UTF-8'),
        'callback_url'       => PAYHERO_CALLBACK_URL,
    ], JSON_UNESCAPED_UNICODE);

    // PayHero expects the full "Basic <base64>" — config stores the base64 part only.
    $authHeader = 'Authorization: Basic ' . PAYHERO_AUTH_TOKEN;

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => $authHeader . "\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $url  = PAYHERO_BASE_URL . (str_contains(PAYHERO_BASE_URL, '?') ? '' : '?is_active=true');
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['ok' => false, 'error' => 'PayHero unreachable'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'PayHero returned a non-JSON response'];
    }
    if (!empty($data['success'])) {
        return [
            'ok'                  => true,
            'reference'           => (string)($data['reference'] ?? ''),
            'checkout_request_id' => (string)($data['CheckoutRequestID'] ?? ''),
            'status'              => (string)($data['status'] ?? 'QUEUED'),
        ];
    }
    $msg = $data['error_message'] ?? ($data['message'] ?? 'PayHero rejected the request');
    return ['ok' => false, 'error' => mb_substr((string)$msg, 0, 250, 'UTF-8')];
}
