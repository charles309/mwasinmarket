<?php
declare(strict_types=1);

/**
 * MwasinMarket — Bootstrap (shared infrastructure)
 * Loaded by api.php and payment_callback.php. Assumes config.php is already required.
 */

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
// USER NOTIFICATIONS (email via SMTP; no SMS provider)
// ============================================================

/**
 * Wrap a short plain-text notification in a minimal HTML envelope.
 */
function _notif_html(string $heading, string $bodyText): string {
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $b = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
    return "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f6f8fb;padding:24px'>
        <div style='max-width:560px;margin:auto;background:#fff;border-radius:8px;padding:24px'>
          <h2 style='color:#2b6cb0;margin:0 0 12px'>{$h}</h2>
          <p style='color:#222;line-height:1.5'>{$b}</p>
          <hr style='border:none;border-top:1px solid #eee;margin:18px 0'>
          <p style='font-size:12px;color:#777'>This is an automated message from MwasinMarket.</p>
        </div></body></html>";
}

/**
 * Send an email notification to a user. Looks up email + username by user_id,
 * sends via SMTP, and writes an email_log row. Safe to call inside handlers —
 * returns ['ok'=>bool, ...] and never throws.
 */
function notify_user(int $userId, string $subject, string $bodyText, ?int $sentBy = null): array {
    $subject = mb_substr(trim($subject), 0, 200, 'UTF-8');
    if ($subject === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'invalid input'];
    }

    try {
        $st = db()->prepare("SELECT email, username FROM users WHERE id = :uid LIMIT 1");
        $st->execute([':uid' => $userId]);
        $u = $st->fetch();
    } catch (Throwable $e) {
        error_log('[MwasinMarket] notify_user lookup failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'lookup failed'];
    }
    if (!$u || empty($u['email'])) {
        return ['ok' => false, 'error' => 'no email on file'];
    }

    $logId = 0;
    try {
        $li = db()->prepare("INSERT INTO email_log (user_id, email, subject, status, sent_by, created_at) VALUES (:uid, :em, :sub, 'queued', :sb, NOW())");
        $li->execute([':uid' => $userId, ':em' => $u['email'], ':sub' => $subject, ':sb' => $sentBy]);
        $logId = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[MwasinMarket] email_log queue failed: ' . $e->getMessage());
    }

    $html = _notif_html($subject, $bodyText);
    $res = smtp_send_mail((string)$u['email'], $subject, $html);

    if ($logId > 0) {
        try {
            $upd = db()->prepare("UPDATE email_log SET status = :st, error = :err WHERE id = :id");
            $upd->execute([
                ':st'  => $res['ok'] ? 'sent' : 'failed',
                ':err' => $res['ok'] ? null  : mb_substr((string)($res['error'] ?? 'send failed'), 0, 250, 'UTF-8'),
                ':id'  => $logId,
            ]);
        } catch (Throwable $e) { /* ignore */ }
    }

    return ['ok' => (bool)$res['ok'], 'log_id' => $logId, 'email' => $u['email'], 'error' => $res['error'] ?? null];
}

// ============================================================
// NETWORK HELPERS
// ============================================================

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
// ADMIN USER VETTING (shared by payments + admin)
// ============================================================

function _admin_user_vetting(int $userId): ?array {
    $pdo = db();
    $u = $pdo->prepare("SELECT id, username, email, phone, full_name, role, balance, locked_balance, bonus_balance, total_wagered, total_wins, verified, email_verified, phone_verified, is_suspended, messaging_restricted, created_at FROM users WHERE id = :id LIMIT 1");
    $u->execute([':id' => $userId]);
    $user = $u->fetch();
    if (!$user) return null;

    $bets = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open,
        SUM(CASE WHEN status='won'  THEN 1 ELSE 0 END) AS won,
        SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) AS lost,
        SUM(CASE WHEN status='void' THEN 1 ELSE 0 END) AS void,
        COALESCE(SUM(stake),0) AS total_stake,
        COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS total_payout
        FROM bets WHERE user_id = :id");
    $bets->execute([':id' => $userId]);
    $bs = $bets->fetch();

    $dep = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_completed,
        MAX(CASE WHEN status='completed' THEN completed_at ELSE NULL END) AS last_at
        FROM deposits WHERE user_id = :id");
    $dep->execute([':id' => $userId]);
    $depRow = $dep->fetch();

    $wd = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='rejected'  THEN 1 ELSE 0 END) AS rejected,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_completed,
        MAX(CASE WHEN status='completed' THEN completed_at ELSE NULL END) AS last_at
        FROM withdrawals WHERE user_id = :id");
    $wd->execute([':id' => $userId]);
    $wdRow = $wd->fetch();

    $bans = $pdo->prepare("SELECT id, reason, banned_at, lifted_at FROM user_bans WHERE user_id = :id ORDER BY banned_at DESC LIMIT 5");
    $bans->execute([':id' => $userId]);
    $banHistory = $bans->fetchAll();

    $lastBets = $pdo->prepare("SELECT slip_id, market_id, stake, status, created_at FROM bets WHERE user_id = :id ORDER BY created_at DESC LIMIT 5");
    $lastBets->execute([':id' => $userId]);
    $recentBets = $lastBets->fetchAll();

    $pendingW = $pdo->prepare("SELECT id, amount, phone, status, created_at FROM withdrawals WHERE user_id = :id AND status IN ('pending','processing','approved') ORDER BY created_at DESC");
    $pendingW->execute([':id' => $userId]);
    $pendingWithdrawals = $pendingW->fetchAll();

    return [
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
            'total'         => (int)$bs['total'],
            'open'          => (int)$bs['open'],
            'won'           => (int)$bs['won'],
            'lost'          => (int)$bs['lost'],
            'void'          => (int)$bs['void'],
            'total_stake'   => (float)$bs['total_stake'],
            'total_payout'  => (float)$bs['total_payout'],
        ],
        'deposit_stats' => [
            'total'           => (int)$depRow['total'],
            'completed'       => (int)$depRow['completed'],
            'total_completed' => (float)$depRow['total_completed'],
            'last_deposit'    => $depRow['last_at'],
        ],
        'withdrawal_stats' => [
            'total'           => (int)$wdRow['total'],
            'completed'       => (int)$wdRow['completed'],
            'pending'         => (int)$wdRow['pending'],
            'rejected'        => (int)$wdRow['rejected'],
            'total_completed' => (float)$wdRow['total_completed'],
            'last_withdrawal' => $wdRow['last_at'],
        ],
        'net_position'        => round((float)$depRow['total_completed'] - (float)$wdRow['total_completed'], 2),
        'ban_history'         => $banHistory,
        'recent_bets'         => $recentBets,
        'pending_withdrawals' => $pendingWithdrawals,
    ];
}

