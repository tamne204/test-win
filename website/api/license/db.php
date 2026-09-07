<?php
// Centralized PDO Database Layer for 2tamne.site (MySQL: ecxaebka_bot)

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $host   = 'localhost';
        $dbname = 'ecxaebka_bot';
        $user   = 'ecxaebka_bot';
        $pass   = 'JTV3SQ6bPqkwZ5UAVa7e';
        $dsn    = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    }
    return $pdo;
}

// ── USERS ────────────────────────────────────────────────────────
function db_get_users() {
    $stmt = get_db()->query("SELECT * FROM `users` ORDER BY `id` ASC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $u = $r['username'];
        // Fetch keys owned by user
        $k_stmt = get_db()->prepare("SELECT `license_key` FROM `licenses` WHERE `owner_username` = :u");
        $k_stmt->execute([':u' => $u]);
        $keys = $k_stmt->fetchAll(PDO::FETCH_COLUMN);

        $result[$u] = [
            'username' => $r['username'],
            'fullname' => $r['fullname'] ?? '',
            'phone' => $r['phone'] ?? '',
            'password_hash' => $r['password_hash'],
            'role' => $r['role'] ?? 'user',
            'registered_ip' => $r['registered_ip'] ?? '',
            'created_at' => $r['created_at'],
            'keys' => $keys
        ];
    }
    return $result;
}

function db_get_user($username) {
    $stmt = get_db()->prepare("SELECT * FROM `users` WHERE `username` = :u LIMIT 1");
    $stmt->execute([':u' => strtolower(trim($username))]);
    $r = $stmt->fetch();
    if (!$r) return null;

    $k_stmt = get_db()->prepare("SELECT `license_key` FROM `licenses` WHERE `owner_username` = :u");
    $k_stmt->execute([':u' => $r['username']]);
    $keys = $k_stmt->fetchAll(PDO::FETCH_COLUMN);

    return [
        'id' => $r['id'],
        'username' => $r['username'],
        'fullname' => $r['fullname'] ?? '',
        'phone' => $r['phone'] ?? '',
        'password_hash' => $r['password_hash'],
        'role' => $r['role'] ?? 'user',
        'registered_ip' => $r['registered_ip'] ?? '',
        'created_at' => $r['created_at'],
        'keys' => $keys
    ];
}

function db_create_user($username, $fullname, $phone, $password_hash, $registered_ip = '') {
    $stmt = get_db()->prepare("
        INSERT INTO `users` (`username`, `fullname`, `phone`, `password_hash`, `registered_ip`, `created_at`)
        VALUES (:u, :fn, :ph, :pw, :ip, NOW())
    ");
    return $stmt->execute([
        ':u'  => strtolower(trim($username)),
        ':fn' => trim($fullname),
        ':ph' => trim($phone),
        ':pw' => $password_hash,
        ':ip' => $registered_ip
    ]);
}

function db_update_user_password($username, $password_hash) {
    $stmt = get_db()->prepare("UPDATE `users` SET `password_hash` = :pw WHERE `username` = :u");
    return $stmt->execute([':pw' => $password_hash, ':u' => strtolower(trim($username))]);
}

function db_delete_user($username) {
    $db = get_db();
    $u = strtolower(trim($username));
    // Unassign keys
    $s1 = $db->prepare("UPDATE `licenses` SET `owner_username` = NULL WHERE `owner_username` = :u");
    $s1->execute([':u' => $u]);
    // Delete user
    $s2 = $db->prepare("DELETE FROM `users` WHERE `username` = :u");
    return $s2->execute([':u' => $u]);
}

// ── LICENSES ─────────────────────────────────────────────────────
function db_get_licenses() {
    $stmt = get_db()->query("SELECT * FROM `licenses` ORDER BY `id` DESC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[$r['license_key']] = [
            'tier' => $r['tier'] ?? 'VIP',
            'product' => $r['product'] ?? 'SLIDESHOW',
            'duration_days' => intval($r['duration_days'] ?? 30),
            'status' => $r['status'] ?? 'active',
            'hwid' => $r['hwid'] ?? '',
            'device_name' => $r['device_name'] ?? '',
            'tool_version' => $r['tool_version'] ?? '',
            'owner_user' => $r['owner_username'] ?? '',
            'note' => $r['note'] ?? '',
            'activated_at' => $r['activated_at'] ?? '',
            'expires_at' => $r['expires_at'] ?? '',
            'created_at' => $r['created_at']
        ];
    }
    return $result;
}

