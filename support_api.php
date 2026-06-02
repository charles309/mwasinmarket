<?php
declare(strict_types=1);

/**
 * MwasinMarket — Support / contact channel.
 * A private 1:1 thread between a user and admins: the user opens a ticket
 * (issue, account/payment problem, or a market suggestion); admins reply
 * privately to that single user. No user ever sees another user's ticket.
 * Requires config.php + bootstrap.php.
 */

const SUPPORT_CATEGORIES = ['issue', 'suggestion', 'market_suggestion', 'payment', 'account', 'other'];

// ---- shared helpers -----------------------------------------------------

function _support_ticket_row(array $t): array {
    return [
        'ticket_id'       => (int)$t['id'],
        'category'        => $t['category'],
        'subject'         => $t['subject'],
        'status'          => $t['status'],
        'market_id'       => $t['market_id'] !== null ? (int)$t['market_id'] : null,
        'last_message_at' => $t['last_message_at'],
        'created_at'      => $t['created_at'],
    ];
}

function _support_messages(int $ticketId): array {
    $stmt = db()->prepare("
        SELECT m.id, m.sender_id, m.sender_role, m.body, m.created_at, u.username
        FROM support_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.ticket_id = :tid
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([':tid' => $ticketId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'message_id' => (int)$r['id'],
            'sender_id'  => (int)$r['sender_id'],
            'sender'     => $r['sender_role'],          // 'user' | 'admin'
            'username'   => $r['username'],
            'body'       => $r['body'],
            'sent_at'    => $r['created_at'],
        ];
    }
    return $out;
}

// ============================================================
// USER ROUTES
// ============================================================

function handle_support_create(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);
    check_rate_limit(20, 3600);  // 20 new tickets / IP / hour

    require_fields($body, ['category', 'subject', 'body']);
    $category = validate_enum($body['category'], SUPPORT_CATEGORIES, 'category');
    $subject  = validate_text($body['subject'], 'subject', 3, 150);
    $message  = validate_text($body['body'], 'body', 1, 2000);

    // Optional market link (e.g. a market suggestion or a complaint about a market).
    $marketDbId = null;
    if (isset($body['market_id']) && $body['market_id'] !== '' && $body['market_id'] !== null) {
        $mid = validate_market_id($body['market_id']);
        $mst = db()->prepare("SELECT id FROM markets WHERE market_id = :mid LIMIT 1");
        $mst->execute([':mid' => $mid]);
        $m = $mst->fetch();
        if (!$m) fail('Referenced market not found.', 404, ['market_id']);
        $marketDbId = (int)$m['id'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO support_tickets (user_id, category, subject, status, market_id, user_unread, admin_unread, last_message_at, created_at) VALUES (:uid, :cat, :subj, 'open', :mid, 0, 1, NOW(), NOW())");
        $ins->execute([':uid' => $auth['user_id'], ':cat' => $category, ':subj' => $subject, ':mid' => $marketDbId]);
        $ticketId = (int)$pdo->lastInsertId();

        $msg = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, body, created_at) VALUES (:tid, :uid, 'user', :b, NOW())");
        $msg->execute([':tid' => $ticketId, ':uid' => $auth['user_id'], ':b' => $message]);
        $pdo->commit();

        create_notification('support_ticket', "New {$category} ticket #{$ticketId}: {$subject}", $ticketId);
        ok([
            'ticket_id' => $ticketId,
            'category'  => $category,
            'subject'   => $subject,
            'status'    => 'open',
        ], 'Ticket created. Our team will reply here.', 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'support_create');
    }
}

