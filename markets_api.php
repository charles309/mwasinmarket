<?php
declare(strict_types=1);

/** MwasinMarket — Markets, betting, LMSR engine, admin market routes. Requires config.php + bootstrap.php. */

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

function public_market_row(array $m, array $outcomes, int $totalHearts = 0): array {
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
        'total_hearts'      => $totalHearts,
        'is_archived'       => (bool)$m['is_archived'],
        'is_featured'       => (bool)($m['is_featured'] ?? 0),
        'outcomes'          => $outcomes,
        'created_at'        => $m['created_at'],
    ];
}

/** Bulk heart counts for a set of internal market ids → [marketId => count]. */
function hearts_for_markets(array $marketIds): array {
    if (empty($marketIds)) return [];
    $ph = implode(',', array_fill(0, count($marketIds), '?'));
    $stmt = db()->prepare("SELECT market_id, COUNT(*) c FROM reactions WHERE reaction='heart' AND market_id IN ({$ph}) GROUP BY market_id");
    $stmt->execute(array_map('intval', $marketIds));
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int)$r['market_id']] = (int)$r['c'];
    return $out;
}

// ============================================================
// ROUTE DISPATCH
// ============================================================
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
    if (!$includeArchived) {
        $where[] = 'is_archived = 0';
    }
    if (!empty($_GET['featured'])) {
        $where[] = 'is_featured = 1';
    }
    // Free-text search on the question/title (parameterised LIKE — no injection).
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $q = trim((string)$_GET['q']);
        if (mb_strlen($q, 'UTF-8') > 100) $q = mb_substr($q, 0, 100, 'UTF-8');
        $where[] = '(question LIKE :q OR title LIKE :q)';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    }

    // Sort: featured first always, then the chosen order.
    $sort = $_GET['sort'] ?? 'newest';
    $orderMap = [
        'newest'       => 'created_at DESC',
        'closing_soon' => 'close_time IS NULL ASC, close_time ASC',
        'volume'       => 'total_wagered DESC',
        'popular'      => 'total_bets DESC',
    ];
    if (!isset($orderMap[$sort])) fail('Invalid sort.', 422, ['sort']);
    $orderSql = 'is_featured DESC, ' . $orderMap[$sort];

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = db()->prepare("SELECT COUNT(*) c FROM markets {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];

    $sql = "SELECT * FROM markets {$whereSql} ORDER BY {$orderSql} LIMIT :lim OFFSET :off";
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
    $heartMap = hearts_for_markets($marketIds);

    $result = [];
    foreach ($markets as $m) {
        $rows = $byMarket[(int)$m['id']] ?? [];
        $snap = market_snapshot($rows, (float)$m['b'], (string)$m['odds_mode']);
        $result[] = public_market_row($m, $snap, $heartMap[(int)$m['id']] ?? 0);
    }

    ok([
        'data' => $result,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil($total / $limit), 'sort' => $sort],
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
    $hearts = hearts_for_markets([(int)$m['id']]);
    ok(public_market_row($m, $snap, $hearts[(int)$m['id']] ?? 0));
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

        $owin = $pdo->prepare("SELECT id, name FROM outcomes WHERE id = :oid AND market_id = :mid LIMIT 1");
        $owin->execute([':oid' => $winOid, ':mid' => $m['id']]);
        $winRow = $owin->fetch();
        if (!$winRow) { $pdo->rollBack(); fail('Winning outcome does not belong to this market.', 422); }

        $wstmt = $pdo->prepare("SELECT id, user_id, stake, possible_win, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' AND outcome_id = :oid FOR UPDATE");
        $wstmt->execute([':mid' => $m['id'], ':oid' => $winOid]);
        $winners = $wstmt->fetchAll();

        $lstmt = $pdo->prepare("SELECT id, user_id, stake, is_bonus_bet FROM bets WHERE market_id = :mid AND status = 'open' AND outcome_id != :oid FOR UPDATE");
        $lstmt->execute([':mid' => $m['id'], ':oid' => $winOid]);
        $losers = $lstmt->fetchAll();

        // Group everything by user_id BEFORE running updates, so we know
        // exactly which users to snapshot for the ledger.
        $userIds = [];
        $realCredit = []; $bonusCredit = []; $realUnlock = []; $bonusUnlock = [];
        $realWinAdd = [];
        foreach ($winners as $w) {
            $uid = (int)$w['user_id']; $userIds[$uid] = true;
            $payout = (float)$w['possible_win']; $stake = (float)$w['stake'];
            if ((int)$w['is_bonus_bet'] === 1) {
                $bonusCredit[$uid] = ($bonusCredit[$uid] ?? 0.0) + $payout;
                $bonusUnlock[$uid] = ($bonusUnlock[$uid] ?? 0.0) + $stake;
            } else {
                $realCredit[$uid]  = ($realCredit[$uid]  ?? 0.0) + $payout;
                $realUnlock[$uid]  = ($realUnlock[$uid]  ?? 0.0) + $stake;
                $realWinAdd[$uid]  = ($realWinAdd[$uid]  ?? 0.0) + $payout;
            }
        }
        $loserUnlockReal = []; $loserUnlockBonus = [];
        foreach ($losers as $l) {
            $uid = (int)$l['user_id']; $userIds[$uid] = true;
            $stake = (float)$l['stake'];
            if ((int)$l['is_bonus_bet'] === 1) $loserUnlockBonus[$uid] = ($loserUnlockBonus[$uid] ?? 0.0) + $stake;
            else                               $loserUnlockReal[$uid]  = ($loserUnlockReal[$uid]  ?? 0.0) + $stake;
        }

        // Snapshot affected users' balances BEFORE updates (locked rows for accurate before/after)
        $balBefore = [];
        if (!empty($userIds)) {
            $ids = array_keys($userIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $snap = $pdo->prepare("SELECT id, balance, bonus_balance FROM users WHERE id IN ({$ph}) FOR UPDATE");
            $snap->execute($ids);
            foreach ($snap->fetchAll() as $r) {
                $balBefore[(int)$r['id']] = ['balance' => (float)$r['balance'], 'bonus_balance' => (float)$r['bonus_balance']];
            }
        }

        // Mark winning bets
        if (!empty($winners)) {
            $winIds = array_map(fn($r) => (int)$r['id'], $winners);
            $ph = implode(',', array_fill(0, count($winIds), '?'));
            $upd = $pdo->prepare("UPDATE bets SET status='won', payout=possible_win WHERE id IN ({$ph})");
            $upd->execute($winIds);

            $uReal = $pdo->prepare("UPDATE users SET balance = balance + :add, locked_balance = locked_balance - :unl, total_wins = total_wins + :win, updated_at=NOW() WHERE id = :uid");
            foreach ($realCredit as $uid => $payout) {
                $uReal->execute([':add' => $payout, ':unl' => $realUnlock[$uid], ':win' => $realWinAdd[$uid], ':uid' => $uid]);
            }
            $uBonus = $pdo->prepare("UPDATE users SET bonus_balance = bonus_balance + :add, locked_balance = locked_balance - :unl, updated_at=NOW() WHERE id = :uid");
            foreach ($bonusCredit as $uid => $payout) {
                $uBonus->execute([':add' => $payout, ':unl' => $bonusUnlock[$uid], ':uid' => $uid]);
            }
        }

        // Mark losing bets
        if (!empty($losers)) {
            $loseIds = array_map(fn($r) => (int)$r['id'], $losers);
            $ph = implode(',', array_fill(0, count($loseIds), '?'));
            $upd = $pdo->prepare("UPDATE bets SET status='lost', payout=0 WHERE id IN ({$ph})");
            $upd->execute($loseIds);

            $uUnlock = $pdo->prepare("UPDATE users SET locked_balance = locked_balance - :unl, updated_at=NOW() WHERE id = :uid");
            foreach ($loserUnlockReal as $uid => $unl)  $uUnlock->execute([':unl' => $unl, ':uid' => $uid]);
            foreach ($loserUnlockBonus as $uid => $unl) $uUnlock->execute([':unl' => $unl, ':uid' => $uid]);
        }

        $mUpd = $pdo->prepare("UPDATE markets SET status='resolved', resolve_time=NOW(), updated_at=NOW() WHERE id=:id");
        $mUpd->execute([':id' => $m['id']]);

        $pdo->commit();

        // POST-COMMIT: ledger with accurate before/after, plus winner email notifications
        $totalRealPaid = 0.0; $totalBonusPaid = 0.0;
        foreach ($realCredit as $uid => $payout) {
            $bb = $balBefore[$uid]['balance'] ?? 0.0;
            $ba = $bb + $payout;  // balance increase from real wins
            record_balance_tx($uid, 'bet_won', $payout, $bb, $ba, null, (int)$m['id'], 'Won real bet on ' . $winRow['name']);
            $totalRealPaid += $payout;
            notify_user($uid, 'You won a bet!', "You won KES " . number_format($payout, 2) . " on '{$winRow['name']}'. Your balance has been credited.");
        }
        foreach ($bonusCredit as $uid => $payout) {
            $bb = $balBefore[$uid]['bonus_balance'] ?? 0.0;
            $ba = $bb + $payout;
            record_balance_tx($uid, 'bet_won', $payout, $bb, $ba, null, (int)$m['id'], 'Won bonus bet on ' . $winRow['name']);
            $totalBonusPaid += $payout;
        }
        foreach ($losers as $l) {
            $uid = (int)$l['user_id'];
            record_balance_tx($uid, 'bet_lost', 0.0, 0.0, 0.0, null, (int)$m['id'], 'Lost bet (market settled)');
        }

        // Market resolved → chat is no longer needed; purge it.
        purge_market_chats((int)$m['id']);

        audit_log('settle_market', (int)$admin['user_id'], 'market', (int)$m['id'], [
            'winning_outcome_id' => $winOid,
            'winning_outcome_name' => $winRow['name'],
            'winners_count'  => count($winners),
            'losers_count'   => count($losers),
            'total_paid_real'  => round($totalRealPaid, 2),
            'total_paid_bonus' => round($totalBonusPaid, 2),
        ]);

        ok([
            'market_id'       => $mid,
            'status'          => 'resolved',
            'winning_outcome' => ['outcome_id' => $winOid, 'name' => $winRow['name']],
            'winners_count'   => count($winners),
            'losers_count'    => count($losers),
            'bets_settled'    => count($winners) + count($losers),
            'total_payout'    => round($totalRealPaid + $totalBonusPaid, 2),
            'total_payout_real'  => round($totalRealPaid, 2),
            'total_payout_bonus' => round($totalBonusPaid, 2),
            'resolved_at'     => gmdate('Y-m-d H:i:s'),
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
        // Market voided → terminal; purge chat.
        purge_market_chats((int)$m['id']);
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

function handle_admin_feature_market(array $body): void {
    $admin = require_admin();
    require_fields($body, ['market_id']);
    $mid = validate_market_id($body['market_id']);
    $feature = array_key_exists('featured', $body) ? (bool)$body['featured'] : true;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $m = _admin_market_lock($pdo, $mid);
        $val = $feature ? 1 : 0;
        $upd = $pdo->prepare("UPDATE markets SET is_featured = :v, updated_at=NOW() WHERE id=:id");
        $upd->execute([':v' => $val, ':id' => $m['id']]);
        $pdo->commit();
        audit_log('feature_market', (int)$admin['user_id'], 'market', (int)$m['id'], ['featured' => (bool)$val]);
        ok(['market_id' => $mid, 'is_featured' => (bool)$val], $val ? 'Market featured' : 'Market unfeatured');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'feature_market');
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

    // Live exposure across the open book on every non-terminal market.
    // Cash-only (is_bonus_bet=0) so the numbers mean real-money risk.
    // max_liability_total: worst-case payout if the most-backed outcome on
    // every market wins; this is the upper bound on house cash outflow.
    $exposure = $pdo->query("
        SELECT
          COALESCE(SUM(t.open_stake),0)                    AS open_stake,
          COALESCE(SUM(t.max_outcome_payout),0)            AS max_liability_total,
          COUNT(DISTINCT CASE WHEN t.open_stake > 0 THEN t.market_id END) AS markets_with_open_book
        FROM (
          SELECT b.market_id,
                 SUM(b.stake)        AS open_stake,
                 MAX(per_outcome)    AS max_outcome_payout
          FROM bets b
          JOIN (
            SELECT market_id, outcome_id, SUM(possible_win) AS per_outcome
            FROM bets
            WHERE status = 'open' AND is_bonus_bet = 0
            GROUP BY market_id, outcome_id
          ) o ON o.market_id = b.market_id
          WHERE b.status = 'open' AND b.is_bonus_bet = 0
          GROUP BY b.market_id
        ) t
    ")->fetch();

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
        'exposure' => [
            'scope'                  => 'cash_only',
            'open_stake'             => (float)$exposure['open_stake'],
            'max_liability_total'    => (float)$exposure['max_liability_total'],
            'markets_with_open_book' => (int)$exposure['markets_with_open_book'],
            'net_if_worst_case'      => round((float)$exposure['open_stake'] - (float)$exposure['max_liability_total'], 2),
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

    // -------- What-if house P/L per potential settlement --------
    // We need open-stake per outcome (separate from non-void volume) so the
    // projection only counts money that is still at risk on this market.
    // Cash-only: bonus bets settle in bonus_balance (not real money) so
    // including them would inflate or distort house P/L numbers.
    $openByOut = db()->prepare("SELECT outcome_id,
        COALESCE(SUM(stake),0)        AS open_stake,
        COALESCE(SUM(possible_win),0) AS open_payout
        FROM bets
        WHERE market_id = :mid AND status = 'open' AND is_bonus_bet = 0
        GROUP BY outcome_id");
    $openByOut->execute([':mid' => $m['id']]);
    $openMap = [];
    foreach ($openByOut->fetchAll() as $r) {
        $openMap[(int)$r['outcome_id']] = [
            'open_stake'  => (float)$r['open_stake'],
            'open_payout' => (float)$r['open_payout'],
        ];
    }
    $totalOpenStake = 0.0;
    foreach ($openMap as $r) $totalOpenStake += $r['open_stake'];

    // For each outcome i, "if i wins now":
    //   house P/L = (every open bet's stake) - (open bets on i payout)
    // i.e. losers' stakes minus winners' payout, on the open book.
    $whatIfOutcomes = [];
    foreach ($snap as $s) {
        $oid = (int)$s['outcome_id'];
        $payout = $openMap[$oid]['open_payout'] ?? 0.0;
        $whatIfOutcomes[] = [
            'outcome_id'       => $oid,
            'outcome_name'     => $s['name'],
            'open_stake_for'   => round($openMap[$oid]['open_stake'] ?? 0.0, 2),
            'open_payout_if_wins' => round($payout, 2),
            'house_pl_if_wins'  => round($totalOpenStake - $payout, 2),
        ];
    }
    $whatIf = [
        'scope'                   => 'cash_only', // bonus bets excluded
        'total_open_stake'        => round($totalOpenStake, 2),
        'outcomes'                => $whatIfOutcomes,
        // Void refunds everyone: house earns zero from this market's open book.
        'house_pl_if_voided_now'  => 0.0,
        // "Market didn't happen, keep all stakes" — i.e. force-close with no
        // settlement, every open bet's stake is recognised as house revenue.
        'house_pl_if_kept_all'    => round($totalOpenStake, 2),
    ];

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
        'what_if'           => $whatIf,
        'graph_summary'     => $graphRows,
        'audit_log'         => $audit,
    ]);
}
