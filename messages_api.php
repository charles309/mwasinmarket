<?php
declare(strict_types=1);

/**
 * MwasinMarket — Market Chat
 * Chat is scoped to a single market. There are NO user-to-user direct messages.
 * When a market is settled or voided, purge_market_chats() (in bootstrap.php)
 * deletes every chat row for that market automatically.
 * Requires config.php + bootstrap.php.
 */

function handle_post_market_chat(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);

    // Messaging restriction also gates market chat.
    $u = db()->prepare("SELECT messaging_restricted FROM users WHERE id = :uid");
    $u->execute([':uid' => $auth['user_id']]);
    $userRow = $u->fetch();
    if ($userRow && (int)$userRow['messaging_restricted'] === 1) {
        fail('Your messaging ability has been restricted by admin', 403);
    }

    require_fields($body, ['market_id', 'body']);
    $mid  = validate_market_id($body['market_id']);
    $text = validate_text($body['body'], 'body', 1, 1000);

    $mst = db()->prepare("SELECT id, status FROM markets WHERE market_id = :mid LIMIT 1");
    $mst->execute([':mid' => $mid]);
    $m = $mst->fetch();
    if (!$m) fail('Market not found.', 404);
    if (in_array($m['status'], ['resolved', 'voided'], true)) {
        fail('Chat is closed for resolved or voided markets.', 409);
    }

    $stickerId = null;
    if (isset($body['sticker_id']) && $body['sticker_id'] !== null && $body['sticker_id'] !== '') {
        $stickerId = strict_positive_int($body['sticker_id'], 'sticker_id');
        $sst = db()->prepare("SELECT id FROM stickers WHERE id = :id AND is_active = 1");
        $sst->execute([':id' => $stickerId]);
        if (!$sst->fetch()) fail('Sticker not found or inactive.', 404, ['sticker_id']);
    }

    $ins = db()->prepare("INSERT INTO market_chats (market_id, user_id, body, sticker_id, is_deleted, created_at) VALUES (:mid, :uid, :b, :s, 0, NOW())");
    $ins->execute([':mid' => $m['id'], ':uid' => $auth['user_id'], ':b' => $text, ':s' => $stickerId]);
    $chatId = (int)db()->lastInsertId();

    ok([
        'chat_id'    => $chatId,
        'market_id'  => $mid,
        'body'       => $text,
        'sticker_id' => $stickerId,
        'posted_at'  => gmdate('Y-m-d H:i:s'),
    ], 'Message posted', 201);
}

function handle_market_chat(): void {
    $mid = validate_market_id($_GET['market_id'] ?? '');
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $mst = db()->prepare("SELECT id, status FROM markets WHERE market_id = :mid LIMIT 1");
    $mst->execute([':mid' => $mid]);
    $m = $mst->fetch();
    if (!$m) fail('Market not found.', 404);

    $count = db()->prepare("SELECT COUNT(*) c FROM market_chats WHERE market_id = :mid AND is_deleted = 0");
    $count->execute([':mid' => $m['id']]);
    $total = (int)$count->fetch()['c'];

    $stmt = db()->prepare("
        SELECT c.id, c.user_id, c.body, c.sticker_id, c.created_at,
               u.username,
               s.name AS sticker_name, s.filename AS sticker_filename, s.category AS sticker_category
        FROM market_chats c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN stickers s ON s.id = c.sticker_id
        WHERE c.market_id = :mid AND c.is_deleted = 0
        ORDER BY c.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':mid', (int)$m['id'], PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $base = STICKER_BASE_URL !== '' ? rtrim(STICKER_BASE_URL, '/') : '';
    $msgs = [];
    foreach ($rows as $r) {
        $msg = [
            'chat_id'   => (int)$r['id'],
            'user_id'   => (int)$r['user_id'],
            'username'  => $r['username'],
            'body'      => $r['body'],
            'sticker'   => null,
            'posted_at' => $r['created_at'],
        ];
        if ($r['sticker_id'] !== null) {
            $msg['sticker'] = [
                'sticker_id' => (int)$r['sticker_id'],
                'name'       => $r['sticker_name'],
                'category'   => $r['sticker_category'],
                'filename'   => $r['sticker_filename'],
                'url'        => $base !== '' ? $base . '/' . $r['sticker_filename'] : null,
            ];
        }
        $msgs[] = $msg;
    }

    ok([
        'data' => $msgs,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_delete_market_chat(array $body): void {
    $auth = require_auth();
    require_fields($body, ['chat_id']);
    $chatId = strict_positive_int($body['chat_id'], 'chat_id');

    $st = db()->prepare("SELECT id, user_id, is_deleted FROM market_chats WHERE id = :id LIMIT 1");
    $st->execute([':id' => $chatId]);
    $chat = $st->fetch();
    if (!$chat) fail('Message not found.', 404);

    $isOwner = ((int)$chat['user_id'] === (int)$auth['user_id']);
    $isAdmin = ($auth['role'] === 'admin');
    if (!$isOwner && !$isAdmin) fail('You cannot delete this message.', 403);
    if ((int)$chat['is_deleted'] === 1) ok(['chat_id' => $chatId, 'deleted' => true]);

    $reason = ($isAdmin && isset($body['reason'])) ? validate_text($body['reason'], 'reason', 0, 200) : null;
    $upd = db()->prepare("UPDATE market_chats SET is_deleted = 1, deleted_by = :by, deleted_reason = :r WHERE id = :id");
    $upd->execute([':by' => $auth['user_id'], ':r' => $reason, ':id' => $chatId]);

    if ($isAdmin && !$isOwner) {
        audit_log('delete_market_chat', (int)$auth['user_id'], 'chat', $chatId, ['reason' => $reason]);
    }
    ok(['chat_id' => $chatId, 'deleted' => true]);
}