// ============================================================
// SMTP / EMAIL
// ============================================================

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
// MATH CAPTCHA (stateless, HMAC-signed) — anti STK-spam
// ============================================================

function _captcha_secret(): string {
    if (APP_SECRET !== '') return APP_SECRET;
    // Fallback derives a key so the feature still works if APP_SECRET is unset,
    // but log a warning — production MUST set APP_SECRET.
    error_log('[MwasinMarket] WARNING: APP_SECRET not set; captcha using derived key.');
    return hash('sha256', DB_PASS . '|' . DB_NAME . '|mwasin-captcha');
}

/** Issue a fresh math challenge. Returns [question, token]. Stateless. */
function captcha_issue(): array {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $ops = ['+', '-', '*'];
    $op = $ops[random_int(0, 2)];
    switch ($op) {
        case '+': $answer = $a + $b; $q = "$a + $b"; break;
        case '-': if ($b > $a) { $t = $a; $a = $b; $b = $t; } $answer = $a - $b; $q = "$a - $b"; break;
        default:  $answer = $a * $b; $q = "$a × $b"; break;
    }
    $exp = time() + CAPTCHA_TTL;
    $nonce = bin2hex(random_bytes(8));
    // Bind the answer to an expiry + nonce, signed so the client can't forge it.
    $payload = $answer . '.' . $exp . '.' . $nonce;
    $sig = hash_hmac('sha256', $payload, _captcha_secret());
    $token = $exp . '.' . $nonce . '.' . $sig;
    return ['question' => $q . ' = ?', 'token' => $token, 'expires_in' => CAPTCHA_TTL];
}

/** Verify a solved challenge. Returns true if correct + unexpired + signature valid. */
function captcha_verify(mixed $token, mixed $answer): bool {
    if (!is_string($token)) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$exp, $nonce, $sig] = $parts;
    if (!ctype_digit($exp) || !ctype_xdigit($nonce) || !ctype_xdigit($sig)) return false;
    if ((int)$exp < time()) return false;
    // Recompute expected signature for each plausible integer answer would leak nothing;
    // instead recompute using the supplied answer and constant-time compare.
    if (is_string($answer)) { $answer = trim($answer); if ($answer === '' || !preg_match('/^-?\d+$/', $answer)) return false; $answer = (int)$answer; }
    if (!is_int($answer)) return false;
    $payload = $answer . '.' . $exp . '.' . $nonce;
    $expected = hash_hmac('sha256', $payload, _captcha_secret());
    return hash_equals($expected, $sig);
}

// ============================================================
// PHONE NORMALISATION
// ============================================================

/** Convert +254XXXXXXXXX / 254XXXXXXXXX / 07XXXXXXXX → 07XXXXXXXX (PayHero local format). */
function phone_to_local(string $phone): string {
    $d = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($d, '254')) $d = '0' . substr($d, 3);
    if (str_starts_with($d, '7') && strlen($d) === 9) $d = '0' . $d;
    return $d;
}

// ============================================================
// DEPOSIT PAUSE (independent of full maintenance mode)
// ============================================================

function deposits_paused(): array {
    try {
        $row = db()->query("SELECT value, message FROM system_settings WHERE `key`='deposits_paused' LIMIT 1")->fetch();
    } catch (Throwable $e) {
        return ['paused' => false, 'message' => null];
    }
    $paused = $row && (string)$row['value'] === '1';
    return ['paused' => $paused, 'message' => $row['message'] ?? null];
}

// ============================================================
// MARKET CHAT PURGE (called when a market reaches a terminal state)
// ============================================================

function purge_market_chats(int $marketId): int {
    try {
        $stmt = db()->prepare("DELETE FROM market_chats WHERE market_id = :mid");
        $stmt->execute([':mid' => $marketId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('[MwasinMarket] purge_market_chats failed: ' . $e->getMessage());
        return 0;
    }
}
