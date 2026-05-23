<?php
declare(strict_types=1);

/**
 * MwasinMarket API — Bootstrap
 * Config, DB, auth, rate limiting, validators, response helpers, audit.
 * Also handles auth + profile + my_bets + health routes.
 */

// ============================================================
// SECTION 1.1 — CONFIGURATION CONSTANTS
// ============================================================

define('DB_HOST',            $_ENV['DB_HOST']            ?? 'localhost');
define('DB_NAME',            $_ENV['DB_NAME']            ?? 'mwasinmarket');
define('DB_USER',            $_ENV['DB_USER']            ?? 'root');
define('DB_PASS',            $_ENV['DB_PASS']            ?? '');
define('FRONTEND_URL',       $_ENV['FRONTEND_URL']       ?? '');
define('DEBUG_MODE',         filter_var($_ENV['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));

define('TOKEN_TTL',          86400);
define('TOKEN_BYTES',        32);
define('MAX_BODY_BYTES',     65536);

define('STICKER_UPLOAD_PATH', $_ENV['STICKER_UPLOAD_PATH'] ?? '/var/www/stickers');
define('STICKER_BASE_URL',    $_ENV['STICKER_BASE_URL']    ?? '');

define('SMS_PROVIDER',       $_ENV['SMS_PROVIDER']       ?? 'africastalking');
define('SMS_API_KEY',        $_ENV['SMS_API_KEY']        ?? '');
define('SMS_USERNAME',       $_ENV['SMS_USERNAME']       ?? '');
define('SMS_SENDER_ID',      $_ENV['SMS_SENDER_ID']      ?? 'MwasinMkt');

define('MPESA_CONSUMER_KEY',    $_ENV['MPESA_CONSUMER_KEY']    ?? '');
define('MPESA_CONSUMER_SECRET', $_ENV['MPESA_CONSUMER_SECRET'] ?? '');
define('MPESA_SHORTCODE',       $_ENV['MPESA_SHORTCODE']       ?? '');
define('MPESA_PASSKEY',         $_ENV['MPESA_PASSKEY']         ?? '');
define('MPESA_CALLBACK_URL',    $_ENV['MPESA_CALLBACK_URL']    ?? '');
define('MPESA_B2C_URL',         $_ENV['MPESA_B2C_URL']         ?? '');
define('MPESA_IP_WHITELIST',    $_ENV['MPESA_IP_WHITELIST']    ?? '');
define('MPESA_WEBHOOK_SECRET',  $_ENV['MPESA_WEBHOOK_SECRET']  ?? '');

define('LMSR_B',             1000);
define('LMSR_B_MIN',         50);
define('LMSR_B_MAX',         10000);
define('LMSR_MAX_ODDS',      10.0);
define('FIXED_MIN_OVERROUND', 1.03);

define('DUMMY_HASH', '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');

define('LARGE_BET_THRESHOLD', 50000.0);
define('WAGER_CAP_WARNING_RATIO', 0.95);

if (!DEBUG_MODE) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);

date_default_timezone_set('Africa/Nairobi');

// ============================================================
// SECTION 1.2 — DB CONNECTION (SINGLETON PDO)
// ============================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    if (DB_PASS === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfiguration.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[MwasinMarket] DB connect failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfiguration.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// SECTION 1.3 — RESPONSE HELPERS
// ============================================================

function ok(mixed $payload = [], string $message = '', int $status = 200): never {
    http_response_code($status);
    $resp = ['success' => true];
    if ($message !== '') $resp['message'] = $message;
    if (is_array($payload) && (array_key_exists('data', $payload) || array_key_exists('meta', $payload))) {
        foreach ($payload as $k => $v) $resp[$k] = $v;
    } else {
        $resp['data'] = $payload;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $error, int $status = 400, array $details = []): never {
    http_response_code($status);
    $resp = ['success' => false, 'error' => $error];
    if (!empty($details)) $resp['details'] = $details;
    if ($status === 503) {
        try {
            $row = db()->query("SELECT value FROM system_settings WHERE `key`='maintenance_mode' LIMIT 1")->fetch();
            if ($row && $row['value'] === '1') $resp['maintenance'] = true;
        } catch (Throwable $e) { /* ignore */ }
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function internal_error(Throwable $e, string $context = ''): never {
    $rid = $GLOBALS['REQUEST_ID'] ?? '-';
    $logEntry = json_encode([
        'time'       => gmdate('Y-m-d H:i:s'),
        'request_id' => $rid,
        'context'    => $context,
        'class'      => get_class($e),
        'message'    => $e->getMessage(),
        'file'       => $e->getFile(),
        'line'       => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    error_log('[MwasinMarket][ERROR] ' . $logEntry);
    http_response_code(500);
    if (DEBUG_MODE) {
        echo json_encode([
            'success' => false,
            'error'   => 'Internal server error.',
            'debug'   => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'context' => $context,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => 'Internal server error. Please try again later.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// SECTION 1.4 — TOKEN AUTH
// ============================================================

function issue_token(int $userId): string {
    $token = bin2hex(random_bytes(TOKEN_BYTES));
    $expires = gmdate('Y-m-d H:i:s', time() + TOKEN_TTL);
    $stmt = db()->prepare("INSERT INTO auth_tokens (user_id, token, expires_at, created_at) VALUES (:uid, :tok, :exp, NOW())");
    $stmt->execute([':uid' => $userId, ':tok' => $token, ':exp' => $expires]);
    return $token;
}

function read_bearer(): string {
    $candidates = [];
    if (isset($_SERVER['HTTP_AUTHORIZATION']))           $candidates[] = $_SERVER['HTTP_AUTHORIZATION'];
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $candidates[] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $val) {
                if (strcasecmp($name, 'Authorization') === 0 || strcasecmp($name, 'X-Authorization') === 0) {
                    $candidates[] = $val;
                }
            }
        }
    }
    $allow = ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'];
    foreach ($allow as $k) {
        if (isset($_SERVER[$k])) $candidates[] = $_SERVER[$k];
    }
    foreach ($candidates as $h) {
        if (!is_string($h)) continue;
        $h = trim($h);
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
    }
    return '';
}

function resolve_token(string $token): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = db()->prepare("
        SELECT t.token, t.user_id, t.expires_at, u.role, u.is_suspended
        FROM auth_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token = :tok
        LIMIT 1
    ");
    $stmt->execute([':tok' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (strtotime($row['expires_at']) < time()) return null;
    return [
        'user_id'      => (int)$row['user_id'],
        'role'         => (string)$row['role'],
        'token'        => $token,
        'is_suspended' => (int)$row['is_suspended'],
    ];
}

function require_auth(): array {
    $tok = read_bearer();
    if ($tok === '') fail('Authentication required.', 401);
    $res = resolve_token($tok);
    if (!$res) fail('Invalid or expired token.', 401);
    return $res;
}

function require_admin(): array {
    $auth = require_auth();
    if ($auth['role'] !== 'admin') fail('Admin access required.', 403);
    return $auth;
}

function revoke_token(string $token): void {
    $stmt = db()->prepare("DELETE FROM auth_tokens WHERE token = :tok");
    $stmt->execute([':tok' => $token]);
}

function cleanup_expired_tokens(int $userId): void {
    $stmt = db()->prepare("DELETE FROM auth_tokens WHERE user_id = :uid AND expires_at < NOW()");
    $stmt->execute([':uid' => $userId]);
}

// ============================================================
// SECTION 1.5 — RATE LIMITING
// ============================================================

function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip)) $ip = '';
    return substr($ip, 0, 45);
}

function check_rate_limit(int $max = 10, int $window = 900): void {
    $ip = client_ip();
    if ($ip === '') return;
    $cutoff = gmdate('Y-m-d H:i:s', time() - $window);
    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :ip AND attempted_at >= :cutoff");
    $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
    $count = (int)$stmt->fetch()['c'];
    if ($count >= $max) {
        fail('Too many attempts. Please try again later.', 429);
    }
    $ins = db()->prepare("INSERT INTO login_attempts (identifier, attempted_at) VALUES (:ip, NOW())");
    $ins->execute([':ip' => $ip]);
}

function clear_rate_limit_attempt(): void {
    $ip = client_ip();
    if ($ip === '') return;
    $stmt = db()->prepare("DELETE FROM login_attempts WHERE identifier = :ip");
    $stmt->execute([':ip' => $ip]);
}

function check_bet_rate_limit(int $userId): void {
    $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM bets WHERE user_id = :uid AND created_at >= :cutoff");
    $stmt->execute([':uid' => $userId, ':cutoff' => $cutoff]);
    if ((int)$stmt->fetch()['c'] >= 30) {
        fail('Too many bets placed too quickly. Please slow down.', 429);
    }
}

// ============================================================
// SECTION 1.6 — STRICT INPUT VALIDATORS
// ============================================================

function strict_positive_amount(mixed $val, string $field, float $min = 0.01, float $max = 10000000.0): float {
    if (is_bool($val) || is_array($val) || is_object($val) || $val === null) {
        fail("Invalid {$field}: must be a positive number.", 422, [$field]);
    }
    if (is_string($val)) {
        $val = trim($val);
        if ($val === '') fail("Invalid {$field}: must be a positive number.", 422, [$field]);
        if (preg_match('/[eE]/', $val)) fail("Invalid {$field}: scientific notation not allowed.", 422, [$field]);
        if (!is_numeric($val)) fail("Invalid {$field}: must be a positive number.", 422, [$field]);
    }
    if (!is_numeric($val)) fail("Invalid {$field}: must be a positive number.", 422, [$field]);
    $f = (float)$val;
    if (!is_finite($f)) fail("Invalid {$field}: must be a finite number.", 422, [$field]);
    if ($f < $min) fail("Invalid {$field}: must be at least {$min}.", 422, [$field]);
    if ($f > $max) fail("Invalid {$field}: must not exceed {$max}.", 422, [$field]);
    return round($f, 2);
}

function strict_non_negative_amount(mixed $val, string $field, float $max = 10000000.0): float {
    if (is_bool($val) || is_array($val) || is_object($val) || $val === null) {
        fail("Invalid {$field}: must be a non-negative number.", 422, [$field]);
    }
    if (is_string($val)) {
        $val = trim($val);
        if ($val === '') fail("Invalid {$field}: must be a non-negative number.", 422, [$field]);
        if (preg_match('/[eE]/', $val)) fail("Invalid {$field}: scientific notation not allowed.", 422, [$field]);
        if (!is_numeric($val)) fail("Invalid {$field}: must be a non-negative number.", 422, [$field]);
    }
    if (!is_numeric($val)) fail("Invalid {$field}: must be a non-negative number.", 422, [$field]);
    $f = (float)$val;
    if (!is_finite($f)) fail("Invalid {$field}: must be a finite number.", 422, [$field]);
    if ($f < 0) fail("Invalid {$field}: must be non-negative.", 422, [$field]);
    if ($f > $max) fail("Invalid {$field}: must not exceed {$max}.", 422, [$field]);
    return round($f, 2);
}

function strict_positive_int(mixed $val, string $field): int {
    if (is_bool($val) || is_array($val) || is_object($val) || $val === null) {
        fail("Invalid {$field}: must be a positive integer.", 422, [$field]);
    }
    if (is_string($val)) {
        $val = trim($val);
        if ($val === '') fail("Invalid {$field}: must be a positive integer.", 422, [$field]);
        if (!preg_match('/^\d+$/', $val)) fail("Invalid {$field}: must be a positive integer.", 422, [$field]);
    } elseif (is_float($val)) {
        if (floor($val) !== $val) fail("Invalid {$field}: must be a positive integer.", 422, [$field]);
    } elseif (!is_int($val)) {
        fail("Invalid {$field}: must be a positive integer.", 422, [$field]);
    }
    $i = (int)$val;
    if ($i <= 0) fail("Invalid {$field}: must be greater than zero.", 422, [$field]);
    if ($i > PHP_INT_MAX) fail("Invalid {$field}: too large.", 422, [$field]);
    return $i;
}

function validate_market_id(mixed $val): string {
    if (!is_string($val)) fail('Invalid market_id.', 422, ['market_id']);
    $val = trim($val);
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $val)) fail('Invalid market_id format.', 422, ['market_id']);
    return $val;
}

function validate_text(mixed $val, string $field, int $minLen, int $maxLen): string {
    if (!is_string($val)) fail("Invalid {$field}: must be a string.", 422, [$field]);
    $val = trim($val);
    $val = strip_tags($val);
    $len = mb_strlen($val, 'UTF-8');
    if ($len < $minLen) fail("Invalid {$field}: minimum {$minLen} characters.", 422, [$field]);
    if ($len > $maxLen) fail("Invalid {$field}: maximum {$maxLen} characters.", 422, [$field]);
    return $val;
}

function require_fields(array $body, array $fields): void {
    $missing = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f, $body)) $missing[] = $f;
    }
    if (!empty($missing)) {
        fail('Missing required fields: ' . implode(', ', $missing), 422, $missing);
    }
}

function valid_email(string $e): bool {
    if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) return false;
    if (mb_strlen($e, 'UTF-8') > 254) return false;
    return true;
}

function validate_phone_ke(mixed $val): string {
    if (!is_string($val)) fail('Invalid phone format.', 422, ['phone']);
    $val = trim($val);
    if (!preg_match('/^\+254\d{9}$/', $val)) {
        fail('Invalid phone format. Use +254XXXXXXXXX (Kenyan format).', 422, ['phone']);
    }
    return $val;
}

function validate_datetime(mixed $val, string $field): string {
    if (!is_string($val)) fail("Invalid {$field}: must be a datetime string.", 422, [$field]);
    $val = trim($val);
    $t = strtotime($val);
    if ($t === false) fail("Invalid {$field}: not a valid datetime.", 422, [$field]);
    return gmdate('Y-m-d H:i:s', $t);
}

function validate_enum(mixed $val, array $allowed, string $field): string {
    if (!is_string($val)) fail("Invalid {$field}: must be a string.", 422, [$field]);
    if (!in_array($val, $allowed, true)) {
        fail("Invalid {$field}. Allowed: " . implode(', ', $allowed), 422, [$field]);
    }
    return $val;
}

// ============================================================
// SECTION 1.7 — AUDIT & LEDGER HELPERS (POST-COMMIT)
// ============================================================

function audit_log(string $action, int $adminId, string $targetType, ?int $targetId = null, array $meta = []): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO audit_logs (admin_id, action, target_type, target_id, meta, created_at)
            VALUES (:aid, :act, :tt, :tid, :meta, NOW())
        ");
        $stmt->execute([
            ':aid'  => $adminId,
            ':act'  => $action,
            ':tt'   => $targetType,
            ':tid'  => $targetId,
            ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('[MwasinMarket] audit_log failed: ' . $e->getMessage());
    }
}

function record_balance_tx(int $userId, string $type, float $amount, float $balBefore, float $balAfter, ?string $reference = null, ?int $marketId = null, string $note = ''): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO balance_transactions
                (user_id, type, amount, balance_before, balance_after, reference_id, market_id, note, created_at)
            VALUES
                (:uid, :type, :amt, :bb, :ba, :ref, :mid, :note, NOW())
        ");
        $stmt->execute([
            ':uid'  => $userId,
            ':type' => $type,
            ':amt'  => $amount,
            ':bb'   => $balBefore,
            ':ba'   => $balAfter,
            ':ref'  => $reference,
            ':mid'  => $marketId,
            ':note' => $note,
        ]);
    } catch (Throwable $e) {
        error_log('[MwasinMarket] record_balance_tx failed: ' . $e->getMessage());
    }
}

function create_notification(string $type, string $message, ?int $targetId = null): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO notifications (type, target_id, message, is_read, created_at)
            VALUES (:t, :tid, :msg, 0, NOW())
        ");
        $stmt->execute([':t' => $type, ':tid' => $targetId, ':msg' => mb_substr($message, 0, 300, 'UTF-8')]);
    } catch (Throwable $e) {
        error_log('[MwasinMarket] notification failed: ' . $e->getMessage());
    }
}

// ============================================================
// SECTION 1.8 — MAINTENANCE MODE CHECK
// ============================================================

function check_maintenance(): void {
    try {
        $row = db()->query("SELECT value, message FROM system_settings WHERE `key`='maintenance_mode' LIMIT 1")->fetch();
    } catch (Throwable $e) {
        return;
    }
    if ($row && (string)$row['value'] === '1') {
        $msg = trim((string)($row['message'] ?? ''));
        if ($msg === '') $msg = 'The platform is currently under maintenance. Please check back soon.';
        fail($msg, 503);
    }
}

// ============================================================
// SECTION 1.9 — AUTH ROUTES (handled in bootstrap.php)
// ============================================================

function dispatch_bootstrap_route(string $route, array $body): void {
    switch ($route) {
        case 'register':    handle_register($body);    return;
        case 'login':       handle_login($body);       return;
        case 'admin_login': handle_admin_login($body); return;
        case 'logout':      handle_logout();           return;
        case 'profile':     handle_profile();          return;
        case 'my_bets':     handle_my_bets();          return;
        case 'health':      handle_health();           return;
    }
    fail('Route not found', 404);
}

function handle_register(array $body): void {
    check_rate_limit(20, 3600);
    require_fields($body, ['username', 'email', 'phone', 'password']);
    $username = validate_text($body['username'], 'username', 3, 30);
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        fail('Username must contain only letters, digits, underscores or hyphens.', 422, ['username']);
    }
    $email = validate_text($body['email'], 'email', 5, 254);
    if (!valid_email($email)) fail('Invalid email address.', 422, ['email']);
    $phone = validate_phone_ke($body['phone']);
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    if (mb_strlen($password, 'UTF-8') < 8) fail('Password must be at least 8 characters.', 422, ['password']);
    if (mb_strlen($password, 'UTF-8') > 200) fail('Password too long.', 422, ['password']);
    $fullName = isset($body['full_name']) ? validate_text($body['full_name'], 'full_name', 0, 120) : '';

    $check = db()->prepare("SELECT id FROM users WHERE username = :u OR email = :e OR phone = :p LIMIT 1");
    $check->execute([':u' => $username, ':e' => $email, ':p' => $phone]);
    if ($check->fetch()) fail('A user with that username, email, or phone already exists.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $ins = db()->prepare("
        INSERT INTO users (username, email, phone, full_name, password_hash, role, balance, locked_balance, bonus_balance, created_at, updated_at)
        VALUES (:u, :e, :p, :fn, :h, 'user', 0, 0, 0, NOW(), NOW())
    ");
    $ins->execute([':u' => $username, ':e' => $email, ':p' => $phone, ':fn' => $fullName, ':h' => $hash]);
    $userId = (int)db()->lastInsertId();
    clear_rate_limit_attempt();
    $token = issue_token($userId);
    audit_log('user_registered', 0, 'user', $userId, ['username' => $username]);
    ok([
        'user_id'  => $userId,
        'username' => $username,
        'email'    => $email,
        'phone'    => $phone,
        'role'     => 'user',
        'token'    => $token,
        'expires_in' => TOKEN_TTL,
    ], 'Registration successful', 201);
}