function db_get_license($key) {
    $stmt = get_db()->prepare("SELECT * FROM `licenses` WHERE `license_key` = :k LIMIT 1");
    $stmt->execute([':k' => trim($key)]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'id' => $r['id'],
        'license_key' => $r['license_key'],
        'tier' => $r['tier'] ?? 'VIP',
        'product' => $r['product'] ?? 'SLIDESHOW',
        'duration_days' => intval($r['duration_days'] ?? 30),
        'status' => $r['status'] ?? 'active',
        'hwid' => $r['hwid'] ?? '',
        'device_name' => $r['device_name'] ?? '',
        'tool_version' => $r['tool_version'] ?? '',
        'owner_user' => $r['owner_username'] ?? '',
        'note' => $r['note'] ?? '',
        'activated_at' => $r['activated_at'] ?? '',
        'expires_at' => $r['expires_at'] ?? '',
        'created_at' => $r['created_at']
    ];
}

function db_create_license($key, $product, $tier, $duration_days, $owner_user = '', $note = '') {
    $stmt = get_db()->prepare("
        INSERT INTO `licenses` (`license_key`, `product`, `tier`, `duration_days`, `status`, `owner_username`, `note`, `created_at`)
        VALUES (:k, :prod, :tier, :dur, 'active', :owner, :note, NOW())
    ");
    return $stmt->execute([
        ':k'     => trim($key),
        ':prod'  => $product,
        ':tier'  => $tier,
        ':dur'   => $duration_days,
        ':owner' => !empty($owner_user) ? strtolower(trim($owner_user)) : null,
        ':note'  => $note
    ]);
}

function db_assign_license_user($key, $owner_user) {
    $stmt = get_db()->prepare("UPDATE `licenses` SET `owner_username` = :owner WHERE `license_key` = :k");
    return $stmt->execute([
        ':owner' => !empty($owner_user) ? strtolower(trim($owner_user)) : null,
        ':k'     => trim($key)
    ]);
}

function db_reset_license_hwid($key) {
    $stmt = get_db()->prepare("UPDATE `licenses` SET `hwid` = NULL, `device_name` = NULL WHERE `license_key` = :k");
    return $stmt->execute([':k' => trim($key)]);
}

function db_toggle_license_ban($key, $new_status) {
    $stmt = get_db()->prepare("UPDATE `licenses` SET `status` = :st WHERE `license_key` = :k");
    return $stmt->execute([':st' => $new_status, ':k' => trim($key)]);
}

function db_delete_license($key) {
    $stmt = get_db()->prepare("DELETE FROM `licenses` WHERE `license_key` = :k");
    return $stmt->execute([':k' => trim($key)]);
}

// ── ORDERS ───────────────────────────────────────────────────────
function db_get_orders() {
    $stmt = get_db()->query("SELECT * FROM `orders` ORDER BY `created_at` DESC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id' => $r['id'],
            'user' => $r['username'],
            'fullname' => $r['fullname'] ?? '',
            'phone' => $r['phone'] ?? '',
            'product' => $r['product'] ?? 'SLIDESHOW',
            'package_name' => $r['package_name'] ?? '',
            'package_price' => $r['package_price'] ?? '',
            'duration_days' => intval($r['duration_days'] ?? 30),
            'tier' => $r['tier'] ?? 'VIP',
            'memo' => $r['memo'] ?? '',
            'status' => $r['status'] ?? 'pending',
            'issued_key' => $r['issued_key'] ?? '',
            'approved_at' => $r['approved_at'] ?? '',
            'created_at' => $r['created_at']
        ];
    }
    return $result;
}

