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
    $logEntry = json_encode([
        'time'    => gmdate('Y-m-d H:i:s'),
        'context' => $context,
        'class'   => get_class($e),
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
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

function handle_login(array $body): void {
    check_rate_limit(10, 900);
    require_fields($body, ['identifier', 'password']);
    $identifier = is_string($body['identifier']) ? trim($body['identifier']) : '';
    $password   = is_string($body['password']) ? $body['password'] : '';
    if ($identifier === '' || $password === '') fail('Invalid credentials.', 401);

    $stmt = db()->prepare("SELECT id, username, password_hash, role, is_suspended FROM users WHERE (username = :id OR email = :id OR phone = :id) LIMIT 1");
    $stmt->execute([':id' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, DUMMY_HASH); // timing
        fail('Invalid credentials.', 401);
    }
    if (!password_verify($password, (string)$user['password_hash'])) {
        fail('Invalid credentials.', 401);
    }
    if ((int)$user['is_suspended'] === 1) {
        fail('Your account has been suspended.', 403);
    }
    clear_rate_limit_attempt();
    cleanup_expired_tokens((int)$user['id']);
    $token = issue_token((int)$user['id']);
    ok([
        'user_id'  => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'token'    => $token,
        'expires_in' => TOKEN_TTL,
    ], 'Login successful');
}

function handle_admin_login(array $body): void {
    check_rate_limit(10, 900);
    require_fields($body, ['identifier', 'password']);
    $identifier = is_string($body['identifier']) ? trim($body['identifier']) : '';
    $password   = is_string($body['password']) ? $body['password'] : '';
    if ($identifier === '' || $password === '') fail('Invalid credentials.', 401);

    $stmt = db()->prepare("SELECT id, username, password_hash, role, is_suspended FROM users WHERE (username = :id OR email = :id OR phone = :id) LIMIT 1");
    $stmt->execute([':id' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, DUMMY_HASH);
        fail('Invalid credentials.', 401);
    }
    if (!password_verify($password, (string)$user['password_hash'])) {
        fail('Invalid credentials.', 401);
    }
    if ($user['role'] !== 'admin') {
        fail('Admin access required.', 403);
    }
    if ((int)$user['is_suspended'] === 1) {
        fail('Your account has been suspended.', 403);
    }
    clear_rate_limit_attempt();
    cleanup_expired_tokens((int)$user['id']);
    $token = issue_token((int)$user['id']);
    audit_log('admin_login', (int)$user['id'], 'user', (int)$user['id'], ['ip' => client_ip()]);
    ok([
        'user_id'  => (int)$user['id'],
        'username' => $user['username'],
        'role'     => 'admin',
        'token'    => $token,
        'expires_in' => TOKEN_TTL,
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
        'user' => [
            'id'                   => (int)$user['id'],
            'username'             => $user['username'],
            'email'                => $user['email'],
            'phone'                => $user['phone'],
            'full_name'            => $user['full_name'],
            'role'                 => $user['role'],
            'balance'              => (float)$user['balance'],
            'locked_balance'       => (float)$user['locked_balance'],
            'available_balance'    => (float)$user['balance'] - (float)$user['locked_balance'],
            'bonus_balance'        => (float)$user['bonus_balance'],
            'total_wagered'        => (float)$user['total_wagered'],
            'total_wins'           => (float)$user['total_wins'],
            'verified'             => (bool)$user['verified'],
            'email_verified'       => (bool)$user['email_verified'],
            'phone_verified'       => (bool)$user['phone_verified'],
            'is_suspended'         => (bool)$user['is_suspended'],
            'messaging_restricted' => (bool)$user['messaging_restricted'],
            'created_at'           => $user['created_at'],
        ],
        'bet_stats' => [
            'total_bets'    => (int)$bs['total_bets'],
            'open_bets'     => (int)$bs['open_bets'],
            'won_bets'      => (int)$bs['won_bets'],
            'lost_bets'     => (int)$bs['lost_bets'],
            'void_bets'     => (int)$bs['void_bets'],
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
            'outcome_name'  => $r['outcome_name'],
            'is_bonus_bet'  => (bool)$r['is_bonus_bet'],
            'shares'        => (float)$r['shares'],
            'stake'         => (float)$r['stake'],
            'odds_at_entry' => (float)$r['odds_at_entry'],
            'possible_win'  => (float)$r['possible_win'],
            'payout'        => $r['payout'] === null ? null : (float)$r['payout'],
            'status'        => $r['status'],
            'void_reason'   => $r['void_reason'],
            'expires_at'    => $r['expires_at'],
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