function _login_identifier(array $body): string {
    foreach (['identifier', 'username', 'email', 'phone'] as $k) {
        if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') return trim($body[$k]);
    }
    return '';
}

function handle_login(array $body): void {
    check_rate_limit(10, 900);
    if (!isset($body['password']) || !is_string($body['password'])) {
        fail('Missing required fields: password', 422, ['password']);
    }
    $password = (string)$body['password'];
    $identifier = _login_identifier($body);
    if ($identifier === '' || $password === '') fail('Invalid credentials.', 401);

    $stmt = db()->prepare("SELECT id, username, email, phone, role, balance, locked_balance, bonus_balance, password_hash, is_suspended FROM users WHERE (username = :id OR email = :id OR phone = :id) LIMIT 1");
    $stmt->execute([':id' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) { password_verify($password, DUMMY_HASH); fail('Invalid credentials.', 401); }
    if (!password_verify($password, (string)$user['password_hash'])) fail('Invalid credentials.', 401);
    if ((int)$user['is_suspended'] === 1) fail('Your account has been suspended.', 403);

    clear_rate_limit_attempt();
    cleanup_expired_tokens((int)$user['id']);
    $token = issue_token((int)$user['id']);
    $exp = time() + TOKEN_TTL;
    ok([
        'user_id'        => (int)$user['id'],
        'username'       => $user['username'],
        'email'          => $user['email'],
        'phone'          => $user['phone'],
        'role'           => $user['role'],
        'balance'        => (float)$user['balance'],
        'locked_balance' => (float)$user['locked_balance'],
        'bonus_balance'  => (float)$user['bonus_balance'],
        'token'          => $token,
        'expires_in'     => TOKEN_TTL,
        'expires_at'     => gmdate('Y-m-d H:i:s', $exp),
    ], 'Login successful');
}

function handle_admin_login(array $body): void {
    check_rate_limit(10, 900);
    if (!isset($body['password']) || !is_string($body['password'])) {
        fail('Missing required fields: password', 422, ['password']);
    }
    $password = (string)$body['password'];
    $identifier = _login_identifier($body);
    if ($identifier === '' || $password === '') fail('Invalid credentials.', 401);

    $stmt = db()->prepare("SELECT id, username, email, phone, role, balance, locked_balance, bonus_balance, password_hash, is_suspended FROM users WHERE (username = :id OR email = :id OR phone = :id) LIMIT 1");
    $stmt->execute([':id' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) { password_verify($password, DUMMY_HASH); fail('Invalid credentials.', 401); }
    if (!password_verify($password, (string)$user['password_hash'])) fail('Invalid credentials.', 401);
    if ($user['role'] !== 'admin') fail('Admin access required.', 403);
    if ((int)$user['is_suspended'] === 1) fail('Your account has been suspended.', 403);

    clear_rate_limit_attempt();
    cleanup_expired_tokens((int)$user['id']);
    $token = issue_token((int)$user['id']);
    $exp = time() + TOKEN_TTL;
    audit_log('admin_login', (int)$user['id'], 'user', (int)$user['id'], ['ip' => client_ip()]);
    ok([
        'user_id'        => (int)$user['id'],
        'username'       => $user['username'],
        'email'          => $user['email'],
        'phone'          => $user['phone'],
        'role'           => 'admin',
        'balance'        => (float)$user['balance'],
        'locked_balance' => (float)$user['locked_balance'],
        'bonus_balance'  => (float)$user['bonus_balance'],
        'token'          => $token,
        'expires_in'     => TOKEN_TTL,
        'expires_at'     => gmdate('Y-m-d H:i:s', $exp),
    ], 'Admin login successful');
}

function handle_logout(): void {
    $auth = require_auth();
    revoke_token($auth['token']);
    ok(['logged_out' => true], 'Logged out');
}

function handle_profile(): void {
    $auth = require_auth();
    $stmt = db()->prepare("
        SELECT id, username, email, phone, full_name, role, balance, locked_balance, bonus_balance,
               total_wagered, total_wins, verified, email_verified, phone_verified,
               is_suspended, messaging_restricted, created_at
        FROM users WHERE id = :uid LIMIT 1
    ");
    $stmt->execute([':uid' => $auth['user_id']]);
    $user = $stmt->fetch();
    if (!$user) fail('User not found.', 404);

    $stats = db()->prepare("
        SELECT
            COUNT(*) AS total_bets,
            SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open_bets,
            SUM(CASE WHEN status='won' THEN 1 ELSE 0 END)  AS won_bets,
            SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) AS lost_bets,
            SUM(CASE WHEN status='void' THEN 1 ELSE 0 END) AS void_bets,
            COALESCE(SUM(stake),0) AS total_stake,
            COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS total_payout
        FROM bets WHERE user_id = :uid
    ");
    $stats->execute([':uid' => $auth['user_id']]);
    $bs = $stats->fetch();

    ok([
        'user_id'              => (int)$user['id'],
        'username'             => $user['username'],
        'email'                => $user['email'],
        'phone'                => $user['phone'],
        'full_name'            => $user['full_name'],
        'role'                 => $user['role'],
        'verified'             => (bool)$user['verified'],
        'email_verified'       => (bool)$user['email_verified'],
        'phone_verified'       => (bool)$user['phone_verified'],
        'is_suspended'         => (bool)$user['is_suspended'],
        'messaging_restricted' => (bool)$user['messaging_restricted'],
        'balance'              => (float)$user['balance'],
        'locked_balance'       => (float)$user['locked_balance'],
        'available_balance'    => (float)$user['balance'] - (float)$user['locked_balance'],
        'bonus_balance'        => (float)$user['bonus_balance'],
        'total_wagered'        => (float)$user['total_wagered'],
        'total_wins'           => (float)$user['total_wins'],
        'member_since'         => $user['created_at'],
        'bet_stats' => [
            'total'         => (int)$bs['total_bets'],
            'open'          => (int)$bs['open_bets'],
            'won'           => (int)$bs['won_bets'],
            'lost'          => (int)$bs['lost_bets'],
            'void'          => (int)$bs['void_bets'],
            'total_stake'   => (float)$bs['total_stake'],
            'total_payout'  => (float)$bs['total_payout'],
        ],
    ]);
}

function handle_my_bets(): void {
    $auth = require_auth();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $history = !empty($_GET['history']);

    $where = ['b.user_id = :uid'];
    $params = [':uid' => $auth['user_id']];
    if ($status !== '') {
        if (!in_array($status, ['open', 'won', 'lost', 'void'], true)) {
            fail('Invalid status filter.', 422, ['status']);
        }
        $where[] = 'b.status = :st';
        $params[':st'] = $status;
    } elseif (!$history) {
        $where[] = "b.status = 'open'";
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) c FROM bets b WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];

    $sql = "
        SELECT b.id, b.slip_id, b.market_id, b.outcome_id, b.is_bonus_bet, b.shares, b.stake,
               b.odds_at_entry, b.possible_win, b.payout, b.status, b.void_reason, b.expires_at, b.created_at,
               m.market_id AS market_pubid, m.question, m.status AS market_status,
               o.name AS outcome_name
        FROM bets b
        JOIN markets m ON m.id = b.market_id
        JOIN outcomes o ON o.id = b.outcome_id
        WHERE {$whereSql}
        ORDER BY b.created_at DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'slip_id'       => $r['slip_id'],
            'market_id'     => $r['market_pubid'],
            'question'      => $r['question'],
            'market_status' => $r['market_status'],
            'outcome_id'    => (int)$r['outcome_id'],
            'outcome'       => $r['outcome_name'],
            'outcome_name'  => $r['outcome_name'],
            'is_bonus_bet'  => (bool)$r['is_bonus_bet'],
            'shares'        => (float)$r['shares'],
            'stake'         => (float)$r['stake'],
            'odds'          => (float)$r['odds_at_entry'],
            'odds_at_entry' => (float)$r['odds_at_entry'],
            'possible_win'  => (float)$r['possible_win'],
            'payout'        => $r['payout'] === null ? null : (float)$r['payout'],
            'status'        => $r['status'],
            'void_reason'   => $r['void_reason'],
            'expires_at'    => $r['expires_at'],
            'placed_at'     => $r['created_at'],
            'created_at'    => $r['created_at'],
        ];
    }

    ok([
        'data' => $out,
        'meta' => [
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function handle_health(): void {
    if (!DEBUG_MODE) {
        $tok = read_bearer();
        $res = $tok === '' ? null : resolve_token($tok);
        if (!$res || $res['role'] !== 'admin') fail('Forbidden.', 403);
    }
    $ok = true;
    $dbError = null;
    try {
        db()->query('SELECT 1')->fetch();
    } catch (Throwable $e) {
        $ok = false;
        $dbError = DEBUG_MODE ? $e->getMessage() : 'db error';
    }
    ok([
        'status' => $ok ? 'healthy' : 'unhealthy',
        'db'     => $ok ? 'up' : 'down',
        'db_error' => $dbError,
        'time'   => gmdate('Y-m-d H:i:s'),
    ]);
}

/**
 * MwasinMarket API — Markets & Betting
 * Assumes bootstrap.php is already loaded.
 *
 * NOTE: No sell/cash-out route exists. Users buy-only. This prevents
 * round-trip LMSR arbitrage entirely.
 */

// ============================================================
// LMSR ENGINE
// ============================================================

function lmsr_cost(array $shares, float $b): float {
    if (empty($shares) || $b <= 0) return 0.0;
    $max = -INF;
    foreach ($shares as $s) {
        $v = (float)$s / $b;
        if ($v > $max) $max = $v;
    }
    if (!is_finite($max)) return 0.0;
    $sum = 0.0;
    foreach ($shares as $s) {
        $sum += exp(((float)$s / $b) - $max);
    }
    return $b * ($max + log($sum));
}

function lmsr_probs(array $shares, float $b): array {
    if (empty($shares) || $b <= 0) return [];
    $max = -INF;
    foreach ($shares as $s) {
        $v = (float)$s / $b;
        if ($v > $max) $max = $v;
    }
    $exps = [];
    $sum = 0.0;
    foreach ($shares as $s) {
        $e = exp(((float)$s / $b) - $max);
        $exps[] = $e;
        $sum += $e;
    }
    $probs = [];
    foreach ($exps as $e) {
        $probs[] = $sum > 0 ? $e / $sum : 0.0;
    }
    return $probs;
}

function lmsr_buy_cost(array $shares, float $b, int $idx, float $qty): float {
    $before = lmsr_cost($shares, $b);
    $shares[$idx] = (float)$shares[$idx] + $qty;
    $after = lmsr_cost($shares, $b);
    return $after - $before;
}

function lmsr_shares_for(array $shares, float $b, int $idx, float $budget): float {
    if ($budget <= 0 || $b <= 0) return 0.0;
    $lo = 0.0;
    $hi = max(1.0, $budget * 2.0);
    for ($i = 0; $i < 80; $i++) {
        $cost = lmsr_buy_cost($shares, $b, $idx, $hi);
        if ($cost >= $budget) break;
        $hi *= 2.0;
        if ($hi > 1e12) break;
    }
    for ($i = 0; $i < 64; $i++) {
        $mid = ($lo + $hi) / 2.0;
        $cost = lmsr_buy_cost($shares, $b, $idx, $mid);
        if ($cost < $budget) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return ($lo + $hi) / 2.0;
}

function lmsr_snapshot(array $rows, float $b): array {
    $shares = [];
    foreach ($rows as $r) $shares[] = (float)$r['shares'];
    $probs = lmsr_probs($shares, $b);
    $out = [];
    foreach ($rows as $i => $r) {
        $p = $probs[$i] ?? 0.0;
        $odds = $p > 0 ? round(1.0 / $p, 4) : 0.0;
        $out[] = [
            'outcome_id' => (int)$r['id'],
            'name'       => $r['name'],
            'probability'=> round($p, 6),
            'odds'       => $odds,
        ];
    }
    return $out;
}

function fixed_snapshot(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $odds = (float)($r['fixed_odds'] ?? 0);
        $p = $odds > 0 ? 1.0 / $odds : 0.0;
        $out[] = [
            'outcome_id' => (int)$r['id'],
            'name'       => $r['name'],
            'probability'=> round($p, 6),
            'odds'       => round($odds, 4),
        ];
    }
    return $out;
}

function market_snapshot(array $rows, float $b, string $oddsMode): array {
    return $oddsMode === 'fixed' ? fixed_snapshot($rows) : lmsr_snapshot($rows, $b);
}

function normalize_probs(array $outcomes): array {
    $probs = [];
    $sum = 0.0;
    foreach ($outcomes as $o) {
        $odds = (float)$o['odds'];
        if ($odds <= 1.0) {
            fail('Each outcome odds must be greater than 1.0.', 422, ['outcomes']);
        }
        $p = 1.0 / $odds;
        $probs[] = $p;
        $sum += $p;
    }
    if ($sum <= 0) fail('Invalid odds configuration.', 422, ['outcomes']);
    $norm = [];
    foreach ($probs as $p) $norm[] = $p / $sum;
    return $norm;
}

function seed_shares(array $probs, float $b): array {
    // To get given probabilities, shares = b * log(p_i) + constant.
    // Constant is arbitrary; choose to keep the smallest at zero for stability.
    $logs = [];
    foreach ($probs as $p) {
        $logs[] = $p > 0 ? log($p) : -50.0;
    }
    $min = min($logs);
    $shares = [];
    foreach ($logs as $l) {
        $shares[] = round($b * ($l - $min), 8);
    }
    return $shares;
}

function record_market_snapshot(int $marketId, array $rows, float $b, float $vol): void {
    try {
        // Detect mode by presence of fixed_odds column populated
        $hasFixed = false;
        foreach ($rows as $r) {
            if (!empty($r['fixed_odds']) && (float)$r['fixed_odds'] > 0) { $hasFixed = true; break; }
        }
        $snap = $hasFixed ? fixed_snapshot($rows) : lmsr_snapshot($rows, $b);
        $stmt = db()->prepare("
            INSERT INTO market_snapshots (market_id, outcome_id, probability, odds, shares, volume, created_at)
            VALUES (:mid, :oid, :p, :o, :s, :v, NOW())
        ");
        foreach ($rows as $i => $r) {
            $s = $snap[$i];
            $stmt->execute([
                ':mid' => $marketId,
                ':oid' => (int)$r['id'],
                ':p'   => $s['probability'],
                ':o'   => $s['odds'],
                ':s'   => (float)($r['shares'] ?? 0),
                ':v'   => $vol,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[MwasinMarket] market_snapshot failed: ' . $e->getMessage());
    }
}

// ============================================================
// HELPERS
// ============================================================

function gen_market_id(): string {
    return bin2hex(random_bytes(8));
}

function gen_slip_id(): string {
    return 'BET_' . strtoupper(bin2hex(random_bytes(4)));
}

function auto_close_markets(): void {
    try {
        $stmt = db()->prepare("UPDATE markets SET status='closed', updated_at=NOW()
            WHERE status='open' AND close_time IS NOT NULL AND close_time <= NOW()");
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[MwasinMarket] auto_close failed: ' . $e->getMessage());
    }
}

function fetch_market_outcomes(int $marketId): array {
    $stmt = db()->prepare("SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id = :mid ORDER BY id ASC");
    $stmt->execute([':mid' => $marketId]);
    return $stmt->fetchAll();
}

function public_market_row(array $m, array $outcomes): array {
    return [
        'id'                => 'pm_' . $m['market_id'],
        'market_id'         => $m['market_id'],
        'source'            => $m['source'] ?: 'local',
        'question'          => $m['question'],
        'title'             => $m['title'],
        'image_url'         => $m['image_url'],
        'category'          => $m['category'],
        'market_type'       => $m['market_type'],
        'status'            => $m['status'],
        'close_time'        => $m['close_time'],
        'resolve_time'      => $m['resolve_time'],
        'void_reason'       => $m['void_reason'],
        'min_stake'         => (float)$m['min_stake'],
        'max_stake'         => (float)$m['max_stake'],
        'max_total_wagered' => (float)$m['max_total_wagered'],
        'total_bets'        => (int)$m['total_bets'],
        'total_wagered'     => (float)$m['total_wagered'],
        'is_archived'       => (bool)$m['is_archived'],
        'outcomes'          => $outcomes,
        'created_at'        => $m['created_at'],
    ];
}

// ============================================================
// ROUTE DISPATCH
// ============================================================

function dispatch_markets_route(string $route, array $body): void {
    switch ($route) {
        case 'markets':                    handle_markets_list();           return;
        case 'market':                     handle_market_single();          return;
        case 'market_history':             handle_market_history();         return;
        case 'bet':                        handle_bet($body);               return;
        case 'admin_create_market':        handle_admin_create_market($body); return;
        case 'admin_pause_market':         handle_admin_pause_market($body); return;
        case 'admin_resume_market':        handle_admin_resume_market($body); return;
        case 'admin_force_close_market':   handle_admin_force_close_market($body); return;
        case 'admin_reopen_market':        handle_admin_reopen_market($body); return;
        case 'admin_settle_market':        handle_admin_settle_market($body); return;
        case 'admin_void_market':          handle_admin_void_market($body); return;
        case 'admin_void_bets_by_time':    handle_admin_void_bets_by_time($body); return;
        case 'admin_void_bet':             handle_admin_void_bet($body); return;
        case 'admin_archive_market':       handle_admin_archive_market($body); return;
        case 'admin_edit_market':          handle_admin_edit_market($body); return;
        case 'admin_adjust_limits':        handle_admin_adjust_limits($body); return;
        case 'admin_extend_close_time':    handle_admin_extend_close_time($body); return;
        case 'admin_set_liquidity':        handle_admin_set_liquidity($body); return;
        case 'admin_reseed_odds':          handle_admin_reseed_odds($body); return;
        case 'admin_set_odds_mode':        handle_admin_set_odds_mode($body); return;
        case 'admin_stats':                handle_admin_stats(); return;
        case 'admin_market_report':        handle_admin_market_report(); return;
    }
    fail('Route not found', 404);
}

// ============================================================
// PUBLIC ROUTES
// ============================================================

function handle_markets_list(): void {
    auto_close_markets();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];
    if (!empty($_GET['category'])) {
        $where[] = 'category = :cat';
        $params[':cat'] = (string)$_GET['category'];
    }
    if (!empty($_GET['source'])) {
        $where[] = 'source = :src';
        $params[':src'] = (string)$_GET['source'];
    }
    if (!empty($_GET['market_type'])) {
        $mt = (string)$_GET['market_type'];
        if (!in_array($mt, ['binary', 'categorical'], true)) fail('Invalid market_type.', 422);
        $where[] = 'market_type = :mt';
        $params[':mt'] = $mt;
    }
    $status = $_GET['status'] ?? 'active';
    $includeResolved = !empty($_GET['include_resolved']);
    $includeArchived = !empty($_GET['include_archived']);
    if ($status === 'active') {
        $where[] = "status IN ('open', 'paused')";
    } elseif (in_array($status, ['open', 'paused', 'closed', 'resolved', 'voided'], true)) {
        $where[] = 'status = :st';
        $params[':st'] = $status;
    } elseif ($status === 'all') {
        // no filter
    } else {
        fail('Invalid status filter.', 422);
    }
    if (!$includeResolved && $status === 'active') {
        // already excluded
    }
    if (!$includeArchived) {
        $where[] = 'is_archived = 0';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = db()->prepare("SELECT COUNT(*) c FROM markets {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];

    $sql = "SELECT * FROM markets {$whereSql} ORDER BY created_at DESC LIMIT :lim OFFSET :off";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $markets = $stmt->fetchAll();

    if (empty($markets)) {
        ok(['data' => [], 'meta' => ['total' => 0, 'page' => $page, 'limit' => $limit, 'pages' => 0]]);
    }

    $marketIds = array_map(fn($m) => (int)$m['id'], $markets);
    $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
    $ostmt = db()->prepare("SELECT id, market_id, name, shares, fixed_odds FROM outcomes WHERE market_id IN ({$placeholders}) ORDER BY id ASC");
    $ostmt->execute($marketIds);
    $outRows = $ostmt->fetchAll();

    $byMarket = [];
    foreach ($outRows as $o) {
        $byMarket[(int)$o['market_id']][] = $o;
    }

    $result = [];
    foreach ($markets as $m) {
        $rows = $byMarket[(int)$m['id']] ?? [];
        $snap = market_snapshot($rows, (float)$m['b'], (string)$m['odds_mode']);
        $result[] = public_market_row($m, $snap);
    }

    ok([
        'data' => $result,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil($total / $limit)],
    ]);
}

function handle_market_single(): void {
    auto_close_markets();
    $mid = validate_market_id($_GET['market_id'] ?? '');
    $stmt = db()->prepare("SELECT * FROM markets WHERE market_id = :mid LIMIT 1");
    $stmt->execute([':mid' => $mid]);
    $m = $stmt->fetch();
    if (!$m) fail('Market not found.', 404);
    $rows = fetch_market_outcomes((int)$m['id']);
    $snap = market_snapshot($rows, (float)$m['b'], (string)$m['odds_mode']);
    ok(public_market_row($m, $snap));
}

function handle_market_history(): void {
    $mid = validate_market_id($_GET['market_id'] ?? '');
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
    $outcomeFilter = isset($_GET['outcome_id']) ? strict_positive_int($_GET['outcome_id'], 'outcome_id') : 0;

    $mstmt = db()->prepare("SELECT id FROM markets WHERE market_id = :mid LIMIT 1");
    $mstmt->execute([':mid' => $mid]);
    $m = $mstmt->fetch();
    if (!$m) fail('Market not found.', 404);

    // Outcome names lookup
    $ostmt = db()->prepare("SELECT id, name FROM outcomes WHERE market_id = :mid");
    $ostmt->execute([':mid' => $m['id']]);
    $names = [];
    foreach ($ostmt->fetchAll() as $o) $names[(int)$o['id']] = $o['name'];

    $sql = "SELECT outcome_id, odds, volume, created_at FROM market_snapshots WHERE market_id = :mid";
    $params = [':mid' => (int)$m['id']];
    if ($outcomeFilter > 0) {
        $sql .= " AND outcome_id = :oid";
        $params[':oid'] = $outcomeFilter;
    }
    $sql .= " ORDER BY created_at DESC LIMIT :lim";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_reverse($stmt->fetchAll());

    $grouped = [];
    foreach ($rows as $r) {
        $oid = (int)$r['outcome_id'];
        if (!isset($grouped[$oid])) $grouped[$oid] = [];
        $grouped[$oid][] = [
            'time'   => $r['created_at'],
            'odds'   => (float)$r['odds'],
            'volume' => (float)$r['volume'],
        ];
    }

    $outcomes = [];
    foreach ($grouped as $oid => $series) {
        $outcomes[] = [
            'outcome_id'   => $oid,
            'outcome_name' => $names[$oid] ?? null,
            'series'       => $series,
        ];
    }

    ok([
        'market_id' => $mid,
        'outcomes'  => $outcomes,
        'count'     => count($rows),
    ]);
}

// ============================================================
// BET ROUTE
// ============================================================

function handle_bet(array $body): void {
    $auth = require_auth();
    $userId = (int)$auth['user_id'];
    check_bet_rate_limit($userId);

    require_fields($body, ['market_id', 'outcome_id', 'amount']);
    $mid     = validate_market_id($body['market_id']);
    $outId   = strict_positive_int($body['outcome_id'], 'outcome_id');
    $amount  = strict_positive_amount($body['amount'], 'amount');
    $isBonus = !empty($body['use_bonus']) ? 1 : 0;

    // Pre-bet auto-close in separate committed transaction
    auto_close_markets();

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Lock market
        $mst = $pdo->prepare("SELECT * FROM markets WHERE market_id = :mid FOR UPDATE");
        $mst->execute([':mid' => $mid]);
        $m = $mst->fetch();
        if (!$m) {
            $pdo->rollBack();
            fail('Market not found.', 404);
        }

        // Auto-close inside transaction if needed
        if ($m['status'] === 'open' && !empty($m['close_time']) && strtotime($m['close_time']) <= time()) {
            $upd = $pdo->prepare("UPDATE markets SET status='closed', updated_at=NOW() WHERE id = :id");
            $upd->execute([':id' => $m['id']]);
            $m['status'] = 'closed';
        }

        if ($m['status'] === 'paused') {
            $pdo->rollBack();
            fail('Market is paused. Try again later.', 503);
        }
        if (in_array($m['status'], ['closed', 'resolved', 'voided'], true)) {
            $pdo->rollBack();
            fail('Market is not accepting bets.', 409);
        }

        // Lock user
        $ust = $pdo->prepare("SELECT id, balance, locked_balance, bonus_balance, is_suspended FROM users WHERE id = :uid FOR UPDATE");
        $ust->execute([':uid' => $userId]);
        $user = $ust->fetch();
        if (!$user) { $pdo->rollBack(); fail('User not found.', 404); }
        if ((int)$user['is_suspended'] === 1) {
            $pdo->rollBack();
            fail('Your account has been suspended.', 403);
        }

        // Stake range
        if ($amount < (float)$m['min_stake']) {
            $pdo->rollBack();
            fail('Stake is below market minimum of ' . (float)$m['min_stake'], 422, ['amount']);
        }
        if ($amount > (float)$m['max_stake']) {
            $pdo->rollBack();
            fail('Stake exceeds market maximum of ' . (float)$m['max_stake'], 422, ['amount']);
        }

        // Wager cap
        $cap = (float)$m['max_total_wagered'];
        if ($cap > 0 && ((float)$m['total_wagered'] + $amount) > $cap) {
            $pdo->rollBack();
            fail('Market wager cap reached. Bet rejected.', 409);
        }

        // Lock outcomes
        $ostmt = $pdo->prepare("SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id = :mid ORDER BY id ASC FOR UPDATE");
        $ostmt->execute([':mid' => $m['id']]);
        $outcomes = $ostmt->fetchAll();
        if (empty($outcomes)) { $pdo->rollBack(); fail('Market has no outcomes.', 409); }

        $selected = null; $selIdx = -1;
        foreach ($outcomes as $i => $o) {
            if ((int)$o['id'] === $outId) { $selected = $o; $selIdx = $i; break; }
        }
        if (!$selected) { $pdo->rollBack(); fail('Outcome not found in this market.', 404); }

        $oddsMode = (string)$m['odds_mode'];
        $b = (float)$m['b'];

        $probBefore = 0.0; $probAfter = 0.0;
        $oddsAtEntry = 0.0; $sharesAcquired = 0.0;
        $maxOddsCap = $m['max_odds'] !== null ? (float)$m['max_odds'] : 0.0;

        if ($oddsMode === 'lmsr') {
            $shares = array_map(fn($o) => (float)$o['shares'], $outcomes);
            $probsBefore = lmsr_probs($shares, $b);
            $probBefore = $probsBefore[$selIdx] ?? 0.0;
            $oddsCurrent = $probBefore > 0 ? 1.0 / $probBefore : INF;

            // Cap check (do not reveal value)
            if ($maxOddsCap > 0 && $oddsCurrent >= $maxOddsCap) {
                $pdo->rollBack();
                create_notification('max_odds_triggered',
                    "Outcome {$selected['name']} in market {$m['market_id']} exceeded max_odds cap. Betting suspended.",
                    (int)$m['id']);
                fail('This selection is not currently available for betting.', 409);
            }

            // Compute shares acquired by spending amount
            $sharesAcquired = lmsr_shares_for($shares, $b, $selIdx, $amount);
            if ($sharesAcquired <= 0) {
                $pdo->rollBack();
                fail('Could not price this bet. Try a different amount.', 422);
            }
            $sharesAfter = $shares;
            $sharesAfter[$selIdx] += $sharesAcquired;
            $probsAfter = lmsr_probs($sharesAfter, $b);
            $probAfter = $probsAfter[$selIdx] ?? $probBefore;
            $oddsAfter = $probAfter > 0 ? 1.0 / $probAfter : INF;

            if ($maxOddsCap > 0 && $oddsAfter > $maxOddsCap) {
                $pdo->rollBack();
                create_notification('max_odds_triggered',
                    "Outcome {$selected['name']} in market {$m['market_id']} would exceed max_odds cap.",
                    (int)$m['id']);
                fail('This selection is not currently available for betting.', 409);
            }
            $effOdds = $sharesAcquired > 0 ? $sharesAcquired / $amount : 0.0;
            $oddsAtEntry = round($effOdds, 4);
        } else {
            // Fixed odds
            $fxOdds = (float)($selected['fixed_odds'] ?? 0);
            if ($fxOdds <= 1.0) { $pdo->rollBack(); fail('Outcome has no valid odds set.', 409); }
            $oddsAtEntry = round($fxOdds, 4);
            $sharesAcquired = $amount * $oddsAtEntry;
            $probBefore = 1.0 / $fxOdds;
            $probAfter = $probBefore;
        }

        $possibleWin = round($sharesAcquired, 2); // For LMSR shares ARE the payout
        if ($oddsMode === 'fixed') {
            $possibleWin = round($amount * $oddsAtEntry, 2);
        }

        // Bonus vs real balance
        if ($isBonus === 1) {
            if ((float)$user['bonus_balance'] < $amount) {
                $pdo->rollBack();
                fail('Insufficient bonus balance.', 402);
            }
            $balBefore = (float)$user['bonus_balance'];
            $upd = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance - :amt, locked_balance = locked_balance + :amt, total_wagered = total_wagered + :amt, updated_at = NOW() WHERE id = :uid AND bonus_balance >= :amt");
            $upd->execute([':amt' => $amount, ':uid' => $userId]);
            if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient bonus balance.', 402); }
            $balAfter = $balBefore - $amount;
        } else {
            $avail = (float)$user['balance'] - (float)$user['locked_balance'];
            if ($avail < $amount) {
                $pdo->rollBack();
                fail('Insufficient balance.', 402);
            }
            $balBefore = (float)$user['balance'];
            $upd = $pdo->prepare("UPDATE users SET balance = balance - :amt, locked_balance = locked_balance + :amt, total_wagered = total_wagered + :amt, updated_at = NOW() WHERE id = :uid AND balance >= :amt");
            $upd->execute([':amt' => $amount, ':uid' => $userId]);
            if ($upd->rowCount() === 0) { $pdo->rollBack(); fail('Insufficient balance.', 402); }
            $balAfter = $balBefore - $amount;
        }

        // Update shares (LMSR only)
        if ($oddsMode === 'lmsr') {
            $sst = $pdo->prepare("UPDATE outcomes SET shares = shares + :delta WHERE id = :oid");
            $sst->execute([':delta' => $sharesAcquired, ':oid' => $outId]);
        }

        // Update market counters
        $mupd = $pdo->prepare("UPDATE markets SET total_bets = total_bets + 1, total_wagered = total_wagered + :amt, updated_at = NOW() WHERE id = :mid");
        $mupd->execute([':amt' => $amount, ':mid' => $m['id']]);

        // Bet record
        $slipId = gen_slip_id();
        $expiresAt = $m['close_time'] ?: gmdate('Y-m-d H:i:s', time() + TOKEN_TTL);
        $bins = $pdo->prepare("
            INSERT INTO bets (slip_id, user_id, market_id, outcome_id, is_bonus_bet, shares, stake, odds_at_entry,
                              possible_win, prob_before, prob_after, status, expires_at, created_at)
            VALUES (:sid, :uid, :mid, :oid, :bonus, :sh, :st, :odds, :pw, :pb, :pa, 'open', :exp, NOW())
        ");
        $bins->execute([
            ':sid'   => $slipId,
            ':uid'   => $userId,
            ':mid'   => $m['id'],
            ':oid'   => $outId,
            ':bonus' => $isBonus,
            ':sh'    => $sharesAcquired,
            ':st'    => $amount,
            ':odds'  => $oddsAtEntry,
            ':pw'    => $possibleWin,
            ':pb'    => $probBefore,
            ':pa'    => $probAfter,
            ':exp'   => $expiresAt,
        ]);
        $betId = (int)$pdo->lastInsertId();

        // Refetch outcomes for snapshot
        $newRows = fetch_market_outcomes((int)$m['id']);
        $newTotalWagered = (float)$m['total_wagered'] + $amount;

        $pdo->commit();

        // Post-commit
        record_market_snapshot((int)$m['id'], $newRows, $b, $newTotalWagered);
        record_balance_tx($userId, 'bet_placed', -$amount, $balBefore, $balAfter, $slipId, (int)$m['id'], 'Bet on ' . $selected['name']);

        if ($amount >= LARGE_BET_THRESHOLD) {
            create_notification('large_bet', "Large bet of KES " . number_format($amount, 2) . " placed on market {$m['market_id']}.", (int)$m['id']);
        }
        if ($cap > 0 && $newTotalWagered >= $cap * WAGER_CAP_WARNING_RATIO) {
            create_notification('wager_cap_near', "Market {$m['market_id']} is within 5% of wager cap.", (int)$m['id']);
        }

        ok([
            'slip_id'       => $slipId,
            'bet_id'        => $betId,
            'market_id'     => $m['market_id'],
            'outcome_id'    => $outId,
            'outcome_name'  => $selected['name'],
            'shares'        => round($sharesAcquired, 6),
            'stake'         => $amount,
            'odds_at_entry' => $oddsAtEntry,
            'possible_win'  => $possibleWin,
            'prob_before'   => round($probBefore, 6),
            'prob_after'    => round($probAfter, 6),
            'is_bonus_bet'  => (bool)$isBonus,
            'expires_at'    => $expiresAt,
            'balance_after' => round($balAfter, 2),
        ], 'Bet placed successfully', 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'bet');
    }
}

// ============================================================
// ADMIN MARKET LIFECYCLE
// ============================================================

function handle_admin_create_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['question', 'category', 'market_type', 'odds_mode', 'outcomes']);
    $question = validate_text($body['question'], 'question', 5, 500);
    $category = validate_text($body['category'], 'category', 2, 60);
    $source   = isset($body['source']) ? validate_text($body['source'], 'source', 0, 120) : '';
    $title    = isset($body['title']) ? validate_text($body['title'], 'title', 0, 200) : '';
    $imageUrl = isset($body['image_url']) ? validate_text($body['image_url'], 'image_url', 0, 500) : '';
    $marketType = validate_enum($body['market_type'], ['binary', 'categorical'], 'market_type');
    $oddsMode   = validate_enum($body['odds_mode'], ['lmsr', 'fixed'], 'odds_mode');

    if (!is_array($body['outcomes'])) fail('outcomes must be an array.', 422, ['outcomes']);
    $outcomes = $body['outcomes'];
    if ($marketType === 'binary' && count($outcomes) !== 2) fail('Binary markets require exactly 2 outcomes.', 422, ['outcomes']);
    if ($marketType === 'categorical' && (count($outcomes) < 2 || count($outcomes) > 20)) fail('Categorical markets require 2–20 outcomes.', 422, ['outcomes']);

    $names = [];
    foreach ($outcomes as $i => $o) {
        if (!is_array($o)) fail('Each outcome must be an object.', 422, ['outcomes']);
        if (empty($o['name']) || !is_string($o['name'])) fail("Outcome #{$i}: name required.", 422, ['outcomes']);
        $n = validate_text($o['name'], "outcomes[{$i}].name", 1, 100);
        if (in_array($n, $names, true)) fail("Duplicate outcome name: {$n}", 422, ['outcomes']);
        $names[] = $n;
        if (!isset($o['odds'])) fail("Outcome #{$i}: odds required.", 422, ['outcomes']);
        $odds = strict_positive_amount($o['odds'], "outcomes[{$i}].odds", 1.01, 1000.0);
    }

    $b = isset($body['b']) ? strict_positive_amount($body['b'], 'b', (float)LMSR_B_MIN, (float)LMSR_B_MAX) : (float)LMSR_B;
    $maxOdds = $oddsMode === 'lmsr'
        ? (isset($body['max_odds']) ? strict_positive_amount($body['max_odds'], 'max_odds', 1.5, 1000.0) : LMSR_MAX_ODDS)
        : null;
    $minStake = isset($body['min_stake']) ? strict_positive_amount($body['min_stake'], 'min_stake', 1.0, 100000.0) : 10.0;
    $maxStake = isset($body['max_stake']) ? strict_positive_amount($body['max_stake'], 'max_stake', $minStake, 10000000.0) : 100000.0;
    $maxTotalWagered = isset($body['max_total_wagered'])
        ? strict_non_negative_amount($body['max_total_wagered'], 'max_total_wagered', 1000000000.0)
        : 0.0;
    $closeTime = isset($body['close_time']) ? validate_datetime($body['close_time'], 'close_time') : null;
    if ($closeTime !== null && strtotime($closeTime) <= time()) {
        fail('close_time must be in the future.', 422, ['close_time']);
    }
    $marketIdStr = isset($body['market_id']) ? validate_market_id($body['market_id']) : gen_market_id();

    // Check uniqueness
    $check = db()->prepare("SELECT id FROM markets WHERE market_id = :mid LIMIT 1");
    $check->execute([':mid' => $marketIdStr]);
    if ($check->fetch()) fail('Market ID already exists.', 409);

    // Validate odds rules
    $implied = 0.0;
    foreach ($outcomes as $o) {
        $implied += 1.0 / (float)$o['odds'];
    }
    $warning = null;
    if ($oddsMode === 'fixed') {
        if ($implied < FIXED_MIN_OVERROUND) {
            fail("Fixed market overround too low. Implied probability sum = " . round($implied, 4) . ". Must be >= " . FIXED_MIN_OVERROUND . ". Adjust odds to ensure the house has a margin.", 422, ['outcomes']);
        }
    } else {
        if (abs($implied - 1.0) > 0.01) {
            $warning = "Provided odds imply probability sum of " . round($implied, 4) . "; LMSR will normalise to 1.0. Opening odds will differ from requested.";
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO markets
                (market_id, title, image_url, source, question, category, market_type, odds_mode, status,
                 close_time, b, min_stake, max_stake, max_total_wagered, max_odds,
                 total_bets, total_wagered, is_archived, created_by, created_at, updated_at)
            VALUES
                (:mid, :title, :img, :src, :q, :cat, :mt, :om, 'open',
                 :ct, :b, :mins, :maxs, :mtw, :modds,
                 0, 0, 0, :cb, NOW(), NOW())
        ");
        $ins->execute([
            ':mid'  => $marketIdStr,
            ':title'=> $title,
            ':img'  => $imageUrl,
            ':src'  => $source,
            ':q'    => $question,
            ':cat'  => $category,
            ':mt'   => $marketType,
            ':om'   => $oddsMode,
            ':ct'   => $closeTime,
            ':b'    => $b,
            ':mins' => $minStake,
            ':maxs' => $maxStake,
            ':mtw'  => $maxTotalWagered,
            ':modds'=> $maxOdds,
            ':cb'   => $admin['user_id'],
        ]);
        $marketDbId = (int)$pdo->lastInsertId();

        // Outcomes
        if ($oddsMode === 'lmsr') {
            // Normalize probs
            $probs = normalize_probs($outcomes);
            $shares = seed_shares($probs, $b);
            $oIns = $pdo->prepare("INSERT INTO outcomes (market_id, name, shares, fixed_odds, created_at) VALUES (:mid, :n, :s, NULL, NOW())");
            foreach ($outcomes as $i => $o) {
                $oIns->execute([':mid' => $marketDbId, ':n' => $o['name'], ':s' => $shares[$i]]);
            }
        } else {
            $oIns = $pdo->prepare("INSERT INTO outcomes (market_id, name, shares, fixed_odds, created_at) VALUES (:mid, :n, 0, :fo, NOW())");
            foreach ($outcomes as $o) {
                $oIns->execute([':mid' => $marketDbId, ':n' => $o['name'], ':fo' => (float)$o['odds']]);
            }
        }

        $pdo->commit();

        $rows = fetch_market_outcomes($marketDbId);
        record_market_snapshot($marketDbId, $rows, $b, 0.0);
        audit_log('create_market', (int)$admin['user_id'], 'market', $marketDbId, [
            'market_id' => $marketIdStr, 'question' => $question, 'odds_mode' => $oddsMode, 'market_type' => $marketType,
        ]);

        $snap = market_snapshot($rows, $b, $oddsMode);
        ok([
            'market_id'   => $marketIdStr,
            'status'      => 'open',
            'odds_mode'   => $oddsMode,
            'market_type' => $marketType,
            'outcomes'    => $snap,
            'warning'     => $warning,
        ], 'Market created', 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'admin_create_market');
    }
}

function _admin_market_lock(PDO $pdo, string $marketIdStr): array {
    $st = $pdo->prepare("SELECT * FROM markets WHERE market_id = :mid FOR UPDATE");
    $st->execute([':mid' => $marketIdStr]);
    $m = $st->fetch();
    if (!$m) { $pdo->rollBack(); fail('Market not found.', 404); }
    return $m;
}

function handle_admin_pause_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $reason = isset($body['pause_reason']) ? validate_text($body['pause_reason'], 'pause_reason', 0, 500) : '';
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if ($m['status'] !== 'open') { $pdo->rollBack(); fail('Only open markets can be paused.', 409); }
        $upd = $pdo->prepare("UPDATE markets SET status='paused', pause_reason=:r, updated_at=NOW() WHERE id=:id");
        $upd->execute([':r' => $reason, ':id' => $m['id']]);
        $pdo->commit();
        audit_log('pause_market', (int)$admin['user_id'], 'market', (int)$m['id'], ['reason' => $reason]);
        ok(['market_id' => $mid, 'status' => 'paused'], 'Market paused');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'pause_market');
    }
}

function handle_admin_resume_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if ($m['status'] !== 'paused') { $pdo->rollBack(); fail('Only paused markets can be resumed.', 409); }
        $upd = $pdo->prepare("UPDATE markets SET status='open', pause_reason=NULL, updated_at=NOW() WHERE id=:id");
        $upd->execute([':id' => $m['id']]);
        $pdo->commit();
        audit_log('resume_market', (int)$admin['user_id'], 'market', (int)$m['id'], []);
        ok(['market_id' => $mid, 'status' => 'open'], 'Market resumed');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'resume_market');
    }
}

function handle_admin_force_close_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $reason = isset($body['reason']) ? validate_text($body['reason'], 'reason', 0, 500) : '';
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be force-closed.', 409); }
        if ($m['status'] === 'closed') { $pdo->rollBack(); fail('Market is already closed.', 409); }
        $upd = $pdo->prepare("UPDATE markets SET status='closed', updated_at=NOW() WHERE id=:id");
        $upd->execute([':id' => $m['id']]);
        $pdo->commit();
        audit_log('force_close_market', (int)$admin['user_id'], 'market', (int)$m['id'], ['reason' => $reason]);
        ok(['market_id' => $mid, 'status' => 'closed'], 'Market force-closed');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'force_close_market');
    }
}

