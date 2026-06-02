<?php
declare(strict_types=1);

/** MwasinMarket — Reactions + stickers. Requires config.php + bootstrap.php. */

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
// STICKER PACKS
// ============================================================

/** slugify "Sports Premier" -> "sports-premier" (a-z0-9- only, max 60). */
function _pack_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'pack-' . bin2hex(random_bytes(3));
    return substr($s, 0, 60);
}

function _sticker_url(string $filename): ?string {
    return STICKER_BASE_URL !== '' ? rtrim(STICKER_BASE_URL, '/') . '/' . $filename : null;
}

/**
 * Public: list every active pack, each one carrying its active stickers.
 * Stickers with no pack (legacy) are returned under a synthetic pack
 * with pack_id = null so the UI can still render them.
 */
function handle_stickers_list(): void {
    $packs = db()->query("SELECT id, name, slug, description, is_default FROM sticker_packs WHERE is_active = 1 ORDER BY is_default DESC, id ASC")->fetchAll();
    $stickers = db()->query("SELECT id, pack_id, name, filename, mime_type, file_size, category, is_pack_default FROM stickers WHERE is_active = 1 ORDER BY pack_id ASC, id ASC")->fetchAll();

    $byPack = [];
    foreach ($stickers as $s) {
        $key = $s['pack_id'] === null ? 'null' : (string)(int)$s['pack_id'];
        $byPack[$key][] = [
            'sticker_id'      => (int)$s['id'],
            'name'            => $s['name'],
            'pack_id'         => $s['pack_id'] === null ? null : (int)$s['pack_id'],
            'category'        => $s['category'],   // retained for legacy clients
            'filename'        => $s['filename'],
            'mime_type'       => $s['mime_type'],
            'file_size'       => (int)$s['file_size'],
            'is_pack_default' => (bool)$s['is_pack_default'],
            'url'             => _sticker_url($s['filename']),
        ];
    }

    $out = [];
    foreach ($packs as $p) {
        $pid = (int)$p['id'];
        $items = $byPack[(string)$pid] ?? [];
        $out[] = [
            'pack_id'     => $pid,
            'name'        => $p['name'],
            'slug'        => $p['slug'],
            'description' => $p['description'],
            'is_default'  => (bool)$p['is_default'],
            'sticker_count' => count($items),
            'stickers'    => $items,
        ];
    }
    if (!empty($byPack['null'])) {
        $out[] = [
            'pack_id'       => null,
            'name'          => 'Uncategorised',
            'slug'          => 'uncategorised',
            'description'   => 'Stickers that are not assigned to a pack.',
            'is_default'    => false,
            'sticker_count' => count($byPack['null']),
            'stickers'      => $byPack['null'],
        ];
    }
    ok(['packs' => $out]);
}

/**
 * Resolve a pack reference from the request body: accepts pack_id (preferred)
 * or pack_slug. Returns the row, or fail()s the request.
 */
function _resolve_pack(?int $packId, ?string $packSlug): array {
    if ($packId !== null && $packId > 0) {
        $st = db()->prepare("SELECT id, name, slug FROM sticker_packs WHERE id = :id AND is_active = 1 LIMIT 1");
        $st->execute([':id' => $packId]);
        $p = $st->fetch();
        if (!$p) fail('Pack not found or inactive.', 404, ['pack_id']);
        return $p;
    }
    if ($packSlug !== null && $packSlug !== '') {
        $st = db()->prepare("SELECT id, name, slug FROM sticker_packs WHERE slug = :s AND is_active = 1 LIMIT 1");
        $st->execute([':s' => $packSlug]);
        $p = $st->fetch();
        if (!$p) fail('Pack not found or inactive.', 404, ['pack_slug']);
        return $p;
    }
    fail('pack_id or pack_slug required.', 422, ['pack_id']);
}

function handle_admin_create_sticker_pack(array $body): void {
    $admin = require_admin();
    require_fields($body, ['name']);
    $name = validate_text($body['name'], 'name', 2, 60);
    $description = isset($body['description']) ? validate_text($body['description'], 'description', 0, 300) : '';
    $slug = isset($body['slug']) ? _pack_slug(validate_text($body['slug'], 'slug', 1, 60)) : _pack_slug($name);

    try {
        $ins = db()->prepare("INSERT INTO sticker_packs (name, slug, description, is_active, is_default, created_by, created_at) VALUES (:n, :s, :d, 1, 0, :cb, NOW())");
        $ins->execute([':n' => $name, ':s' => $slug, ':d' => $description, ':cb' => $admin['user_id']]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) fail('A pack with that slug already exists.', 409, ['slug']);
        throw $e;
    }
    $pid = (int)db()->lastInsertId();
    audit_log('create_sticker_pack', (int)$admin['user_id'], 'sticker_pack', $pid, ['name' => $name, 'slug' => $slug]);
    ok([
        'pack_id'     => $pid,
        'name'        => $name,
        'slug'        => $slug,
        'description' => $description,
        'is_active'   => true,
        'is_default'  => false,
    ], 'Sticker pack created', 201);
}

