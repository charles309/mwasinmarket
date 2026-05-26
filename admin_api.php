<?php
declare(strict_types=1);

/** MwasinMarket — Admin SMS, user controls, maintenance, notifications. Requires config.php + bootstrap.php. */

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