function handle_admin_reopen_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if ($m['status'] !== 'closed') { $pdo->rollBack(); fail('Only closed markets can be reopened.', 409); }
        $clearCT = !empty($m['close_time']) && strtotime($m['close_time']) <= time();
        if ($clearCT) {
            $upd = $pdo->prepare("UPDATE markets SET status='open', close_time=NULL, updated_at=NOW() WHERE id=:id");
            $upd->execute([':id' => $m['id']]);
        } else {
            $upd = $pdo->prepare("UPDATE markets SET status='open', updated_at=NOW() WHERE id=:id");
            $upd->execute([':id' => $m['id']]);
        }
        $pdo->commit();
        audit_log('reopen_market', (int)$admin['user_id'], 'market', (int)$m['id'], ['close_time_cleared' => $clearCT]);
        ok(['market_id' => $mid, 'status' => 'open', 'close_time_cleared' => $clearCT], 'Market reopened');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'reopen_market');
    }
}

function handle_admin_settle_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'winning_outcome_id']);
    $mid    = validate_market_id($body['market_id']);
    $winOid = strict_positive_int($body['winning_outcome_id'], 'winning_outcome_id');
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Market is already in terminal state.', 409); }

        // Validate winning outcome
        $owin = $pdo->prepare("SELECT id, name FROM outcomes WHERE id = :oid AND market_id = :mid LIMIT 1");
        $owin->execute([':oid' => $winOid, ':mid' => $m['id']]);
        $winRow = $owin->fetch();
        if (!$winRow) { $pdo->rollBack(); fail('Winning outcome does not belong to this market.', 422); }

        // Winners: open bets on winning outcome
        $wstmt = $pdo->prepare("SELECT id, user_id, stake, possible_win, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' AND outcome_id = :oid FOR UPDATE");
        $wstmt->execute([':mid' => $m['id'], ':oid' => $winOid]);
        $winners = $wstmt->fetchAll();

        // Losers: open bets on other outcomes
        $lstmt = $pdo->prepare("SELECT id, user_id, stake, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' AND outcome_id != :oid FOR UPDATE");
        $lstmt->execute([':mid' => $m['id'], ':oid' => $winOid]);
        $losers = $lstmt->fetchAll();

        // Batch winners
        if (!empty($winners)) {
            $winIds = array_map(fn($r) => (int)$r['id'], $winners);
            $ph = implode(',', array_fill(0, count($winIds), '?'));
            $upd = $pdo->prepare("UPDATE bets SET status='won', payout=possible_win WHERE id IN ({$ph})");
            $upd->execute($winIds);

            // Group payouts by user_id (real vs bonus)
            $realPayouts = []; $bonusPayouts = []; $realUnlock = []; $bonusUnlock = [];
            $realWinAdd = [];
            foreach ($winners as $w) {
                $uid = (int)$w['user_id'];
                $payout = (float)$w['possible_win'];
                $stake = (float)$w['stake'];
                if ((int)$w['is_bonus_bet'] === 1) {
                    $bonusPayouts[$uid] = ($bonusPayouts[$uid] ?? 0.0) + $payout;
                    $bonusUnlock[$uid]  = ($bonusUnlock[$uid] ?? 0.0) + $stake;
                } else {
                    $realPayouts[$uid] = ($realPayouts[$uid] ?? 0.0) + $payout;
                    $realUnlock[$uid]  = ($realUnlock[$uid] ?? 0.0) + $stake;
                    $realWinAdd[$uid]  = ($realWinAdd[$uid] ?? 0.0) + $payout;
                }
            }

            $uReal = $pdo->prepare("UPDATE users SET balance = balance + :add, locked_balance = locked_balance - :unl, total_wins = total_wins + :win, updated_at=NOW() WHERE id = :uid");
            foreach ($realPayouts as $uid => $payout) {
                $uReal->execute([':add' => $payout, ':unl' => $realUnlock[$uid], ':win' => $realWinAdd[$uid], ':uid' => $uid]);
            }
            $uBonus = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :add, locked_balance = locked_balance - :unl, updated_at=NOW() WHERE id = :uid");
            foreach ($bonusPayouts as $uid => $payout) {
                $uBonus->execute([':add' => $payout, ':unl' => $bonusUnlock[$uid], ':uid' => $uid]);
            }
        }

        // Batch losers
        if (!empty($losers)) {
            $loseIds = array_map(fn($r) => (int)$r['id'], $losers);
            $ph = implode(',', array_fill(0, count($loseIds), '?'));
            $upd = $pdo->prepare("UPDATE bets SET status='lost', payout=0 WHERE id IN ({$ph})");
            $upd->execute($loseIds);

            $unlockReal = []; $unlockBonus = [];
            foreach ($losers as $l) {
                $uid = (int)$l['user_id'];
                $stake = (float)$l['stake'];
                if ((int)$l['is_bonus_bet'] === 1) {
                    $unlockBonus[$uid] = ($unlockBonus[$uid] ?? 0.0) + $stake;
                } else {
                    $unlockReal[$uid] = ($unlockReal[$uid] ?? 0.0) + $stake;
                }
            }
            $uUnlock = $pdo->prepare("UPDATE users SET locked_balance = locked_balance - :unl, updated_at=NOW() WHERE id = :uid");
            foreach ($unlockReal as $uid => $unl) $uUnlock->execute([':unl' => $unl, ':uid' => $uid]);
            foreach ($unlockBonus as $uid => $unl) $uUnlock->execute([':unl' => $unl, ':uid' => $uid]);
        }

        $mUpd = $pdo->prepare("UPDATE markets SET status='resolved', resolve_time=NOW(), updated_at=NOW() WHERE id=:id");
        $mUpd->execute([':id' => $m['id']]);

        $pdo->commit();

        // Post-commit: balance_transactions for each affected user
        if (!empty($winners)) {
            foreach ($winners as $w) {
                record_balance_tx((int)$w['user_id'], 'bet_won', (float)$w['possible_win'], 0.0, 0.0, null, (int)$m['id'], 'Won bet on ' . $winRow['name']);
            }
        }
        if (!empty($losers)) {
            foreach ($losers as $l) {
                record_balance_tx((int)$l['user_id'], 'bet_lost', 0.0, 0.0, 0.0, null, (int)$m['id'], 'Lost bet (market settled)');
            }
        }
        audit_log('settle_market', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'winning_outcome_id' => $winOid,
            'winning_outcome_name' => $winRow['name'],
            'winners_count' => count($winners),
            'losers_count'  => count($losers),
        ]);

        ok([
            'market_id'        => $mid,
            'status'           => 'resolved',
            'winning_outcome'  => ['outcome_id' => $winOid, 'name' => $winRow['name']],
            'winners_count'    => count($winners),
            'losers_count'     => count($losers),
        ], 'Market settled');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'settle_market');
    }
}