function handle_support_tickets(): void {
    $auth = require_auth();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $count = db()->prepare("SELECT COUNT(*) c FROM support_tickets WHERE user_id = :uid");
    $count->execute([':uid' => $auth['user_id']]);
    $total = (int)$count->fetch()['c'];

    $stmt = db()->prepare("SELECT * FROM support_tickets WHERE user_id = :uid ORDER BY last_message_at DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue(':uid', (int)$auth['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $t) {
        $row = _support_ticket_row($t);
        $row['unread'] = (int)$t['user_unread'];   // replies awaiting the user
        $rows[] = $row;
    }
    ok([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_support_thread(): void {
    $auth = require_auth();
    $ticketId = strict_positive_int($_GET['ticket_id'] ?? null, 'ticket_id');

    $st = db()->prepare("SELECT * FROM support_tickets WHERE id = :id LIMIT 1");
    $st->execute([':id' => $ticketId]);
    $t = $st->fetch();
    if (!$t) fail('Ticket not found.', 404);
    if ((int)$t['user_id'] !== (int)$auth['user_id']) fail('You cannot view this ticket.', 403);

    // Opening the thread clears the user's unread counter.
    $clr = db()->prepare("UPDATE support_tickets SET user_unread = 0 WHERE id = :id");
    $clr->execute([':id' => $ticketId]);

    $row = _support_ticket_row($t);
    $row['messages'] = _support_messages($ticketId);
    ok($row);
}

function handle_support_reply(array $body): void {
    $auth = require_auth();
    if ($auth['is_suspended']) fail('Your account has been suspended.', 403);
    require_fields($body, ['ticket_id', 'body']);
    $ticketId = strict_positive_int($body['ticket_id'], 'ticket_id');
    $message  = validate_text($body['body'], 'body', 1, 2000);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM support_tickets WHERE id = :id FOR UPDATE");
        $st->execute([':id' => $ticketId]);
        $t = $st->fetch();
        if (!$t) { $pdo->rollBack(); fail('Ticket not found.', 404); }
        if ((int)$t['user_id'] !== (int)$auth['user_id']) { $pdo->rollBack(); fail('You cannot reply to this ticket.', 403); }
        if ($t['status'] === 'closed') { $pdo->rollBack(); fail('This ticket is closed. Open a new one.', 409); }

        $msg = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, body, created_at) VALUES (:tid, :uid, 'user', :b, NOW())");
        $msg->execute([':tid' => $ticketId, ':uid' => $auth['user_id'], ':b' => $message]);

        $upd = $pdo->prepare("UPDATE support_tickets SET status='open', admin_unread = admin_unread + 1, last_message_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $ticketId]);
        $pdo->commit();

        create_notification('support_reply', "User replied on ticket #{$ticketId}.", $ticketId);
        ok(['ticket_id' => $ticketId, 'status' => 'open'], 'Reply sent', 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'support_reply');
    }
}

function handle_support_close(array $body): void {
    $auth = require_auth();
    require_fields($body, ['ticket_id']);
    $ticketId = strict_positive_int($body['ticket_id'], 'ticket_id');

    $st = db()->prepare("SELECT user_id, status FROM support_tickets WHERE id = :id LIMIT 1");
    $st->execute([':id' => $ticketId]);
    $t = $st->fetch();
    if (!$t) fail('Ticket not found.', 404);
    if ((int)$t['user_id'] !== (int)$auth['user_id']) fail('You cannot close this ticket.', 403);

    $upd = db()->prepare("UPDATE support_tickets SET status='closed', last_message_at = NOW() WHERE id = :id");
    $upd->execute([':id' => $ticketId]);
    ok(['ticket_id' => $ticketId, 'status' => 'closed'], 'Ticket closed');
}

// ============================================================
// ADMIN ROUTES
// ============================================================

function handle_admin_support_tickets(): void {
    require_admin();
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = []; $params = [];
    $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
    if ($status !== '' && $status !== 'all') {
        $status = validate_enum($status, ['open', 'answered', 'closed'], 'status');
        $where[] = 't.status = :st'; $params[':st'] = $status;
    }
    if (isset($_GET['category']) && $_GET['category'] !== '') {
        $cat = validate_enum((string)$_GET['category'], SUPPORT_CATEGORIES, 'category');
        $where[] = 't.category = :cat'; $params[':cat'] = $cat;
    }
    if (!empty($_GET['unread'])) {
        $where[] = 't.admin_unread > 0';
    }
    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countQ = db()->prepare("SELECT COUNT(*) c FROM support_tickets t {$whereSql}");
    $countQ->execute($params);
    $total = (int)$countQ->fetch()['c'];

    $sql = "SELECT t.*, u.username, u.email, u.phone, mk.market_id AS market_pubid
            FROM support_tickets t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN markets mk ON mk.id = t.market_id
            {$whereSql}
            ORDER BY (t.admin_unread > 0) DESC, t.last_message_at DESC
            LIMIT :lim OFFSET :off";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $t) {
        $row = _support_ticket_row($t);
        $row['market_id'] = $t['market_pubid'] ?? null;  // expose public market id to admin
        $row['admin_unread'] = (int)$t['admin_unread'];
        $row['user'] = [
            'user_id'  => (int)$t['user_id'],
            'username' => $t['username'],
            'email'    => $t['email'],
            'phone'    => $t['phone'],
        ];
        $rows[] = $row;
    }
    ok([
        'data' => $rows,
        'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int)ceil(max(1, $total) / $limit)],
    ]);
}

function handle_admin_support_thread(): void {
    require_admin();
    $ticketId = strict_positive_int($_GET['ticket_id'] ?? null, 'ticket_id');

    $st = db()->prepare("SELECT t.*, u.username, u.email, u.phone, mk.market_id AS market_pubid
                         FROM support_tickets t
                         JOIN users u ON u.id = t.user_id
                         LEFT JOIN markets mk ON mk.id = t.market_id
                         WHERE t.id = :id LIMIT 1");
    $st->execute([':id' => $ticketId]);
    $t = $st->fetch();
    if (!$t) fail('Ticket not found.', 404);

    $clr = db()->prepare("UPDATE support_tickets SET admin_unread = 0 WHERE id = :id");
    $clr->execute([':id' => $ticketId]);

    $row = _support_ticket_row($t);
    $row['market_id'] = $t['market_pubid'] ?? null;
    $row['user'] = [
        'user_id'  => (int)$t['user_id'],
        'username' => $t['username'],
        'email'    => $t['email'],
        'phone'    => $t['phone'],
    ];
    $row['messages'] = _support_messages($ticketId);
    ok($row);
}

function handle_admin_support_reply(array $body): void {
    $admin = require_admin();
    require_fields($body, ['ticket_id', 'body']);
    $ticketId = strict_positive_int($body['ticket_id'], 'ticket_id');
    $message  = validate_text($body['body'], 'body', 1, 2000);
    // Accept either name for back-compat; default ON (admin replies usually want a heads-up email).
    $notify = array_key_exists('notify_email', $body)
        ? (bool)$body['notify_email']
        : (array_key_exists('notify_sms', $body) ? (bool)$body['notify_sms'] : true);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT t.* FROM support_tickets t WHERE t.id = :id FOR UPDATE");
        $st->execute([':id' => $ticketId]);
        $t = $st->fetch();
        if (!$t) { $pdo->rollBack(); fail('Ticket not found.', 404); }
        if ($t['status'] === 'closed') { $pdo->rollBack(); fail('Ticket is closed. Reopen by asking the user to start a new ticket.', 409); }

        $msg = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, body, created_at) VALUES (:tid, :aid, 'admin', :b, NOW())");
        $msg->execute([':tid' => $ticketId, ':aid' => $admin['user_id'], ':b' => $message]);

        $upd = $pdo->prepare("UPDATE support_tickets SET status='answered', user_unread = user_unread + 1, admin_unread = 0, last_message_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $ticketId]);
        $pdo->commit();

        audit_log('support_reply', (int)$admin['user_id'], 'ticket', $ticketId, ['user_id' => (int)$t['user_id']]);
        if ($notify) {
            notify_user((int)$t['user_id'], 'MwasinMarket support replied to your ticket', "We've replied to your support ticket #{$ticketId}. Sign in to read the message and continue the conversation.", (int)$admin['user_id']);
        }
        ok(['ticket_id' => $ticketId, 'status' => 'answered'], 'Reply sent to the user', 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        internal_error($e, 'admin_support_reply');
    }
}

function handle_admin_support_close(array $body): void {
    $admin = require_admin();
    require_fields($body, ['ticket_id']);
    $ticketId = strict_positive_int($body['ticket_id'], 'ticket_id');
    $note = isset($body['note']) ? validate_text($body['note'], 'note', 0, 500) : '';

    $st = db()->prepare("SELECT id FROM support_tickets WHERE id = :id LIMIT 1");
    $st->execute([':id' => $ticketId]);
    if (!$st->fetch()) fail('Ticket not found.', 404);

    $upd = db()->prepare("UPDATE support_tickets SET status='closed', last_message_at = NOW() WHERE id = :id");
    $upd->execute([':id' => $ticketId]);
    audit_log('support_close', (int)$admin['user_id'], 'ticket', $ticketId, ['note' => $note]);
    ok(['ticket_id' => $ticketId, 'status' => 'closed'], 'Ticket closed');
}
