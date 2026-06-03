<?php
declare(strict_types=1);

/** MwasinMarket — Auth routes. Requires config.php + bootstrap.php. */

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
    try {
        $ins->execute([':u' => $username, ':e' => $email, ':p' => $phone, ':fn' => $fullName, ':h' => $hash]);
    } catch (PDOException $e) {
        // Unique-constraint race between the check above and this insert.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            fail('A user with that username, email, or phone already exists.', 409);
        }
        throw $e;
    }
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

    // Distinct placeholders: MySQL native prepares do not allow reusing the
    // same named placeholder; we bind the same value to three different keys.
    $stmt = db()->prepare("SELECT id, username, email, phone, role, balance, locked_balance, bonus_balance, password_hash, is_suspended FROM users WHERE (username = :u OR email = :e OR phone = :p) LIMIT 1");
    $stmt->execute([':u' => $identifier, ':e' => $identifier, ':p' => $identifier]);
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

    // Distinct placeholders: MySQL native prepares do not allow reusing the
    // same named placeholder; we bind the same value to three different keys.
    $stmt = db()->prepare("SELECT id, username, email, phone, role, balance, locked_balance, bonus_balance, password_hash, is_suspended FROM users WHERE (username = :u OR email = :e OR phone = :p) LIMIT 1");
    $stmt->execute([':u' => $identifier, ':e' => $identifier, ':p' => $identifier]);
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

    // Profile is what the user sees. We hide cumulative "money won" and
    // payout totals to avoid loss-chasing / discouragement signalling.
    // Settled vs open counts are kept so users can still navigate their
    // history (open vs settled) but without anchoring on a money figure.
    $settled = (int)$bs['won_bets'] + (int)$bs['lost_bets'] + (int)$bs['void_bets'];
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
        'member_since'         => $user['created_at'],
        'bet_stats' => [
            'total'   => (int)$bs['total_bets'],
            'open'    => (int)$bs['open_bets'],
            'settled' => $settled,
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
