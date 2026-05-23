<?php
declare(strict_types=1);

/**
 * MwasinMarket API — Single Entry Point
 * All requests come through here. Routes are dispatched to handler files.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ---- CORS ----
$frontendUrl = defined('FRONTEND_URL') ? FRONTEND_URL : '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($frontendUrl !== '') {
    if ($origin !== '' && $origin === $frontendUrl) {
        header('Access-Control-Allow-Origin: ' . $frontendUrl);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: ' . $frontendUrl);
    }
} else {
    if (!DEBUG_MODE) {
        error_log('[MwasinMarket] WARNING: FRONTEND_URL not set; CORS wildcard only allowed in DEBUG_MODE.');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- DB password must be set ----
if (DB_PASS === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server misconfiguration.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Body size limit ----
$rawBody = '';
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
    if (!$isMultipart) {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > MAX_BODY_BYTES) {
            fail('Request body too large. Max ' . MAX_BODY_BYTES . ' bytes.', 413);
        }
        $stream = fopen('php://input', 'rb');
        if ($stream !== false) {
            $rawBody = stream_get_contents($stream, MAX_BODY_BYTES + 1);
            fclose($stream);
            if ($rawBody !== false && strlen($rawBody) > MAX_BODY_BYTES) {
                fail('Request body too large. Max ' . MAX_BODY_BYTES . ' bytes.', 413);
            }
        }
        if ($rawBody === false) $rawBody = '';
    }
}

// ---- JSON parse ----
$BODY = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fail('Malformed JSON body: ' . json_last_error_msg(), 400);
    }
    if (!is_array($decoded)) {
        fail('Request body must be a JSON object.', 400);
    }
    $BODY = $decoded;
}
$GLOBALS['BODY'] = $BODY;

// ---- Route ----
$ROUTE = $_GET['route'] ?? '';
if (!is_string($ROUTE)) $ROUTE = '';
$ROUTE = trim($ROUTE);

if ($ROUTE === '') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'name'    => 'MwasinMarket API',
            'version' => '5.0',
            'time'    => gmdate('Y-m-d H:i:s') . ' UTC',
            'status'  => 'online',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Maintenance bypass list ----
$MAINTENANCE_BYPASS = ['admin_login', 'admin_maintenance', 'health'];
if (!in_array($ROUTE, $MAINTENANCE_BYPASS, true)) {
    check_maintenance();
}

// ---- Route dispatch map ----
$BOOTSTRAP_ROUTES = ['register', 'login', 'admin_login', 'logout', 'profile', 'my_bets', 'health'];

$MARKETS_ROUTES = [
    'markets', 'market', 'market_history', 'bet',
    'admin_create_market', 'admin_pause_market', 'admin_resume_market',
    'admin_force_close_market', 'admin_reopen_market', 'admin_settle_market',
    'admin_void_market', 'admin_void_bets_by_time', 'admin_void_bet',
    'admin_archive_market', 'admin_edit_market', 'admin_adjust_limits',
    'admin_extend_close_time', 'admin_set_liquidity', 'admin_reseed_odds',
    'admin_set_odds_mode', 'admin_stats', 'admin_market_report',
];

$PAYMENTS_ROUTES = [
    'deposit_request', 'payment_status', 'withdrawal_request',
    'mpesa_webhook', 'admin_credit_user', 'admin_pending_withdrawals',
    'admin_approve_withdrawal', 'admin_reject_withdrawal', 'admin_payment_report',
];

$SOCIAL_ROUTES = [
    'react', 'reactions',
    'send_message', 'inbox', 'conversation', 'delete_message',
    'admin_send_sms', 'admin_sms_history',
    'stickers', 'admin_upload_sticker', 'admin_delete_sticker',
    'admin_ban_user', 'admin_unban_user', 'admin_restrict_messaging',
    'admin_maintenance', 'admin_notifications', 'admin_mark_notifications_read',
];

try {
    if (in_array($ROUTE, $BOOTSTRAP_ROUTES, true)) {
        dispatch_bootstrap_route($ROUTE, $BODY);
    } elseif (in_array($ROUTE, $MARKETS_ROUTES, true)) {
        require_once __DIR__ . '/markets.php';
        dispatch_markets_route($ROUTE, $BODY);
    } elseif (in_array($ROUTE, $PAYMENTS_ROUTES, true)) {
        require_once __DIR__ . '/payments.php';
        dispatch_payments_route($ROUTE, $BODY);
    } elseif (in_array($ROUTE, $SOCIAL_ROUTES, true)) {
        require_once __DIR__ . '/social.php';
        dispatch_social_route($ROUTE, $BODY);
    } else {
        fail('Route not found', 404);
    }
} catch (Throwable $e) {
    internal_error($e, 'dispatch:' . $ROUTE);
}
