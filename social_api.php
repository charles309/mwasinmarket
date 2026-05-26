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