function handle_admin_edit_sticker_pack(array $body): void {
    $admin = require_admin();
    require_fields($body, ['pack_id']);
    $pid = strict_positive_int($body['pack_id'], 'pack_id');

    $st = db()->prepare("SELECT * FROM sticker_packs WHERE id = :id LIMIT 1");
    $st->execute([':id' => $pid]);
    $p = $st->fetch();
    if (!$p) fail('Pack not found.', 404);

    $sets = []; $params = [':id' => $pid]; $changes = [];
    if (isset($body['name'])) {
        $n = validate_text($body['name'], 'name', 2, 60);
        $sets[] = 'name = :n'; $params[':n'] = $n; $changes['name'] = $n;
    }
    if (isset($body['description'])) {
        $d = validate_text($body['description'], 'description', 0, 300);
        $sets[] = 'description = :d'; $params[':d'] = $d; $changes['description'] = $d;
    }
    if (isset($body['is_active'])) {
        $v = filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $sets[] = 'is_active = :ia'; $params[':ia'] = $v; $changes['is_active'] = (bool)$v;
    }
    if (empty($sets)) fail('No editable fields provided.', 422);

    try {
        $upd = db()->prepare("UPDATE sticker_packs SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id");
        $upd->execute($params);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) fail('Slug already in use.', 409);
        throw $e;
    }
    audit_log('edit_sticker_pack', (int)$admin['user_id'], 'sticker_pack', $pid, $changes);
    ok(['pack_id' => $pid, 'changes' => $changes], 'Pack updated');
}

function handle_admin_delete_sticker_pack(array $body): void {
    $admin = require_admin();
    require_fields($body, ['pack_id']);
    $pid = strict_positive_int($body['pack_id'], 'pack_id');

    $st = db()->prepare("SELECT id, is_default FROM sticker_packs WHERE id = :id LIMIT 1");
    $st->execute([':id' => $pid]);
    $p = $st->fetch();
    if (!$p) fail('Pack not found.', 404);
    if ((int)$p['is_default'] === 1) fail('Default packs cannot be deleted — deactivate instead.', 409);

    // Soft delete: deactivate the pack. The FK on stickers is ON DELETE SET NULL,
    // so we *could* hard-delete, but soft-delete preserves history for old chats.
    $upd = db()->prepare("UPDATE sticker_packs SET is_active = 0, updated_at = NOW() WHERE id = :id");
    $upd->execute([':id' => $pid]);
    audit_log('delete_sticker_pack', (int)$admin['user_id'], 'sticker_pack', $pid, []);
    ok(['pack_id' => $pid, 'deleted' => true], 'Pack deactivated');
}

// ============================================================
// STICKERS
// ============================================================

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

    // pack_id or pack_slug is required; category retained for legacy clients.
    $packId   = isset($_POST['pack_id'])   ? strict_positive_int($_POST['pack_id'], 'pack_id') : null;
    $packSlug = isset($_POST['pack_slug']) && is_string($_POST['pack_slug']) ? trim($_POST['pack_slug']) : null;
    $pack = _resolve_pack($packId, $packSlug);

    // Keep populating the legacy category column for old clients (defaults to pack name).
    $category = isset($_POST['category']) ? validate_text($_POST['category'], 'category', 1, 40) : (string)$pack['name'];

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

    $ins = db()->prepare("INSERT INTO stickers (pack_id, name, filename, mime_type, file_size, category, is_active, is_pack_default, uploaded_by, created_at) VALUES (:pid, :n, :f, :m, :sz, :c, 1, 0, :ub, NOW())");
    $ins->execute([
        ':pid' => (int)$pack['id'], ':n' => $name, ':f' => $filename, ':m' => $mime,
        ':sz' => (int)$f['size'], ':c' => $category, ':ub' => $admin['user_id'],
    ]);
    $sid = (int)db()->lastInsertId();
    audit_log('upload_sticker', (int)$admin['user_id'], 'sticker', $sid, [
        'name' => $name, 'pack_id' => (int)$pack['id'], 'pack_slug' => $pack['slug'], 'filename' => $filename,
    ]);

    ok([
        'sticker_id' => $sid,
        'name'       => $name,
        'pack_id'    => (int)$pack['id'],
        'pack_name'  => (string)$pack['name'],
        'pack_slug'  => (string)$pack['slug'],
        'category'   => $category,
        'filename'   => $filename,
        'size_bytes' => (int)$f['size'],
        'mime_type'  => $mime,
        'url'        => _sticker_url($filename),
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