function handle_admin_void_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'reason']);
    $mid = validate_market_id($body['market_id']);
    $reason = validate_text($body['reason'], 'reason', 5, 500);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Market is already in terminal state.', 409); }

        $bstmt = $pdo->prepare("SELECT id, user_id, outcome_id, stake, shares, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' FOR UPDATE");
        $bstmt->execute([':mid' => $m['id']]);
        $bets = $bstmt->fetchAll();

        if (!empty($bets)) {
            $betIds = array_map(fn($r) => (int)$r['id'], $bets);
            $ph = implode(',', array_fill(0, count($betIds), '?'));
            $upd = $pdo->prepare("UPDATE bets SET status='void', payout=stake, void_reason=? WHERE id IN ({$ph})");
            $upd->execute(array_merge([$reason], $betIds));

            $refundReal = []; $refundBonus = [];
            $sharesPerOutcome = [];
            $stakeReversal = 0.0;
            foreach ($bets as $b) {
                $uid = (int)$b['user_id'];
                $stake = (float)$b['stake'];
                if ((int)$b['is_bonus_bet'] === 1) {
                    $refundBonus[$uid] = ($refundBonus[$uid] ?? 0.0) + $stake;
                } else {
                    $refundReal[$uid] = ($refundReal[$uid] ?? 0.0) + $stake;
                }
                $oid = (int)$b['outcome_id'];
                $sharesPerOutcome[$oid] = ($sharesPerOutcome[$oid] ?? 0.0) + (float)$b['shares'];
                $stakeReversal += $stake;
            }
            $uReal = $pdo->prepare("UPDATE users SET balance = balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
            foreach ($refundReal as $uid => $amt) $uReal->execute([':amt' => $amt, ':uid' => $uid]);
            $uBonus = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
            foreach ($refundBonus as $uid => $amt) $uBonus->execute([':amt' => $amt, ':uid' => $uid]);

            if ($m['odds_mode'] === 'lmsr') {
                $oUpd = $pdo->prepare("UPDATE outcomes SET shares = GREATEST(0, shares - :d) WHERE id = :oid");
                foreach ($sharesPerOutcome as $oid => $delta) {
                    $oUpd->execute([':d' => $delta, ':oid' => $oid]);
                }
            }

            $mUpd = $pdo->prepare("UPDATE markets SET status='voided', void_reason=:r, total_wagered = GREATEST(0, total_wagered - :s), total_bets = GREATEST(0, total_bets - :c), updated_at=NOW() WHERE id=:id");
            $mUpd->execute([':r' => $reason, ':s' => $stakeReversal, ':c' => count($bets), ':id' => $m['id']]);
        } else {
            $mUpd = $pdo->prepare("UPDATE markets SET status='voided', void_reason=:r, updated_at=NOW() WHERE id=:id");
            $mUpd->execute([':r' => $reason, ':id' => $m['id']]);
        }

        $pdo->commit();

        foreach ($bets as $b) {
            record_balance_tx((int)$b['user_id'], 'bet_voided', (float)$b['stake'], 0.0, 0.0, null, (int)$m['id'], 'Market voided: ' . $reason);
        }
        audit_log('void_market', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'reason' => $reason, 'bets_voided' => count($bets),
        ]);

        ok([
            'market_id'   => $mid,
            'status'      => 'voided',
            'bets_voided' => count($bets),
            'reason'      => $reason,
        ], 'Market voided. All open bets refunded.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'void_market');
    }
}

function handle_admin_void_bets_by_time(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'cutoff_time', 'reason']);
    $mid    = validate_market_id($body['market_id']);
    $cutoff = validate_datetime($body['cutoff_time'], 'cutoff_time');
    if (strtotime($cutoff) >= time()) fail('cutoff_time must be in the past.', 422, ['cutoff_time']);
    $reason = validate_text($body['reason'], 'reason', 10, 500);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if ($m['status'] === 'voided') { $pdo->rollBack(); fail('Market is already voided.', 409); }

        $bstmt = $pdo->prepare("SELECT id, user_id, outcome_id, stake, shares, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' AND created_at > :cut FOR UPDATE");
        $bstmt->execute([':mid' => $m['id'], ':cut' => $cutoff]);
        $bets = $bstmt->fetchAll();

        if (empty($bets)) {
            $pdo->rollBack();
            fail('No open bets found after the specified cutoff time.', 404);
        }

        $betIds = array_map(fn($r) => (int)$r['id'], $bets);
        $ph = implode(',', array_fill(0, count($betIds), '?'));
        $upd = $pdo->prepare("UPDATE bets SET status='void', payout=stake, void_reason=? WHERE id IN ({$ph})");
        $upd->execute(array_merge([$reason], $betIds));

        $refundReal = []; $refundBonus = [];
        $sharesPerOutcome = [];
        $totalRefund = 0.0;
        $affectedUsers = [];
        foreach ($bets as $b) {
            $uid = (int)$b['user_id'];
            $stake = (float)$b['stake'];
            $affectedUsers[$uid] = true;
            if ((int)$b['is_bonus_bet'] === 1) {
                $refundBonus[$uid] = ($refundBonus[$uid] ?? 0.0) + $stake;
            } else {
                $refundReal[$uid] = ($refundReal[$uid] ?? 0.0) + $stake;
            }
            $oid = (int)$b['outcome_id'];
            $sharesPerOutcome[$oid] = ($sharesPerOutcome[$oid] ?? 0.0) + (float)$b['shares'];
            $totalRefund += $stake;
        }
        $uReal = $pdo->prepare("UPDATE users SET balance = balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
        foreach ($refundReal as $uid => $amt) $uReal->execute([':amt' => $amt, ':uid' => $uid]);
        $uBonus = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
        foreach ($refundBonus as $uid => $amt) $uBonus->execute([':amt' => $amt, ':uid' => $uid]);

        if ($m['odds_mode'] === 'lmsr') {
            $oUpd = $pdo->prepare("UPDATE outcomes SET shares = GREATEST(0, shares - :d) WHERE id = :oid");
            foreach ($sharesPerOutcome as $oid => $delta) {
                $oUpd->execute([':d' => $delta, ':oid' => $oid]);
            }
        }
        $mUpd = $pdo->prepare("UPDATE markets SET total_wagered = GREATEST(0, total_wagered - :s), total_bets = GREATEST(0, total_bets - :c), updated_at=NOW() WHERE id = :id");
        $mUpd->execute([':s' => $totalRefund, ':c' => count($bets), ':id' => $m['id']]);

        $pdo->commit();

        foreach ($bets as $b) {
            record_balance_tx((int)$b['user_id'], 'bet_voided', (float)$b['stake'], 0.0, 0.0, null, (int)$m['id'], 'Voided by time: ' . $reason);
        }
        audit_log('void_bets_by_time', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'cutoff_time' => $cutoff,
            'reason' => $reason,
            'count' => count($bets),
            'total_refunded' => round($totalRefund, 2),
            'affected_users' => count($affectedUsers),
        ]);

        ok([
            'market_id'      => $mid,
            'cutoff_time'    => $cutoff,
            'reason'         => $reason,
            'bets_voided'    => count($bets),
            'total_refunded' => round($totalRefund, 2),
            'affected_users' => count($affectedUsers),
        ], 'Bets voided successfully');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'void_bets_by_time');
    }
}