function db_create_order($order_id, $username, $fullname, $phone, $product, $package_name, $package_price, $duration_days, $tier, $memo) {
    $stmt = get_db()->prepare("
        INSERT INTO `orders` (`id`, `username`, `fullname`, `phone`, `product`, `package_name`, `package_price`, `duration_days`, `tier`, `memo`, `status`, `created_at`)
        VALUES (:id, :u, :fn, :ph, :prod, :pname, :pprice, :dur, :tier, :memo, 'pending', NOW())
    ");
    return $stmt->execute([
        ':id'     => $order_id,
        ':u'      => strtolower(trim($username)),
        ':fn'     => $fullname,
        ':ph'     => $phone,
        ':prod'   => $product,
        ':pname'  => $package_name,
        ':pprice' => $package_price,
        ':dur'    => $duration_days,
        ':tier'   => $tier,
        ':memo'   => $memo
    ]);
}

function db_approve_order($order_id, $issued_key) {
    $stmt = get_db()->prepare("
        UPDATE `orders` SET `status` = 'approved', `issued_key` = :ikey, `approved_at` = NOW()
        WHERE `id` = :id
    ");
    return $stmt->execute([':ikey' => $issued_key, ':id' => $order_id]);
}

function db_reject_order($order_id) {
    $stmt = get_db()->prepare("UPDATE `orders` SET `status` = 'rejected' WHERE `id` = :id");
    return $stmt->execute([':id' => $order_id]);
}

// ── TRIAL IPS ────────────────────────────────────────────────────
function db_is_ip_trial_claimed($ip, $product = 'SLIDESHOW') {
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM `trial_ips` WHERE `ip_address` = :ip AND `product` = :prod");
    $stmt->execute([':ip' => trim($ip), ':prod' => $product]);
    return ($stmt->fetchColumn() > 0);
}

function db_claim_ip_trial($ip, $username, $product = 'SLIDESHOW') {
    $stmt = get_db()->prepare("
        INSERT IGNORE INTO `trial_ips` (`ip_address`, `product`, `user_id`, `claimed_at`)
        VALUES (:ip, :prod, :u, NOW())
    ");
    return $stmt->execute([':ip' => trim($ip), ':prod' => $product, ':u' => $username]);
}

// ── TICKETS (FEATURES & BUGS) ────────────────────────────────────
function db_get_features() {
    $stmt = get_db()->query("SELECT * FROM `tickets_features` ORDER BY `created_at` DESC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id' => $r['id'],
            'user' => $r['username'],
            'title' => $r['title'],
            'description' => $r['description'],
            'status' => $r['status'],
            'created_at' => $r['created_at']
        ];
    }
    return $result;
}

function db_create_feature($id, $username, $title, $description) {
    $stmt = get_db()->prepare("
        INSERT INTO `tickets_features` (`id`, `username`, `title`, `description`, `status`, `created_at`)
        VALUES (:id, :u, :t, :d, 'Đang xem xét', NOW())
    ");
    return $stmt->execute([':id' => $id, ':u' => $username, ':t' => $title, ':d' => $description]);
}

function db_update_feature_status($id, $status) {
    $stmt = get_db()->prepare("UPDATE `tickets_features` SET `status` = :st WHERE `id` = :id");
    return $stmt->execute([':st' => $status, ':id' => $id]);
}

function db_delete_feature($id) {
    $stmt = get_db()->prepare("DELETE FROM `tickets_features` WHERE `id` = :id");
    return $stmt->execute([':id' => $id]);
}

function db_get_bugs() {
    $stmt = get_db()->query("SELECT * FROM `tickets_bugs` ORDER BY `created_at` DESC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id' => $r['id'],
            'user' => $r['username'],
            'title' => $r['title'],
            'description' => $r['description'],
            'error_code' => $r['error_code'] ?? '',
            'status' => $r['status'],
            'created_at' => $r['created_at']
        ];
    }
    return $result;
}

function db_create_bug($id, $username, $title, $description, $error_code = '') {
    $stmt = get_db()->prepare("
        INSERT INTO `tickets_bugs` (`id`, `username`, `title`, `description`, `error_code`, `status`, `created_at`)
        VALUES (:id, :u, :t, :d, :err, 'Đã tiếp nhận', NOW())
    ");
    return $stmt->execute([':id' => $id, ':u' => $username, ':t' => $title, ':d' => $description, ':err' => $error_code]);
}

function db_update_bug_status($id, $status) {
    $stmt = get_db()->prepare("UPDATE `tickets_bugs` SET `status` = :st WHERE `id` = :id");
    return $stmt->execute([':st' => $status, ':id' => $id]);
}

function db_delete_bug($id) {
    $stmt = get_db()->prepare("DELETE FROM `tickets_bugs` WHERE `id` = :id");
    return $stmt->execute([':id' => $id]);
}

// ── SYSTEM CONFIG ────────────────────────────────────────────────
function db_get_system_config() {
    $stmt = get_db()->query("SELECT `config_key`, `config_value` FROM `system_config`");
    $rows = $stmt->fetchAll();
    $result = [
        'app_version' => '2.0.0',
        'download_url' => '/downloads/SlideshowBuilder_v2.0.0.zip',
        'release_notes' => '',
        'broadcast_notice' => [
            'id' => '',
            'active' => false,
            'type' => 'info',
            'title' => '',
            'content' => '',
            'button_text' => '',
            'button_url' => '',
            'created_at' => '',
            'read_count' => 0
        ]
    ];
    foreach ($rows as $r) {
        $k = $r['config_key'];
        $v = $r['config_value'];
        $decoded = json_decode($v, true);
        $result[$k] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $v;
    }
    return $result;
}

function db_save_system_config($data) {
    $stmt = get_db()->prepare("
        INSERT INTO `system_config` (`config_key`, `config_value`)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`)
    ");
    foreach ($data as $k => $v) {
        $val = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
        $stmt->execute([':k' => $k, ':v' => $val]);
    }
}
?>