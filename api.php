<?php
declare(strict_types=1);

/**
 * MwasinMarket — API entry point / router.
 * The only file the web server exposes for JSON routes (PayHero callbacks
 * go to the separate payment_callback.php). Loads config + shared bootstrap,
 * then every route module, then dispatches by ?route=<name>.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/payment_stk_deposit.php';
require_once __DIR__ . '/auth_api.php';
require_once __DIR__ . '/markets_api.php';
require_once __DIR__ . '/messages_api.php';
require_once __DIR__ . '/social_api.php';
require_once __DIR__ . '/payments_api.php';
require_once __DIR__ . '/admin_api.php';
require_once __DIR__ . '/support_api.php';

// Request ID — propagate inbound or generate. Tag the response + error log.
$REQUEST_ID = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
if (!is_string($REQUEST_ID) || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $REQUEST_ID)) {
    $REQUEST_ID = bin2hex(random_bytes(8));
}
$GLOBALS['REQUEST_ID'] = $REQUEST_ID;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Request-ID: ' . $REQUEST_ID);
header('X-Frame-Options: DENY');
header('Permissions-Policy: interest-cohort=()');
$onHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($onHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$frontendUrl = FRONTEND_URL;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($frontendUrl !== '') {
    header('Access-Control-Allow-Origin: ' . $frontendUrl);
    if ($origin === $frontendUrl) header('Vary: Origin');
} else if (DEBUG_MODE) {
    header('Access-Control-Allow-Origin: *');
} else {
    error_log('[MwasinMarket] WARNING: FRONTEND_URL not set.');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204); exit;
}

if (DB_PASS === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server misconfiguration.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = '';
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'multipart/form-data') === false) {
        $clen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($clen > MAX_BODY_BYTES) fail('Request body too large. Max ' . MAX_BODY_BYTES . ' bytes.', 413);
        $stream = fopen('php://input', 'rb');
        if ($stream !== false) {
            $rawBody = stream_get_contents($stream, MAX_BODY_BYTES + 1) ?: '';
            fclose($stream);
            if (strlen($rawBody) > MAX_BODY_BYTES) fail('Request body too large. Max ' . MAX_BODY_BYTES . ' bytes.', 413);
        }
    }
}

$BODY = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) fail('Malformed JSON body: ' . json_last_error_msg(), 400);
    if (!is_array($decoded)) fail('Request body must be a JSON object.', 400);
    $BODY = $decoded;
}
$GLOBALS['BODY'] = $BODY;

$ROUTE = $_GET['route'] ?? '';
if (!is_string($ROUTE)) $ROUTE = '';
$ROUTE = trim($ROUTE);

if ($ROUTE === '') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'name'    => 'MwasinMarket API',
            'version' => '5.4',
            'time'    => gmdate('Y-m-d H:i:s') . ' UTC',
            'status'  => 'online',
            'docs'    => '?route=routes',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ROUTE === 'routes') {
    $ROUTE_CATALOGUE = [
        // [route, method, auth, description]
        ['register',                       'POST', 'public', 'Create account (20/IP/hr)'],
        ['login',                          'POST', 'public', 'User login by username/email/phone'],
        ['admin_login',                    'POST', 'public', 'Admin login'],
        ['logout',                         'POST', 'user',   'Revoke current token'],
        ['profile',                        'GET',  'user',   'Account details + bet stats'],
        ['my_bets',                        'GET',  'user',   'Bet history (status, history, page, limit)'],
        ['health',                         'GET',  'admin',  'Health check (DEBUG_MODE public)'],
        ['routes',                         'GET',  'public', 'This catalogue'],
        ['request_email_verification',     'POST', 'user',   'Send email verification link'],
        ['verify_email',                   'POST', 'public', 'Verify email by token'],
        ['request_password_reset',         'POST', 'public', 'Send password reset link (no enumeration)'],
        ['reset_password',                 'POST', 'public', 'Set new password using emailed token'],
        ['markets',                        'GET',  'public', 'List markets with live odds'],
        ['market',                         'GET',  'public', 'Single market with live odds'],
        ['market_history',                 'GET',  'public', 'Odds time-series grouped by outcome'],
        ['bet',                            'POST', 'user',   'Place a bet (30/user/60s)'],
        ['admin_create_market',            'POST', 'admin',  'Create a new market'],
        ['admin_pause_market',             'POST', 'admin',  'Pause an open market'],
        ['admin_resume_market',            'POST', 'admin',  'Resume a paused market'],
        ['admin_force_close_market',       'POST', 'admin',  'Force-close a market'],
        ['admin_reopen_market',            'POST', 'admin',  'Reopen a closed market'],
        ['admin_settle_market',            'POST', 'admin',  'Pay winners — permanent'],
        ['admin_void_market',              'POST', 'admin',  'Full refund — permanent'],
        ['admin_void_bets_by_time',        'POST', 'admin',  'Void all open bets after cutoff'],
        ['admin_void_bet',                 'POST', 'admin',  'Void a single open bet'],
        ['admin_archive_market',           'POST', 'admin',  'Archive/unarchive resolved/voided market'],
        ['admin_feature_market',           'POST', 'admin',  'Feature/unfeature a market (pins to top)'],
        ['admin_edit_market',              'POST', 'admin',  'Edit question/category/source/title/image'],
        ['admin_adjust_limits',            'POST', 'admin',  'Stake / wager cap / max_odds limits'],
        ['admin_extend_close_time',        'POST', 'admin',  'Push close deadline forward'],
        ['admin_set_liquidity',            'POST', 'admin',  'Change LMSR b parameter'],
        ['admin_reseed_odds',              'POST', 'admin',  'Reset displayed odds (by id or name)'],
        ['admin_set_odds_mode',            'POST', 'admin',  'Switch lmsr <-> fixed'],
        ['admin_stats',                    'GET',  'admin',  'Dashboard overview'],
        ['admin_market_report',            'GET',  'admin',  'Deep single-market report'],
        ['admin_notifications',            'GET',  'admin',  'Unread system alerts'],
        ['admin_mark_notifications_read',  'POST', 'admin',  'Mark notification(s) read'],
        ['market_chat',                    'GET',  'public', 'Read a market chat thread'],
        ['post_market_chat',               'POST', 'user',   'Post a message in a market chat'],
        ['delete_market_chat',             'POST', 'user',   'Delete own chat (admin: any)'],
        ['react',                          'POST', 'user',   'Toggle heart on a market'],
        ['reactions',                      'GET',  'public', 'Heart count (and own state if authed)'],
        ['stickers',                       'GET',  'public', 'List sticker library grouped by folder'],
        ['admin_upload_sticker',           'POST', 'admin',  'multipart/form-data sticker upload'],
        ['admin_delete_sticker',           'POST', 'admin',  'Soft-delete a sticker'],
        ['deposit_captcha',                'GET',  'user',   'Get a math captcha before depositing'],
        ['deposit_request',                'POST', 'user',   'PayHero STK Push (captcha + rate-limited)'],
        ['payment_status',                 'GET',  'user',   'Poll a deposit status'],
        ['payment_history',                'GET',  'user',   'Deposit + withdrawal history'],
        ['submit_manual_claim',            'POST', 'user',   'Claim a missed deposit by M-Pesa code'],
        ['withdrawal_request',             'POST', 'user',   'Request a withdrawal (locks balance)'],
        ['admin_pending_withdrawals',      'GET',  'admin',  'List pending withdrawals + full user vetting'],
        ['admin_approve_withdrawal',       'POST', 'admin',  'Approve + record M-Pesa transaction_code'],
        ['admin_reject_withdrawal',        'POST', 'admin',  'Reject withdrawal and unlock balance'],
        ['admin_credit_user',              'POST', 'admin',  'Manual credit / debit'],
        ['admin_payment_report',           'GET',  'admin',  'Deposit / withdrawal aggregates'],
        ['admin_manual_claims',            'GET',  'admin',  'List manual deposit claims'],
        ['admin_approve_manual_claim',     'POST', 'admin',  'Approve a manual claim and credit'],
        ['admin_reject_manual_claim',      'POST', 'admin',  'Reject a manual claim'],
        ['admin_deposit_pause',            'POST', 'admin',  'Pause/resume deposits (GET to read)'],
        ['support_create',                 'POST', 'user',   'Open a support ticket / suggestion'],
        ['support_tickets',                'GET',  'user',   'List own support tickets'],
        ['support_thread',                 'GET',  'user',   'Read own ticket thread'],
        ['support_reply',                  'POST', 'user',   'Reply on own ticket'],
        ['support_close',                  'POST', 'user',   'Close own ticket'],
        ['admin_support_tickets',          'GET',  'admin',  'List all tickets (filter status/category/unread)'],
        ['admin_support_thread',           'GET',  'admin',  'Read a ticket thread'],
        ['admin_support_reply',            'POST', 'admin',  'Reply privately to one user'],
        ['admin_support_close',            'POST', 'admin',  'Close a ticket'],
        ['admin_send_sms',                 'POST', 'admin',  'Send SMS to user or broadcast'],
        ['admin_sms_history',              'GET',  'admin',  'View sent SMS log'],
        ['admin_ban_user',                 'POST', 'admin',  'Full account ban'],
        ['admin_unban_user',               'POST', 'admin',  'Lift ban'],
        ['admin_restrict_messaging',       'POST', 'admin',  'Toggle messaging restriction'],
        ['admin_maintenance',              'POST', 'admin',  'Toggle maintenance (GET to read)'],
    ];
    $items = [];
    foreach ($ROUTE_CATALOGUE as $r) {
        $items[] = ['route' => $r[0], 'method' => $r[1], 'auth' => $r[2], 'description' => $r[3]];
    }
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'version' => '5.4',
            'count'   => count($items),
            'routes'  => $items,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$MAINTENANCE_BYPASS = ['admin_login', 'admin_maintenance', 'health', 'routes'];
if (!in_array($ROUTE, $MAINTENANCE_BYPASS, true)) {
    check_maintenance();
}
try {
    switch ($ROUTE) {
        // Auth + identity
        case 'register':                   handle_register($BODY);    break;
        case 'login':                      handle_login($BODY);       break;
        case 'admin_login':                handle_admin_login($BODY); break;
        case 'logout':                     handle_logout();           break;
        case 'profile':                    handle_profile();          break;
        case 'my_bets':                    handle_my_bets();          break;
        case 'health':                     handle_health();           break;

        // Email verification + password reset
        case 'request_email_verification': handle_request_email_verification(); break;
        case 'verify_email':               handle_verify_email($BODY);          break;
        case 'request_password_reset':     handle_request_password_reset($BODY); break;
        case 'reset_password':             handle_reset_password($BODY);        break;

        // Markets + betting
        case 'markets':                    handle_markets_list();         break;
        case 'market':                     handle_market_single();        break;
        case 'market_history':             handle_market_history();       break;
        case 'bet':                        handle_bet($BODY);             break;
        case 'admin_create_market':        handle_admin_create_market($BODY); break;
        case 'admin_pause_market':         handle_admin_pause_market($BODY); break;
        case 'admin_resume_market':        handle_admin_resume_market($BODY); break;
        case 'admin_force_close_market':   handle_admin_force_close_market($BODY); break;
        case 'admin_reopen_market':        handle_admin_reopen_market($BODY); break;
        case 'admin_settle_market':        handle_admin_settle_market($BODY); break;
        case 'admin_void_market':          handle_admin_void_market($BODY); break;
        case 'admin_void_bets_by_time':    handle_admin_void_bets_by_time($BODY); break;
        case 'admin_void_bet':             handle_admin_void_bet($BODY); break;
        case 'admin_archive_market':       handle_admin_archive_market($BODY); break;
        case 'admin_feature_market':       handle_admin_feature_market($BODY); break;
        case 'admin_edit_market':          handle_admin_edit_market($BODY); break;
        case 'admin_adjust_limits':        handle_admin_adjust_limits($BODY); break;
        case 'admin_extend_close_time':    handle_admin_extend_close_time($BODY); break;
        case 'admin_set_liquidity':        handle_admin_set_liquidity($BODY); break;
        case 'admin_reseed_odds':          handle_admin_reseed_odds($BODY); break;
        case 'admin_set_odds_mode':        handle_admin_set_odds_mode($BODY); break;
        case 'admin_stats':                handle_admin_stats(); break;
        case 'admin_market_report':        handle_admin_market_report(); break;

        // Market chat (no DMs)
        case 'market_chat':                handle_market_chat(); break;
        case 'post_market_chat':           handle_post_market_chat($BODY); break;
        case 'delete_market_chat':         handle_delete_market_chat($BODY); break;

        // Social
        case 'react':                      handle_react($BODY); break;
        case 'reactions':                  handle_reactions(); break;
        case 'stickers':                   handle_stickers_list(); break;
        case 'admin_upload_sticker':       handle_admin_upload_sticker(); break;
        case 'admin_delete_sticker':       handle_admin_delete_sticker($BODY); break;

        // Payments
        case 'deposit_captcha':            handle_deposit_captcha(); break;
        case 'deposit_request':            handle_deposit_request($BODY); break;
        case 'payment_status':             handle_payment_status(); break;
        case 'payment_history':            handle_payment_history(); break;
        case 'submit_manual_claim':        handle_submit_manual_claim($BODY); break;
        case 'withdrawal_request':         handle_withdrawal_request($BODY); break;
        case 'admin_pending_withdrawals':  handle_admin_pending_withdrawals(); break;
        case 'admin_approve_withdrawal':   handle_admin_approve_withdrawal($BODY); break;
        case 'admin_reject_withdrawal':    handle_admin_reject_withdrawal($BODY); break;
        case 'admin_credit_user':          handle_admin_credit_user($BODY); break;
        case 'admin_payment_report':       handle_admin_payment_report(); break;
        case 'admin_manual_claims':        handle_admin_manual_claims(); break;
        case 'admin_approve_manual_claim': handle_admin_approve_manual_claim($BODY); break;
        case 'admin_reject_manual_claim':  handle_admin_reject_manual_claim($BODY); break;
        case 'admin_deposit_pause':        handle_admin_deposit_pause($BODY); break;

        // Support / contact (user <-> admin private)
        case 'support_create':                handle_support_create($BODY); break;
        case 'support_tickets':               handle_support_tickets(); break;
        case 'support_thread':                handle_support_thread(); break;
        case 'support_reply':                 handle_support_reply($BODY); break;
        case 'support_close':                 handle_support_close($BODY); break;
        case 'admin_support_tickets':         handle_admin_support_tickets(); break;
        case 'admin_support_thread':          handle_admin_support_thread(); break;
        case 'admin_support_reply':           handle_admin_support_reply($BODY); break;
        case 'admin_support_close':           handle_admin_support_close($BODY); break;

        // Admin — SMS, user controls, maintenance, notifications
        case 'admin_send_sms':                handle_admin_send_sms($BODY); break;
        case 'admin_sms_history':             handle_admin_sms_history(); break;
        case 'admin_ban_user':                handle_admin_ban_user($BODY); break;
        case 'admin_unban_user':              handle_admin_unban_user($BODY); break;
        case 'admin_restrict_messaging':      handle_admin_restrict_messaging($BODY); break;
        case 'admin_maintenance':             handle_admin_maintenance($BODY); break;
        case 'admin_notifications':           handle_admin_notifications(); break;
        case 'admin_mark_notifications_read': handle_admin_mark_notifications_read($BODY); break;

        default:
            fail('Route not found', 404);
    }
} catch (Throwable $e) {
    internal_error($e, 'dispatch:' . $ROUTE);
}