function handle_admin_void_bet(array $body): void {
    $admin = require_admin();
    require_fields($body, ['slip_id', 'reason']);
    $slipId = is_string($body['slip_id']) ? trim($body['slip_id']) : '';
    if (!preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $slipId)) fail('Invalid slip_id.', 422, ['slip_id']);
    $reason = validate_text($body['reason'], 'reason', 5, 500);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $bst = $pdo->prepare("SELECT b.*, m.odds_mode, m.market_id AS mpid FROM bets b JOIN markets m ON m.id = b.market_id WHERE b.slip_id = :sid FOR UPDATE");
        $bst->execute([':sid' => $slipId]);
        $bet = $bst->fetch();
        if (!$bet) { $pdo->rollBack(); fail('Bet not found.', 404); }
        if ($bet['status'] !== 'open') { $pdo->rollBack(); fail('Only open bets can be voided.', 409); }

        $stake = (float)$bet['stake'];
        $upd = $pdo->prepare("UPDATE bets SET status='void', payout=stake, void_reason=:r WHERE id=:id");
        $upd->execute([':r' => $reason, ':id' => $bet['id']]);

        if ((int)$bet['is_bonus_bet'] === 1) {
            $uupd = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
        } else {
            $uupd = $pdo->prepare("UPDATE users SET balance = balance + :amt, locked_balance = locked_balance - :amt, total_wagered = GREATEST(0, total_wagered - :amt), updated_at=NOW() WHERE id = :uid");
        }
        $uupd->execute([':amt' => $stake, ':uid' => $bet['user_id']]);

        if ($bet['odds_mode'] === 'lmsr') {
            $oUpd = $pdo->prepare("UPDATE outcomes SET shares = GREATEST(0, shares - :d) WHERE id = :oid");
            $oUpd->execute([':d' => (float)$bet['shares'], ':oid' => $bet['outcome_id']]);
        }
        $mUpd = $pdo->prepare("UPDATE markets SET total_wagered = GREATEST(0, total_wagered - :s), total_bets = GREATEST(0, total_bets - 1), updated_at=NOW() WHERE id = :id");
        $mUpd->execute([':s' => $stake, ':id' => $bet['market_id']]);

        $pdo->commit();

        record_balance_tx((int)$bet['user_id'], 'bet_voided', $stake, 0.0, 0.0, $slipId, (int)$bet['market_id'], 'Bet voided: ' . $reason);
        audit_log('void_bet', (int)$admin['user_id'], 'bet', (int)$bet['id'], [
            'slip_id' => $slipId, 'reason' => $reason, 'stake' => $stake,
        ]);

        ok([
            'slip_id' => $slipId,
            'status'  => 'void',
            'refunded' => $stake,
            'reason'  => $reason,
        ], 'Bet voided');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'void_bet');
    }
}

function handle_admin_archive_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $archive = array_key_exists('archive', $body) ? (bool)$body['archive'] : true;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (!in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Only resolved or voided markets can be archived.', 409); }
        $val = $archive ? 1 : 0;
        $upd = $pdo->prepare("UPDATE markets SET is_archived = :v, updated_at=NOW() WHERE id=:id");
        $upd->execute([':v' => $val, ':id' => $m['id']]);
        $pdo->commit();
        audit_log('archive_market', (int)$admin['user_id'], 'market', (int)$m['id'], ['archived' => (bool)$val]);
        ok(['market_id' => $mid, 'is_archived' => (bool)$val], $val ? 'Market archived' : 'Market unarchived');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'archive_market');
    }
}

function handle_admin_edit_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be edited.', 409); }

        $sets = [];
        $params = [':id' => $m['id']];
        $changes = [];
        if (isset($body['question'])) {
            $q = validate_text($body['question'], 'question', 5, 500);
            $sets[] = 'question = :q'; $params[':q'] = $q; $changes['question'] = $q;
        }
        if (isset($body['category'])) {
            $c = validate_text($body['category'], 'category', 2, 60);
            $sets[] = 'category = :c'; $params[':c'] = $c; $changes['category'] = $c;
        }
        if (isset($body['source'])) {
            $s = validate_text($body['source'], 'source', 0, 120);
            $sets[] = 'source = :s'; $params[':s'] = $s; $changes['source'] = $s;
        }
        if (isset($body['title'])) {
            $t = validate_text($body['title'], 'title', 0, 200);
            $sets[] = 'title = :t'; $params[':t'] = $t; $changes['title'] = $t;
        }
        if (isset($body['image_url'])) {
            $iu = validate_text($body['image_url'], 'image_url', 0, 500);
            $sets[] = 'image_url = :iu'; $params[':iu'] = $iu; $changes['image_url'] = $iu;
        }
        if (empty($sets)) { $pdo->rollBack(); fail('No editable fields provided.', 422); }
        $sets[] = 'updated_at = NOW()';
        $upd = $pdo->prepare("UPDATE markets SET " . implode(', ', $sets) . " WHERE id = :id");
        $upd->execute($params);
        $pdo->commit();
        audit_log('edit_market', (int)$admin['user_id'], 'market', (int)$m['id'], $changes);
        ok(['market_id' => $mid, 'changes' => $changes], 'Market updated');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'edit_market');
    }
}

// ============================================================
// ADMIN ODDS & PRICING
// ============================================================

function handle_admin_adjust_limits(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be modified.', 409); }

        $sets = [];
        $params = [':id' => $m['id']];
        $changes = [];

        $minStake = (float)$m['min_stake'];
        $maxStake = (float)$m['max_stake'];
        if (isset($body['min_stake'])) {
            $minStake = strict_positive_amount($body['min_stake'], 'min_stake', 1.0, 100000.0);
            $sets[] = 'min_stake = :mins'; $params[':mins'] = $minStake; $changes['min_stake'] = $minStake;
        }
        if (isset($body['max_stake'])) {
            $maxStake = strict_positive_amount($body['max_stake'], 'max_stake', $minStake, 10000000.0);
            $sets[] = 'max_stake = :maxs'; $params[':maxs'] = $maxStake; $changes['max_stake'] = $maxStake;
        }
        if ($maxStake < $minStake) { $pdo->rollBack(); fail('max_stake must be >= min_stake.', 422); }
        if (isset($body['max_total_wagered'])) {
            $mtw = strict_non_negative_amount($body['max_total_wagered'], 'max_total_wagered', 1000000000.0);
            $sets[] = 'max_total_wagered = :mtw'; $params[':mtw'] = $mtw; $changes['max_total_wagered'] = $mtw;
        }
        if (isset($body['max_odds'])) {
            if ($m['odds_mode'] !== 'lmsr') { $pdo->rollBack(); fail('max_odds is LMSR-only.', 422); }
            $mo = strict_positive_amount($body['max_odds'], 'max_odds', 1.5, 1000.0);
            $sets[] = 'max_odds = :mo'; $params[':mo'] = $mo; $changes['max_odds'] = $mo;
        }
        if (empty($sets)) { $pdo->rollBack(); fail('No limits provided.', 422); }
        $sets[] = 'updated_at = NOW()';
        $upd = $pdo->prepare("UPDATE markets SET " . implode(', ', $sets) . " WHERE id = :id");
        $upd->execute($params);

        $pdo->commit();
        audit_log('adjust_limits', (int)$admin['user_id'], 'market', (int)$m['id'], $changes);
        ok(['market_id' => $mid, 'changes' => $changes], 'Limits updated');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'adjust_limits');
    }
}

function handle_admin_extend_close_time(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'close_time']);
    $mid = validate_market_id($body['market_id']);
    $newCT = validate_datetime($body['close_time'], 'close_time');
    if (strtotime($newCT) <= time()) fail('close_time must be in the future.', 422, ['close_time']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be modified.', 409); }
        if (!empty($m['close_time']) && strtotime($newCT) <= strtotime($m['close_time'])) {
            $pdo->rollBack();
            fail('New close_time must be after current close_time.', 422, ['close_time']);
        }
        $upd = $pdo->prepare("UPDATE markets SET close_time = :ct, updated_at = NOW() WHERE id = :id");
        $upd->execute([':ct' => $newCT, ':id' => $m['id']]);
        $pdo->commit();
        audit_log('extend_close_time', (int)$admin['user_id'], 'market', (int)$m['id'], ['from' => $m['close_time'], 'to' => $newCT]);
        ok(['market_id' => $mid, 'close_time' => $newCT], 'Close time extended');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'extend_close_time');
    }
}

function handle_admin_set_liquidity(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'b']);
    $mid = validate_market_id($body['market_id']);
    $newB = strict_positive_amount($body['b'], 'b', (float)LMSR_B_MIN, (float)LMSR_B_MAX);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if ($m['odds_mode'] !== 'lmsr') { $pdo->rollBack(); fail('Only LMSR markets have a liquidity parameter.', 422); }
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be modified.', 409); }
        $oldB = (float)$m['b'];
        $upd = $pdo->prepare("UPDATE markets SET b = :b, updated_at = NOW() WHERE id = :id");
        $upd->execute([':b' => $newB, ':id' => $m['id']]);
        $rows = fetch_market_outcomes((int)$m['id']);
        $pdo->commit();
        record_market_snapshot((int)$m['id'], $rows, $newB, (float)$m['total_wagered']);
        audit_log('set_liquidity', (int)$admin['user_id'], 'market', (int)$m['id'], ['from' => $oldB, 'to' => $newB]);
        $warning = $oldB !== $newB ? 'Liquidity change will cause an immediate price shift. Existing bets are unaffected (locked at entry odds).' : null;
        ok(['market_id' => $mid, 'b' => $newB, 'warning' => $warning], 'Liquidity updated');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'set_liquidity');
    }
}

function handle_admin_reseed_odds(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'outcomes']);
    $mid = validate_market_id($body['market_id']);
    if (!is_array($body['outcomes'])) fail('outcomes must be an array.', 422, ['outcomes']);
    $force = !empty($body['force']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be modified.', 409); }

        $openBets = $pdo->prepare("SELECT COUNT(*) c FROM bets WHERE market_id = :mid AND status = 'open'");
        $openBets->execute([':mid' => $m['id']]);
        $openCount = (int)$openBets->fetch()['c'];
        if ($openCount > 0 && !$force) {
            $pdo->rollBack();
            fail("Cannot reseed: {$openCount} open bets exist. Pass force=true to override.", 409);
        }

        $oRows = $pdo->prepare("SELECT id, name FROM outcomes WHERE market_id = :mid ORDER BY id ASC FOR UPDATE");
        $oRows->execute([':mid' => $m['id']]);
        $existing = $oRows->fetchAll();
        if (count($body['outcomes']) !== count($existing)) {
            $pdo->rollBack();
            fail('You must provide odds for every existing outcome (' . count($existing) . ').', 422, ['outcomes']);
        }
        $implied = 0.0;
        // Build a name→id lookup so callers can identify outcomes by either field.
        $nameToId = [];
        foreach ($existing as $ex) $nameToId[mb_strtolower($ex['name'])] = (int)$ex['id'];

        foreach ($body['outcomes'] as $i => &$o) {
            if (!is_array($o)) fail("outcomes[{$i}] must be an object.", 422, ['outcomes']);
            if (!isset($o['outcome_id']) && isset($o['name']) && is_string($o['name'])) {
                $key = mb_strtolower(trim($o['name']));
                if (!isset($nameToId[$key])) fail("outcomes[{$i}] name '{$o['name']}' not found in this market.", 422, ['outcomes']);
                $o['outcome_id'] = $nameToId[$key];
            }
            if (!isset($o['outcome_id'])) fail("outcomes[{$i}].outcome_id or name required.", 422, ['outcomes']);
            $oid = strict_positive_int($o['outcome_id'], "outcomes[{$i}].outcome_id");
            $o['outcome_id'] = $oid;
            if ((int)$existing[$i]['id'] !== $oid) {
                $found = false;
                foreach ($existing as $ex) if ((int)$ex['id'] === $oid) { $found = true; break; }
                if (!$found) fail("outcome_id {$oid} not in this market.", 422, ['outcomes']);
            }
            if (!isset($o['odds'])) fail("outcomes[{$i}].odds required.", 422, ['outcomes']);
            $odds = strict_positive_amount($o['odds'], "outcomes[{$i}].odds", 1.01, 1000.0);
            $implied += 1.0 / $odds;
        }
        unset($o);

        if ($m['odds_mode'] === 'fixed') {
            if ($implied < FIXED_MIN_OVERROUND) {
                $pdo->rollBack();
                fail("Fixed market overround too low. Implied probability sum = " . round($implied, 4) . ". Must be >= " . FIXED_MIN_OVERROUND . ".", 422, ['outcomes']);
            }
            $oUpd = $pdo->prepare("UPDATE outcomes SET fixed_odds = :fo WHERE id = :oid AND market_id = :mid");
            foreach ($body['outcomes'] as $o) {
                $oUpd->execute([':fo' => (float)$o['odds'], ':oid' => (int)$o['outcome_id'], ':mid' => $m['id']]);
            }
        } else {
            $probs = normalize_probs($body['outcomes']);
            $shares = seed_shares($probs, (float)$m['b']);
            $oUpd = $pdo->prepare("UPDATE outcomes SET shares = :s, fixed_odds = NULL WHERE id = :oid AND market_id = :mid");
            foreach ($body['outcomes'] as $i => $o) {
                $oUpd->execute([':s' => $shares[$i], ':oid' => (int)$o['outcome_id'], ':mid' => $m['id']]);
            }
        }

        $upd = $pdo->prepare("UPDATE markets SET updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $m['id']]);

        $rows = fetch_market_outcomes((int)$m['id']);
        $pdo->commit();
        record_market_snapshot((int)$m['id'], $rows, (float)$m['b'], (float)$m['total_wagered']);
        audit_log('reseed_odds', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'force' => $force, 'open_bets' => $openCount,
        ]);
        ok(['market_id' => $mid, 'outcomes' => market_snapshot($rows, (float)$m['b'], (string)$m['odds_mode'])], 'Odds reseeded');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'reseed_odds');
    }
}

function handle_admin_set_odds_mode(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id', 'odds_mode']);
    $mid = validate_market_id($body['market_id']);
    $newMode = validate_enum($body['odds_mode'], ['lmsr', 'fixed'], 'odds_mode');
    $force = !empty($body['force']);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        if (in_array($m['status'], ['resolved', 'voided'], true)) { $pdo->rollBack(); fail('Terminal markets cannot be modified.', 409); }
        if ($m['odds_mode'] === $newMode) { $pdo->rollBack(); fail('Market is already in this mode.', 409); }

        $openBets = $pdo->prepare("SELECT COUNT(*) c FROM bets WHERE market_id = :mid AND status = 'open'");
        $openBets->execute([':mid' => $m['id']]);
        $openCount = (int)$openBets->fetch()['c'];
        if ($openCount > 0 && !$force) {
            $pdo->rollBack();
            fail("Cannot change odds_mode: {$openCount} open bets exist. Pass force=true to override.", 409);
        }

        $oRows = $pdo->prepare("SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id = :mid ORDER BY id ASC FOR UPDATE");
        $oRows->execute([':mid' => $m['id']]);
        $outcomes = $oRows->fetchAll();

        if ($newMode === 'fixed') {
            // Convert LMSR probs to fixed odds (use current probs as fair odds)
            $b = (float)$m['b'];
            $shares = array_map(fn($o) => (float)$o['shares'], $outcomes);
            $probs = lmsr_probs($shares, $b);
            // Apply 5% margin to ensure overround
            $margin = 1.05;
            $oUpd = $pdo->prepare("UPDATE outcomes SET fixed_odds = :fo, shares = 0 WHERE id = :oid");
            foreach ($outcomes as $i => $o) {
                $p = $probs[$i] ?? 0.0;
                if ($p <= 0) { $pdo->rollBack(); fail('Cannot convert: outcome has zero probability.', 422); }
                $newOdds = round(1.0 / ($p * $margin), 4);
                if ($newOdds <= 1.01) $newOdds = 1.02;
                $oUpd->execute([':fo' => $newOdds, ':oid' => (int)$o['id']]);
            }
        } else {
            // Convert fixed odds to LMSR shares
            $probs = [];
            foreach ($outcomes as $o) {
                $fo = (float)$o['fixed_odds'];
                if ($fo <= 1.0) { $pdo->rollBack(); fail('Cannot convert: outcome has invalid fixed odds.', 422); }
                $probs[] = 1.0 / $fo;
            }
            $sum = array_sum($probs);
            if ($sum <= 0) { $pdo->rollBack(); fail('Cannot convert: invalid odds.', 422); }
            $norm = array_map(fn($p) => $p / $sum, $probs);
            $shares = seed_shares($norm, (float)$m['b']);
            $oUpd = $pdo->prepare("UPDATE outcomes SET shares = :s, fixed_odds = NULL WHERE id = :oid");
            foreach ($outcomes as $i => $o) {
                $oUpd->execute([':s' => $shares[$i], ':oid' => (int)$o['id']]);
            }
        }

        $mUpd = $pdo->prepare("UPDATE markets SET odds_mode = :mo, max_odds = :modds, updated_at = NOW() WHERE id = :id");
        $mUpd->execute([
            ':mo' => $newMode,
            ':modds' => $newMode === 'lmsr' ? LMSR_MAX_ODDS : null,
            ':id' => $m['id'],
        ]);

        $rows = fetch_market_outcomes((int)$m['id']);
        $pdo->commit();
        record_market_snapshot((int)$m['id'], $rows, (float)$m['b'], (float)$m['total_wagered']);
        audit_log('set_odds_mode', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'from' => $m['odds_mode'], 'to' => $newMode, 'force' => $force, 'open_bets' => $openCount,
        ]);
        ok(['market_id' => $mid, 'odds_mode' => $newMode, 'outcomes' => market_snapshot($rows, (float)$m['b'], $newMode)], 'Odds mode changed');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'set_odds_mode');
    }
}

