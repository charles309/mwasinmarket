<?php
// =============================================================
//  MwasinMarket — Prediction Market API  v4.7
//  Single file · LMSR + Fixed-odds pricing · MySQL 8+
//
//  CHANGES FROM v4.6
//  ──────────────────────────────────────────────────────────
//  [SEC]   max_odds removed from all public API responses.
//          Users can never see the odds cap — it is now only
//          visible in admin_market_report and admin routes.
//          markets / market routes no longer SELECT or return
//          max_odds.
//  [SEC]   Bet route max_odds rejection message no longer
//          reveals the cap value or current live odds to users.
//          Old: "Odds (12.5×) exceed market limit (10.0×)."
//          New: "This outcome is not currently available for
//               betting. Try another outcome or check back later."
//  [FIX]   Fixed-mode snapshot odds stored at 2dp (was 6dp) in
//          admin_reseed_odds and admin_set_odds_mode post-commit
//          snapshot inserts. Consistent with all other paths.
//  [FIX]   Health check now includes max_odds in the markets
//          column checklist — catches missing migration.
//  [FIX]   admin_pause_market: pause_reason is now optional
//          (defaults to "Paused by admin"). It was mandatory
//          before but is an internal admin note never shown to
//          users, so requiring it was unnecessary friction.
//
//  [CHANGE] LMSR_B default raised 100 → 1000. Markets now open
//           with a much more stable price curve by default.
//           Pass "b": 500 explicitly to use a smaller value.
//  [NEW]    max_odds cap on LMSR markets. Each LMSR market has
//           a max_odds field (default 10.0). If the LMSR curve
//           produces odds above this cap for a given outcome,
//           bets on that outcome are rejected with a clear
//           message. Protects the house from extreme payouts.
//           Fixed markets are unaffected (admin sets exact odds).
//  [NEW]    admin_adjust_limits now accepts max_odds adjustment.
//  [FIX]    read_bearer() adds one more Nginx-specific fallback:
//           scans all $_SERVER keys for any variation of the
//           AUTHORIZATION key name, catching servers that use
//           non-standard key casing or prefixes.
//  [NOTE]   Nginx config must include:
//             fastcgi_param HTTP_AUTHORIZATION $http_authorization;
//           in the PHP location block for tokens to work.
//           The PHP code has four fallbacks but the Nginx config
//           fix is the most reliable solution.
//  [DB]     Run this migration before deploying v4.6:
//             ALTER TABLE markets
//               ADD COLUMN IF NOT EXISTS max_odds DECIMAL(8,2)
//               NOT NULL DEFAULT 10.00
//               AFTER max_total_wagered;
//
//  CHANGES FROM v4.4
//  ──────────────────────────────────────────────────────────
//  [FIX]   read_bearer() now checks HTTP_AUTHORIZATION,
//          REDIRECT_HTTP_AUTHORIZATION, and getallheaders()
//          so tokens work on Apache CGI/FastCGI shared hosting
//          (was silently breaking when Apache stripped the header).
//  [FIX]   admin_create_market LMSR: detects bookmaker margin in
//          submitted odds and adds a clear warning showing
//          requested vs actual opening odds. LMSR always prices
//          at fair (margin-free) odds — use odds_mode=fixed for
//          exact admin-set values.
//  [FIX]   admin_void_bet: shares reversal now guarded by
//          odds_mode check — fixed-mode bets (shares=0) skip
//          the UPDATE outcomes SET shares=GREATEST(0,shares-0).
//  [FIX]   register: clear_rate_limit_attempt() now called on
//          success so registrations don't burn rate-limit slots.
//          field has been removed from all API inputs and responses:
//          • admin_create_market: outcomes now require 'odds' only
//            for both lmsr and fixed modes. 'probability' input
//            is no longer accepted.
//          • admin_reseed_odds: outcomes require 'odds' only.
//          • lmsr_snapshot / fixed_snapshot: 'probability' removed
//            from output; all public outcome objects now have
//            outcome_id, name, odds only.
//          • market_history series: 'probability' removed; series
//            points now have time, odds, volume only.
//          • admin_market_report graph_summary: opening_probability
//            and current_probability removed; only odds returned.
//          • admin_set_odds_mode response: probability removed from
//            outcome objects.
//          • admin_reseed_odds audit log: old_prob / new_prob
//            removed; only odds values stored.
//          • normalize_probs() internal: probability key removed;
//            internal-only fallback uses _implied_prob key which
//            is never exposed to API callers.
//
//  [NEW]   fixed_snapshot() helper — unified 2dp odds for fixed markets.
//  [NEW]   admin_reopen_market — reopen a manually-closed market back to open.
//  [FIX]   admin_create_market — fixed mode now validates odds (not prob),
//          writes fixed_odds to DB, and returns fixed_snapshot() in response.
//          b-range check skipped in fixed mode (b still stored for potential
//          mode switch later).
//  [FIX]   Bet route — in fixed mode reads fixed_odds directly, skips LMSR
//          cost math, stake = amount exactly, shares stay at 0.
//  [FIX]   markets/market/admin_market_report — SELECT fixed_odds from outcomes,
//          call fixed_snapshot() vs lmsr_snapshot() based on odds_mode.
//  [FIX]   admin_set_odds_mode — required missing DB column documented; fixed
//          snapshot rounds odds to 2dp consistently.
//  [FIX]   All odds 2dp everywhere (lmsr_snapshot, record_market_snapshot,
//          fixed_snapshot, bet route, reseed audit log).
//  [DB]    Run these migrations before deploying v4.0:
//          ALTER TABLE markets ADD COLUMN IF NOT EXISTS odds_mode
//            ENUM('lmsr','fixed') NOT NULL DEFAULT 'lmsr' AFTER market_type;
//          ALTER TABLE outcomes ADD COLUMN IF NOT EXISTS fixed_odds
//            DECIMAL(10,2) NULL AFTER shares;
//
//  CHANGES FROM v3.2
//  ──────────────────────────────────────────────────────────
//  [FIX]   GET ?route=markets HTTP 500 — added try/catch to
//          all read-only routes (markets, market, market_history,
//          my_bets, profile). Raw SQL errors never leak.
//  [FIX]   Auto-close now runs in a separate pre-bet transaction
//          so it persists even when the bet is rejected.
//          maybe_auto_close() retained but deprecated.
//  [FIX]   All catch blocks use inTransaction() guard before
//          rollBack() — prevents crash on already-committed txn.
//  [FIX]   admin_extend_close_time syntax typo ($trim=).
//  [FIX]   admin_create_market honours custom market_id body
//          field and validates uniqueness.
//  [FIX]   lmsr_probs/lmsr_cost/lmsr_snapshot guard against
//          empty outcome arrays (prevents PHP 8.1 ValueError).
//  [FIX]   Default market list status changed to 'active'
//          (open + paused) — excludes resolved/voided/archived.
//  [FIX]   404 route no longer echoes the user-supplied route
//          name into the JSON response.
//  [SEC]   admin_create_market warns on low-b + no wager cap.
//
//  CHANGES FROM v3.1
//  ──────────────────────────────────────────────────────────
//  [FIX]   Auto-close: when close_time passes, the market
//          transitions to 'closed' automatically on next bet
//          attempt. Admin is notified via audit log.
//          Admin can then settle at any time — no manual
//          force-close step required.
//  [FIX]   admin_settle_market now works on open, paused,
//          closed, AND auto-closed markets (any non-terminal).
//  [SEC]   Root URL (no ?route=) returns safe status JSON.
//          Does NOT list available routes.
//  [SEC]   404 fallback does NOT leak available route list.
//
//  ROUTES
//  ──────────────────────────────────────────────────────────
//  POST  ?route=admin_login
//  POST  ?route=register
//  POST  ?route=login
//  POST  ?route=logout
//  GET   ?route=profile
//  GET   ?route=markets
//  GET   ?route=market&market_id=
//  GET   ?route=market_history&market_id=
//  POST  ?route=bet
//  GET   ?route=my_bets
//  POST  ?route=admin_create_market
//  POST  ?route=admin_pause_market
//  POST  ?route=admin_resume_market
//  POST  ?route=admin_force_close_market
//  POST  ?route=admin_reopen_market
//  POST  ?route=admin_settle_market
//  POST  ?route=admin_void_market
//  POST  ?route=admin_void_bet
//  POST  ?route=admin_archive_market
//  POST  ?route=admin_edit_market
//  POST  ?route=admin_adjust_limits
//  POST  ?route=admin_extend_close_time
//  GET   ?route=admin_stats
//  GET   ?route=admin_market_report
//  POST  ?route=admin_reseed_odds
//  POST  ?route=admin_set_odds_mode
//  POST  ?route=admin_set_liquidity
//  POST  ?route=admin_credit_user
//
//  CLOSE_TIME LIFECYCLE
//  ──────────────────────────────────────────────────────────
//  Market created (open) → close_time set by admin (optional)
//  close_time passes → next bet attempt auto-closes the market
//                      status = 'closed', audit logged
//  Admin can settle at ANY time (open/paused/closed/auto-closed)
//  No manual force-close needed for deadline-driven markets.
//
//  BALANCE LIFECYCLE
//  ──────────────────────────────────────────────────────────
//  Bet placed  → balance -= stake   | locked_balance += stake
//               total_wagered += stake
//  Bet won     → locked_balance -= stake | balance += payout
//               total_wins += payout
//  Bet lost    → locked_balance -= stake  (house keeps stake)
//  Bet voided  → locked_balance -= stake | balance += stake
//  Bet revoked → same as voided + LMSR shares reversed
//
//  LMSR b PRODUCTION LIMITS
//  ──────────────────────────────────────────────────────────
//  b = 50    → ~35 KES moves binary 50/50 to 60/40  (volatile, testing only)
//  b = 100   → ~70 KES  (very small markets)
//  b = 500   → ~350 KES (small markets)
//  b = 1000  → ~700 KES (default — balanced for most markets)
//  b = 5000  → ~3500 KES (large high-volume markets)
//  b = 10000 → ~7000 KES (maximum — very flat movement)
//  Never use b < 50 with real money.
// =============================================================

declare(strict_types=1);

// =============================================================
//  SECTION 1 — CONFIGURATION
// =============================================================
define('DB_HOST',      $_ENV['DB_HOST']      ?? 'localhost');
define('DB_NAME',      $_ENV['DB_NAME']      ?? 'mwasinmarket');
define('DB_USER',      $_ENV['DB_USER']      ?? 'root');
define('DB_PASS',      $_ENV['DB_PASS']      ?? 'NewStrongPassword123!');   // MUST be set in ENV
define('FRONTEND_URL', $_ENV['FRONTEND_URL'] ?? '');   // Set real origin in production
define('DEBUG_MODE',   (bool)($_ENV['DEBUG_MODE'] ?? false));  // Set to true for verbose errors (NEVER in production)
define('TOKEN_TTL',    86400);   // 24 hours
define('TOKEN_BYTES',  32);      // 64-char hex token

define('LMSR_B',        1000);   // default liquidity — raised from 100 for stability
define('LMSR_B_MIN',    50);     // production minimum — prevents extreme volatility
define('LMSR_B_MAX',    10000);  // production maximum
define('LMSR_MAX_ODDS', 10.0);   // default max odds cap — bets rejected above this on LMSR markets

// Timing-safe dummy hash — same algorithm and cost as real hashes
define('DUMMY_HASH', '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');


// =============================================================
//  SECTION 2 — BOOTSTRAP + HEADERS
// =============================================================
header('Content-Type: application/json');

if (FRONTEND_URL !== '') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === FRONTEND_URL) {
        header('Access-Control-Allow-Origin: ' . FRONTEND_URL);
        header('Access-Control-Allow-Credentials: true');
    }
} else {
    header('Access-Control-Allow-Origin: *');
    if (PHP_SAPI !== 'cli') {
        error_log('[MwasinMarket] WARNING: FRONTEND_URL not set. CORS is open (*).');
    }
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strlen(DB_PASS) === 0 && PHP_SAPI !== 'cli') {
    http_response_code(500);
    echo json_encode(['success' => false,
        'error' => 'Server misconfiguration: DB_PASS environment variable is not set.']);
    exit;
}

$ROUTE  = $_GET['route'] ?? '';
$METHOD = $_SERVER['REQUEST_METHOD'];
$BODY   = json_decode(file_get_contents('php://input'), true) ?? [];

// =============================================================
//  SAFE ROOT RESPONSE — no ?route= given
//  Returns only API identity. Does NOT list routes.
// =============================================================
if ($ROUTE === '') {
    echo json_encode([
        'success' => true,
        'api'     => 'MwasinMarket Prediction Market API',
        
        
        
    ], JSON_PRETTY_PRINT);
    exit;
}


// =============================================================
//  SECTION 3 — DATABASE
// =============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}


// =============================================================
//  SECTION 4 — RESPONSE HELPERS
// =============================================================
function ok(mixed $payload, string $message = '', int $status = 200): never {
    http_response_code($status);
    $r = ['success' => true];
    if ($message) $r['message'] = $message;
    if (is_array($payload) && (isset($payload['data']) || isset($payload['meta']))) {
        $r = array_merge($r, $payload);
    } else {
        $r['data'] = $payload;
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function fail(string $error, int $status = 400, array $details = []): never {
    http_response_code($status);
    $r = ['success' => false, 'error' => $error];
    if ($details) $r['details'] = $details;
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function internal_error(Throwable $e, string $context = ''): never {
    $msg = ($context ? "$context: " : '') . $e->getMessage();
    error_log('[MwasinMarket] ' . $msg);
    if (DEBUG_MODE) {
        fail('Internal error: ' . $msg, 500, [
            'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => array_slice(array_map(fn($f) =>
                (isset($f['file']) ? basename($f['file']).':'.$f['line'].' ' : '') .
                ($f['function'] ?? '?'), $e->getTrace()), 0, 5),
        ]);
    }
    fail('Internal server error. Please try again later.', 500);
}


// =============================================================
//  SECTION 5 — OPAQUE TOKEN AUTH
// =============================================================
function issue_token(int $userId): string {
    $token = bin2hex(random_bytes(TOKEN_BYTES));
    db()->prepare("INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:uid,:tok,:exp)")
        ->execute([':uid' => $userId, ':tok' => $token,
                   ':exp' => date('Y-m-d H:i:s', time() + TOKEN_TTL)]);
    return $token;
}

function read_bearer(): string {
    // $_SERVER['HTTP_AUTHORIZATION'] is NOT populated by Apache when PHP
    // runs as CGI or FastCGI (the most common shared-hosting setup).
    // For Nginx + PHP-FPM add this to your site config:
    //   fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    // The PHP code below has four fallbacks for different server setups.
    $h = $_SERVER['HTTP_AUTHORIZATION']           // Nginx (with fastcgi_param) + Apache mod_php
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] // Apache CGI after a RewriteRule
        ?? '';

    // Fallback 2: getallheaders() — works on Apache mod_php and some FastCGI setups
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $h = $value;
                break;
            }
        }
    }

    // Fallback 3: scan every $_SERVER key for any AUTHORIZATION variant.
    // Nginx and some CGI stacks may use non-standard casing or prefixes
    // (e.g. HTTP_AUTHORIZATION, AUTHORIZATION, HTTP_X_AUTHORIZATION).
    if ($h === '') {
        foreach ($_SERVER as $key => $value) {
            if (str_contains(strtoupper($key), 'AUTHORIZATION')) {
                $h = $value;
                break;
            }
        }
    }

    if (!str_starts_with($h, 'Bearer ')) fail('Missing or malformed Authorization header', 401);
    $t = trim(substr($h, 7));
    if ($t === '') fail('Empty token', 401);
    return $t;
}

