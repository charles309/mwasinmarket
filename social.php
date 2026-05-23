<?php
declare(strict_types=1);

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