// ============================================================
// ADMIN REPORTING
// ============================================================

function handle_admin_stats(): void {
    require_admin();
    $pdo = db();

    $users = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) AS verified,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END) AS suspended,
        SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS created_today
        FROM users")->fetch();
    $active = $pdo->query("SELECT COUNT(DISTINCT user_id) c FROM auth_tokens WHERE created_at >= CURDATE()")->fetch();

    $markets = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='open'     THEN 1 ELSE 0 END) AS open,
        SUM(CASE WHEN status='paused'   THEN 1 ELSE 0 END) AS paused,
        SUM(CASE WHEN status='closed'   THEN 1 ELSE 0 END) AS closed,
        SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN status='voided'   THEN 1 ELSE 0 END) AS voided,
        SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS created_today
        FROM markets")->fetch();

    $fin = $pdo->query("SELECT
        COUNT(*) AS total_bets,
        COALESCE(SUM(stake),0) AS total_wagered,
        COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS total_payouts,
        COALESCE(SUM(CASE WHEN status='void' THEN payout ELSE 0 END),0) AS total_refunds,
        SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS bets_today,
        COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN stake ELSE 0 END),0) AS wagered_today
        FROM bets")->fetch();
    $houseProfit = (float)$fin['total_wagered'] - (float)$fin['total_payouts'] - (float)$fin['total_refunds'];

    $topBettors = $pdo->query("SELECT u.username, COALESCE(SUM(b.stake),0) AS total_wagered,
        COALESCE(SUM(CASE WHEN b.status='won' THEN b.payout ELSE 0 END),0) AS total_wins,
        COUNT(b.id) AS bet_count
        FROM bets b JOIN users u ON u.id = b.user_id
        GROUP BY u.id, u.username
        ORDER BY total_wagered DESC LIMIT 10")->fetchAll();

    ok([
        'users' => [
            'total'         => (int)$users['total'],
            'verified'      => (int)$users['verified'],
            'admins'        => (int)$users['admins'],
            'suspended'     => (int)$users['suspended'],
            'active_today'  => (int)$active['c'],
            'created_today' => (int)$users['created_today'],
        ],
        'markets' => [
            'total'         => (int)$markets['total'],
            'open'          => (int)$markets['open'],
            'paused'        => (int)$markets['paused'],
            'closed'        => (int)$markets['closed'],
            'resolved'      => (int)$markets['resolved'],
            'voided'        => (int)$markets['voided'],
            'created_today' => (int)$markets['created_today'],
        ],
        'financials' => [
            'total_bets'    => (int)$fin['total_bets'],
            'total_wagered' => (float)$fin['total_wagered'],
            'total_payouts' => (float)$fin['total_payouts'],
            'total_refunds' => (float)$fin['total_refunds'],
            'house_profit'  => round($houseProfit, 2),
            'bets_today'    => (int)$fin['bets_today'],
            'wagered_today' => (float)$fin['wagered_today'],
        ],
        'top_bettors' => $topBettors,
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function handle_admin_market_report(): void {
    require_admin();
    $mid = validate_market_id($_GET['market_id'] ?? '');
    $stmt = db()->prepare("SELECT * FROM markets WHERE market_id = :mid LIMIT 1");
    $stmt->execute([':mid' => $mid]);
    $m = $stmt->fetch();
    if (!$m) fail('Market not found.', 404);
    $rows = fetch_market_outcomes((int)$m['id']);
    $snap = market_snapshot($rows, (float)$m['b'], (string)$m['odds_mode']);

    $betSum = db()->prepare("SELECT
        COUNT(*) AS total_bets,
        SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open_bets,
        SUM(CASE WHEN status='won'  THEN 1 ELSE 0 END) AS won_bets,
        SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) AS lost_bets,
        SUM(CASE WHEN status='void' THEN 1 ELSE 0 END) AS void_bets,
        COALESCE(SUM(stake),0) AS total_stake,
        COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS total_payout
        FROM bets WHERE market_id = :mid");
    $betSum->execute([':mid' => $m['id']]);
    $bs = $betSum->fetch();

    $volByOutcome = db()->prepare("SELECT outcome_id, COUNT(*) AS bets,
        COALESCE(SUM(stake),0) AS volume,
        COALESCE(SUM(CASE WHEN status='open' THEN possible_win ELSE 0 END),0) AS open_liability
        FROM bets WHERE market_id = :mid AND status != 'void' GROUP BY outcome_id");
    $volByOutcome->execute([':mid' => $m['id']]);
    $vols = $volByOutcome->fetchAll();
    $volMap = [];
    foreach ($vols as $v) $volMap[(int)$v['outcome_id']] = [
        'bets' => (int)$v['bets'], 'volume' => (float)$v['volume'], 'open_liability' => (float)$v['open_liability'],
    ];

    $volumeByOutcome = [];
    foreach ($snap as $s) {
        $oid = (int)$s['outcome_id'];
        $volumeByOutcome[] = [
            'outcome_id'     => $oid,
            'outcome_name'   => $s['name'],
            'bet_count'      => $volMap[$oid]['bets'] ?? 0,
            'total_staked'   => $volMap[$oid]['volume'] ?? 0.0,
            'open_liability' => $volMap[$oid]['open_liability'] ?? 0.0,
        ];
    }

    // Max liability across all outcomes (worst-case payout if any one outcome wins)
    $maxLiability = 0.0;
    foreach ($volumeByOutcome as $v) {
        if ($v['open_liability'] > $maxLiability) $maxLiability = $v['open_liability'];
    }
    $stakeAtRisk = 0.0;
    foreach ($vols as $v) { if (true) $stakeAtRisk += (float)$v['volume']; }
    $houseProfit = (float)$bs['total_stake'] - (float)$bs['total_payout'];

    $graphStmt = db()->prepare("SELECT s.outcome_id, o.name AS outcome_name,
        MIN(s.created_at) AS first_at, MAX(s.created_at) AS last_at,
        COUNT(*) AS samples,
        SUBSTRING_INDEX(GROUP_CONCAT(s.odds ORDER BY s.created_at ASC),  ',', 1) AS opening_odds,
        SUBSTRING_INDEX(GROUP_CONCAT(s.odds ORDER BY s.created_at DESC), ',', 1) AS current_odds
        FROM market_snapshots s
        JOIN outcomes o ON o.id = s.outcome_id
        WHERE s.market_id = :mid GROUP BY s.outcome_id, o.name");
    $graphStmt->execute([':mid' => $m['id']]);
    $graphRows = $graphStmt->fetchAll();
    foreach ($graphRows as &$gr) {
        $gr['opening_odds']   = (float)$gr['opening_odds'];
        $gr['current_odds']   = (float)$gr['current_odds'];
        $gr['outcome_id']     = (int)$gr['outcome_id'];
        $gr['snapshot_count'] = (int)$gr['samples'];
        unset($gr['samples']);
    }
    unset($gr);

    $auditStmt = db()->prepare("SELECT a.id, a.admin_id, u.username AS admin, a.action, a.meta, a.created_at AS at
        FROM audit_logs a LEFT JOIN users u ON u.id = a.admin_id
        WHERE a.target_type = 'market' AND a.target_id = :id ORDER BY a.created_at DESC LIMIT 50");
    $auditStmt->execute([':id' => $m['id']]);
    $audit = $auditStmt->fetchAll();
    foreach ($audit as &$a) {
        $a['meta'] = $a['meta'] ? json_decode($a['meta'], true) : null;
        $a['admin'] = $a['admin'] ?? ($a['admin_id'] == 0 ? 'system' : null);
    }
    unset($a);

    $marketBlock = [
        'market_id'         => $m['market_id'],
        'question'          => $m['question'],
        'category'          => $m['category'],
        'market_type'       => $m['market_type'],
        'odds_mode'         => $m['odds_mode'],
        'status'            => $m['status'],
        'b'                 => (float)$m['b'],
        'min_stake'         => (float)$m['min_stake'],
        'max_stake'         => (float)$m['max_stake'],
        'max_total_wagered' => (float)$m['max_total_wagered'],
        'max_odds'          => $m['max_odds'] !== null ? (float)$m['max_odds'] : null,
        'total_bets'        => (int)$m['total_bets'],
        'total_wagered'     => (float)$m['total_wagered'],
        'pause_reason'      => $m['pause_reason'],
        'void_reason'       => $m['void_reason'],
        'close_time'        => $m['close_time'],
        'resolve_time'      => $m['resolve_time'],
    ];

    ok([
        'market'      => $marketBlock,
        'live_odds'   => $snap,
        'bet_summary' => [
            'total_bets'    => (int)$bs['total_bets'],
            'open'          => (int)$bs['open_bets'],
            'won'           => (int)$bs['won_bets'],
            'lost'          => (int)$bs['lost_bets'],
            'void'          => (int)$bs['void_bets'],
            'total_staked'  => (float)$bs['total_stake'],
            'stake_at_risk' => $stakeAtRisk,
            'max_liability' => $maxLiability,
            'house_profit'  => round($houseProfit, 2),
        ],
        'volume_by_outcome' => $volumeByOutcome,
        'graph_summary'     => $graphRows,
        'audit_log'         => $audit,
    ]);
}

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

/**
 * MwasinMarket API — Social Features
 * Reactions, messages, stickers, SMS, bans, maintenance mode, notifications.
 * Assumes bootstrap.php is loaded.
 */

// ============================================================
// ROUTE DISPATCH
// ============================================================

function dispatch_social_route(string $route, array $body): void {
    switch ($route) {
        case 'react':                          handle_react($body); return;
        case 'reactions':                      handle_reactions(); return;
        case 'send_message':                   handle_send_message($body); return;
        case 'inbox':                          handle_inbox(); return;
        case 'conversation':                   handle_conversation(); return;
        case 'delete_message':                 handle_delete_message($body); return;
        case 'stickers':                       handle_stickers_list(); return;
        case 'admin_upload_sticker':           handle_admin_upload_sticker(); return;
        case 'admin_delete_sticker':           handle_admin_delete_sticker($body); return;
        case 'admin_send_sms':                 handle_admin_send_sms($body); return;
        case 'admin_sms_history':              handle_admin_sms_history(); return;
        case 'admin_ban_user':                 handle_admin_ban_user($body); return;
        case 'admin_unban_user':               handle_admin_unban_user($body); return;
        case 'admin_restrict_messaging':       handle_admin_restrict_messaging($body); return;
        case 'admin_maintenance':              handle_admin_maintenance($body); return;
        case 'admin_notifications':            handle_admin_notifications(); return;
        case 'admin_mark_notifications_read':  handle_admin_mark_notifications_read($body); return;
    }
    fail('Route not found', 404);
}

// ============================================================
// REACTIONS
// ============================================================

function handle_react(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);
    require_fields($body, ['market_id', 'reaction']);
    $mid = validate_market_id($body['market_id']);
    $reaction = is_string($body['reaction']) ? trim($body['reaction']) : '';
    if ($reaction !== 'heart') fail("Only 'heart' reactions are supported", 422, ['reaction']);

    $mst = db()->prepare("SELECT id FROM markets WHERE market_id = :mid LIMIT 1");
    $mst->execute([':mid' => $mid]);
    $m = $mst->fetch();
    if (!$m) fail('Market not found.', 404);

    $pdo = db();
    $check = $pdo->prepare("SELECT id FROM reactions WHERE user_id = :uid AND market_id = :mid AND reaction = 'heart' LIMIT 1");
    $check->execute([':uid' => $auth['user_id'], ':mid' => $m['id']]);
    $existing = $check->fetch();

    if ($existing) {
        $del = $pdo->prepare("DELETE FROM reactions WHERE id = :id");
        $del->execute([':id' => $existing['id']]);
        $active = false;
    } else {
        $ins = $pdo->prepare("INSERT INTO reactions (user_id, market_id, reaction, created_at) VALUES (:uid, :mid, 'heart', NOW())");
        $ins->execute([':uid' => $auth['user_id'], ':mid' => $m['id']]);
        $active = true;
    }

    $count = $pdo->prepare("SELECT COUNT(*) c FROM reactions WHERE market_id = :mid AND reaction = 'heart'");
    $count->execute([':mid' => $m['id']]);
    $total = (int)$count->fetch()['c'];

    ok([
        'market_id'    => $mid,
        'reaction'     => 'heart',
        'active'       => $active,
        'total_hearts' => $total,
    ]);
}

function handle_reactions(): void {
    $mid = validate_market_id($_GET['market_id'] ?? '');
    $mst = db()->prepare("SELECT id FROM markets WHERE market_id = :mid LIMIT 1");
    $mst->execute([':mid' => $mid]);
    $m = $mst->fetch();
    if (!$m) fail('Market not found.', 404);

    $count = db()->prepare("SELECT COUNT(*) c FROM reactions WHERE market_id = :mid AND reaction = 'heart'");
    $count->execute([':mid' => $m['id']]);
    $total = (int)$count->fetch()['c'];

    $userHas = null;
    $tok = read_bearer();
    if ($tok !== '') {
        $auth = resolve_token($tok);
        if ($auth) {
            $u = db()->prepare("SELECT 1 FROM reactions WHERE user_id = :uid AND market_id = :mid AND reaction = 'heart' LIMIT 1");
            $u->execute([':uid' => $auth['user_id'], ':mid' => $m['id']]);
            $userHas = (bool)$u->fetch();
        }
    }
    $resp = ['market_id' => $mid, 'total_hearts' => $total];
    if ($userHas !== null) $resp['user_has_hearted'] = $userHas;
    ok($resp);
}

// ============================================================
// MESSAGES
// ============================================================

function handle_send_message(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);

    $u = db()->prepare("SELECT messaging_restricted FROM users WHERE id = :uid");
    $u->execute([':uid' => $auth['user_id']]);
    $userRow = $u->fetch();
    if ($userRow && (int)$userRow['messaging_restricted'] === 1) {
        fail('Your messaging ability has been restricted by admin', 403);
    }

    require_fields($body, ['to_user_id', 'body']);
    $toUid = strict_positive_int($body['to_user_id'], 'to_user_id');
    if ($toUid === (int)$auth['user_id']) fail('Cannot send a message to yourself.', 422);

    $rec = db()->prepare("SELECT id, is_suspended FROM users WHERE id = :id");
    $rec->execute([':id' => $toUid]);
    $r = $rec->fetch();
    if (!$r) fail('Recipient not found.', 404);
    if ((int)$r['is_suspended'] === 1) fail('Recipient is not available.', 403);

    $msgBody = validate_text($body['body'], 'body', 1, 1000);

    $stickerId = null;
    if (isset($body['sticker_id']) && $body['sticker_id'] !== null && $body['sticker_id'] !== '') {
        $stickerId = strict_positive_int($body['sticker_id'], 'sticker_id');
        $sst = db()->prepare("SELECT id FROM stickers WHERE id = :id AND is_active = 1");
        $sst->execute([':id' => $stickerId]);
        if (!$sst->fetch()) fail('Sticker not found or inactive.', 404, ['sticker_id']);
    }

    $ins = db()->prepare("INSERT INTO messages (from_user_id, to_user_id, body, sticker_id, is_read, deleted_by_sender, deleted_by_recipient, created_at) VALUES (:f, :t, :b, :s, 0, 0, 0, NOW())");
    $ins->execute([':f' => $auth['user_id'], ':t' => $toUid, ':b' => $msgBody, ':s' => $stickerId]);
    $mid = (int)db()->lastInsertId();

    ok([
        'message_id'  => $mid,
        'to_user_id'  => $toUid,
        'preview'     => mb_substr($msgBody, 0, 60, 'UTF-8') . (mb_strlen($msgBody, 'UTF-8') > 60 ? '...' : ''),
        'sticker_id'  => $stickerId,
        'sent_at'     => gmdate('Y-m-d H:i:s'),
    ], 'Message sent', 201);
}

function handle_inbox(): void {
    $auth = require_auth();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "
        SELECT
            other_id,
            other_username,
            MAX(created_at) AS last_at,
            SUBSTRING_INDEX(GROUP_CONCAT(body ORDER BY created_at DESC SEPARATOR '<<>>'), '<<>>', 1) AS last_body,
            SUM(CASE WHEN unread_for_me = 1 THEN 1 ELSE 0 END) AS unread_count
        FROM (
            SELECT
                CASE WHEN from_user_id = :uid THEN to_user_id ELSE from_user_id END AS other_id,
                CASE WHEN from_user_id = :uid THEN tu.username ELSE fu.username END AS other_username,
                m.body,
                m.created_at,
                CASE WHEN m.to_user_id = :uid AND m.is_read = 0 THEN 1 ELSE 0 END AS unread_for_me
            FROM messages m
            JOIN users fu ON fu.id = m.from_user_id
            JOIN users tu ON tu.id = m.to_user_id
            WHERE (m.from_user_id = :uid AND m.deleted_by_sender = 0)
               OR (m.to_user_id   = :uid AND m.deleted_by_recipient = 0)
        ) AS sub
        GROUP BY other_id, other_username
        ORDER BY last_at DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':uid', $auth['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $convs = [];
    foreach ($rows as $r) {
        $body = (string)$r['last_body'];
        $convs[] = [
            'other_user_id' => (int)$r['other_id'],
            'other_username'=> $r['other_username'],
            'last_preview'  => mb_substr($body, 0, 60, 'UTF-8') . (mb_strlen($body, 'UTF-8') > 60 ? '...' : ''),
            'last_at'       => $r['last_at'],
            'unread_count'  => (int)$r['unread_count'],
        ];
    }

    $totalQ = db()->prepare("SELECT COUNT(DISTINCT CASE WHEN from_user_id = :uid THEN to_user_id ELSE from_user_id END) c
        FROM messages WHERE (from_user_id = :uid AND deleted_by_sender = 0) OR (to_user_id = :uid AND deleted_by_recipient = 0)");
    $totalQ->execute([':uid' => $auth['user_id']]);
    $total = (int)$totalQ->fetch()['c'];

    ok([
        'data' => $convs,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_conversation(): void {
    $auth = require_auth();
    $otherId = strict_positive_int($_GET['user_id'] ?? null, 'user_id');
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $mark = db()->prepare("UPDATE messages SET is_read = 1 WHERE to_user_id = :me AND from_user_id = :other AND is_read = 0");
    $mark->execute([':me' => $auth['user_id'], ':other' => $otherId]);

    $count = db()->prepare("SELECT COUNT(*) c FROM messages m
        WHERE ((m.from_user_id = :me AND m.to_user_id = :other AND m.deleted_by_sender = 0)
            OR (m.from_user_id = :other AND m.to_user_id = :me AND m.deleted_by_recipient = 0))");
    $count->execute([':me' => $auth['user_id'], ':other' => $otherId]);
    $total = (int)$count->fetch()['c'];

    $stmt = db()->prepare("
        SELECT m.id, m.from_user_id, m.to_user_id, m.body, m.sticker_id, m.is_read, m.created_at,
               u.username AS from_username,
               s.name AS sticker_name, s.filename AS sticker_filename, s.category AS sticker_category
        FROM messages m
        JOIN users u ON u.id = m.from_user_id
        LEFT JOIN stickers s ON s.id = m.sticker_id
        WHERE ((m.from_user_id = :me AND m.to_user_id = :other AND m.deleted_by_sender = 0)
            OR (m.from_user_id = :other AND m.to_user_id = :me AND m.deleted_by_recipient = 0))
        ORDER BY m.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':me', $auth['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':other', $otherId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $messages = [];
    foreach ($rows as $r) {
        $msg = [
            'message_id'    => (int)$r['id'],
            'from_user_id'  => (int)$r['from_user_id'],
            'to_user_id'    => (int)$r['to_user_id'],
            'from_username' => $r['from_username'],
            'body'          => $r['body'],
            'sticker'       => null,
            'sent_at'       => $r['created_at'],
            'is_read'       => (bool)$r['is_read'],
        ];
        if ($r['sticker_id'] !== null) {
            $msg['sticker'] = [
                'sticker_id' => (int)$r['sticker_id'],
                'name'       => $r['sticker_name'],
                'category'   => $r['sticker_category'],
                'filename'   => $r['sticker_filename'],
                'url'        => STICKER_BASE_URL !== '' ? rtrim(STICKER_BASE_URL, '/') . '/' . $r['sticker_filename'] : null,
            ];
        }
        $messages[] = $msg;
    }

    ok([
        'data' => $messages,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_delete_message(array $body): void {
    $auth = require_auth();
    require_fields($body, ['message_id']);
    $mid = strict_positive_int($body['message_id'], 'message_id');

    $pdo = db();
    $st = $pdo->prepare("SELECT id, from_user_id, to_user_id, deleted_by_sender, deleted_by_recipient FROM messages WHERE id = :id LIMIT 1");
    $st->execute([':id' => $mid]);
    $msg = $st->fetch();
    if (!$msg) fail('Message not found.', 404);

    $uid = (int)$auth['user_id'];
    if ((int)$msg['from_user_id'] !== $uid && (int)$msg['to_user_id'] !== $uid) {
        fail('You cannot delete this message.', 403);
    }

    $isSender = ((int)$msg['from_user_id'] === $uid);
    if ($isSender) {
        $upd = $pdo->prepare("UPDATE messages SET deleted_by_sender = 1 WHERE id = :id");
        $upd->execute([':id' => $mid]);
        $newSender = 1; $newRec = (int)$msg['deleted_by_recipient'];
    } else {
        $upd = $pdo->prepare("UPDATE messages SET deleted_by_recipient = 1 WHERE id = :id");
        $upd->execute([':id' => $mid]);
        $newSender = (int)$msg['deleted_by_sender']; $newRec = 1;
    }
    if ($newSender === 1 && $newRec === 1) {
        $del = $pdo->prepare("DELETE FROM messages WHERE id = :id");
        $del->execute([':id' => $mid]);
    }
    ok(['message_id' => $mid, 'deleted' => true]);
}

// ============================================================
// STICKERS
// ============================================================

function handle_stickers_list(): void {
    $stmt = db()->query("SELECT id, name, filename, mime_type, file_size, category, is_pack_default FROM stickers WHERE is_active = 1 ORDER BY category ASC, id ASC");
    $rows = $stmt->fetchAll();
    $base = STICKER_BASE_URL !== '' ? rtrim(STICKER_BASE_URL, '/') : '';
    $grouped = [];
    foreach ($rows as $r) {
        $cat = $r['category'];
        if (!isset($grouped[$cat])) $grouped[$cat] = [];
        $grouped[$cat][] = [
            'sticker_id'      => (int)$r['id'],
            'name'            => $r['name'],
            'category'        => $cat,
            'filename'        => $r['filename'],
            'mime_type'       => $r['mime_type'],
            'file_size'       => (int)$r['file_size'],
            'is_pack_default' => (bool)$r['is_pack_default'],
            'url'             => $base !== '' ? $base . '/' . $r['filename'] : null,
        ];
    }
    ok(['folders' => $grouped]);
}

function handle_admin_upload_sticker(): void {
    $admin = require_admin();

    if (!isset($_FILES['sticker'])) fail('No sticker file uploaded.', 422, ['sticker']);
    $f = $_FILES['sticker'];
    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        fail('File upload failed (error ' . (int)($f['error'] ?? -1) . ')', 422, ['sticker']);
    }
    if (!is_uploaded_file($f['tmp_name'])) fail('Invalid upload.', 422, ['sticker']);
    if ((int)$f['size'] > 512 * 1024) fail('File too large. Max 512 KB.', 422, ['sticker']);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) fail('Could not detect MIME type.', 422);
    $mime = (string)finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $allowedMime = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowedMime[$mime])) fail('Only PNG, GIF, or WebP allowed.', 422, ['sticker']);
    $ext = $allowedMime[$mime];

    $imageInfo = @getimagesize($f['tmp_name']);
    if ($imageInfo === false) fail('Could not read image dimensions.', 422);
    if ($imageInfo[0] > 512 || $imageInfo[1] > 512) fail('Image dimensions must be <= 512x512.', 422, ['sticker']);

    $name = isset($_POST['name']) ? validate_text($_POST['name'], 'name', 1, 80) : '';
    if ($name === '') fail('name required.', 422, ['name']);
    $category = isset($_POST['category']) ? validate_text($_POST['category'], 'category', 1, 40) : '';
    if ($category === '') fail('category required.', 422, ['category']);

    if (!is_dir(STICKER_UPLOAD_PATH)) {
        if (!@mkdir(STICKER_UPLOAD_PATH, 0755, true) && !is_dir(STICKER_UPLOAD_PATH)) {
            fail('Sticker storage unavailable.', 500);
        }
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = rtrim(STICKER_UPLOAD_PATH, '/') . '/' . $filename;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        fail('Could not save uploaded file.', 500);
    }

    $ins = db()->prepare("INSERT INTO stickers (name, filename, mime_type, file_size, category, is_active, is_pack_default, uploaded_by, created_at) VALUES (:n, :f, :m, :sz, :c, 1, 0, :ub, NOW())");
    $ins->execute([
        ':n' => $name, ':f' => $filename, ':m' => $mime, ':sz' => (int)$f['size'],
        ':c' => $category, ':ub' => $admin['user_id'],
    ]);
    $sid = (int)db()->lastInsertId();
    audit_log('upload_sticker', (int)$admin['user_id'], 'sticker', $sid, ['name' => $name, 'category' => $category, 'filename' => $filename]);

    ok([
        'sticker_id' => $sid,
        'name'       => $name,
        'category'   => $category,
        'filename'   => $filename,
        'size_bytes' => (int)$f['size'],
        'mime_type'  => $mime,
        'url'        => STICKER_BASE_URL !== '' ? rtrim(STICKER_BASE_URL, '/') . '/' . $filename : null,
    ], 'Sticker uploaded', 201);
}

function handle_admin_delete_sticker(array $body): void {
    $admin = require_admin();
    require_fields($body, ['sticker_id']);
    $sid = strict_positive_int($body['sticker_id'], 'sticker_id');
    $upd = db()->prepare("UPDATE stickers SET is_active = 0 WHERE id = :id");
    $upd->execute([':id' => $sid]);
    if ($upd->rowCount() === 0) fail('Sticker not found.', 404);
    audit_log('delete_sticker', (int)$admin['user_id'], 'sticker', $sid, []);
    ok(['sticker_id' => $sid, 'deleted' => true], 'Sticker deactivated');
}

// ============================================================
// SMS
// ============================================================

function handle_admin_send_sms(array $body): void {
    $admin = require_admin();
    require_fields($body, ['message']);
    $message = validate_text($body['message'], 'message', 1, 160);

    $broadcast = !empty($body['broadcast']);
    $recipients = [];
    if ($broadcast) {
        $filter = is_array($body['filter'] ?? null) ? $body['filter'] : [];
        $where = ['is_suspended = 0', "phone IS NOT NULL", "phone != ''"];
        $params = [];
        if (isset($filter['role'])) {
            $role = $filter['role'];
            if (!in_array($role, ['user', 'admin'], true)) fail('Invalid filter.role', 422);
            $where[] = 'role = :role';
            $params[':role'] = $role;
        }
        $sql = "SELECT id, phone FROM users WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT 500";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll();
    } else {
        require_fields($body, ['user_id']);
        $uid = strict_positive_int($body['user_id'], 'user_id');
        $u = db()->prepare("SELECT id, phone FROM users WHERE id = :id LIMIT 1");
        $u->execute([':id' => $uid]);
        $row = $u->fetch();
        if (!$row) fail('User not found.', 404);
        if (empty($row['phone'])) fail('User has no phone number.', 422);
        $recipients = [$row];
    }

    $queued = 0; $sent = 0; $failed = 0;
    $sample = [];
    foreach ($recipients as $r) {
        $res = send_sms_now((string)$r['phone'], $message, (int)$r['id'], (int)$admin['user_id']);
        $queued++;
        if ($res['status'] === 'sent') $sent++;
        elseif ($res['status'] === 'failed') $failed++;
        if (count($sample) < 10) $sample[] = $r['phone'];
    }
    audit_log('admin_send_sms', (int)$admin['user_id'], 'sms', null, [
        'broadcast' => $broadcast, 'recipients' => $queued, 'sent' => $sent, 'failed' => $failed,
    ]);
    ok([
        'queued'     => $queued,
        'sent'       => $sent,
        'failed'     => $failed,
        'recipients' => $sample,
    ], 'SMS dispatched');
}

function handle_admin_sms_history(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $status = $_GET['status'] ?? '';

    $where = []; $params = [];
    if ($status !== '') {
        if (!in_array($status, ['queued', 'sent', 'failed'], true)) fail('Invalid status filter.', 422);
        $where[] = 's.status = :st';
        $params[':st'] = $status;
    }
    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countQ = db()->prepare("SELECT COUNT(*) c FROM sms_log s {$whereSql}");
    $countQ->execute($params);
    $total = (int)$countQ->fetch()['c'];

    $sql = "SELECT s.id, s.user_id, s.phone, s.message, s.status, s.provider, s.provider_id, s.sent_by, s.error, s.created_at, u.username
        FROM sms_log s
        LEFT JOIN users u ON u.id = s.user_id
        {$whereSql}
        ORDER BY s.created_at DESC
        LIMIT :lim OFFSET :off";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['preview'] = mb_substr((string)$r['message'], 0, 60, 'UTF-8');
    }
    unset($r);
    ok([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

// ============================================================
// USER CONTROLS
// ============================================================

function handle_admin_ban_user(array $body): void {
    $admin = require_admin();
    require_fields($body, ['user_id', 'reason']);
    $uid = strict_positive_int($body['user_id'], 'user_id');
    $reason = validate_text($body['reason'], 'reason', 5, 500);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ust = $pdo->prepare("SELECT id, username, role, is_suspended FROM users WHERE id = :id FOR UPDATE");
        $ust->execute([':id' => $uid]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }
        if ($u['role'] === 'admin') { $pdo->rollBack(); fail('Cannot ban an admin user.', 403); }
        if ((int)$u['is_suspended'] === 1) { $pdo->rollBack(); fail('User is already suspended.', 409); }

        $upd = $pdo->prepare("UPDATE users SET is_suspended = 1, updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $uid]);

        $delTok = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :id");
        $delTok->execute([':id' => $uid]);

        $ins = $pdo->prepare("INSERT INTO user_bans (user_id, banned_by, reason, banned_at) VALUES (:u, :b, :r, NOW())");
        $ins->execute([':u' => $uid, ':b' => $admin['user_id'], ':r' => $reason]);

        $pdo->commit();
        audit_log('ban_user', (int)$admin['user_id'], 'user', $uid, ['reason' => $reason]);
        ok([
            'user_id'      => $uid,
            'username'     => $u['username'],
            'is_suspended' => true,
            'reason'       => $reason,
        ], 'User suspended');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'ban_user');
    }
}

function handle_admin_unban_user(array $body): void {
    $admin = require_admin();
    require_fields($body, ['user_id']);
    $uid = strict_positive_int($body['user_id'], 'user_id');
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 300) : '';

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ust = $pdo->prepare("SELECT id, username, is_suspended FROM users WHERE id = :id FOR UPDATE");
        $ust->execute([':id' => $uid]);
        $u = $ust->fetch();
        if (!$u) { $pdo->rollBack(); fail('User not found.', 404); }
        if ((int)$u['is_suspended'] === 0) { $pdo->rollBack(); fail('User is not suspended.', 409); }

        $upd = $pdo->prepare("UPDATE users SET is_suspended = 0, updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $uid]);

        $latest = $pdo->prepare("SELECT id FROM user_bans WHERE user_id = :u AND lifted_at IS NULL ORDER BY banned_at DESC LIMIT 1");
        $latest->execute([':u' => $uid]);
        $latestRow = $latest->fetch();
        if ($latestRow) {
            $upd2 = $pdo->prepare("UPDATE user_bans SET lifted_at = NOW(), lifted_by = :lb, lift_note = :ln WHERE id = :id");
            $upd2->execute([':lb' => $admin['user_id'], ':ln' => $note, ':id' => $latestRow['id']]);
        }

        $pdo->commit();
        audit_log('unban_user', (int)$admin['user_id'], 'user', $uid, ['note' => $note]);
        ok([
            'user_id'      => $uid,
            'username'     => $u['username'],
            'is_suspended' => false,
        ], 'User unsuspended');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'unban_user');
    }
}

function handle_admin_restrict_messaging(array $body): void {
    $admin = require_admin();
    require_fields($body, ['user_id', 'restricted']);
    $uid = strict_positive_int($body['user_id'], 'user_id');
    $restricted = $body['restricted'];
    if (is_string($restricted)) $restricted = filter_var($restricted, FILTER_VALIDATE_BOOLEAN);
    if (!is_bool($restricted) && $restricted !== 0 && $restricted !== 1) fail('restricted must be boolean.', 422, ['restricted']);
    $val = $restricted ? 1 : 0;
    $reason = isset($body['reason']) ? validate_text($body['reason'], 'reason', 0, 500) : '';

    $st = db()->prepare("SELECT id, username FROM users WHERE id = :id");
    $st->execute([':id' => $uid]);
    $u = $st->fetch();
    if (!$u) fail('User not found.', 404);

    $upd = db()->prepare("UPDATE users SET messaging_restricted = :v, updated_at = NOW() WHERE id = :id");
    $upd->execute([':v' => $val, ':id' => $uid]);

    audit_log($val ? 'restrict_messaging' : 'unrestrict_messaging', (int)$admin['user_id'], 'user', $uid, ['reason' => $reason]);
    ok([
        'user_id'              => $uid,
        'username'             => $u['username'],
        'messaging_restricted' => (bool)$val,
    ], $val ? 'Messaging restricted' : 'Messaging restriction lifted');
}

// ============================================================
// MAINTENANCE MODE
// ============================================================

function handle_admin_maintenance(array $body): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $admin = require_admin();

    if ($method === 'GET' || (empty($body) && $method !== 'POST')) {
        $row = db()->query("SELECT value, message, updated_at FROM system_settings WHERE `key`='maintenance_mode' LIMIT 1")->fetch();
        ok([
            'enabled' => $row ? ((string)$row['value'] === '1') : false,
            'message' => $row['message'] ?? null,
            'set_at'  => $row['updated_at'] ?? null,
        ]);
    }

    require_fields($body, ['enabled']);
    $enabled = $body['enabled'];
    if (is_string($enabled)) $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    if (!is_bool($enabled) && $enabled !== 0 && $enabled !== 1) fail('enabled must be boolean.', 422, ['enabled']);
    $val = $enabled ? '1' : '0';
    $message = isset($body['message']) ? validate_text($body['message'], 'message', 0, 300) : '';
    if ($message === '' && $enabled) {
        $message = 'The platform is currently under maintenance. Please check back soon.';
    }

    $exists = db()->query("SELECT id FROM system_settings WHERE `key`='maintenance_mode' LIMIT 1")->fetch();
    if ($exists) {
        $upd = db()->prepare("UPDATE system_settings SET value = :v, message = :m, updated_by = :ub, updated_at = NOW() WHERE id = :id");
        $upd->execute([':v' => $val, ':m' => $message, ':ub' => $admin['user_id'], ':id' => $exists['id']]);
    } else {
        $ins = db()->prepare("INSERT INTO system_settings (`key`, value, message, updated_by, updated_at) VALUES ('maintenance_mode', :v, :m, :ub, NOW())");
        $ins->execute([':v' => $val, ':m' => $message, ':ub' => $admin['user_id']]);
    }
    audit_log('maintenance_mode', (int)$admin['user_id'], 'system', null, ['enabled' => (bool)$enabled, 'message' => $message]);
    ok([
        'maintenance_enabled' => (bool)$enabled,
        'message'             => $message,
    ], $enabled ? 'Maintenance mode enabled' : 'Maintenance mode disabled');
}

// ============================================================
// NOTIFICATIONS
// ============================================================

function handle_admin_notifications(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $unreadOnly = !empty($_GET['unread']);

    $where = []; $params = [];
    if ($unreadOnly) {
        $where[] = 'is_read = 0';
    }
    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countQ = db()->prepare("SELECT COUNT(*) c FROM notifications {$whereSql}");
    $countQ->execute($params);
    $total = (int)$countQ->fetch()['c'];

    $sql = "SELECT id, type, target_id, message, is_read, created_at FROM notifications {$whereSql} ORDER BY created_at DESC LIMIT :lim OFFSET :off";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'notification_id' => (int)$r['id'],
            'type'            => $r['type'],
            'message'         => $r['message'],
            'target_id'       => $r['target_id'] === null ? null : (int)$r['target_id'],
            'is_read'         => (bool)$r['is_read'],
            'created_at'      => $r['created_at'],
        ];
    }
    ok([
        'data' => $out,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_admin_mark_notifications_read(array $body): void {
    $admin = require_admin();
    if (!empty($body['all'])) {
        $upd = db()->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        $upd->execute();
        $count = $upd->rowCount();
        audit_log('notifications_read_all', (int)$admin['user_id'], 'system', null, ['count' => $count]);
        ok(['marked' => $count]);
    }
    require_fields($body, ['notification_ids']);
    if (!is_array($body['notification_ids']) || empty($body['notification_ids'])) {
        fail('notification_ids must be a non-empty array.', 422, ['notification_ids']);
    }
    $ids = [];
    foreach ($body['notification_ids'] as $i => $v) {
        $ids[] = strict_positive_int($v, "notification_ids[{$i}]");
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $upd = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ({$ph})");
    $upd->execute($ids);
    $count = $upd->rowCount();
    audit_log('notifications_read', (int)$admin['user_id'], 'system', null, ['count' => $count, 'ids' => $ids]);
    ok(['marked' => $count]);
}

// ============================================================
// SECTION X — SMTP (Outlook STARTTLS, pure PHP, no Composer)
// ============================================================

if (!defined('SMTP_HOST'))      define('SMTP_HOST',      $_ENV['SMTP_HOST']      ?? 'smtp-mail.outlook.com');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)($_ENV['SMTP_PORT'] ?? 587));
if (!defined('SMTP_USERNAME'))  define('SMTP_USERNAME',  $_ENV['SMTP_USERNAME']  ?? '');
if (!defined('SMTP_PASSWORD'))  define('SMTP_PASSWORD',  $_ENV['SMTP_PASSWORD']  ?? '');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      $_ENV['SMTP_FROM']      ?? '');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? 'MwasinMarket');
if (!defined('APP_PUBLIC_URL')) define('APP_PUBLIC_URL', $_ENV['APP_PUBLIC_URL'] ?? '');
if (!defined('EMAIL_TOKEN_TTL'))    define('EMAIL_TOKEN_TTL',    86400);     // 24 h
if (!defined('PASSWORD_RESET_TTL')) define('PASSWORD_RESET_TTL', 3600);      // 1 h

function smtp_send_mail(string $to, string $subject, string $htmlBody): array {
    if (SMTP_HOST === '' || SMTP_USERNAME === '' || SMTP_PASSWORD === '' || SMTP_FROM === '') {
        return ['ok' => false, 'error' => 'SMTP not configured'];
    }
    if (!valid_email($to)) {
        return ['ok' => false, 'error' => 'Invalid recipient address'];
    }
    $fp = @stream_socket_client(
        'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
        $errno, $errstr, 15, STREAM_CLIENT_CONNECT
    );
    if (!$fp) return ['ok' => false, 'error' => "Connect failed: {$errstr}"];
    stream_set_timeout($fp, 15);

    $read = function() use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function(string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function(string $code) use ($read): string {
        $r = $read();
        if (substr($r, 0, 3) !== $code) {
            throw new RuntimeException("SMTP expected {$code}: " . trim($r));
        }
        return $r;
    };

    try {
        $expect('220');
        $write('EHLO ' . gethostname()); $expect('250');
        $write('STARTTLS'); $expect('220');
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $cryptoMethod)) {
            throw new RuntimeException('TLS handshake failed');
        }
        $write('EHLO ' . gethostname()); $expect('250');
        $write('AUTH LOGIN'); $expect('334');
        $write(base64_encode(SMTP_USERNAME)); $expect('334');
        $write(base64_encode(SMTP_PASSWORD)); $expect('235');
        $write('MAIL FROM:<' . SMTP_FROM . '>'); $expect('250');
        $write('RCPT TO:<' . $to . '>'); $expect('250');
        $write('DATA'); $expect('354');

        $fromName = addslashes(SMTP_FROM_NAME);
        $headers  = "From: \"{$fromName}\" <" . SMTP_FROM . ">\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: " . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=utf-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . bin2hex(random_bytes(8)) . "@" . SMTP_HOST . ">\r\n";

        // RFC5321: lines beginning with "." must be dot-stuffed
        $bodyOut = preg_replace('/(^|\r\n)\./', '$1..', $htmlBody);
        fwrite($fp, $headers . "\r\n" . $bodyOut . "\r\n.\r\n");
        $expect('250');
        $write('QUIT');
        @fclose($fp);
        return ['ok' => true];
    } catch (Throwable $e) {
        @fclose($fp);
        error_log('[MwasinMarket][SMTP] ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function send_verification_email(int $userId, string $email, string $username): array {
    $token = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + EMAIL_TOKEN_TTL);
    $ins = db()->prepare("INSERT INTO email_verification_tokens (user_id, token, expires_at, created_at) VALUES (:uid, :tok, :exp, NOW())");
    $ins->execute([':uid' => $userId, ':tok' => $token, ':exp' => $expires]);

    $link = APP_PUBLIC_URL !== ''
        ? rtrim(APP_PUBLIC_URL, '/') . '/verify-email?token=' . $token
        : 'POST ?route=verify_email with body { "token": "' . $token . '" }';
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f6f8fb;padding:30px'>
        <div style='max-width:560px;margin:auto;background:#fff;border-radius:8px;padding:30px'>
        <h2 style='color:#2b6cb0'>Verify your email — MwasinMarket</h2>
        <p>Hi {$u},</p>
        <p>Please verify your email to activate your account:</p>
        <p style='text-align:center;margin:25px 0'>
          <a href='{$l}' style='background:#2b6cb0;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px'>Verify Email</a>
        </p>
        <p style='font-size:12px;color:#666'>Or paste this token into the app: <code>{$token}</code></p>
        <p style='font-size:12px;color:#666'>This link expires in 24 hours. If you did not register, ignore this email.</p>
        </div></body></html>";

    return smtp_send_mail($email, 'Verify your MwasinMarket email', $body);
}

function send_password_reset_email(int $userId, string $email, string $username, string $ip): array {
    $token = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL);
    $ins = db()->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at, requested_ip, created_at) VALUES (:uid, :tok, :exp, :ip, NOW())");
    $ins->execute([':uid' => $userId, ':tok' => $token, ':exp' => $expires, ':ip' => $ip]);

    $link = APP_PUBLIC_URL !== ''
        ? rtrim(APP_PUBLIC_URL, '/') . '/reset-password?token=' . $token
        : 'POST ?route=reset_password with body { "token": "' . $token . '", "new_password": "..." }';
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f6f8fb;padding:30px'>
        <div style='max-width:560px;margin:auto;background:#fff;border-radius:8px;padding:30px'>
        <h2 style='color:#c53030'>Password reset — MwasinMarket</h2>
        <p>Hi {$u},</p>
        <p>We received a password reset request for your account.</p>
        <p style='text-align:center;margin:25px 0'>
          <a href='{$l}' style='background:#c53030;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px'>Reset Password</a>
        </p>
        <p style='font-size:12px;color:#666'>Or paste this token: <code>{$token}</code></p>
        <p style='font-size:12px;color:#666'>This link expires in 1 hour. If you did not request this, ignore this email — your password will not change.</p>
        </div></body></html>";

    return smtp_send_mail($email, 'Reset your MwasinMarket password', $body);
}

// ============================================================
// EMAIL VERIFICATION + PASSWORD RESET ROUTES
// ============================================================

function handle_request_email_verification(): void {
    $auth = require_auth();
    check_rate_limit(5, 3600); // 5/IP/hour
    $st = db()->prepare("SELECT id, username, email, email_verified FROM users WHERE id = :uid LIMIT 1");
    $st->execute([':uid' => $auth['user_id']]);
    $u = $st->fetch();
    if (!$u) fail('User not found.', 404);
    if ((int)$u['email_verified'] === 1) fail('Email is already verified.', 409);

    $res = send_verification_email((int)$u['id'], (string)$u['email'], (string)$u['username']);
    if (!$res['ok']) fail('Could not send verification email. Try again later.', 502);
    ok(['email' => $u['email'], 'sent' => true], 'Verification email sent');
}

function handle_verify_email(array $body): void {
    require_fields($body, ['token']);
    $token = is_string($body['token']) ? trim($body['token']) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('Invalid token format.', 422, ['token']);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM email_verification_tokens WHERE token = :t LIMIT 1 FOR UPDATE");
        $st->execute([':t' => $token]);
        $row = $st->fetch();
        if (!$row)                              { $pdo->rollBack(); fail('Invalid or expired token.', 401); }
        if ($row['used_at'] !== null)           { $pdo->rollBack(); fail('Token already used.', 409); }
        if (strtotime($row['expires_at']) < time()) { $pdo->rollBack(); fail('Token has expired.', 401); }

        $upd = $pdo->prepare("UPDATE users SET email_verified = 1, verified = 1, updated_at = NOW() WHERE id = :uid");
        $upd->execute([':uid' => $row['user_id']]);

        $mark = $pdo->prepare("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id");
        $mark->execute([':id' => $row['id']]);

        $pdo->commit();
        audit_log('email_verified', 0, 'user', (int)$row['user_id'], []);
        ok(['user_id' => (int)$row['user_id'], 'email_verified' => true], 'Email verified successfully');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'verify_email');
    }
}

function handle_request_password_reset(array $body): void {
    check_rate_limit(5, 3600); // 5/IP/hour
    require_fields($body, ['email']);
    $email = is_string($body['email']) ? trim($body['email']) : '';
    if (!valid_email($email)) {
        // Always respond success-ish to avoid email enumeration
        ok(['sent' => true], 'If that email is registered, a reset link has been sent');
    }
    $st = db()->prepare("SELECT id, username, email FROM users WHERE email = :e LIMIT 1");
    $st->execute([':e' => $email]);
    $u = $st->fetch();
    if ($u) {
        send_password_reset_email((int)$u['id'], (string)$u['email'], (string)$u['username'], client_ip());
        audit_log('password_reset_requested', 0, 'user', (int)$u['id'], ['ip' => client_ip()]);
    }
    // Identical response either way (no enumeration leak)
    ok(['sent' => true], 'If that email is registered, a reset link has been sent');
}

function handle_reset_password(array $body): void {
    check_rate_limit(10, 900);
    require_fields($body, ['token', 'new_password']);
    $token = is_string($body['token']) ? trim($body['token']) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('Invalid token format.', 422, ['token']);
    $newPass = is_string($body['new_password']) ? $body['new_password'] : '';
    if (mb_strlen($newPass, 'UTF-8') < 8)   fail('Password must be at least 8 characters.', 422, ['new_password']);
    if (mb_strlen($newPass, 'UTF-8') > 200) fail('Password too long.', 422, ['new_password']);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = :t LIMIT 1 FOR UPDATE");
        $st->execute([':t' => $token]);
        $row = $st->fetch();
        if (!$row)                              { $pdo->rollBack(); fail('Invalid or expired token.', 401); }
        if ($row['used_at'] !== null)           { $pdo->rollBack(); fail('Token already used.', 409); }
        if (strtotime($row['expires_at']) < time()) { $pdo->rollBack(); fail('Token has expired.', 401); }

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd = $pdo->prepare("UPDATE users SET password_hash = :h, updated_at = NOW() WHERE id = :uid");
        $upd->execute([':h' => $hash, ':uid' => $row['user_id']]);

        $mark = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id");
        $mark->execute([':id' => $row['id']]);

        // Revoke all tokens (force logout everywhere)
        $del = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :uid");
        $del->execute([':uid' => $row['user_id']]);

        $pdo->commit();
        clear_rate_limit_attempt();
        audit_log('password_reset', 0, 'user', (int)$row['user_id'], ['ip' => client_ip()]);
        ok(['user_id' => (int)$row['user_id'], 'reset' => true], 'Password reset successful. Please log in.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'reset_password');
    }
}

// ============================================================
// ENTRY POINT (was index.php). All requests dispatch here.
// ============================================================

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
            'version' => '5.2',
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
        ['profile',                        'GET',  'user',   'Authenticated user details + bet stats'],
        ['my_bets',                        'GET',  'user',   'User bet history (status, history, page, limit)'],
        ['health',                         'GET',  'admin',  'DB / SMTP / dependency health (DEBUG_MODE public)'],
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
        ['admin_edit_market',              'POST', 'admin',  'Edit question/category/source/title/image'],
        ['admin_adjust_limits',            'POST', 'admin',  'Stake / wager cap / max_odds limits'],
        ['admin_extend_close_time',        'POST', 'admin',  'Push close deadline forward'],
        ['admin_set_liquidity',            'POST', 'admin',  'Change LMSR b parameter'],
        ['admin_reseed_odds',              'POST', 'admin',  'Reset displayed odds (by id or name)'],
        ['admin_set_odds_mode',            'POST', 'admin',  'Switch lmsr ↔ fixed'],
        ['admin_stats',                    'GET',  'admin',  'Dashboard overview'],
        ['admin_market_report',            'GET',  'admin',  'Deep single-market report'],
        ['admin_notifications',            'GET',  'admin',  'Unread system alerts'],
        ['admin_mark_notifications_read',  'POST', 'admin',  'Mark notification(s) read'],
        ['deposit_request',                'POST', 'user',   'Initiate M-Pesa STK Push'],
        ['payment_status',                 'GET',  'user',   'Poll deposit status'],
        ['withdrawal_request',             'POST', 'user',   'Request M-Pesa withdrawal'],
        ['mpesa_webhook',                  'POST', 'mpesa',  'Safaricom STK callback (IP allowlist)'],
        ['admin_credit_user',              'POST', 'admin',  'Credit / debit user balance'],
        ['admin_pending_withdrawals',      'GET',  'admin',  'List pending withdrawals'],
        ['admin_approve_withdrawal',       'POST', 'admin',  'Approve and disburse via B2C'],
        ['admin_reject_withdrawal',        'POST', 'admin',  'Reject withdrawal and refund lock'],
        ['admin_payment_report',           'GET',  'admin',  'Deposit / withdrawal aggregates'],
        ['react',                          'POST', 'user',   'Toggle heart on a market'],
        ['reactions',                      'GET',  'public', 'Heart count (and own state if authed)'],
        ['send_message',                   'POST', 'user',   'Send DM to another user'],
        ['inbox',                          'GET',  'user',   'List conversations'],
        ['conversation',                   'GET',  'user',   'Thread with one user'],
        ['delete_message',                 'POST', 'user',   'Soft-delete own message'],
        ['stickers',                       'GET',  'public', 'List sticker library grouped by folder'],
        ['admin_upload_sticker',           'POST', 'admin',  'multipart/form-data sticker upload'],
        ['admin_delete_sticker',           'POST', 'admin',  'Soft-delete a sticker'],
        ['admin_send_sms',                 'POST', 'admin',  'Send SMS to user or broadcast'],
        ['admin_sms_history',              'GET',  'admin',  'View sent SMS log'],
        ['admin_ban_user',                 'POST', 'admin',  'Full account ban'],
        ['admin_unban_user',               'POST', 'admin',  'Lift ban'],
        ['admin_restrict_messaging',       'POST', 'admin',  'Toggle messaging restriction'],
        ['admin_maintenance',              'POST', 'admin',  'Toggle maintenance mode'],
        ['admin_maintenance',              'GET',  'admin',  'Current maintenance state'],
    ];
    $items = [];
    foreach ($ROUTE_CATALOGUE as $r) {
        $items[] = ['route' => $r[0], 'method' => $r[1], 'auth' => $r[2], 'description' => $r[3]];
    }
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'version' => '5.2',
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
        case 'register':    handle_register($BODY);    break;
        case 'login':       handle_login($BODY);       break;
        case 'admin_login': handle_admin_login($BODY); break;
        case 'logout':      handle_logout();           break;
        case 'profile':     handle_profile();          break;
        case 'my_bets':     handle_my_bets();          break;
        case 'health':      handle_health();           break;

        // Email verification + password reset
        case 'request_email_verification': handle_request_email_verification(); break;
        case 'verify_email':               handle_verify_email($BODY);          break;
        case 'request_password_reset':     handle_request_password_reset($BODY); break;
        case 'reset_password':             handle_reset_password($BODY);        break;

        // Markets + betting
        case 'markets':                  handle_markets_list();         break;
        case 'market':                   handle_market_single();        break;
        case 'market_history':           handle_market_history();       break;
        case 'bet':                      handle_bet($BODY);             break;
        case 'admin_create_market':      handle_admin_create_market($BODY); break;
        case 'admin_pause_market':       handle_admin_pause_market($BODY); break;
        case 'admin_resume_market':      handle_admin_resume_market($BODY); break;
        case 'admin_force_close_market': handle_admin_force_close_market($BODY); break;
        case 'admin_reopen_market':      handle_admin_reopen_market($BODY); break;
        case 'admin_settle_market':      handle_admin_settle_market($BODY); break;
        case 'admin_void_market':        handle_admin_void_market($BODY); break;
        case 'admin_void_bets_by_time':  handle_admin_void_bets_by_time($BODY); break;
        case 'admin_void_bet':           handle_admin_void_bet($BODY); break;
        case 'admin_archive_market':     handle_admin_archive_market($BODY); break;
        case 'admin_edit_market':        handle_admin_edit_market($BODY); break;
        case 'admin_adjust_limits':      handle_admin_adjust_limits($BODY); break;
        case 'admin_extend_close_time':  handle_admin_extend_close_time($BODY); break;
        case 'admin_set_liquidity':      handle_admin_set_liquidity($BODY); break;
        case 'admin_reseed_odds':        handle_admin_reseed_odds($BODY); break;
        case 'admin_set_odds_mode':      handle_admin_set_odds_mode($BODY); break;
        case 'admin_stats':              handle_admin_stats(); break;
        case 'admin_market_report':      handle_admin_market_report(); break;

        // Payments
        case 'deposit_request':           handle_deposit_request($BODY); break;
        case 'payment_status':            handle_payment_status(); break;
        case 'withdrawal_request':        handle_withdrawal_request($BODY); break;
        case 'mpesa_webhook':             handle_mpesa_webhook($BODY); break;
        case 'admin_credit_user':         handle_admin_credit_user($BODY); break;
        case 'admin_pending_withdrawals': handle_admin_pending_withdrawals(); break;
        case 'admin_approve_withdrawal':  handle_admin_approve_withdrawal($BODY); break;
        case 'admin_reject_withdrawal':   handle_admin_reject_withdrawal($BODY); break;
        case 'admin_payment_report':      handle_admin_payment_report(); break;

        // Social
        case 'react':                         handle_react($BODY); break;
        case 'reactions':                     handle_reactions(); break;
        case 'send_message':                  handle_send_message($BODY); break;
        case 'inbox':                         handle_inbox(); break;
        case 'conversation':                  handle_conversation(); break;
        case 'delete_message':                handle_delete_message($BODY); break;
        case 'stickers':                      handle_stickers_list(); break;
        case 'admin_upload_sticker':          handle_admin_upload_sticker(); break;
        case 'admin_delete_sticker':          handle_admin_delete_sticker($BODY); break;
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