function resolve_token(string $token): ?array {
    $stmt = db()->prepare("SELECT t.user_id, t.expires_at, u.role
        FROM auth_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token = :tok LIMIT 1");
    $stmt->execute([':tok' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (strtotime($row['expires_at']) < time()) {
        db()->prepare('DELETE FROM auth_tokens WHERE token = :tok')->execute([':tok' => $token]);
        return null;
    }
    return ['user_id' => (int)$row['user_id'], 'role' => $row['role'], 'token' => $token];
}

function require_auth(): array {
    $ctx = resolve_token(read_bearer());
    if (!$ctx) fail('Invalid or expired token. Please log in again.', 401);
    return $ctx;
}

function require_admin(): array {
    $ctx = require_auth();
    if ($ctx['role'] !== 'admin') fail('Admin access required.', 403);
    return $ctx;
}

function revoke_token(string $token): void {
    db()->prepare('DELETE FROM auth_tokens WHERE token = :tok')->execute([':tok' => $token]);
}

function cleanup_expired_tokens(int $userId): void {
    try {
        db()->prepare("DELETE FROM auth_tokens WHERE user_id = :uid AND expires_at < NOW()")
            ->execute([':uid' => $userId]);
    } catch (Throwable) { /* non-fatal */ }
}


// =============================================================
//  SECTION 6 — RATE LIMITING
//  Uses REMOTE_ADDR only — no proxy header trust.
//
//  Design:
//  • check_rate_limit() inserts a row BEFORE credentials are
//    checked so every attempt (good or bad) is recorded up-front.
//  • On successful login the caller MUST call
//    clear_rate_limit_attempt() to remove that row. Only failed
//    attempts accumulate toward the cap.
//  • Lazy pruning: each call deletes rows older than 24 h with
//    LIMIT 200 so the cleanup is O(1) and never blocks the request.
// =============================================================
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }

function check_rate_limit(int $max = 10, int $window = 900): void {
    $ip    = client_ip();
    $pdo   = db();
    $since = date('Y-m-d H:i:s', time() - $window);

    // Lazy pruning — keep the table small without a cron job.
    // Deletes rows > 24 h old, capped at 200 rows per call to
    // bound the DELETE cost regardless of table size.
    try {
        $pdo->prepare("DELETE FROM login_attempts
                        WHERE attempted_at < NOW() - INTERVAL 1 DAY
                        LIMIT 200")->execute();
    } catch (Throwable) { /* non-fatal — never block a request for cleanup */ }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identifier=:ip AND attempted_at>:since'
    );
    $stmt->execute([':ip' => $ip, ':since' => $since]);
    if ((int)$stmt->fetchColumn() >= $max) {
        fail('Too many login attempts. Please wait 15 minutes.', 429);
    }

    // Record this attempt. Removed by clear_rate_limit_attempt() on success.
    $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (:ip)')
        ->execute([':ip' => $ip]);
}

/**
 * Remove the most recent login_attempts row for this IP.
 * Call this immediately after a SUCCESSFUL login so that
 * successful logins do not count toward the failure cap.
 * Uses LIMIT 1 ORDER BY attempted_at DESC to target the row
 * just inserted by check_rate_limit().
 */
function clear_rate_limit_attempt(): void {
    try {
        db()->prepare(
            "DELETE FROM login_attempts
              WHERE identifier = :ip
              ORDER BY attempted_at DESC
              LIMIT 1"
        )->execute([':ip' => client_ip()]);
    } catch (Throwable) { /* non-fatal */ }
}


// =============================================================
//  SECTION 7 — AUTO-CLOSE HELPER (DEPRECATED)
//
//  NOTE: As of v3.3, auto-close is handled in a separate
//  pre-bet transaction (see the bet route). This function is
//  retained for backward compatibility but is no longer called
//  internally. The separate-transaction approach ensures the
//  close persists even when the bet is rejected.
// =============================================================
function maybe_auto_close(array &$mkt, PDO $pdo): void {
    // Only auto-close open markets (paused markets await manual action)
    if ($mkt['status'] !== 'open') return;
    if (!$mkt['close_time'])       return;
    if (strtotime($mkt['close_time']) >= time()) return;

    // close_time has passed — transition to closed
    $pdo->prepare("UPDATE markets SET status='closed' WHERE id=:mid")
        ->execute([':mid' => (int)$mkt['id']]);

    // Log without blocking (post-commit audit_log would not run here,
    // so we write directly — this is inside the transaction intentionally)
    try {
        db()->prepare("INSERT INTO audit_logs (admin_id,action,target_type,target_id,meta)
            VALUES (0,'auto_closed','market',:mid,:m)")
            ->execute([
                ':mid' => (int)$mkt['id'],
                ':m'   => json_encode([
                    'market_id'  => $mkt['market_id'],
                    'close_time' => $mkt['close_time'],
                    'reason'     => 'close_time deadline reached — auto-closed by system',
                ]),
            ]);
    } catch (Throwable) { /* non-fatal */ }

    // Update the local copy so the caller sees the new status
    $mkt['status'] = 'closed';
}


// =============================================================
//  SECTION 8 — LMSR PRICING ENGINE
//
//  Probability:  p_i = exp(s_i / b) / Σ exp(s_j / b)
//  Cost fn:      C(s) = b · ln( Σ exp(s_i / b) )
//  Buy cost:     cost = C(shares_after) − C(shares_before)
//  EU odds:      odds = 1 / p_i
//
//  Log-sum-exp trick — prevents float overflow on large shares.
// =============================================================
function lmsr_cost(array $shares, float $b): float {
    if (empty($shares)) return 0.0;
    $sc = array_map(fn($s) => $s / $b, $shares);
    $mx = max($sc);
    return $b * ($mx + log(array_sum(array_map(fn($x) => exp($x - $mx), $sc))));
}

function lmsr_probs(array $shares, float $b): array {
    if (empty($shares)) return [];
    $sc = array_map(fn($s) => $s / $b, $shares);
    $mx = max($sc);
    $ex = array_map(fn($x) => exp($x - $mx), $sc);
    $t  = array_sum($ex);
    return array_map(fn($x) => $x / $t, $ex);
}

function lmsr_buy_cost(array $shares, float $b, int $idx, float $qty): float {
    $after = $shares; $after[$idx] += $qty;
    return lmsr_cost($after, $b) - lmsr_cost($shares, $b);
}

function lmsr_shares_for(array $shares, float $b, int $idx, float $budget): float {
    $lo = 0.0; $hi = $budget * 20;
    for ($i = 0; $i < 64; $i++) {
        $mid  = ($lo + $hi) / 2.0;
        $cost = lmsr_buy_cost($shares, $b, $idx, $mid);
        if (abs($cost - $budget) < 0.0001) break;
        $cost < $budget ? ($lo = $mid) : ($hi = $mid);
    }
    return ($lo + $hi) / 2.0;
}

function lmsr_snapshot(array $rows, float $b): array {
    if (empty($rows)) return [];
    $sv    = array_map(fn($r) => (float)$r['shares'], $rows);
    $probs = lmsr_probs($sv, $b);
    $out   = [];
    foreach ($rows as $i => $r) {
        $p     = $probs[$i];
        $out[] = [
            'outcome_id'  => isset($r['id']) ? (int)$r['id'] : null,
            'name'        => $r['name'],
            'odds'        => $p > 0 ? round(1.0 / $p, 2) : 999.0,
        ];
    }
    return $out;
}

// Fixed-odds snapshot — reads admin-set fixed_odds from outcome rows.
// Used whenever odds_mode = 'fixed'.
function fixed_snapshot(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $fo    = (float)($r['fixed_odds'] ?? 0);
        $out[] = [
            'outcome_id'  => isset($r['id']) ? (int)$r['id'] : null,
            'name'        => $r['name'],
            'odds'        => $fo > 1.0 ? round($fo, 2) : 999.0,
        ];
    }
    return $out;
}

// Choose the right snapshot function based on odds_mode string.
function market_snapshot(array $rows, float $b, string $oddsMode): array {
    return $oddsMode === 'fixed' ? fixed_snapshot($rows) : lmsr_snapshot($rows, $b);
}


// =============================================================
//  SECTION 9 — OUTCOME NORMALIZATION
// =============================================================
function normalize_probs(array $outcomes): array {
    // All public input uses odds only (EU decimal > 1.0).
    // Internally, implied probability = 1 / odds.
    // The '_implied_prob' key is used only by internal fallbacks (e.g. admin_set_odds_mode
    // partial reseed) and is never exposed to API callers.
    $raw = array_map(function ($o) {
        if (isset($o['odds']))           return 1.0 / max(1.0001, (float)$o['odds']);
        if (isset($o['_implied_prob']))  return max(0.0001, (float)$o['_implied_prob']);
        return 0.5;
    }, $outcomes);
    $sum = array_sum($raw);
    if ($sum <= 0) fail('Outcome odds produce zero total implied probability', 422);
    return array_map(fn($p) => $p / $sum, $raw);
}

function seed_shares(array $probs, float $b): array {
    // Clamp every probability to [ε, 1] before calling log().
    // Without this, p=0  → log(0)         = -INF  (silently corrupts shares)
    //                 p<0 → log(negative)  = NaN   (propagates through all LMSR math)
    // ε = 1e-9 is small enough to produce a very large but finite share offset
    // that will be ignored in practice (odds ≈ 1e9 : 1).
    $eps  = 1e-9;
    $safe = array_map(fn($p) => max($eps, min(1.0 - $eps, (float)$p)), $probs);
    $p0   = $safe[0];
    return array_map(fn($p) => round($b * log($p / $p0), 8), $safe);
}


// =============================================================
//  SECTION 10 — VALIDATION HELPERS
// =============================================================
function require_fields(array $body, array $fields): void {
    $missing = array_filter($fields, fn($f) =>
        !isset($body[$f]) || (is_string($body[$f]) && trim($body[$f]) === '')
    );
    if ($missing) fail('Missing required fields', 422, array_values($missing));
}

function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function new_slip_id(): string   { return 'BET_' . strtoupper(bin2hex(random_bytes(4))); }
function new_market_id(): string { return bin2hex(random_bytes(8)); }


// =============================================================
//  SECTION 11 — AUDIT · BALANCE LEDGER · SNAPSHOTS
//  All called AFTER transaction commits — failures never roll
//  back confirmed financial operations.
// =============================================================
function audit_log(string $action, int $adminId, string $targetType,
                   ?int $targetId = null, array $meta = []): void {
    try {
        db()->prepare("INSERT INTO audit_logs (admin_id,action,target_type,target_id,meta)
            VALUES (:a,:act,:tt,:tid,:m)")
            ->execute([':a' => $adminId, ':act' => $action, ':tt' => $targetType,
                       ':tid' => $targetId,
                       ':m' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { error_log('[MwasinMarket] audit_log: ' . $e->getMessage()); }
}

function record_balance_tx(int $userId, string $type, float $amount,
                            float $balBefore, float $balAfter,
                            ?string $reference = null, ?int $marketId = null,
                            string $note = ''): void {
    try {
        db()->prepare("INSERT INTO balance_transactions
            (user_id,type,amount,balance_before,balance_after,reference_id,market_id,note)
            VALUES (:uid,:t,:a,:bb,:ba,:ref,:mid,:n)")
            ->execute([':uid' => $userId, ':t' => $type,
                       ':a' => round($amount, 2), ':bb' => round($balBefore, 2),
                       ':ba' => round($balAfter, 2), ':ref' => $reference,
                       ':mid' => $marketId, ':n' => $note]);
    } catch (Throwable $e) { error_log('[MwasinMarket] record_balance_tx: ' . $e->getMessage()); }
}

function fetch_balances(array $userIds, PDO $pdo): array {
    if (empty($userIds)) return [];
    $in   = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id, balance FROM users WHERE id IN ($in)");
    $stmt->execute(array_values($userIds));
    $map  = [];
    foreach ($stmt->fetchAll() as $row) $map[(int)$row['id']] = (float)$row['balance'];
    return $map;
}

function record_market_snapshot(int $marketId, array $rows, float $b, float $vol): void {
    try {
        $sv    = array_map(fn($r) => (float)$r['shares'], $rows);
        $probs = lmsr_probs($sv, $b);
        $stmt  = db()->prepare("INSERT INTO market_snapshots
            (market_id,outcome_id,probability,odds,shares,volume)
            VALUES (:mid,:oid,:p,:o,:s,:v)");
        foreach ($rows as $i => $r) {
            $p = $probs[$i];
            $stmt->execute([':mid' => $marketId, ':oid' => (int)$r['id'],
                            ':p' => round($p, 6),
                            ':o' => $p > 0 ? round(1.0 / $p, 2) : 999.0,
                            ':s' => round((float)$r['shares'], 8),
                            ':v' => round($vol, 2)]);
        }
    } catch (Throwable $e) { error_log('[MwasinMarket] record_market_snapshot: ' . $e->getMessage()); }
}


// =============================================================
//  SECTION 12 — ROUTER
// =============================================================
match (true) {


// =============================================================
//  GET ?route=health
//  Diagnostic route — checks DB connectivity and table structure.
//  Only available when DEBUG_MODE is enabled.
// =============================================================
$ROUTE === 'health' && $METHOD === 'GET' => (function () {
    if (!DEBUG_MODE) fail('Route not found', 404);
    $checks = [];

    // 1. DB connection
    try {
        $pdo = db();
        $checks['db_connection'] = 'OK';
    } catch (Throwable $e) {
        fail('DB connection failed: ' . $e->getMessage(), 500);
    }

    // 2. Required tables
    $required = ['users','auth_tokens','markets','outcomes','bets',
                 'balance_transactions','audit_logs','market_snapshots','login_attempts'];
    $existing = array_column(
        $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
    $missing  = array_diff($required, $existing);
    $checks['tables'] = $missing
        ? ['status' => 'MISSING', 'missing' => array_values($missing)]
        : ['status' => 'OK', 'found' => count($existing)];

    // 3. markets column check
    if (in_array('markets', $existing, true)) {
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM markets")->fetchAll(), 'Field');
        $need = ['id','market_id','title','image_url','source','question','category','market_type',
                 'odds_mode','status','close_time','pause_reason','total_bets','total_wagered',
                 'b','min_stake','max_stake','max_total_wagered','max_odds','is_archived',
                 'resolve_time','void_reason','created_by','created_at','updated_at'];
        $mcols = array_diff($need, $cols);
        $checks['markets_columns'] = $mcols
            ? ['status' => 'MISSING_COLUMNS', 'missing' => array_values($mcols), 'have' => $cols]
            : ['status' => 'OK', 'columns' => count($cols)];
    }

    // 4. outcomes column check
    if (in_array('outcomes', $existing, true)) {
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM outcomes")->fetchAll(), 'Field');
        $need = ['id','market_id','name','shares','fixed_odds'];
        $mcols = array_diff($need, $cols);
        $checks['outcomes_columns'] = $mcols
            ? ['status' => 'MISSING_COLUMNS', 'missing' => array_values($mcols), 'have' => $cols]
            : ['status' => 'OK', 'columns' => count($cols)];
    }

    // 5. Quick row counts
    try {
        $checks['row_counts'] = [
            'markets'  => (int)$pdo->query("SELECT COUNT(*) FROM markets")->fetchColumn(),
            'outcomes' => (int)$pdo->query("SELECT COUNT(*) FROM outcomes")->fetchColumn(),
            'users'    => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'bets'     => (int)$pdo->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        $checks['row_counts'] = ['error' => $e->getMessage()];
    }

    // 6. Try the exact markets query
    try {
        $pdo->prepare("SELECT m.id,m.market_id,m.source,m.question,m.category,m.market_type,
            m.status,m.close_time,m.pause_reason,m.total_bets,m.total_wagered,
            m.b,m.min_stake,m.max_stake,m.max_total_wagered,m.is_archived
            FROM markets m WHERE 1=1 AND m.is_archived=0 AND m.status IN ('open','paused')
            ORDER BY m.created_at DESC LIMIT 1 OFFSET 0")->execute();
        $checks['markets_query'] = 'OK';
    } catch (Throwable $e) {
        $checks['markets_query'] = ['status' => 'FAILED', 'error' => $e->getMessage()];
    }

    // 7. Try the outcomes FK join
    try {
        $pdo->prepare("SELECT o.id, o.name, o.shares FROM outcomes o
            JOIN markets m ON o.market_id = m.id LIMIT 1")->execute();
        $checks['outcomes_fk'] = 'OK';
    } catch (Throwable $e) {
        $checks['outcomes_fk'] = ['status' => 'FAILED', 'error' => $e->getMessage()];
    }

    ok($checks, 'Health check complete');
})(),


// =============================================================
//  POST ?route=admin_login
//  Body: { "username": "admin", "password": "..." }
// =============================================================
$ROUTE === 'admin_login' && $METHOD === 'POST' => (function () use ($BODY) {
    check_rate_limit();
    require_fields($BODY, ['username', 'password']);

    $stmt = db()->prepare('SELECT id,password_hash,role FROM users WHERE username=:u LIMIT 1');
    $stmt->execute([':u' => trim($BODY['username'])]);
    $user = $stmt->fetch();

    $hash = $user ? $user['password_hash'] : DUMMY_HASH;
    if (!password_verify($BODY['password'], $hash) || !$user) fail('Invalid credentials', 401);
    if ($user['role'] !== 'admin') fail('Admin access only', 403);

    // Successful login — remove the attempt row so it doesn't count toward the cap
    clear_rate_limit_attempt();

    $userId = (int)$user['id'];
    cleanup_expired_tokens($userId);
    $token = issue_token($userId);

    ok(['token'      => $token,
        'expires_in' => TOKEN_TTL,
        'expires_at' => date('Y-m-d H:i:s', time() + TOKEN_TTL),
        'user_id'    => $userId,
        'role'       => 'admin'], 'Admin login successful');
})(),


// =============================================================
//  POST ?route=register
//  Body: { username, email, phone, full_name, password }
// =============================================================
$ROUTE === 'register' && $METHOD === 'POST' => (function () use ($BODY) {
    check_rate_limit(20, 3600); // 20 registrations per IP per hour
    require_fields($BODY, ['username', 'email', 'phone', 'full_name', 'password']);

    $username  = trim($BODY['username']);
    $email     = strtolower(trim($BODY['email']));
    $phone     = trim($BODY['phone']);
    $full_name = trim($BODY['full_name']);
    $password  = $BODY['password'];

    $errors = [];
    if (!valid_email($email))                   $errors[] = 'Invalid email address';
    if (strlen($password) < 8)                  $errors[] = 'Password must be at least 8 characters';
    if (!preg_match('/^\+?\d{9,15}$/', $phone)) $errors[] = 'Invalid phone number (e.g. +254712345678)';
    if (strlen($username) < 3)                  $errors[] = 'Username must be at least 3 characters';
    if (strlen($username) > 60)                 $errors[] = 'Username max 60 characters';
    if (!preg_match('/^\w+$/', $username))       $errors[] = 'Username: letters, numbers, underscore only';
    if ($errors) fail('Validation failed', 422, $errors);

    $pdo = db();
    $chk = $pdo->prepare('SELECT id FROM users WHERE username=:u OR email=:e LIMIT 1');
    $chk->execute([':u' => $username, ':e' => $email]);
    if ($chk->fetch()) fail('Username or email already registered', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users
        (username,email,phone,full_name,password_hash,role,balance,locked_balance,bonus_balance,verified)
        VALUES (:u,:e,:ph,:fn,:pw,'user',0.00,0.00,0.00,0)")
        ->execute([':u' => $username, ':e' => $email, ':ph' => $phone,
                   ':fn' => $full_name, ':pw' => $hash]);

    $userId = (int)$pdo->lastInsertId();
    $token  = issue_token($userId);

    // Remove the attempt row so a successful registration doesn't burn a
    // slot against the 20/hour rate limit — same pattern as login.
    clear_rate_limit_attempt();

    ok(['user_id'    => $userId,
        'username'   => $username,
        'token'      => $token,
        'expires_in' => TOKEN_TTL,
        'expires_at' => date('Y-m-d H:i:s', time() + TOKEN_TTL)],
        'Account created successfully', 201);
})(),


// =============================================================
//  POST ?route=login
//  Body: { "username" or "email", "password" }
// =============================================================
$ROUTE === 'login' && $METHOD === 'POST' => (function () use ($BODY) {
    check_rate_limit();
    $password = $BODY['password'] ?? '';
    $ident    = trim($BODY['username'] ?? $BODY['email'] ?? '');
    if (!$ident)    fail('username or email is required', 422);
    if (!$password) fail('password is required', 422);

    $stmt = db()->prepare('SELECT id,password_hash,role,username,balance,locked_balance,bonus_balance
        FROM users WHERE username=:u OR email=:e LIMIT 1');
    $stmt->execute([':u' => $ident, ':e' => strtolower($ident)]);
    $user = $stmt->fetch();

    $hash = $user ? $user['password_hash'] : DUMMY_HASH;
    if (!password_verify($password, $hash) || !$user) fail('Invalid credentials', 401);

    // Successful login — remove the attempt row so it doesn't count toward the cap
    clear_rate_limit_attempt();

    $userId = (int)$user['id'];
    cleanup_expired_tokens($userId);
    $token = issue_token($userId);

    ok(['user_id'        => $userId,
        'username'       => $user['username'],
        'role'           => $user['role'],
        'balance'        => (float)$user['balance'],
        'locked_balance' => (float)$user['locked_balance'],
        'bonus_balance'  => (float)$user['bonus_balance'],
        'token'          => $token,
        'expires_in'     => TOKEN_TTL,
        'expires_at'     => date('Y-m-d H:i:s', time() + TOKEN_TTL)],
        'Login successful');
})(),


// =============================================================
//  POST ?route=logout
//  Authorization: Bearer <token>
// =============================================================
$ROUTE === 'logout' && $METHOD === 'POST' => (function () {
    $token = read_bearer();
    revoke_token($token);
    ok([], 'Logged out successfully');
})(),


// =============================================================
//  GET ?route=profile
//  Authorization: Bearer <token>
// =============================================================
$ROUTE === 'profile' && $METHOD === 'GET' => (function () {
  try {
    $ctx    = require_auth();
    $userId = $ctx['user_id'];
    $pdo    = db();

    $u = $pdo->prepare('SELECT id,username,email,phone,full_name,role,balance,locked_balance,
        bonus_balance,verified,created_at FROM users WHERE id=:uid LIMIT 1');
    $u->execute([':uid' => $userId]);
    $u = $u->fetch();
    if (!$u) fail('User not found', 404);

    $st = $pdo->prepare('SELECT COUNT(*) AS t, SUM(status="open") AS o, SUM(status="won") AS w,
        SUM(status="lost") AS l, SUM(status="void") AS v
        FROM bets WHERE user_id=:uid');
    $st->execute([':uid' => $userId]);
    $st = $st->fetch();

    ok(['user_id'        => (int)$u['id'],
        'username'       => $u['username'],
        'email'          => $u['email'],
        'phone'          => $u['phone'],
        'full_name'      => $u['full_name'],
        'role'           => $u['role'],
        'verified'       => (bool)$u['verified'],
        'balance'        => (float)$u['balance'],
        'locked_balance' => (float)$u['locked_balance'],
        'bonus_balance'  => (float)$u['bonus_balance'],
        'member_since'   => $u['created_at'],
        'bet_stats'      => [
            'total' => (int)$st['t'],
            'open'  => (int)$st['o'],
            'won'   => (int)$st['w'],
            'lost'  => (int)$st['l'],
            'void'  => (int)$st['v'],
            // lifetime_winnings intentionally omitted — financial detail
            // not surfaced to users.
        ]]);
  } catch (Throwable $e) {
      internal_error($e, 'profile');
  }
})(),


// =============================================================
//  GET ?route=markets
//  Public.
//  Params:
//    category, source, market_type
//    status        = open (default) | paused | closed | resolved | voided | active
//                    'active' = open + paused
//    include_resolved = 1  → include resolved/voided
//    include_archived = 1  → include archived markets
//    page, limit
// =============================================================
$ROUTE === 'markets' && $METHOD === 'GET' => (function () {
  try {
    $cat          = strtolower(trim($_GET['category']     ?? ''));
    $status       = trim($_GET['status']                  ?? 'active');
    $source       = trim($_GET['source']                  ?? '');
    $mtype        = trim($_GET['market_type']             ?? '');
    // odds_mode filter intentionally not exposed to users —
    // the pricing mechanism (lmsr vs fixed) is an internal detail.
    $inclResolved = (bool)(int)($_GET['include_resolved'] ?? 0);
    $inclArchived = (bool)(int)($_GET['include_archived'] ?? 0);
    $page         = max(1, (int)($_GET['page']            ?? 1));
    $limit        = min(100, max(1, (int)($_GET['limit']  ?? 20)));
    $offset       = ($page - 1) * $limit;

    $where = ['1=1']; $p = [];
    if ($cat)   { $where[] = 'LOWER(m.category)=:cat';   $p[':cat']    = $cat; }
    if ($source){ $where[] = 'm.source=:src';             $p[':src']    = $source; }
    if ($mtype) { $where[] = 'm.market_type=:mt';         $p[':mt']     = $mtype; }
    if (!$inclArchived) { $where[] = 'm.is_archived=0'; }

    if ($status === 'active') {
        // Exclude markets whose close_time has already passed — those are
        // effectively closed even if their DB status is still 'open'.
        $where[] = "m.status IN ('open','paused')";
        $where[] = "(m.close_time IS NULL OR m.close_time > NOW())";
    } elseif (in_array($status, ['open','paused','closed','resolved','voided'], true)) {
        $where[] = 'm.status=:status'; $p[':status'] = $status;
        // For 'open' filter, also exclude expired markets
        if ($status === 'open') {
            $where[] = "(m.close_time IS NULL OR m.close_time > NOW())";
        }
    } elseif (!$inclResolved) {
        $where[] = "m.status IN ('open','paused','closed')";
    }

    $sql = implode(' AND ', $where);
    $pdo = db();

    $cs = $pdo->prepare("SELECT COUNT(*) FROM markets m WHERE $sql");
    $cs->execute($p);
    $total = (int)$cs->fetchColumn();

    $ms = $pdo->prepare("SELECT m.id,m.market_id,m.source,m.question,m.category,m.market_type,
        m.odds_mode,m.status,m.close_time,m.pause_reason,m.total_bets,m.total_wagered,
        m.b,m.min_stake,m.max_stake,m.max_total_wagered,m.is_archived
        FROM markets m WHERE $sql ORDER BY m.created_at DESC LIMIT :lim OFFSET :off");
    foreach ($p as $k => $v) $ms->bindValue($k, $v);
    $ms->bindValue(':lim', $limit, PDO::PARAM_INT);
    $ms->bindValue(':off', $offset, PDO::PARAM_INT);
    $ms->execute();

    $oStmt      = $pdo->prepare('SELECT id,name,shares,fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id');
    $closeStmt  = $pdo->prepare("UPDATE markets SET status='closed' WHERE id=:mid AND status='open'");
    $data  = [];

    foreach ($ms->fetchAll() as $mkt) {
        // Enforce close_time strictly — if this open market's deadline has
        // passed, close it in the DB right now so future queries see 'closed'.
        if ($mkt['status'] === 'open'
            && $mkt['close_time']
            && strtotime($mkt['close_time']) <= time()) {
            $closeStmt->execute([':mid' => (int)$mkt['id']]);
            if ($closeStmt->rowCount() > 0) {
                audit_log('auto_closed', 0, 'market', (int)$mkt['id'], [
                    'market_id'  => $mkt['market_id'],
                    'close_time' => $mkt['close_time'],
                    'reason'     => 'close_time deadline reached — auto-closed on listing',
                ]);
            }
            $mkt['status'] = 'closed';
        }
        $oStmt->execute([':mid' => $mkt['id']]);
        $oddsMode = $mkt['odds_mode'] ?? 'lmsr';
        // odds_mode is used internally to choose the right pricing snapshot
        // but is intentionally omitted from the public response.
        $row = [
            'id'                => 'pm_' . $mkt['market_id'],
            'market_id'         => $mkt['market_id'],
            'source'            => $mkt['source'],
            'question'          => $mkt['question'],
            'category'          => $mkt['category'],
            'market_type'       => $mkt['market_type'],
            'total_wagered'     => (float)$mkt['total_wagered'],
            'total_bets'        => (int)$mkt['total_bets'],
            'status'            => $mkt['status'],
            'is_archived'       => (bool)$mkt['is_archived'],
            'close_time'        => $mkt['close_time'] ?? null,
            'min_stake'         => (float)$mkt['min_stake'],
            'max_stake'         => (float)$mkt['max_stake'],
            'max_total_wagered' => (float)$mkt['max_total_wagered'],
            // max_odds is intentionally omitted — internal house-protection detail,
            // not surfaced to users.
            'outcomes'          => market_snapshot($oStmt->fetchAll(), (float)$mkt['b'], $oddsMode),
        ];
        // pause_reason is intentionally omitted — internal admin detail,
        // not surfaced to users so they cannot see why odds were changed.
        $data[] = $row;
    }

    ok(['data' => $data, 'meta' => ['total' => $total, 'page' => $page,
        'limit' => $limit, 'pages' => (int)ceil($total / $limit)]]);
  } catch (Throwable $e) {
      internal_error($e, 'markets');
  }
})(),


// =============================================================
//  GET ?route=market&market_id=...
//  Public. Single market with live odds.
// =============================================================
$ROUTE === 'market' && $METHOD === 'GET' => (function () {
  try {
    $mid = trim($_GET['market_id'] ?? '');
    if (!$mid) fail('market_id param is required', 422);

    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id,market_id,source,question,category,market_type,odds_mode,status,
        close_time,resolve_time,total_bets,total_wagered,b,min_stake,max_stake,
        max_total_wagered,void_reason,pause_reason,is_archived
        FROM markets WHERE market_id=:mid LIMIT 1");
    $stmt->execute([':mid' => $mid]);
    $mkt = $stmt->fetch();
    if (!$mkt) fail('Market not found', 404);

    // Enforce close_time strictly — close the market in the DB the instant
    // its deadline is reached, even if no bet attempt has been made yet.
    if ($mkt['status'] === 'open'
        && $mkt['close_time']
        && strtotime($mkt['close_time']) <= time()) {
        $upd = $pdo->prepare("UPDATE markets SET status='closed' WHERE id=:mid AND status='open'");
        $upd->execute([':mid' => (int)$mkt['id']]);
        if ($upd->rowCount() > 0) {
            audit_log('auto_closed', 0, 'market', (int)$mkt['id'], [
                'market_id'  => $mkt['market_id'],
                'close_time' => $mkt['close_time'],
                'reason'     => 'close_time deadline reached — auto-closed on market fetch',
            ]);
        }
        $mkt['status'] = 'closed';
    }

    $oStmt = $pdo->prepare('SELECT id,name,shares,fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id');
    $oStmt->execute([':mid' => $mkt['id']]);
    $oddsMode = $mkt['odds_mode'] ?? 'lmsr';

    $resp = [
        'id'                => 'pm_' . $mkt['market_id'],
        'market_id'         => $mkt['market_id'],
        'source'            => $mkt['source'],
        'question'          => $mkt['question'],
        'category'          => $mkt['category'],
        'market_type'       => $mkt['market_type'],
        // odds_mode is used internally to choose the right pricing function
        // but is intentionally omitted from the public response so users
        // cannot distinguish LMSR from fixed-odds markets.
        'total_wagered'     => (float)$mkt['total_wagered'],
        'total_bets'        => (int)$mkt['total_bets'],
        'status'            => $mkt['status'],
        'is_archived'       => (bool)$mkt['is_archived'],
        'close_time'        => $mkt['close_time'] ?? null,
        'resolve_time'      => $mkt['resolve_time'] ?? null,
        'min_stake'         => (float)$mkt['min_stake'],
        'max_stake'         => (float)$mkt['max_stake'],
        'max_total_wagered' => (float)$mkt['max_total_wagered'],
        // max_odds intentionally omitted — internal house-protection detail,
        // not surfaced to users.
        'void_reason'       => $mkt['void_reason'],
        'outcomes'          => market_snapshot($oStmt->fetchAll(), (float)$mkt['b'], $oddsMode),
    ];
    // pause_reason intentionally omitted — internal admin detail,
    // not surfaced to users so they cannot see why odds were changed.
    ok($resp);
  } catch (Throwable $e) {
      internal_error($e, 'market');
  }
})(),


// =============================================================
//  GET ?route=market_history&market_id=...
//  Public. Time-series snapshots for graph rendering.
//  Params: outcome_id (optional), limit (10-500, default 200)
//  Returns: ASC order — continuous timeline, one point per bet.
// =============================================================
$ROUTE === 'market_history' && $METHOD === 'GET' => (function () {
  try {
    $mid   = trim($_GET['market_id'] ?? '');
    $oid   = (int)($_GET['outcome_id'] ?? 0);
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
    if (!$mid) fail('market_id param is required', 422);

    $pdo = db();
    $m   = $pdo->prepare('SELECT id FROM markets WHERE market_id=:mid LIMIT 1');
    $m->execute([':mid' => $mid]);
    $m = $m->fetch();
    if (!$m) fail('Market not found', 404);

    $where = ['s.market_id=:dbid']; $p = [':dbid' => (int)$m['id']];
    if ($oid) { $where[] = 's.outcome_id=:oid'; $p[':oid'] = $oid; }

    $stmt = $pdo->prepare("SELECT s.outcome_id, o.name AS outcome_name,
        s.probability, s.odds, s.shares, s.volume, s.created_at AS recorded_at
        FROM market_snapshots s JOIN outcomes o ON o.id=s.outcome_id
        WHERE " . implode(' AND ', $where) . " ORDER BY s.created_at ASC LIMIT :lim");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $grouped = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = (int)$r['outcome_id'];
        if (!isset($grouped[$key]))
            $grouped[$key] = ['outcome_id' => $key, 'outcome_name' => $r['outcome_name'], 'series' => []];
        $grouped[$key]['series'][] = [
            'time'   => $r['recorded_at'],
            'odds'   => (float)$r['odds'],
            // 'shares' intentionally omitted — internal pricing detail;
            // its value would reveal the odds mode (LMSR vs fixed) to users.
            'volume' => (float)$r['volume'],
        ];
    }

    ok(['market_id' => $mid, 'outcomes' => array_values($grouped),
        'count' => array_sum(array_map(fn($g) => count($g['series']), $grouped))]);
  } catch (Throwable $e) {
      internal_error($e, 'market_history');
  }
})(),


// =============================================================
//  POST ?route=bet
//  Authorization: Bearer <token>
//  Body: { "market_id": "...", "outcome_id": 3, "amount": 500 }
//
//  AUTO-CLOSE LOGIC:
//  Before the bet transaction, a separate short transaction checks
//  if close_time has passed. If so, the market is set to 'closed'
//  and committed independently, then the bet is rejected. This
//  ensures the auto-close persists even though the bet fails.
// =============================================================
$ROUTE === 'bet' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx    = require_auth();
    $userId = $ctx['user_id'];

    require_fields($BODY, ['market_id', 'outcome_id', 'amount']);
    $extMid    = trim($BODY['market_id']);
    $outcomeId = (int)$BODY['outcome_id'];
    $amount    = (float)$BODY['amount'];
    if ($amount <= 0) fail('Amount must be greater than 0', 422);

    $pdo = db();

    // ── Pre-bet auto-close (separate transaction) ─────────
    // Commits independently so the close persists even when
    // the bet itself is rejected.
    $pdo->beginTransaction();
    $acStmt = $pdo->prepare('SELECT id, status, close_time, market_id
        FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
    $acStmt->execute([':mid' => $extMid]);
    $acRow = $acStmt->fetch();
    if ($acRow && $acRow['status'] === 'open'
        && $acRow['close_time']
        && strtotime($acRow['close_time']) < time()) {
        $pdo->prepare("UPDATE markets SET status='closed' WHERE id=:mid")
            ->execute([':mid' => (int)$acRow['id']]);
        $pdo->commit();
        audit_log('auto_closed', 0, 'market', (int)$acRow['id'], [
            'market_id'  => $acRow['market_id'],
            'close_time' => $acRow['close_time'],
            'reason'     => 'close_time deadline reached — auto-closed by system',
        ]);
        fail('Market has closed. Please wait for settlement.', 409);
    }
    $pdo->rollBack(); // release lock, no changes needed

    // ── Main bet transaction ──────────────────────────────
    // Variables populated inside try, used in post-commit block
    $slipId = $stake = $b = $dbId = $newTotal = $balanceBefore = null;
    $rows = $sv = []; $idx = null; $sharesBought = 0.0;

    $pdo->beginTransaction();

    try {
        // Lock market row — prevents concurrent state changes
        $mkt = $pdo->prepare('SELECT id,status,b,min_stake,max_stake,max_total_wagered,max_odds,
            total_wagered,close_time,pause_reason,market_id,odds_mode
            FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]);
        $mkt = $mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');

        // ── Status rejection ──────────────────────────────
        // NOTE: pause_reason is deliberately NOT echoed back — it is an
        // internal admin note that must not be visible to users.
        match ($mkt['status']) {
            'paused'   => throw new RuntimeException('Market temporarily unavailable. Please try again later.|503'),
            'closed'   => throw new RuntimeException('Market has closed. Please wait for settlement.|409'),
            'resolved' => throw new RuntimeException('Market has already been settled.|409'),
            'voided'   => throw new RuntimeException('Market has been voided.|409'),
            'open'     => null,
            default    => throw new RuntimeException('Market unavailable|409'),
        };

        // ── Stake range ───────────────────────────────────
        $minStake = (float)$mkt['min_stake'];
        $maxStake = (float)$mkt['max_stake'];
        if ($amount < $minStake)
            throw new RuntimeException('Minimum stake not met. Minimum is KES '
                . number_format($minStake, 2) . '|422');
        if ($amount > $maxStake)
            throw new RuntimeException('Maximum stake exceeded. Maximum is KES '
                . number_format($maxStake, 2) . '|422');

        $dbId = (int)$mkt['id'];
        $b    = (float)$mkt['b'];

        // ── Lock outcome rows ─────────────────────────────
        $oStmt = $pdo->prepare('SELECT id,name,shares,fixed_odds FROM outcomes
            WHERE market_id=:mid ORDER BY id FOR UPDATE');
        $oStmt->execute([':mid' => $dbId]);
        $rows = $oStmt->fetchAll();

        $idx = null;
        foreach ($rows as $i => $r) { if ((int)$r['id'] === $outcomeId) { $idx = $i; break; } }
        if ($idx === null) throw new RuntimeException('Outcome not found in this market|404');

        // ── Pricing — LMSR or Fixed ────────────────────────
        $oddsMode = $mkt['odds_mode'] ?? 'lmsr';
        $sv       = array_map(fn($r) => (float)$r['shares'], $rows);

        if ($oddsMode === 'fixed') {
            // Fixed mode: use admin-set odds directly, stake = amount exactly
            $fo = (float)($rows[$idx]['fixed_odds'] ?? 0);
            if ($fo <= 1.0) throw new RuntimeException(
                'This outcome is not available for betting. Contact support.|409');
            $oddsAtEntry  = round($fo, 2);
            $stake        = round($amount, 2);
            $sharesBought = 0.0;                         // shares never move in fixed mode
            $probAtEntry  = round(1.0 / $fo, 4);
            $probAfter    = $probAtEntry;                // unchanged
            $possibleWin  = round($stake * $oddsAtEntry, 2);
        } else {
            // LMSR mode: cost-function pricing
            $probAtEntry  = lmsr_probs($sv, $b)[$idx];
            $oddsAtEntry  = $probAtEntry > 0 ? round(1.0 / $probAtEntry, 2) : 999.0;

            // ── Max-odds cap (LMSR only) ───────────────────
            // Protects the house from extreme payouts on thinly-backed outcomes.
            // The cap value and live odds are deliberately NOT included in the
            // error message — they are internal pricing details users should not see.
            $maxOddsCap = (float)($mkt['max_odds'] ?? LMSR_MAX_ODDS);
            if ($maxOddsCap > 0 && $oddsAtEntry > $maxOddsCap) {
                throw new RuntimeException(
                    'This outcome is not currently available for betting. '
                    . 'Try another outcome or check back later.|409');
            }

            $sharesBought = lmsr_shares_for($sv, $b, $idx, $amount);
            $stake        = round(lmsr_buy_cost($sv, $b, $idx, $sharesBought), 2);
            $svNew        = $sv; $svNew[$idx] += $sharesBought;
            $probAfter    = lmsr_probs($svNew, $b)[$idx];
            $possibleWin  = round($stake * $oddsAtEntry, 2);
        }

        // ── Wager cap check vs exact $stake (not $amount) ─
        $maxTotalWag = (float)$mkt['max_total_wagered'];
        if ($maxTotalWag > 0) {
            $remaining = $maxTotalWag - (float)$mkt['total_wagered'];
            if ($stake > $remaining)
                throw new RuntimeException('Market wager cap reached. Remaining: KES '
                    . number_format(max(0.0, $remaining), 2) . '|409');
        }

        // ── Fetch balance for accurate ledger ─────────────
        $balRow = $pdo->prepare('SELECT balance FROM users WHERE id=:uid');
        $balRow->execute([':uid' => $userId]);
        $balanceBefore = (float)$balRow->fetchColumn();

        // ── Atomic deduction ──────────────────────────────
        $deduct = $pdo->prepare('UPDATE users
            SET balance=balance-:stake, locked_balance=locked_balance+:lock,
                total_wagered=total_wagered+:tw
            WHERE id=:uid AND balance>=:check');
        $deduct->execute([':stake' => $stake, ':lock' => $stake,
                          ':tw' => $stake, ':uid' => $userId, ':check' => $stake]);
        if ($deduct->rowCount() === 0) throw new RuntimeException('Insufficient balance|402');

        // ── Update LMSR shares (skipped in fixed-odds mode) ──
        if ($oddsMode === 'lmsr') {
            $pdo->prepare('UPDATE outcomes SET shares=shares+:s WHERE id=:oid')
                ->execute([':s' => $sharesBought, ':oid' => $outcomeId]);
        }
        // fixed mode: sharesBought is already 0.0, no DB update needed

        // ── Update market counters ────────────────────────
        $newTotal = (float)$mkt['total_wagered'] + $stake;
        $pdo->prepare('UPDATE markets SET total_bets=total_bets+1,
            total_wagered=total_wagered+:stake WHERE id=:mid')
            ->execute([':stake' => $stake, ':mid' => $dbId]);

        // ── Insert bet record ─────────────────────────────
        $slipId    = new_slip_id();
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_TTL);

        $pdo->prepare("INSERT INTO bets
            (slip_id,user_id,market_id,outcome_id,shares,stake,odds_at_entry,
             possible_win,payout,prob_before,prob_after,status,expires_at)
            VALUES (:sid,:u,:m,:o,:sh,:stake,:odds,:pw,NULL,:pb,:pa,'open',:exp)")
            ->execute([':sid' => $slipId, ':u' => $userId, ':m' => $dbId,
                       ':o' => $outcomeId, ':sh' => $sharesBought,
                       ':stake' => $stake, ':odds' => $oddsAtEntry, ':pw' => $possibleWin,
                       ':pb' => $probAtEntry, ':pa' => $probAfter, ':exp' => $expiresAt]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) {
            [$msg, $code] = explode('|', $e->getMessage(), 2);
            fail($msg, (int)$code);
        }
        internal_error($e, 'bet');
    }

    // ── Post-commit: snapshot + ledger ────────────────
    // Runs only on success (try completed without throw).
    // Outside try/catch so rollBack() is never called on
    // a committed transaction.
    $rowsAfter                 = $rows;
    $rowsAfter[$idx]['shares'] = $sv[$idx] + $sharesBought;
    record_market_snapshot($dbId, $rowsAfter, $b, $newTotal);
    record_balance_tx($userId, 'bet_placed', -$stake, $balanceBefore,
        $balanceBefore - $stake, $slipId, $dbId, "Bet on '{$rows[$idx]['name']}'");

    ok(['slip_id'      => $slipId,
        'market_id'    => $extMid,
        'outcome'      => $rows[$idx]['name'],
        'stake'        => $stake,
        'odds'         => $oddsAtEntry,
        'possible_win' => $possibleWin,
        'status'       => 'open',
        'expires_at'   => $expiresAt],
        'Bet placed successfully');
})(),


// =============================================================
//  GET ?route=my_bets
//  Authorization: Bearer <token>
//  Params: status (open|won|lost|void), history=1, page, limit
//  Default: open bets + last 30 days. history=1 = full ledger.
// =============================================================
$ROUTE === 'my_bets' && $METHOD === 'GET' => (function () {
  try {
    $ctx     = require_auth();
    $userId  = $ctx['user_id'];
    $status  = trim($_GET['status']  ?? '');
    $history = (bool)(int)($_GET['history'] ?? 0);
    $page    = max(1, (int)($_GET['page']   ?? 1));
    $limit   = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset  = ($page - 1) * $limit;

    $where = ['b.user_id=:uid']; $p = [':uid' => $userId];

    if (in_array($status, ['open','won','lost','void'], true)) {
        $where[] = 'b.status=:status'; $p[':status'] = $status;
    } elseif (!$history) {
        $since   = date('Y-m-d H:i:s', strtotime('-30 days'));
        $where[] = "(b.status='open' OR b.created_at>=:since)";
        $p[':since'] = $since;
    }

    $sql = implode(' AND ', $where);
    $pdo = db();

    $cs = $pdo->prepare("SELECT COUNT(*) FROM bets b WHERE $sql");
    $cs->execute($p);
    $total = (int)$cs->fetchColumn();

    $stmt = $pdo->prepare("SELECT b.slip_id,b.stake,b.odds_at_entry,b.possible_win,b.payout,
        b.void_reason,b.status,b.expires_at,b.created_at,
        m.question,m.market_id,m.status AS market_status,o.name AS outcome_name
        FROM bets b JOIN markets m ON m.id=b.market_id JOIN outcomes o ON o.id=b.outcome_id
        WHERE $sql ORDER BY b.created_at DESC LIMIT :lim OFFSET :off");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $bets = array_map(fn($r) => [
        'slip_id'       => $r['slip_id'],
        'market_id'     => $r['market_id'],
        'question'      => $r['question'],
        'outcome'       => $r['outcome_name'],
        'stake'         => (float)$r['stake'],
        'odds'          => (float)$r['odds_at_entry'],
        'possible_win'  => (float)$r['possible_win'],
        'payout'        => $r['payout'] !== null ? (float)$r['payout'] : null,
        'void_reason'   => $r['void_reason'],
        'status'        => $r['status'],
        'market_status' => $r['market_status'],
        'expires_at'    => $r['expires_at'],
        'placed_at'     => $r['created_at'],
    ], $stmt->fetchAll());

    ok(['data' => $bets, 'meta' => ['total' => $total, 'page' => $page,
        'limit' => $limit, 'pages' => (int)ceil($total / $limit)]]);
  } catch (Throwable $e) {
      internal_error($e, 'my_bets');
  }
})(),


// =============================================================
//  POST ?route=admin_create_market
//  Authorization: Bearer <admin_token>
//  Body: see curl docs for full field list.
//  b validated: 50–10000. Initial snapshot recorded at creation.
// =============================================================
$ROUTE === 'admin_create_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['question', 'category', 'outcomes']);
    $question    = trim($BODY['question']);
    $category    = strtolower(trim($BODY['category']));
    $source      = trim($BODY['source']               ?? 'local');
    $closeTime   = trim($BODY['close_time']           ?? '');
    $customId    = trim($BODY['market_id']            ?? '');
    $b           = (float)($BODY['b']                 ?? LMSR_B);
    $minStake    = (float)($BODY['min_stake']          ?? 10);
    $maxStake    = (float)($BODY['max_stake']          ?? 100000);
    $maxTotalWag = (float)($BODY['max_total_wagered']  ?? 0);
    $marketType  = trim($BODY['market_type']           ?? 'binary');
    $outcomes    = $BODY['outcomes']                   ?? [];
    $oddsMode    = trim($BODY['odds_mode']             ?? 'lmsr');
    // max_odds: LMSR only — reject bets when live odds exceed this value.
    // Default 10.0 means no outcome can be bet above 10× return.
    // Fixed markets ignore this (admin controls exact odds directly).
    $maxOdds     = (float)($BODY['max_odds']           ?? LMSR_MAX_ODDS);

    $errors = [];
    if (!in_array($marketType, ['binary','categorical'], true)) $errors[] = 'market_type must be binary or categorical';
    if (!in_array($oddsMode,   ['lmsr','fixed'],         true)) $errors[] = 'odds_mode must be lmsr or fixed';
    if ($marketType === 'binary' && count($outcomes) !== 2)     $errors[] = 'binary markets must have exactly 2 outcomes';
    if (count($outcomes) < 2)   $errors[] = 'at least 2 outcomes required';
    if (count($outcomes) > 20)  $errors[] = 'max 20 outcomes allowed';
    // b range only enforced in LMSR mode — in fixed mode b is stored but not used for live pricing
    if ($oddsMode === 'lmsr') {
        if ($b < LMSR_B_MIN) $errors[] = "b must be >= " . LMSR_B_MIN . " (production minimum)";
        if ($b > LMSR_B_MAX) $errors[] = "b must be <= " . LMSR_B_MAX . " (production maximum)";
    }
    if ($minStake < 1)           $errors[] = 'min_stake must be >= 1';
    if ($maxStake <= $minStake)  $errors[] = 'max_stake must be > min_stake';
    if ($maxTotalWag < 0)        $errors[] = 'max_total_wagered must be >= 0';
    if ($closeTime && !strtotime($closeTime)) $errors[] = 'invalid close_time — use Y-m-d H:i:s';
    if ($oddsMode === 'lmsr') {
        if ($maxOdds < 1.5)  $errors[] = 'max_odds must be >= 1.5';
        if ($maxOdds > 50.0) $errors[] = 'max_odds must be <= 50.0 — use fixed mode for higher odds';
    }

    $seenNames = [];
    foreach ($outcomes as $i => $o) {
        $name = trim($o['name'] ?? '');
        if (!$name) { $errors[] = "outcome[$i]: name required"; continue; }
        $key  = strtolower($name);
        if (in_array($key, $seenNames, true)) $errors[] = "Duplicate outcome name: $name";
        $seenNames[] = $key;

        if ($oddsMode === 'fixed') {
            // Fixed mode: each outcome must have decimal odds > 1.0
            if (!isset($o['odds'])) {
                $errors[] = "outcome[$i] '$name': 'odds' required (e.g. 1.85 means a 1.85× return).";
            } elseif ((float)$o['odds'] <= 1.0) {
                $errors[] = "outcome[$i] '$name': odds must be > 1.0 (e.g. 2.50). Got: {$o['odds']}";
            }
        } else {
            // LMSR mode: decimal odds > 1.0 required for every outcome
            if (!isset($o['odds'])) {
                $errors[] = "outcome[$i] '$name': 'odds' required (e.g. 2.00 means 2× return).";
            } elseif ((float)$o['odds'] <= 1.0) {
                $errors[] = "outcome[$i] '$name': odds must be > 1.0 (EU decimal format, e.g. 2.00). Got: {$o['odds']}";
            }
        }
    }
    if ($errors) fail('Validation failed', 422, $errors);

    $normProbs = normalize_probs($outcomes);
    $sharesVec = seed_shares($normProbs, $b);
    $mid       = $customId ?: new_market_id();

    $pdo = db();

    // ── Validate custom market_id uniqueness ──────────
    if ($customId) {
        $chk = $pdo->prepare('SELECT 1 FROM markets WHERE market_id=:mid LIMIT 1');
        $chk->execute([':mid' => $customId]);
        if ($chk->fetch()) fail('market_id already exists', 409);
    }

    // ── Warn about risky LMSR config ──────────────────
    $warnings = [];
    if ($oddsMode === 'lmsr' && $b < 500 && $maxTotalWag == 0) {
        $warnings[] = 'Low b ('.(int)$b.') with no wager cap — prices will be volatile. Consider setting max_total_wagered or using b >= 1000.';
    }

    // ── Warn when LMSR normalises away the bookmaker margin ───────────────
    // LMSR requires implied probabilities to sum to exactly 1.0 (fair odds).
    // If the admin-supplied odds carry overround (e.g. 1.80 + 1.67 implies
    // 0.556 + 0.599 = 1.154, not 1.0), normalize_probs() strips that margin
    // and the opening odds will differ from what was requested.
    // We detect this and tell the admin exactly what changed.
    if ($oddsMode === 'lmsr') {
        $rawSum = array_sum(array_map(fn($o) => 1.0 / max(1.0001, (float)$o['odds']), $outcomes));
        if (abs($rawSum - 1.0) > 0.001) {
            $margin = round(($rawSum - 1.0) * 100, 2);
            $adjPairs = [];
            foreach ($outcomes as $i => $o) {
                $req = (float)$o['odds'];
                $act = $normProbs[$i] > 0 ? round(1.0 / $normProbs[$i], 2) : 999.0;
                $adjPairs[] = trim($o['name']) . ": requested {$req} → actual {$act}";
            }
            $warnings[] = "LMSR markets use fair (margin-free) odds. Your input had a bookmaker "
                . "margin of {$margin}% which has been removed. Actual opening odds: "
                . implode(', ', $adjPairs) . ". "
                . "Use odds_mode=fixed if you need exact admin-set odds with no adjustment.";
        }
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO markets
            (market_id,source,question,category,market_type,odds_mode,status,close_time,b,
             min_stake,max_stake,max_total_wagered,max_odds,created_by)
            VALUES (:mid,:src,:q,:cat,:mt,:om,'open',:ct,:b,:mn,:mx,:maxw,:maxodds,:uid)")
            ->execute([':mid' => $mid, ':src' => $source, ':q' => $question,
                       ':cat' => $category, ':mt' => $marketType, ':om' => $oddsMode,
                       ':ct' => $closeTime ?: null, ':b' => $b,
                       ':mn' => $minStake, ':mx' => $maxStake,
                       ':maxw' => $maxTotalWag, ':maxodds' => ($oddsMode === 'lmsr' ? $maxOdds : null),
                       ':uid' => $adminId]);
        $dbId  = (int)$pdo->lastInsertId();
        // Store fixed_odds for fixed mode; NULL for LMSR
        $oStmt = $pdo->prepare('INSERT INTO outcomes (market_id,name,shares,fixed_odds) VALUES (:mid,:name,:shares,:fo)');
        foreach ($outcomes as $i => $o) {
            $fo = ($oddsMode === 'fixed') ? round((float)($o['odds'] ?? 0), 2) : null;
            $oStmt->execute([':mid' => $dbId, ':name' => trim($o['name']),
                             ':shares' => $sharesVec[$i], ':fo' => $fo]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[MwasinMarket] admin_create_market: ' . $e->getMessage());
        fail('Failed to create market.', 500);
    }

    audit_log('create_market', $adminId, 'market', $dbId,
        ['market_id' => $mid, 'question' => $question, 'b' => $b, 'odds_mode' => $oddsMode]);

    $rows = $pdo->prepare('SELECT id,name,shares,fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id');
    $rows->execute([':mid' => $dbId]);
    $outcomeRows = $rows->fetchAll();

    // Initial snapshot — gives the graph a t=0 opening price baseline.
    // Fixed-mode markets write probabilities from fixed_odds (not LMSR shares)
    // so the graph baseline matches what users see at the market listing.
    if ($oddsMode === 'fixed') {
        try {
            $sStmt = $pdo->prepare("INSERT INTO market_snapshots
                (market_id,outcome_id,probability,odds,shares,volume)
                VALUES (:mid,:oid,:p,:o,:s,0.00)");
            foreach ($outcomeRows as $r) {
                $fo = (float)($r['fixed_odds'] ?? 0);
                $fp = $fo > 1.0 ? round(1.0 / $fo, 6) : 0.0;
                $sStmt->execute([':mid' => $dbId, ':oid' => (int)$r['id'],
                    ':p' => $fp, ':o' => round($fo, 2),
                    ':s' => round((float)$r['shares'], 8)]);
            }
        } catch (Throwable $e) {
            error_log('[MwasinMarket] admin_create_market fixed snapshot: ' . $e->getMessage());
        }
    } else {
        record_market_snapshot($dbId, $outcomeRows, $b, 0.0);
    }

    ok(['market_id'         => $mid,
        'question'          => $question,
        'category'          => $category,
        'market_type'       => $marketType,
        'odds_mode'         => $oddsMode,
        'status'            => 'open',
        'close_time'        => $closeTime ?: null,
        'b'                 => $b,
        'min_stake'         => $minStake,
        'max_stake'         => $maxStake,
        'max_total_wagered' => $maxTotalWag,
        'max_odds'          => $oddsMode === 'lmsr' ? $maxOdds : null,
        'outcomes'          => market_snapshot($outcomeRows, $b, $oddsMode)]
        + ($warnings ? ['warnings' => $warnings] : []),
        'Market created successfully', 201);
})(),


// =============================================================
//  POST ?route=admin_pause_market
//  Body: { "market_id": "...", "pause_reason": "..." }
// =============================================================
$ROUTE === 'admin_pause_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid      = trim($BODY['market_id']);
    $pauseReason = trim($BODY['pause_reason'] ?? 'Paused by admin');

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]); $mkt = $mkt->fetch();
        if (!$mkt)                    throw new RuntimeException('Market not found|404');
        if ($mkt['status'] !== 'open') throw new RuntimeException(
            "Only open markets can be paused. Current: {$mkt['status']}|409");
        $pdo->prepare("UPDATE markets SET status='paused',pause_reason=:r WHERE id=:mid")
            ->execute([':r' => $pauseReason, ':mid' => (int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_pause_market');
    }
    audit_log('pause_market', $adminId, 'market', (int)$mkt['id'],
        ['market_id' => $extMid, 'pause_reason' => $pauseReason]);
    ok(['market_id' => $extMid, 'status' => 'paused', 'pause_reason' => $pauseReason], 'Market paused');
})(),


// =============================================================
//  POST ?route=admin_resume_market
//  Body: { "market_id": "..." }
// =============================================================
$ROUTE === 'admin_resume_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid = trim($BODY['market_id']);

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status,pause_reason FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]); $mkt = $mkt->fetch();
        if (!$mkt)                       throw new RuntimeException('Market not found|404');
        if ($mkt['status'] !== 'paused') throw new RuntimeException(
            "Only paused markets can be resumed. Current: {$mkt['status']}|409");
        $pdo->prepare("UPDATE markets SET status='open',pause_reason=NULL WHERE id=:mid")
            ->execute([':mid' => (int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_resume_market');
    }
    audit_log('resume_market', $adminId, 'market', (int)$mkt['id'],
        ['market_id' => $extMid, 'previous_pause_reason' => $mkt['pause_reason']]);
    ok(['market_id' => $extMid, 'status' => 'open'], 'Market resumed');
})(),


// =============================================================
//  POST ?route=admin_force_close_market
//  Body: { "market_id": "...", "reason": "Event ended" }
//  Stops bets. Market stays in DB. Admin can settle anytime.
// =============================================================
$ROUTE === 'admin_force_close_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid = trim($BODY['market_id']);
    $reason = trim($BODY['reason'] ?? 'Closed by admin pending settlement');

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]); $mkt = $mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        if (in_array($mkt['status'], ['resolved','voided'], true))
            throw new RuntimeException("Cannot close a {$mkt['status']} market.|409");
        if ($mkt['status'] === 'closed') throw new RuntimeException('Market is already closed|409');
        $pdo->prepare("UPDATE markets SET status='closed' WHERE id=:mid")
            ->execute([':mid' => (int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_force_close_market');
    }
    audit_log('force_close_market', $adminId, 'market', (int)$mkt['id'],
        ['market_id' => $extMid, 'reason' => $reason, 'previous_status' => $mkt['status']]);
    ok(['market_id' => $extMid, 'status' => 'closed', 'reason' => $reason], 'Market closed');
})(),


// =============================================================
//  POST ?route=admin_settle_market
//  Authorization: Bearer <admin_token>
//  Body: { "market_id": "...", "winning_outcome_id": 3 }
//
//  Settles ANY non-terminal market: open, paused, closed,
//  or auto-closed. Admin can call this at any time after
//  the event result is known — no prior force-close needed.
//
//  Settlement is FINAL. Status → resolved. resolve_time set.
//  Won:  locked -= stake | balance += possible_win | payout = possible_win
//  Lost: locked -= stake | house keeps stake       | payout = 0
// =============================================================
$ROUTE === 'admin_settle_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['market_id', 'winning_outcome_id']);
    $extMid   = trim($BODY['market_id']);
    $winOutId = (int)$BODY['winning_outcome_id'];

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $mkt = $pdo->prepare('SELECT id,status FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]); $mkt = $mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');

        // Can settle from open, paused, closed (including auto-closed)
        // Cannot settle already resolved or voided
        if ($mkt['status'] === 'resolved')
            throw new RuntimeException('Market is already resolved.|409');
        if ($mkt['status'] === 'voided')
            throw new RuntimeException('Cannot settle a voided market.|409');

        $dbId = (int)$mkt['id'];

        $oc = $pdo->prepare('SELECT id,name FROM outcomes WHERE id=:oid AND market_id=:mid LIMIT 1');
        $oc->execute([':oid' => $winOutId, ':mid' => $dbId]);
        $winner = $oc->fetch();
        if (!$winner) throw new RuntimeException('Winning outcome not found in this market|404');

        $allBets = $pdo->prepare('SELECT id,user_id,outcome_id,stake,possible_win,slip_id
            FROM bets WHERE market_id=:mid AND status="open"');
        $allBets->execute([':mid' => $dbId]);
        $bets = $allBets->fetchAll();

        $affectedIds    = array_unique(array_column($bets, 'user_id'));
        $runningBalance = fetch_balances($affectedIds, $pdo);
        $totalPayout    = 0.0; $wonCount = 0; $lostCount = 0; $txLog = [];

        foreach ($bets as $bet) {
            $uid       = (int)$bet['user_id'];
            $stake     = (float)$bet['stake'];
            $balBefore = $runningBalance[$uid] ?? 0.0;

            if ((int)$bet['outcome_id'] === $winOutId) {
                $payout = (float)$bet['possible_win'];
                $pdo->prepare('UPDATE users SET locked_balance=locked_balance-:stake,
                    balance=balance+:payout, total_wins=total_wins+:tw WHERE id=:uid')
                    ->execute([':stake'=>$stake,':payout'=>$payout,':tw'=>$payout,':uid'=>$uid]);
                $pdo->prepare('UPDATE bets SET status="won",payout=:p WHERE id=:id')
                    ->execute([':p'=>$payout,':id'=>$bet['id']]);
                $balAfter = $balBefore + $payout;
                $runningBalance[$uid] = $balAfter;
                $txLog[] = ['uid'=>$uid,'type'=>'bet_won','amount'=>$payout,
                            'ref'=>$bet['slip_id'],'before'=>$balBefore,'after'=>$balAfter];
                $totalPayout += $payout; $wonCount++;
            } else {
                $pdo->prepare('UPDATE users SET locked_balance=locked_balance-:stake WHERE id=:uid')
                    ->execute([':stake'=>$stake,':uid'=>$uid]);
                $pdo->prepare('UPDATE bets SET status="lost",payout=0 WHERE id=:id')
                    ->execute([':id'=>$bet['id']]);
                $txLog[] = ['uid'=>$uid,'type'=>'bet_lost','amount'=>0.0,
                            'ref'=>$bet['slip_id'],'before'=>$balBefore,'after'=>$balBefore];
                $lostCount++;
            }
        }

        $pdo->prepare('UPDATE markets SET status="resolved",resolve_time=NOW() WHERE id=:mid')
            ->execute([':mid' => $dbId]);
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_settle_market');
    }

    audit_log('settle_market', $adminId, 'market', $dbId,
        ['market_id'=>$extMid,'winning_outcome'=>$winner['name'],
         'bets_won'=>$wonCount,'bets_lost'=>$lostCount,'total_payout'=>round($totalPayout,2)]);
    foreach ($txLog as $tx) {
        record_balance_tx($tx['uid'],$tx['type'],$tx['amount'],
            $tx['before'],$tx['after'],$tx['ref'],$dbId);
    }

    ok(['market_id'       => $extMid,
        'winning_outcome' => $winner['name'],
        'bets_won'        => $wonCount,
        'bets_lost'       => $lostCount,
        'total_payout'    => round($totalPayout, 2)],
        'Market settled successfully');
})(),


// =============================================================
//  POST ?route=admin_void_market
//  Body: { "market_id": "...", "reason": "..." }
//  Full stake refund. LMSR shares reversed. Permanent.
// =============================================================
$ROUTE === 'admin_void_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid = trim($BODY['market_id']);
    $reason = trim($BODY['reason'] ?? 'Voided by admin');

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid'=>$extMid]); $mkt=$mkt->fetch();
        if (!$mkt)                         throw new RuntimeException('Market not found|404');
        if ($mkt['status'] === 'resolved') throw new RuntimeException('Cannot void a resolved market|409');
        if ($mkt['status'] === 'voided')   throw new RuntimeException('Market already voided|409');

        $dbId = (int)$mkt['id'];

        $oStmt = $pdo->prepare('SELECT id,name,shares FROM outcomes WHERE market_id=:mid ORDER BY id FOR UPDATE');
        $oStmt->execute([':mid'=>$dbId]);
        $outcomeRows = $oStmt->fetchAll();

        $allBets = $pdo->prepare('SELECT id,user_id,stake,shares AS bet_shares,outcome_id,slip_id
            FROM bets WHERE market_id=:mid AND status="open"');
        $allBets->execute([':mid'=>$dbId]);
        $bets = $allBets->fetchAll();

        $affectedIds     = array_unique(array_column($bets, 'user_id'));
        $runningBalance  = fetch_balances($affectedIds, $pdo);
        $sharesToReverse = array_fill_keys(array_column($outcomeRows,'id'), 0.0);
        $totalRefunded   = 0.0; $txLog = [];

        foreach ($bets as $bet) {
            $uid       = (int)$bet['user_id'];
            $stake     = (float)$bet['stake'];
            $betShares = (float)$bet['bet_shares'];
            $outId     = (int)$bet['outcome_id'];
            $balBefore = $runningBalance[$uid] ?? 0.0;

            $pdo->prepare('UPDATE users SET locked_balance=locked_balance-:stake,
                balance=balance+:refund WHERE id=:uid')
                ->execute([':stake'=>$stake,':refund'=>$stake,':uid'=>$uid]);
            $pdo->prepare("UPDATE bets SET status='void',payout=:p,void_reason=:r WHERE id=:id")
                ->execute([':p'=>$stake,':r'=>$reason,':id'=>$bet['id']]);

            $balAfter=$balBefore+$stake; $runningBalance[$uid]=$balAfter;
            if (isset($sharesToReverse[$outId])) $sharesToReverse[$outId]+=$betShares;
            $txLog[] = ['uid'=>$uid,'stake'=>$stake,'ref'=>$bet['slip_id'],
                        'before'=>$balBefore,'after'=>$balAfter];
            $totalRefunded += $stake;
        }

        foreach ($sharesToReverse as $outId => $delta) {
            if ($delta > 0)
                $pdo->prepare('UPDATE outcomes SET shares=GREATEST(0,shares-:s) WHERE id=:oid')
                    ->execute([':s'=>$delta,':oid'=>$outId]);
        }

        $pdo->prepare("UPDATE markets SET status='voided',void_reason=:r,
            total_wagered=GREATEST(0,total_wagered-:tw),total_bets=GREATEST(0,total_bets-:tb)
            WHERE id=:mid")
            ->execute([':r'=>$reason,':tw'=>$totalRefunded,':tb'=>count($bets),':mid'=>$dbId]);
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_void_market');
    }

    audit_log('void_market',$adminId,'market',$dbId,
        ['market_id'=>$extMid,'void_reason'=>$reason,
         'bets_refunded'=>count($bets),'total_refunded'=>round($totalRefunded,2)]);
    foreach ($txLog as $tx)
        record_balance_tx($tx['uid'],'bet_voided',$tx['stake'],$tx['before'],$tx['after'],$tx['ref'],$dbId,$reason);

    ok(['market_id'=>$extMid,'reason'=>$reason,
        'bets_refunded'=>count($bets),'total_refunded'=>round($totalRefunded,2)],
        'Market voided and stakes refunded');
})(),


// =============================================================
//  POST ?route=admin_void_bet
//  Body: { "slip_id": "BET_A1B2C3D4", "reason": "..." }
//  Revoke a single OPEN bet. LMSR shares reversed.
//  Settled bets cannot be revoked.
// =============================================================
$ROUTE === 'admin_void_bet' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['slip_id']);
    $slipId = trim($BODY['slip_id']);
    $reason = trim($BODY['reason'] ?? 'Revoked by admin');

    $pdo = db(); $pdo->beginTransaction();
    try {
        $bet = $pdo->prepare('SELECT id,user_id,market_id,outcome_id,stake,shares,status,slip_id
            FROM bets WHERE slip_id=:sid LIMIT 1 FOR UPDATE');
        $bet->execute([':sid'=>$slipId]); $bet=$bet->fetch();
        if (!$bet) throw new RuntimeException('Bet slip not found|404');
        if ($bet['status'] !== 'open')
            throw new RuntimeException("Only open bets can be revoked. Status: {$bet['status']}|409");

        $userId    = (int)$bet['user_id'];
        $dbMktId   = (int)$bet['market_id'];
        $outcomeId = (int)$bet['outcome_id'];
        $stake     = (float)$bet['stake'];
        $betShares = (float)$bet['shares'];

        $mkt = $pdo->prepare('SELECT id,status,b,market_id,odds_mode FROM markets WHERE id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid'=>$dbMktId]); $mkt=$mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|500');
        if (in_array($mkt['status'],['resolved','voided'],true))
            throw new RuntimeException("Cannot revoke a bet on a {$mkt['status']} market.|409");

        $oc = $pdo->prepare('SELECT id,name FROM outcomes WHERE id=:oid FOR UPDATE');
        $oc->execute([':oid'=>$outcomeId]); $oc=$oc->fetch();

        $balRow = $pdo->prepare('SELECT balance FROM users WHERE id=:uid');
        $balRow->execute([':uid'=>$userId]);
        $balBefore = (float)$balRow->fetchColumn();

        $pdo->prepare('UPDATE users SET balance=balance+:stake,locked_balance=locked_balance-:lock WHERE id=:uid')
            ->execute([':stake'=>$stake,':lock'=>$stake,':uid'=>$userId]);

        // Only reverse LMSR shares if the market is in lmsr mode.
        // Fixed-mode bets always have shares = 0 so there is nothing to reverse.
        if (($mkt['odds_mode'] ?? 'lmsr') === 'lmsr' && $betShares > 0) {
            $pdo->prepare('UPDATE outcomes SET shares=GREATEST(0,shares-:s) WHERE id=:oid')
                ->execute([':s'=>$betShares,':oid'=>$outcomeId]);
        }
        $pdo->prepare('UPDATE markets SET total_wagered=GREATEST(0,total_wagered-:stake),
            total_bets=GREATEST(0,total_bets-1) WHERE id=:mid')
            ->execute([':stake'=>$stake,':mid'=>$dbMktId]);
        $pdo->prepare("UPDATE bets SET status='void',payout=:p,void_reason=:r WHERE id=:id")
            ->execute([':p'=>$stake,':r'=>$reason,':id'=>$bet['id']]);

        $pdo->commit();
        $balAfter = $balBefore + $stake;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_void_bet');
    }

    record_balance_tx($userId,'bet_revoked',$stake,$balBefore,$balAfter,$slipId,$dbMktId,$reason);
    audit_log('void_bet',$adminId,'market',$dbMktId,
        ['slip_id'=>$slipId,'user_id'=>$userId,'market_id'=>$mkt['market_id'],
         'outcome'=>$oc['name'],'stake'=>$stake,'shares_reversed'=>$betShares,'reason'=>$reason]);

    ok(['slip_id'=>$slipId,'market_id'=>$mkt['market_id'],'outcome'=>$oc['name'],
        'stake_refunded'=>$stake,'shares_reversed'=>round($betShares,8),'reason'=>$reason],
        'Bet revoked and stake refunded');
})(),


// =============================================================
//  POST ?route=admin_archive_market
//  Body: { "market_id": "...", "archive": true }
//  Only resolved/voided markets can be archived.
// =============================================================
$ROUTE === 'admin_archive_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid  = trim($BODY['market_id']);
    $archive = (bool)($BODY['archive'] ?? true);

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status,is_archived FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid'=>$extMid]); $mkt=$mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        if (!in_array($mkt['status'],['resolved','voided'],true))
            throw new RuntimeException("Only resolved/voided markets can be archived. Current: {$mkt['status']}|409");
        $pdo->prepare('UPDATE markets SET is_archived=:a WHERE id=:mid')
            ->execute([':a'=>(int)$archive,':mid'=>(int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_archive_market');
    }
    audit_log($archive?'archive_market':'unarchive_market',$adminId,'market',(int)$mkt['id'],
        ['market_id'=>$extMid,'is_archived'=>$archive]);
    ok(['market_id'=>$extMid,'is_archived'=>$archive],
        $archive ? 'Market archived' : 'Market unarchived');
})(),


// =============================================================
//  POST ?route=admin_edit_market
//  Body: { "market_id": "...", "question": "...", "category": "...", "source": "..." }
//  Terminal markets (resolved, voided) are locked.
// =============================================================
$ROUTE === 'admin_edit_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid  = trim($BODY['market_id']);
    $updates = [];
    foreach (['question','category','source'] as $f) {
        if (isset($BODY[$f]) && trim($BODY[$f]) !== '') $updates[$f] = trim($BODY[$f]);
    }
    // odds_mode is intentionally NOT editable here — use admin_set_odds_mode which
    // handles the full migration (fixed_odds write/clear, open-bet safety gate, snapshot).
    if (empty($updates)) fail('Provide at least one editable field: question, category, source', 422);
    if (isset($updates['category'])) $updates['category'] = strtolower($updates['category']);

    $pdo = db(); $pdo->beginTransaction(); $mkt = null;
    try {
        $mktS = $pdo->prepare('SELECT id,status,question,category,source FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mktS->execute([':mid'=>$extMid]); $mkt=$mktS->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        if (in_array($mkt['status'],['resolved','voided'],true))
            throw new RuntimeException("Cannot edit a {$mkt['status']} market.|409");
        $setClauses=[]; $params=[':mid'=>(int)$mkt['id']];
        foreach ($updates as $col=>$val) { $setClauses[]="$col=:$col"; $params[":$col"]=$val; }
        $pdo->prepare("UPDATE markets SET ".implode(',',$setClauses)." WHERE id=:mid")->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(),'|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e,'admin_edit_market');
    }
    $before=array_intersect_key(['question'=>$mkt['question'],'category'=>$mkt['category'],'source'=>$mkt['source']],$updates);
    audit_log('edit_market',$adminId,'market',(int)$mkt['id'],['market_id'=>$extMid,'before'=>$before,'after'=>$updates]);
    ok(array_merge(['market_id'=>$extMid],$updates),'Market updated');
})(),


// =============================================================
//  POST ?route=admin_adjust_limits
//  Body: { "market_id": "...", "min_stake":25, "max_stake":10000, "max_total_wagered":0 }
// =============================================================
$ROUTE === 'admin_adjust_limits' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY,['market_id']);
    $extMid = trim($BODY['market_id']);

    $pdo = db(); $pdo->beginTransaction(); $mkt=null;
    try {
        $mktS = $pdo->prepare('SELECT id,status,odds_mode,min_stake,max_stake,max_total_wagered,max_odds,total_wagered
            FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mktS->execute([':mid'=>$extMid]); $mkt=$mktS->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        if (in_array($mkt['status'],['resolved','voided'],true))
            throw new RuntimeException("Cannot adjust limits on a {$mkt['status']} market.|409");

        $newMin    = isset($BODY['min_stake'])          ? (float)$BODY['min_stake']          : (float)$mkt['min_stake'];
        $newMax    = isset($BODY['max_stake'])          ? (float)$BODY['max_stake']          : (float)$mkt['max_stake'];
        $newCap    = isset($BODY['max_total_wagered'])  ? (float)$BODY['max_total_wagered']  : (float)$mkt['max_total_wagered'];
        $newMaxOdds= isset($BODY['max_odds'])           ? (float)$BODY['max_odds']           : (float)($mkt['max_odds'] ?? LMSR_MAX_ODDS);

        $errors=[];
        if ($newMin<1)        $errors[]='min_stake must be >= 1';
        if ($newMax<=$newMin) $errors[]='max_stake must be > min_stake';
        if ($newCap<0)        $errors[]='max_total_wagered must be >= 0';
        if ($newCap>0&&$newCap<(float)$mkt['total_wagered'])
            $errors[]='max_total_wagered cannot be below current total_wagered of KES '.number_format((float)$mkt['total_wagered'],2);
        if (($mkt['odds_mode']??'lmsr')==='lmsr') {
            if ($newMaxOdds < 1.5)  $errors[]='max_odds must be >= 1.5';
            if ($newMaxOdds > 50.0) $errors[]='max_odds must be <= 50.0';
        }
        if ($errors) { $pdo->rollBack(); fail('Validation failed',422,$errors); }

        $noChange = abs($newMin-(float)$mkt['min_stake'])<=0.001
                 && abs($newMax-(float)$mkt['max_stake'])<=0.001
                 && abs($newCap-(float)$mkt['max_total_wagered'])<=0.001
                 && abs($newMaxOdds-(float)($mkt['max_odds']??LMSR_MAX_ODDS))<=0.001;
        if ($noChange) { $pdo->rollBack(); fail('No changes detected',422); }

        $pdo->prepare("UPDATE markets SET min_stake=:mn,max_stake=:mx,max_total_wagered=:cap,max_odds=:mo WHERE id=:mid")
            ->execute([':mn'=>$newMin,':mx'=>$newMax,':cap'=>$newCap,':mo'=>$newMaxOdds,':mid'=>(int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(),'|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e,'admin_adjust_limits');
    }
    audit_log('adjust_limits',$adminId,'market',(int)$mkt['id'],[
        'market_id'=>$extMid,
        'before'=>['min_stake'=>(float)$mkt['min_stake'],'max_stake'=>(float)$mkt['max_stake'],'max_total_wagered'=>(float)$mkt['max_total_wagered'],'max_odds'=>(float)($mkt['max_odds']??LMSR_MAX_ODDS)],
        'after' =>['min_stake'=>$newMin,'max_stake'=>$newMax,'max_total_wagered'=>$newCap,'max_odds'=>$newMaxOdds]]);
    ok(['market_id'=>$extMid,'min_stake'=>$newMin,'max_stake'=>$newMax,'max_total_wagered'=>$newCap,'max_odds'=>$newMaxOdds],'Limits updated');
})(),


// =============================================================
//  POST ?route=admin_extend_close_time
//  Body: { "market_id": "...", "close_time": "2027-06-01 18:00:00" }
//  Extension only — new time must be after existing.
// =============================================================
$ROUTE === 'admin_extend_close_time' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY,['market_id','close_time']);
    $extMid = trim($BODY['market_id']);
    $newCT=trim($BODY['close_time']);
    if (!strtotime($newCT)) fail('Invalid close_time. Use Y-m-d H:i:s.',422);
    $newTs=strtotime($newCT);
    if ($newTs<=time()) fail('New close_time must be in the future.',422);

    $pdo=db(); $pdo->beginTransaction(); $mkt=null;
    try {
        $mktS=$pdo->prepare('SELECT id,status,close_time FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mktS->execute([':mid'=>$extMid]); $mkt=$mktS->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        match($mkt['status']){
            'open','paused'=>null,
            'closed'  =>throw new RuntimeException('Market is closed|409'),
            'resolved'=>throw new RuntimeException('Market is resolved — close time locked|409'),
            'voided'  =>throw new RuntimeException('Market is voided — close time locked|409'),
            default   =>throw new RuntimeException('Unknown status|409'),
        };
        if ($mkt['close_time']!==null&&$newTs<=strtotime($mkt['close_time']))
            throw new RuntimeException('New close_time must be after current ('.$mkt['close_time'].')|422');
        $pdo->prepare('UPDATE markets SET close_time=:ct WHERE id=:mid')
            ->execute([':ct'=>date('Y-m-d H:i:s',$newTs),':mid'=>(int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(),'|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e,'admin_extend_close_time');
    }
    audit_log('extend_close_time',$adminId,'market',(int)$mkt['id'],
        ['market_id'=>$extMid,'previous'=>$mkt['close_time'],'new'=>date('Y-m-d H:i:s',$newTs)]);
    ok(['market_id'=>$extMid,'previous_close_time'=>$mkt['close_time'],
        'new_close_time'=>date('Y-m-d H:i:s',$newTs)],'Close time extended');
})(),


// =============================================================
//  GET ?route=admin_stats
// =============================================================
$ROUTE === 'admin_stats' && $METHOD === 'GET' => (function () {
    require_admin();
  try {
    $pdo=db();

    $users=$pdo->query("SELECT COUNT(*) AS u,SUM(role='user'AND verified=1) AS v,SUM(role='admin') AS a FROM users")->fetch();
    $activeToday=(int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM bets WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $mktByStatus=$pdo->query("SELECT status,COUNT(*) FROM markets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $marketsToday=(int)$pdo->query("SELECT COUNT(*) FROM markets WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $fin=$pdo->query("SELECT COUNT(*) AS b,COALESCE(SUM(stake),0) AS tw,
        COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS tp,
        COALESCE(SUM(CASE WHEN status='void' THEN payout ELSE 0 END),0) AS tr FROM bets")->fetch();
    $today=$pdo->query("SELECT COUNT(*) AS bt,COALESCE(SUM(stake),0) AS wt FROM bets WHERE DATE(created_at)=CURDATE()")->fetch();
    $bigV=$pdo->query("SELECT market_id,LEFT(question,80) AS question,total_wagered FROM markets ORDER BY total_wagered DESC LIMIT 1")->fetch()?:null;
    $bigW=$pdo->query("SELECT m.market_id,LEFT(m.question,80) AS q,COALESCE(SUM(b.payout),0) AS p
        FROM markets m LEFT JOIN bets b ON b.market_id=m.id AND b.status='won'
        WHERE m.status='resolved' GROUP BY m.id ORDER BY p DESC LIMIT 1")->fetch()?:null;
    $topB=$pdo->query("SELECT u.username,u.total_wagered,u.total_wins,COUNT(b.id) AS bc
        FROM users u JOIN bets b ON b.user_id=u.id WHERE u.role='user'
        GROUP BY u.id ORDER BY u.total_wagered DESC LIMIT 10")->fetchAll();

    ok(['users'=>['total'=>(int)$users['u'],'verified'=>(int)$users['v'],
                  'admins'=>(int)$users['a'],'active_today'=>$activeToday],
        'markets'=>['total'=>(int)array_sum($mktByStatus),
                    'open'=>(int)($mktByStatus['open']??0),'paused'=>(int)($mktByStatus['paused']??0),
                    'closed'=>(int)($mktByStatus['closed']??0),'resolved'=>(int)($mktByStatus['resolved']??0),
                    'voided'=>(int)($mktByStatus['voided']??0),'created_today'=>$marketsToday],
        'financials'=>['total_bets'=>(int)$fin['b'],'total_wagered'=>(float)$fin['tw'],
                       'total_payouts'=>(float)$fin['tp'],'total_refunds'=>(float)$fin['tr'],
                       'house_profit'=>round((float)$fin['tw']-(float)$fin['tp']-(float)$fin['tr'],2),
                       'bets_today'=>(int)$today['bt'],'wagered_today'=>(float)$today['wt']],
        'highlights'=>['biggest_market_by_volume'=>$bigV,'biggest_winning_market'=>$bigW],
        'top_bettors'=>array_map(fn($r)=>['username'=>$r['username'],
            'total_wagered'=>(float)$r['total_wagered'],'total_wins'=>(float)$r['total_wins'],
            'bet_count'=>(int)$r['bc']],$topB)]);
  } catch (Throwable $e) {
      internal_error($e, 'admin_stats');
  }
})(),


// =============================================================
//  GET ?route=admin_market_report&market_id=...
// =============================================================
$ROUTE === 'admin_market_report' && $METHOD === 'GET' => (function () {
    require_admin();
  try {
    $extMid=trim($_GET['market_id']??'');
    if (!$extMid) fail('market_id param is required',422);

    $pdo=db();
    $mktS=$pdo->prepare("SELECT id,market_id,source,question,category,market_type,status,close_time,
        resolve_time,void_reason,pause_reason,b,min_stake,max_stake,max_total_wagered,max_odds,is_archived,
        total_bets,total_wagered,created_by,created_at,updated_at,odds_mode
        FROM markets WHERE market_id=:mid LIMIT 1");
    $mktS->execute([':mid'=>$extMid]); $mkt=$mktS->fetch();
    if (!$mkt) fail('Market not found',404);
    $dbId=(int)$mkt['id']; $b=(float)$mkt['b'];

    $oRows=$pdo->prepare('SELECT id,name,shares,fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id');
    $oRows->execute([':mid'=>$dbId]); $outcomeRows=$oRows->fetchAll();
    $liveSnapshot=market_snapshot($outcomeRows,$b,$mkt['odds_mode']??'lmsr');

    $bs=$pdo->prepare("SELECT COUNT(*) AS t,SUM(status='open') AS o,SUM(status='won') AS w,
        SUM(status='lost') AS l,SUM(status='void') AS v,
        COALESCE(SUM(stake),0) AS ts,
        COALESCE(SUM(CASE WHEN status='won' THEN payout ELSE 0 END),0) AS tp,
        COALESCE(SUM(CASE WHEN status='void' THEN payout ELSE 0 END),0) AS tr,
        COALESCE(SUM(CASE WHEN status='open' THEN stake ELSE 0 END),0) AS sar,
        COALESCE(SUM(CASE WHEN status='open' THEN possible_win ELSE 0 END),0) AS ml
        FROM bets WHERE market_id=:mid");
    $bs->execute([':mid'=>$dbId]); $bs=$bs->fetch();

    $volS=$pdo->prepare("SELECT o.id AS oid,o.name AS n,COUNT(b.id) AS bc,
        COALESCE(SUM(b.stake),0) AS ts,
        COALESCE(SUM(CASE WHEN b.status='won' THEN b.payout ELSE 0 END),0) AS po,
        COALESCE(SUM(CASE WHEN b.status='open' THEN b.possible_win ELSE 0 END),0) AS ol
        FROM outcomes o LEFT JOIN bets b ON b.outcome_id=o.id AND b.market_id=:mid
        WHERE o.market_id=:mid2 GROUP BY o.id ORDER BY o.id");
    $volS->execute([':mid'=>$dbId,':mid2'=>$dbId]);

    $gS=$pdo->prepare("
        SELECT
            s.outcome_id,
            o.name AS n,
            COUNT(s.id) AS sc,
            agg.first_snap_id,
            agg.last_snap_id
        FROM market_snapshots s
        JOIN outcomes o ON o.id = s.outcome_id
        JOIN (
            SELECT outcome_id,
                   MIN(id) AS first_snap_id,
                   MAX(id) AS last_snap_id
            FROM market_snapshots
            WHERE market_id = :mid_agg
            GROUP BY outcome_id
        ) agg ON agg.outcome_id = s.outcome_id
        WHERE s.market_id = :mid
        GROUP BY s.outcome_id, o.name, agg.first_snap_id, agg.last_snap_id
        ORDER BY s.outcome_id
    ");
    $gS->execute([':mid' => $dbId, ':mid_agg' => $dbId]);
    $gsRows = $gS->fetchAll();

    // Fetch opening and closing snapshots in two bulk queries (avoids N correlated subqueries)
    $graphSummary = [];
    if (!empty($gsRows)) {
        $firstIds = array_column($gsRows, 'first_snap_id');
        $lastIds  = array_column($gsRows, 'last_snap_id');
        $allIds   = array_unique(array_merge($firstIds, $lastIds));
        $inList   = implode(',', array_map('intval', $allIds));
        $snaps    = $pdo->query("SELECT id,probability,odds,shares,volume,created_at FROM market_snapshots WHERE id IN ($inList)")->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($gsRows as $r) {
            $fs = $snaps[$r['first_snap_id']] ?? [];
            $ls = $snaps[$r['last_snap_id']]  ?? [];
            $graphSummary[] = [
                'outcome_id'          => (int)$r['outcome_id'],
                'outcome_name'        => $r['n'],
                'opening_odds'     => (float)($fs['odds'] ?? 0),
                'current_odds'     => (float)($ls['odds'] ?? 0),
                'snapshot_count'      => (int)$r['sc'],
                'last_snapshot_at'    => $ls['created_at'] ?? null,
            ];
        }
    }

    $aS=$pdo->prepare("SELECT a.action,a.meta,a.created_at,
        COALESCE(u.username, CASE WHEN a.admin_id=0 THEN 'system' ELSE CONCAT('user#',a.admin_id) END) AS au
        FROM audit_logs a LEFT JOIN users u ON u.id=a.admin_id AND a.admin_id > 0
        WHERE a.target_type='market' AND a.target_id=:mid ORDER BY a.created_at DESC LIMIT 20");
    $aS->execute([':mid'=>$dbId]);

    $cS=$pdo->prepare('SELECT username FROM users WHERE id=:uid LIMIT 1');
    $cS->execute([':uid'=>(int)$mkt['created_by']]);

    ok([
        'market'=>['market_id'=>$mkt['market_id'],'question'=>$mkt['question'],'category'=>$mkt['category'],
            'source'=>$mkt['source'],'market_type'=>$mkt['market_type'],'odds_mode'=>$mkt['odds_mode']??'lmsr',
            'status'=>$mkt['status'],
            'is_archived'=>(bool)$mkt['is_archived'],'close_time'=>$mkt['close_time'],
            'resolve_time'=>$mkt['resolve_time'],'void_reason'=>$mkt['void_reason'],
            'pause_reason'=>$mkt['pause_reason'],'b'=>(float)$mkt['b'],
            'min_stake'=>(float)$mkt['min_stake'],'max_stake'=>(float)$mkt['max_stake'],
            'max_total_wagered'=>(float)$mkt['max_total_wagered'],
            'max_odds'=>($mkt['odds_mode']??'lmsr')==='lmsr' ? (float)($mkt['max_odds']??LMSR_MAX_ODDS) : null,
            'total_bets'=>(int)$mkt['total_bets'],'total_wagered'=>(float)$mkt['total_wagered'],
            'created_by'=>$cS->fetchColumn()?:'unknown','created_at'=>$mkt['created_at'],'updated_at'=>$mkt['updated_at']],
        'live_odds'=>$liveSnapshot,
        'bet_summary'=>['total_bets'=>(int)$bs['t'],'open'=>(int)$bs['o'],'won'=>(int)$bs['w'],
            'lost'=>(int)$bs['l'],'void'=>(int)$bs['v'],'total_staked'=>(float)$bs['ts'],
            'total_paid_out'=>(float)$bs['tp'],'total_refunded'=>(float)$bs['tr'],
            'stake_at_risk'=>(float)$bs['sar'],'max_liability'=>(float)$bs['ml'],
            'house_profit'=>round((float)$bs['ts']-(float)$bs['tp']-(float)$bs['tr'],2)],
        'volume_by_outcome'=>array_map(fn($r)=>['outcome_id'=>(int)$r['oid'],'outcome_name'=>$r['n'],
            'bet_count'=>(int)$r['bc'],'total_staked'=>(float)$r['ts'],
            'paid_out'=>(float)$r['po'],'open_liability'=>(float)$r['ol']],$volS->fetchAll()),
        'graph_summary' => $graphSummary,
        'audit_log'=>array_map(fn($r)=>['action'=>$r['action'],'admin'=>$r['au'],
            'meta'=>json_decode($r['meta']??'{}',true),'at'=>$r['created_at']],$aS->fetchAll()),
    ]);
  } catch (Throwable $e) {
      internal_error($e, 'admin_market_report');
  }
})(),


// =============================================================
//  POST ?route=admin_reseed_odds
//  Authorization: Bearer <admin_token>
//
//  Administratively reset LMSR shares so the market opens at
//  new target probabilities, without settling or voiding it.
//
//  WHY THIS EXISTS
//  ─────────────────────────────────────────────────────────
//  When a market is first created the admin seeds opening odds.
//  New information may warrant an administrative correction —
//  e.g. a team injury changes the fair 70/30 to 55/45.
//  This route resets the share vector so future bets start
//  from the corrected price without touching existing bets
//  or their locked-in odds/payouts.
//
//  WHAT IT DOES
//  ─────────────────────────────────────────────────────────
//  • Accepts new odds per outcome (by outcome_id or name).
//  • Normalises implied probabilities (1/odds) to Σ = 1.
//  • Normalises to Σp = 1.
//  • Computes new seed shares:  s_i = b · ln(p_i / p_0)
//  • Overwrites outcomes.shares for every outcome in the market.
//  • Records a market_snapshots row so the odds graph shows a
//    visible step-change at the reseed point.
//  • Writes a full audit log entry with before/after values.
//
//  WHAT IT DOES NOT DO
//  ─────────────────────────────────────────────────────────
//  • Does NOT refund, settle, or void any existing bets.
//  • Does NOT change any bet's locked odds_at_entry or possible_win.
//  • Does NOT touch user balances.
//
//  SAFETY GATES
//  ─────────────────────────────────────────────────────────
//  • Only open or paused markets can be reseeded.
//  • ALL outcomes for the market must be present in the request.
//  • If there are open (unsettled) bets, you MUST pass "force": true.
//    Without it the request is rejected with a count of open bets
//    and their total stake so the admin can evaluate the risk.
//
//  Body:
//  {
//    "market_id": "b2a4431b5ab65de9",
//    "outcomes": [
//      { "outcome_id": 1, "odds": 1.43 },
//      { "outcome_id": 2, "odds": 3.33 }
//    ],
//    "force": true,
//    "reason": "Team injury — correcting opening price"  (optional)
//  }
//
//  Response (success):
//  {
//    "success": true,
//    "message": "Market odds reseeded successfully",
//    "data": {
//      "market_id": "b2a4431b5ab65de9",
//      "reason": "Team injury — correcting opening price",
//      "open_bets_affected": 3,
//      "outcomes": [
//        { "outcome_id": 1, "name": "Yes", "odds": 1.4300 },
//        { "outcome_id": 2, "name": "No",  "odds": 3.3249 }
//      ]
//    }
//  }
// =============================================================
$ROUTE === 'admin_reseed_odds' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['market_id', 'outcomes']);
    $extMid   = trim($BODY['market_id']);
    $incoming = $BODY['outcomes'] ?? [];
    $force    = (bool)($BODY['force']  ?? false);
    $reason   = trim($BODY['reason']   ?? 'Odds reseeded by admin');

    // ── Basic input validation ─────────────────────────────
    if (empty($incoming) || !is_array($incoming)) {
        fail('outcomes must be a non-empty array', 422);
    }

    // Each entry identifies an outcome by outcome_id (int) OR name (string).
    // Using name is more convenient when you copy from the market creation response.
    // Example: { "name": "Yes", "odds": 1.43 }  — same as { "outcome_id": 5, "odds": 1.43 }
    foreach ($incoming as $i => $o) {
        $hasId   = isset($o['outcome_id']) && (int)$o['outcome_id'] > 0;
        $hasName = isset($o['name']) && trim($o['name']) !== '';
        if (!$hasId && !$hasName) {
            fail("outcomes[$i]: identify the outcome with outcome_id (integer) or name (string)", 422);
        }
        $label = $hasId ? "outcome_id {$o['outcome_id']}" : "name \"{$o['name']}\"";
        if (!isset($o['odds'])) {
            fail("outcomes[$i] ($label): 'odds' required (EU decimal, e.g. 1.43)", 422);
        }
        if ((float)$o['odds'] <= 1.0) {
            fail("outcomes[$i] ($label): odds must be > 1.0 (e.g. 1.43)", 422);
        }
    }

    // $incomingMap (outcome_id → entry) is built inside the try block after DB rows
    // are fetched, so that name-based entries can be resolved to their real IDs.
    $incomingMap = [];

    $pdo = db();
    $pdo->beginTransaction();

    $dbId         = 0;
    $b            = 0.0;
    $marketExtId  = '';
    $newShares    = [];
    $beforeState  = [];
    $dbRows       = [];
    $openBetCount = 0;
    $openBetStake = 0.0;

    try {
        // ── Lock market ────────────────────────────────────
        $mktStmt = $pdo->prepare(
            'SELECT id, market_id, status, b, odds_mode FROM markets WHERE market_id = :mid LIMIT 1 FOR UPDATE'
        );
        $mktStmt->execute([':mid' => $extMid]);
        $mkt = $mktStmt->fetch();

        if (!$mkt) {
            throw new RuntimeException('Market not found|404');
        }
        if (in_array($mkt['status'], ['resolved', 'voided'], true)) {
            throw new RuntimeException(
                "Cannot reseed a {$mkt['status']} market. Only open or paused markets can be reseeded.|409"
            );
        }
        if ($mkt['status'] === 'closed') {
            throw new RuntimeException(
                'Cannot reseed a closed market. Resume or reopen it first, or settle/void it.|409'
            );
        }

        $dbId        = (int)$mkt['id'];
        $b           = (float)$mkt['b'];
        $marketExtId = $mkt['market_id'];
        $oddsMode    = $mkt['odds_mode'] ?? 'lmsr';

        // ── Lock all outcome rows ──────────────────────────
        $oStmt = $pdo->prepare(
            'SELECT id, name, shares FROM outcomes WHERE market_id = :mid ORDER BY id FOR UPDATE'
        );
        $oStmt->execute([':mid' => $dbId]);
        $dbRows = $oStmt->fetchAll();

        if (empty($dbRows)) {
            throw new RuntimeException('Market has no outcomes. Data integrity error.|500');
        }

        // ── Resolve incoming entries: name → outcome_id ────
        // Build a case-insensitive name map from the actual DB rows.
        $nameToId = [];
        foreach ($dbRows as $r) {
            $nameToId[strtolower(trim($r['name']))] = (int)$r['id'];
        }

        foreach ($incoming as $i => $o) {
            if (isset($o['outcome_id']) && (int)$o['outcome_id'] > 0) {
                $oid = (int)$o['outcome_id'];
            } else {
                // Resolve by name (case-insensitive)
                $nameKey = strtolower(trim($o['name']));
                if (!isset($nameToId[$nameKey])) {
                    $pdo->rollBack();
                    // Give the admin the valid names so they can fix it immediately
                    $validNames = implode(', ', array_map(fn($r) => '"' . $r['name'] . '"', $dbRows));
                    fail(
                        "outcomes[$i]: name \"{$o['name']}\" not found in this market. "
                        . "Valid names: $validNames",
                        422
                    );
                }
                $oid = $nameToId[$nameKey];
            }
            if (isset($incomingMap[$oid])) {
                $pdo->rollBack();
                fail("Duplicate outcome (id $oid) in request", 422);
            }
            $incomingMap[$oid] = $o;
        }

        // ── Completeness check — all DB outcomes must be covered ──
        $dbIds      = array_map(fn($r) => (int)$r['id'], $dbRows);
        $missingIds = array_diff($dbIds, array_keys($incomingMap));
        $extraIds   = array_diff(array_keys($incomingMap), $dbIds);

        if (!empty($missingIds)) {
            // Show name + id for each missing outcome so the admin knows what to add
            $missingLabels = array_map(function ($mid) use ($dbRows) {
                foreach ($dbRows as $r) {
                    if ((int)$r['id'] === $mid) return "\"{$r['name']}\" (id $mid)";
                }
                return "id $mid";
            }, $missingIds);
            throw new RuntimeException(
                'All outcomes must be included. Missing: '
                . implode(', ', $missingLabels) . '|422'
            );
        }
        if (!empty($extraIds)) {
            throw new RuntimeException(
                'Unknown outcome_id(s) not in this market: '
                . implode(', ', $extraIds) . '|422'
            );
        }

        // ── Open bets safety gate ──────────────────────────
        $betChk = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(stake), 0) AS total_stake
             FROM bets WHERE market_id = :mid AND status = 'open'"
        );
        $betChk->execute([':mid' => $dbId]);
        $betInfo      = $betChk->fetch();
        $openBetCount = (int)$betInfo['cnt'];
        $openBetStake = (float)$betInfo['total_stake'];

        if ($openBetCount > 0 && !$force) {
            $pdo->rollBack();
            fail(
                'This market has open bets. Pass "force": true to confirm you understand '
                . 'that existing bets keep their locked odds — the reseed only affects future bets.',
                409,
                [
                    'open_bets'        => $openBetCount,
                    'open_stake_total' => round($openBetStake, 2),
                    'hint'             => 'Re-send the request with "force": true to proceed.',
                ]
            );
        }

        // ── Build ordered arrays for normalise → seed ─────
        // Order must match dbRows so we can pair new shares back to outcome ids
        $orderedIncoming = [];
        foreach ($dbRows as $r) {
            $orderedIncoming[] = $incomingMap[(int)$r['id']];
        }

        // ── Normalise → seed shares ────────────────────────
        $normProbs = normalize_probs($orderedIncoming);
        $newShares = seed_shares($normProbs, $b);

        // ── Capture before state for audit log ────────────
        foreach ($dbRows as $i => $r) {
            $oldSv     = array_map(fn($row) => (float)$row['shares'], $dbRows);
            $oldProbs  = lmsr_probs($oldSv, $b);
            $op        = $oldProbs[$i];
            $beforeState[] = [
                'outcome_id'  => (int)$r['id'],
                'name'        => $r['name'],
                'old_shares'  => (float)$r['shares'],
                'old_odds'    => $op > 0 ? round(1.0 / $op, 2) : 999.0,
            ];
        }

        // ── Write new shares or fixed_odds depending on mode ──
        if ($oddsMode === 'fixed') {
            // Fixed mode: convert normalised probabilities → fixed_odds.
            // Shares are left untouched (not used for pricing in fixed mode).
            $updStmt = $pdo->prepare('UPDATE outcomes SET fixed_odds = :fo WHERE id = :id');
            foreach ($dbRows as $i => $r) {
                $pv = $normProbs[$i];
                $fo = ($pv > 0) ? round(1.0 / $pv, 2) : 999.0;
                $updStmt->execute([':fo' => $fo, ':id' => (int)$r['id']]);
            }
        } else {
            // LMSR mode: write new seed shares.
            $updStmt = $pdo->prepare('UPDATE outcomes SET shares = :shares WHERE id = :id');
            foreach ($dbRows as $i => $r) {
                $updStmt->execute([':shares' => $newShares[$i], ':id' => (int)$r['id']]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) {
            [$msg, $code] = explode('|', $e->getMessage(), 2);
            fail($msg, (int)$code);
        }
        internal_error($e, 'admin_reseed_odds');
    }

    // ── Post-commit: rebuild rows with new values ──────────
    // Fetch fresh (includes fixed_odds) so snapshot and response use the committed values
    $freshStmt = db()->prepare(
        'SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id = :mid ORDER BY id'
    );
    $freshStmt->execute([':mid' => $dbId]);
    $freshRows = $freshStmt->fetchAll();

    // Fetch current market volume and odds_mode for the snapshot record
    $mktMeta = db()->prepare('SELECT total_wagered, odds_mode FROM markets WHERE id = :mid LIMIT 1');
    $mktMeta->execute([':mid' => $dbId]);
    $mktMeta       = $mktMeta->fetch();
    $currentVolume = (float)($mktMeta['total_wagered'] ?? 0);
    $currentMode   = $mktMeta['odds_mode'] ?? $oddsMode;

    // Record snapshot — gives the graph a visible step-change at the reseed point
    if ($currentMode === 'fixed') {
        // Fixed snapshot: store admin-set odds as flat probability/odds lines
        try {
            $sStmt = db()->prepare("INSERT INTO market_snapshots
                (market_id,outcome_id,probability,odds,shares,volume)
                VALUES (:mid,:oid,:p,:o,:s,:v)");
            foreach ($freshRows as $r) {
                $fo = (float)($r['fixed_odds'] ?? 0);
                $fp = $fo > 1.0 ? round(1.0 / $fo, 6) : 0.0;
                $sStmt->execute([':mid' => $dbId, ':oid' => (int)$r['id'],
                    ':p' => $fp, ':o' => round($fo, 2),
                    ':s' => round((float)$r['shares'], 8), ':v' => $currentVolume]);
            }
        } catch (Throwable $e) {
            error_log('[MwasinMarket] admin_reseed_odds fixed snapshot: ' . $e->getMessage());
        }
    } else {
        record_market_snapshot($dbId, $freshRows, $b, $currentVolume);
    }

    // ── Audit log ──────────────────────────────────────────
    $afterState = [];
    if ($currentMode === 'fixed') {
        foreach ($freshRows as $r) {
            $fo = (float)($r['fixed_odds'] ?? 0);
            $afterState[] = [
                'outcome_id' => (int)$r['id'],
                'name'       => $r['name'],
                'new_odds'   => $fo > 1.0 ? round($fo, 2) : 999.0,
            ];
        }
    } else {
        $newSv    = array_map(fn($r) => (float)$r['shares'], $freshRows);
        $newProbs = lmsr_probs($newSv, $b);
        foreach ($freshRows as $i => $r) {
            $np = $newProbs[$i];
            $afterState[] = [
                'outcome_id' => (int)$r['id'],
                'name'       => $r['name'],
                'new_shares' => (float)$r['shares'],
                'new_odds'   => $np > 0 ? round(1.0 / $np, 2) : 999.0,
            ];
        }
    }

    audit_log('reseed_odds', $adminId, 'market', $dbId, [
        'market_id'          => $marketExtId,
        'reason'             => $reason,
        'force'              => $force,
        'open_bets_affected' => $openBetCount,
        'before'             => $beforeState,
        'after'              => $afterState,
    ]);

    ok([
        'market_id'          => $marketExtId,
        // 'reason' is returned only to the admin caller — it is never
        // surfaced on any public route and is not visible to users.
        'reason'             => $reason,
        // Existing bets are completely unaffected: odds_at_entry and
        // possible_win are locked at placement and never altered by a reseed.
        // Only future bets start from the new seeded price.
        'open_bets_affected' => $openBetCount,
        'outcomes'           => market_snapshot($freshRows, $b, $currentMode),
    ], 'Market odds reseeded successfully');
})(),


// =============================================================
//  POST ?route=admin_set_odds_mode
//  Authorization: Bearer <admin_token>
//
//  Switch a market between LMSR (dynamic) and fixed-odds pricing.
//  Dedicated route — deliberately separate from admin_edit_market
//  so the audit trail makes the pricing mode change explicit and
//  the safety checks are impossible to accidentally skip.
//
//  Modes:
//    lmsr  — every bet shifts odds via the LMSR cost function
//    fixed — admin-set odds stored in outcomes.fixed_odds;
//            bets never move the price
//
//  Body:
//  {
//    "market_id":  "b2a4431b5ab65de9",
//    "odds_mode":  "fixed",               // "lmsr" | "fixed"
//    "outcomes": [                        // required when switching TO fixed
//      { "outcome_id": 5, "odds": 1.85 },
//      { "outcome_id": 6, "odds": 2.10 }
//    ],
//    "force": true                        // required if open bets exist
//  }
//
//  When switching TO fixed:
//    - outcomes[] with fixed odds are required
//    - fixed_odds written to each outcome row
//  When switching TO lmsr:
//    - outcomes[] is optional; if provided, used to reseed shares
//    - if omitted, LMSR starts from current share state (prices
//      may look odd — passing outcomes is recommended)
//    - fixed_odds cleared to NULL on all outcomes
//
//  Safety: if there are open bets and force != true, the request
//  is rejected with a summary so the admin can assess the impact.
// =============================================================
$ROUTE === 'admin_set_odds_mode' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['market_id', 'odds_mode']);
    $extMid   = trim($BODY['market_id']);
    $newMode  = trim($BODY['odds_mode']);
    $force    = (bool)($BODY['force'] ?? false);
    $incoming = $BODY['outcomes'] ?? [];

    if (!in_array($newMode, ['lmsr', 'fixed'], true)) {
        fail("odds_mode must be 'lmsr' or 'fixed'", 422);
    }

    // Validate incoming outcomes (only when supplied)
    foreach ($incoming as $i => $o) {
        $hasId   = isset($o['outcome_id']) && (int)$o['outcome_id'] > 0;
        $hasName = isset($o['name']) && trim($o['name']) !== '';
        if (!$hasId && !$hasName) {
            fail("outcomes[$i]: provide outcome_id or name", 422);
        }
        if ($newMode === 'fixed') {
            if (!isset($o['odds'])) {
                fail("outcomes[$i]: odds required when switching to fixed mode", 422);
            }
            if ((float)$o['odds'] <= 1.0) {
                fail("outcomes[$i]: odds must be > 1.0", 422);
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();

    $dbId     = 0;
    $prevMode = '';
    $dbRows   = [];

    try {
        $mktStmt = $pdo->prepare(
            'SELECT id, status, b, odds_mode FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE'
        );
        $mktStmt->execute([':mid' => $extMid]);
        $mkt = $mktStmt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');

        if (in_array($mkt['status'], ['resolved', 'voided'], true)) {
            throw new RuntimeException(
                "Cannot change odds mode on a {$mkt['status']} market|409"
            );
        }

        $dbId     = (int)$mkt['id'];
        $b        = (float)$mkt['b'];
        $prevMode = $mkt['odds_mode'] ?? 'lmsr';

        if ($prevMode === $newMode) {
            $pdo->rollBack();
            fail("Market is already in '$newMode' mode", 422);
        }

        // Lock outcome rows
        $oStmt = $pdo->prepare(
            'SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id FOR UPDATE'
        );
        $oStmt->execute([':mid' => $dbId]);
        $dbRows = $oStmt->fetchAll();

        // Open-bet safety gate
        $betChk = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(stake),0) AS st
             FROM bets WHERE market_id=:mid AND status='open'"
        );
        $betChk->execute([':mid' => $dbId]);
        $betInfo      = $betChk->fetch();
        $openBetCount = (int)$betInfo['cnt'];

        if ($openBetCount > 0 && !$force) {
            $pdo->rollBack();
            fail(
                'This market has open bets whose locked odds will be unaffected, '
                . 'but future bets will use the new pricing mode. '
                . 'Pass "force": true to confirm.',
                409,
                ['open_bets' => $openBetCount, 'open_stake_total' => round((float)$betInfo['st'], 2)]
            );
        }

        // Build name→id map for resolving incoming by name
        $nameToId = [];
        foreach ($dbRows as $r) {
            $nameToId[strtolower(trim($r['name']))] = (int)$r['id'];
        }

        // Resolve incoming outcomes map: outcome_id => entry
        $incomingMap = [];
        foreach ($incoming as $o) {
            if (isset($o['outcome_id']) && (int)$o['outcome_id'] > 0) {
                $oid = (int)$o['outcome_id'];
            } else {
                $nameKey = strtolower(trim($o['name']));
                if (!isset($nameToId[$nameKey])) {
                    $validNames = implode(', ', array_map(fn($r) => '"'.$r['name'].'"', $dbRows));
                    $pdo->rollBack();
                    fail("Outcome name \"{$o['name']}\" not found. Valid: $validNames", 422);
                }
                $oid = $nameToId[$nameKey];
            }
            $incomingMap[$oid] = $o;
        }

        // Switching TO fixed: require all outcomes to have odds
        if ($newMode === 'fixed') {
            if (empty($incomingMap)) {
                $pdo->rollBack();
                fail('outcomes[] with fixed odds is required when switching to fixed mode', 422);
            }
            $dbIds      = array_map(fn($r) => (int)$r['id'], $dbRows);
            $missingIds = array_diff($dbIds, array_keys($incomingMap));
            if (!empty($missingIds)) {
                $labels = array_map(function ($mid) use ($dbRows) {
                    foreach ($dbRows as $r) {
                        if ((int)$r['id'] === $mid) return "\"{$r['name']}\" (id $mid)";
                    }
                    return "id $mid";
                }, $missingIds);
                $pdo->rollBack();
                fail('Missing fixed odds for: ' . implode(', ', $labels), 422);
            }

            // Write fixed odds, clear shares (not used for pricing in fixed mode)
            $updStmt = $pdo->prepare(
                'UPDATE outcomes SET fixed_odds=:fo WHERE id=:id'
            );
            foreach ($dbRows as $r) {
                $fo = (float)$incomingMap[(int)$r['id']]['odds'];
                $updStmt->execute([':fo' => round($fo, 2), ':id' => (int)$r['id']]);
            }

        } else {
            // Switching TO lmsr: clear fixed_odds on all outcomes
            $pdo->prepare('UPDATE outcomes SET fixed_odds=NULL WHERE market_id=:mid')
                ->execute([':mid' => $dbId]);

            // If new LMSR seed provided, apply it
            if (!empty($incomingMap)) {
                $dbIds    = array_map(fn($r) => (int)$r['id'], $dbRows);
                $extraIds = array_diff(array_keys($incomingMap), $dbIds);
                if (!empty($extraIds)) {
                    $pdo->rollBack();
                    fail('Unknown outcome_id(s): ' . implode(', ', $extraIds), 422);
                }
                $orderedIncoming = [];
                foreach ($dbRows as $i => $r) {
                    if (isset($incomingMap[(int)$r['id']])) {
                        $orderedIncoming[] = $incomingMap[(int)$r['id']];
                    } else {
                        // Outcome not supplied — keep its current implied probability
                        $sv    = array_map(fn($row) => (float)$row['shares'], $dbRows);
                        $probs = lmsr_probs($sv, $b);
                        $orderedIncoming[] = ['_implied_prob' => max(1e-9, $probs[$i])];
                    }
                }
                $normProbs = normalize_probs($orderedIncoming);
                $newShares = seed_shares($normProbs, $b);
                $updStmt   = $pdo->prepare('UPDATE outcomes SET shares=:s WHERE id=:id');
                foreach ($dbRows as $i => $r) {
                    $updStmt->execute([':s' => $newShares[$i], ':id' => (int)$r['id']]);
                }
            }
        }

        // Update odds_mode on the market
        $pdo->prepare('UPDATE markets SET odds_mode=:m WHERE id=:mid')
            ->execute([':m' => $newMode, ':mid' => $dbId]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) {
            [$msg, $code] = explode('|', $e->getMessage(), 2);
            fail($msg, (int)$code);
        }
        internal_error($e, 'admin_set_odds_mode');
    }

    // Post-commit: fresh rows + snapshot for graph step-change marker
    $freshStmt = db()->prepare(
        'SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id'
    );
    $freshStmt->execute([':mid' => $dbId]);
    $freshRows = $freshStmt->fetchAll();

    $mktMeta = db()->prepare('SELECT b, total_wagered FROM markets WHERE id=:mid LIMIT 1');
    $mktMeta->execute([':mid' => $dbId]);
    $mktMeta = $mktMeta->fetch();
    $freshB  = (float)($mktMeta['b'] ?? 100);
    $vol     = (float)($mktMeta['total_wagered'] ?? 0);

    if ($newMode === 'lmsr') {
        record_market_snapshot($dbId, $freshRows, $freshB, $vol);
    } else {
        // Fixed snapshot — store admin-set odds as flat probability/odds lines
        try {
            $sStmt = db()->prepare("INSERT INTO market_snapshots
                (market_id,outcome_id,probability,odds,shares,volume)
                VALUES (:mid,:oid,:p,:o,:s,:v)");
            foreach ($freshRows as $r) {
                $fo = (float)($r['fixed_odds'] ?? 0);
                $fp = $fo > 1.0 ? round(1.0 / $fo, 6) : 0.0;
                $sStmt->execute([':mid' => $dbId, ':oid' => (int)$r['id'],
                    ':p' => $fp, ':o' => round($fo, 2),
                    ':s' => round((float)$r['shares'], 8), ':v' => $vol]);
            }
        } catch (Throwable $e) {
            error_log('[MwasinMarket] admin_set_odds_mode fixed snapshot: ' . $e->getMessage());
        }
    }

    audit_log('set_odds_mode', $adminId, 'market', $dbId, [
        'market_id'   => $extMid,
        'prev_mode'   => $prevMode,
        'new_mode'    => $newMode,
        'open_bets'   => $openBetCount ?? 0,
        'force'       => $force,
    ]);

    // Build response snapshot — LMSR or fixed display
    $respOutcomes = ($newMode === 'fixed')
        ? array_map(fn($r) => [
            'outcome_id'  => (int)$r['id'],
            'name'        => $r['name'],
            'odds'        => (float)($r['fixed_odds'] ?: 999.0),
          ], $freshRows)
        : lmsr_snapshot($freshRows, $freshB);

    ok([
        'market_id' => $extMid,
        'prev_mode' => $prevMode,
        'odds_mode' => $newMode,
        'outcomes'  => $respOutcomes,
    ], "Odds mode switched from '$prevMode' to '$newMode'");
})(),


// =============================================================
//  POST ?route=admin_credit_user
//  Authorization: Bearer <admin_token>
//
//  Credit or debit a user's balance.
//  This is the only way to add funds to an account.
//  Debits are also supported (for corrections) but cannot
//  take balance below 0.
//
//  Body:
//  {
//    "user_id":   5,              // required
//    "amount":    1000.00,        // required — positive = credit, negative = debit
//    "type":      "deposit",      // "deposit" | "bonus" | "withdrawal" | "adjustment"
//    "note":      "M-Pesa ref: QGHY7T"   // optional but strongly recommended
//  }
//
//  Response:
//  {
//    "data": {
//      "user_id":         5,
//      "username":        "charles",
//      "amount":          1000.00,
//      "type":            "deposit",
//      "balance_before":  0.00,
//      "balance_after":   1000.00,
//      "note":            "M-Pesa ref: QGHY7T"
//    }
//  }
// =============================================================
$ROUTE === 'admin_credit_user' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['user_id', 'amount', 'type']);

    $targetUserId = (int)$BODY['user_id'];
    $amount       = round((float)$BODY['amount'], 2);
    $type         = trim($BODY['type']);
    $note         = trim($BODY['note'] ?? '');

    $allowedTypes = ['deposit', 'bonus', 'withdrawal', 'adjustment'];
    if (!in_array($type, $allowedTypes, true)) {
        fail("type must be one of: " . implode(', ', $allowedTypes), 422);
    }
    if ($amount === 0.0) {
        fail('amount cannot be zero', 422);
    }
    if (abs($amount) > 10_000_000) {
        fail('amount exceeds the single-transaction limit of KES 10,000,000', 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    $username      = '';
    $balanceBefore = 0.0;
    $balanceAfter  = 0.0;

    try {
        // Lock user row to prevent concurrent balance races
        $userStmt = $pdo->prepare(
            'SELECT id, username, balance FROM users WHERE id=:uid LIMIT 1 FOR UPDATE'
        );
        $userStmt->execute([':uid' => $targetUserId]);
        $user = $userStmt->fetch();
        if (!$user) throw new RuntimeException('User not found|404');

        $balanceBefore = (float)$user['balance'];
        $balanceAfter  = round($balanceBefore + $amount, 2);
        $username      = $user['username'];

        if ($balanceAfter < 0.0) {
            throw new RuntimeException(
                'Debit of KES ' . number_format(abs($amount), 2)
                . ' would take balance below zero. '
                . 'Current balance: KES ' . number_format($balanceBefore, 2) . '|422'
            );
        }

        if ($amount > 0) {
            // Credit — straightforward balance increase
            $pdo->prepare('UPDATE users SET balance=balance+:a WHERE id=:uid')
                ->execute([':a' => $amount, ':uid' => $targetUserId]);
        } else {
            // Debit — conditional update guards against concurrent spend
            $upd = $pdo->prepare(
                'UPDATE users SET balance=balance+:a WHERE id=:uid AND balance+:a2 >= 0'
            );
            $upd->execute([':a' => $amount, ':a2' => $amount, ':uid' => $targetUserId]);
            if ($upd->rowCount() === 0) {
                throw new RuntimeException(
                    'Concurrent balance change prevented the debit. Please retry.|409'
                );
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) {
            [$msg, $code] = explode('|', $e->getMessage(), 2);
            fail($msg, (int)$code);
        }
        internal_error($e, 'admin_credit_user');
    }

    // Post-commit: ledger entry + audit
    record_balance_tx(
        $targetUserId,
        $type,
        $amount,
        $balanceBefore,
        $balanceAfter,
        null,   // no reference_id (not a bet)
        null,   // no market_id
        $note ?: "Admin credit by admin_id=$adminId"
    );

    audit_log('credit_user', $adminId, 'user', $targetUserId, [
        'username'       => $username,
        'type'           => $type,
        'amount'         => $amount,
        'balance_before' => $balanceBefore,
        'balance_after'  => $balanceAfter,
        'note'           => $note,
    ]);

    ok([
        'user_id'        => $targetUserId,
        'username'       => $username,
        'amount'         => $amount,
        'type'           => $type,
        'balance_before' => $balanceBefore,
        'balance_after'  => $balanceAfter,
        'note'           => $note,
    ], $amount >= 0
        ? 'Balance credited successfully'
        : 'Balance debited successfully'
    );
})(),


// =============================================================
//  POST ?route=admin_reopen_market
//  Body: { "market_id": "...", "reason": "..." }
//
//  Reopens a manually-closed market back to 'open' so bets can
//  resume. Only works on status='closed' markets.
//
//  Use admin_resume_market for paused markets.
//  Resolved and voided markets are permanent — cannot be reopened.
// =============================================================
$ROUTE === 'admin_reopen_market' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];
    require_fields($BODY, ['market_id']);
    $extMid = trim($BODY['market_id']);
    $reason = trim($BODY['reason'] ?? 'Reopened by admin');

    $pdo = db(); $pdo->beginTransaction();
    try {
        $mkt = $pdo->prepare('SELECT id,status,close_time FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mkt->execute([':mid' => $extMid]); $mkt = $mkt->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');

        if ($mkt['status'] === 'paused')
            throw new RuntimeException(
                'Market is paused, not closed. Use admin_resume_market to reopen a paused market.|409');
        if (in_array($mkt['status'], ['resolved','voided'], true))
            throw new RuntimeException(
                "Cannot reopen a {$mkt['status']} market — this state is permanent.|409");
        if ($mkt['status'] === 'open')
            throw new RuntimeException('Market is already open.|409');
        if ($mkt['status'] !== 'closed')
            throw new RuntimeException("Cannot reopen a market with status '{$mkt['status']}'.|409");

        // Clear close_time if it has already passed — without this, the pre-bet
        // auto-close transaction would fire on the very next bet and re-close
        // the market immediately after reopening.
        $clearCt = ($mkt['close_time'] && strtotime($mkt['close_time']) < time());
        $pdo->prepare("UPDATE markets SET status='open'" . ($clearCt ? ", close_time=NULL" : "") . " WHERE id=:mid")
            ->execute([':mid' => (int)$mkt['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_reopen_market');
    }
    audit_log('reopen_market', $adminId, 'market', (int)$mkt['id'],
        ['market_id' => $extMid, 'reason' => $reason,
         'close_time_cleared' => $clearCt ?? false]);
    ok(['market_id'          => $extMid,
        'status'             => 'open',
        'reason'             => $reason,
        'close_time_cleared' => $clearCt ?? false],
        'Market reopened — bets are now accepted');
})(),


// =============================================================
//  POST ?route=admin_set_liquidity
//  Authorization: Bearer <admin_token>
//
//  Update the LMSR liquidity parameter (b) for a market.
//
//  WHY THIS EXISTS
//  ─────────────────────────────────────────────────────────
//  The b parameter controls how much KES it takes to move the
//  LMSR price curve. A higher b means more volume is needed to
//  shift odds, making the market more stable. This route lets
//  admin adjust b on a live market without settling or voiding it.
//
//  IMPORTANT — LMSR PRICE SHIFT WARNING
//  ─────────────────────────────────────────────────────────
//  Changing b changes the effective odds computed from existing
//  shares: p_i = exp(s_i / b) / Σ exp(s_j / b). If you want to
//  maintain the current displayed odds after a b change, call
//  admin_reseed_odds immediately after this route to recalculate
//  the share vector for the new b.
//
//  FIXED-MODE MARKETS
//  ─────────────────────────────────────────────────────────
//  b is stored but NOT used for pricing in fixed mode. Updating
//  b on a fixed market is allowed (it will be applied if the
//  market is later switched to lmsr mode) and returns a warning.
//
//  Body:  { "market_id": "b2a4431b5ab65de9", "b": 500 }
//
//  Response:
//  {
//    "market_id": "b2a4431b5ab65de9",
//    "prev_b":    100,
//    "b":         500,
//    "odds_mode": "lmsr"
//  }
// =============================================================
$ROUTE === 'admin_set_liquidity' && $METHOD === 'POST' => (function () use ($BODY) {
    $ctx     = require_admin();
    $adminId = $ctx['user_id'];

    require_fields($BODY, ['market_id', 'b']);
    $extMid = trim($BODY['market_id']);
    $newB   = (float)$BODY['b'];

    // Validate b range (same limits enforced on market creation)
    if ($newB < LMSR_B_MIN) fail('b must be >= ' . LMSR_B_MIN . ' (production minimum)', 422);
    if ($newB > LMSR_B_MAX) fail('b must be <= ' . LMSR_B_MAX . ' (production maximum)', 422);

    $pdo = db(); $pdo->beginTransaction(); $mkt = null;
    try {
        $mktS = $pdo->prepare('SELECT id, status, b, odds_mode, market_id, total_wagered
            FROM markets WHERE market_id=:mid LIMIT 1 FOR UPDATE');
        $mktS->execute([':mid' => $extMid]); $mkt = $mktS->fetch();
        if (!$mkt) throw new RuntimeException('Market not found|404');
        if (in_array($mkt['status'], ['resolved', 'voided'], true))
            throw new RuntimeException("Cannot change b on a {$mkt['status']} market — status is permanent.|409");

        $prevB = (float)$mkt['b'];
        if (abs($prevB - $newB) < 0.0001) {
            $pdo->rollBack();
            fail('No change — b is already ' . $newB, 422);
        }

        $pdo->prepare('UPDATE markets SET b=:b WHERE id=:mid')
            ->execute([':b' => $newB, ':mid' => (int)$mkt['id']]);
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), '|')) { [$msg,$code]=explode('|',$e->getMessage(),2); fail($msg,(int)$code); }
        internal_error($e, 'admin_set_liquidity');
    }

    $oddsMode = $mkt['odds_mode'] ?? 'lmsr';
    $dbId     = (int)$mkt['id'];

    // Post-commit: record a market snapshot so the graph reflects the b change
    if ($oddsMode === 'lmsr') {
        $oRows = db()->prepare('SELECT id, name, shares, fixed_odds FROM outcomes WHERE market_id=:mid ORDER BY id');
        $oRows->execute([':mid' => $dbId]);
        $outcomeRows = $oRows->fetchAll();
        record_market_snapshot($dbId, $outcomeRows, $newB, (float)$mkt['total_wagered']);
    }

    audit_log('set_liquidity', $adminId, 'market', $dbId, [
        'market_id' => $extMid,
        'prev_b'    => $prevB,
        'b'         => $newB,
        'odds_mode' => $oddsMode,
    ]);

    $warnings = [];
    if ($oddsMode === 'lmsr') {
        $warnings[] = 'LMSR prices have shifted because b changed. '
            . 'Call admin_reseed_odds if you want to maintain the pre-change odds.';
    } else {
        $warnings[] = 'Market is in fixed mode — b is stored but not used for live pricing. '
            . 'This value will apply if the market is later switched to lmsr mode.';
    }

    ok(array_merge(
        ['market_id' => $extMid, 'prev_b' => $prevB, 'b' => $newB, 'odds_mode' => $oddsMode],
        ['warnings' => $warnings]
    ), 'Liquidity parameter updated');
})(),


// =============================================================
//  404 — Unknown route
//  Does NOT list available routes (security hardening).
// =============================================================
default => fail('Route not found', 404),

}; // end match
