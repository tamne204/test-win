<?php
// Centralized PDO Database Layer for 2tamne.site (MySQL: ecxaebka_bot)

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $host   = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: '2tamne_site';
        $user   = getenv('DB_USER') ?: '2tamne_site';
        $pass   = getenv('DB_PASS') ?: 'CyRzJKKmWf';
        $dsn    = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }
    return $pdo;
}

// ── USERS ────────────────────────────────────────────────────────
function db_ensure_users_permissions_column() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        get_db()->exec("ALTER TABLE `users` ADD COLUMN `permissions` TEXT NULL AFTER `role`");
    } catch (Exception $e) {
        // Column already exists or table cannot be altered
    }
}

function db_get_users() {
    db_ensure_users_permissions_column();
    $stmt = get_db()->query("SELECT * FROM `users` ORDER BY `id` ASC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $u = $r['username'];
        $uid = $r['id'];

        // Fetch keys owned by user
        $k_stmt = get_db()->prepare("SELECT `license_key` FROM `licenses` WHERE `owner_username` = :u");
        $k_stmt->execute([':u' => $u]);
        $keys = $k_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch wallet balance
        $w_stmt = get_db()->prepare("SELECT balance, reserved_balance FROM `credit_wallets` WHERE `user_id` = :uid LIMIT 1");
        $w_stmt->execute([':uid' => $uid]);
        $w_row = $w_stmt->fetch();
        $token_balance = $w_row ? (int)$w_row['balance'] : 50;
        $token_reserved = $w_row ? (int)$w_row['reserved_balance'] : 0;

        $perms = [];
        if (!empty($r['permissions'])) {
            $dec = json_decode($r['permissions'], true);
            if (is_array($dec)) $perms = $dec;
        }

        $result[$u] = [
            'id' => $r['id'],
            'username' => $r['username'],
            'fullname' => $r['fullname'] ?? '',
            'phone' => $r['phone'] ?? '',
            'password_hash' => $r['password_hash'],
            'role' => $r['role'] ?? 'user',
            'permissions' => $perms,
            'token_balance' => $token_balance,
            'token_reserved' => $token_reserved,
            'registered_ip' => $r['registered_ip'] ?? '',
            'created_at' => $r['created_at'],
            'keys' => $keys
        ];
    }
    return $result;
}

function db_get_user($username) {
    db_ensure_users_permissions_column();
    $stmt = get_db()->prepare("SELECT * FROM `users` WHERE `username` = :u LIMIT 1");
    $stmt->execute([':u' => strtolower(trim($username))]);
    $r = $stmt->fetch();
    if (!$r) return null;

    $uid = $r['id'];
    $k_stmt = get_db()->prepare("SELECT `license_key` FROM `licenses` WHERE `owner_username` = :u");
    $k_stmt->execute([':u' => $r['username']]);
    $keys = $k_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch wallet balance
    $w_stmt = get_db()->prepare("SELECT balance, reserved_balance FROM `credit_wallets` WHERE `user_id` = :uid LIMIT 1");
    $w_stmt->execute([':uid' => $uid]);
    $w_row = $w_stmt->fetch();
    $token_balance = $w_row ? (int)$w_row['balance'] : 50;
    $token_reserved = $w_row ? (int)$w_row['reserved_balance'] : 0;

    $perms = [];
    if (!empty($r['permissions'])) {
        $dec = json_decode($r['permissions'], true);
        if (is_array($dec)) $perms = $dec;
    }

    return [
        'id' => $r['id'],
        'username' => $r['username'],
        'fullname' => $r['fullname'] ?? '',
        'phone' => $r['phone'] ?? '',
        'password_hash' => $r['password_hash'],
        'role' => $r['role'] ?? 'user',
        'permissions' => $perms,
        'token_balance' => $token_balance,
        'token_reserved' => $token_reserved,
        'registered_ip' => $r['registered_ip'] ?? '',
        'created_at' => $r['created_at'],
        'keys' => $keys
    ];
}

function db_create_user($username, $fullname, $phone, $password_hash, $registered_ip = '') {
    $u = strtolower(trim($username));
    $stmt = get_db()->prepare("
        INSERT INTO `users` (`username`, `fullname`, `phone`, `password_hash`, `registered_ip`, `created_at`)
        VALUES (:u, :fn, :ph, :pw, :ip, NOW())
    ");
    $ok = $stmt->execute([
        ':u'  => $u,
        ':fn' => trim($fullname),
        ':ph' => trim($phone),
        ':pw' => $password_hash,
        ':ip' => $registered_ip
    ]);

    if ($ok) {
        $userId = get_db()->lastInsertId();
        if ($userId) {
            db_ensure_user_wallet($userId, 50);
        }
    }
    return $ok;
}

function db_update_user_password($username, $password_hash) {
    $stmt = get_db()->prepare("UPDATE `users` SET `password_hash` = :pw WHERE `username` = :u");
    return $stmt->execute([':pw' => $password_hash, ':u' => strtolower(trim($username))]);
}

function db_update_user_role($username, $role) {
    $stmt = get_db()->prepare("UPDATE `users` SET `role` = :r WHERE `username` = :u");
    return $stmt->execute([':r' => strtolower(trim($role)), ':u' => strtolower(trim($username))]);
}

function db_update_user_permissions($username, $role, array $permissions) {
    db_ensure_users_permissions_column();
    $stmt = get_db()->prepare("UPDATE `users` SET `role` = :r, `permissions` = :p WHERE `username` = :u");
    return $stmt->execute([
        ':r' => strtolower(trim($role)),
        ':p' => json_encode(array_values(array_unique($permissions)), JSON_UNESCAPED_UNICODE),
        ':u' => strtolower(trim($username))
    ]);
}

function db_update_user_profile($username, $fullname, $phone) {
    $stmt = get_db()->prepare("UPDATE `users` SET `fullname` = :fn, `phone` = :ph WHERE `username` = :u");
    return $stmt->execute([':fn' => trim($fullname), ':ph' => trim($phone), ':u' => strtolower(trim($username))]);
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

// ── CAPCUT V2 HARDENED LICENSE STORAGE & AUDIT LOG ────────────────
function db_get_capcut_lookup_pepper(): string {
    $pepper = getenv('CAPCUT_LOOKUP_PEPPER');
    if (!empty($pepper)) {
        return $pepper;
    }
    return '2toolne_pepper_secret_c7e3f89a1b02456d89ef23456789abcd';
}

function generate_capcut_v2_key(): string {
    // 32-character Crockford Base32 alphabet (no 0, O, 1, I to avoid ambiguity)
    // 4 groups of 4 characters = 16 characters = 80 bits entropy
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $alpha_len = strlen($alphabet);
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, $alpha_len - 1)];
        }
        $groups[] = $chunk;
    }
    return '2TL-CAP-' . implode('-', $groups);
}

function db_ensure_capcut_license_columns() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db = get_db();
        $cols = [
            "ALTER TABLE `licenses` ADD COLUMN `key_lookup_hash` VARCHAR(64) NULL DEFAULT NULL AFTER `license_key`",
            "ALTER TABLE `licenses` ADD COLUMN `key_secret_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `key_lookup_hash`",
            "ALTER TABLE `licenses` ADD COLUMN `key_last4` VARCHAR(8) NULL DEFAULT NULL AFTER `key_secret_hash`",
            "ALTER TABLE `licenses` ADD COLUMN `license_id` VARCHAR(32) NULL DEFAULT NULL AFTER `key_last4`",
            "ALTER TABLE `licenses` ADD INDEX `idx_lic_lookup` (`key_lookup_hash`)",
            "ALTER TABLE `licenses` ADD INDEX `idx_lic_id` (`license_id`)"
        ];
        foreach ($cols as $sql) {
            try { $db->exec($sql); } catch (Exception $e) {}
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
                `id` VARCHAR(64) NOT NULL PRIMARY KEY,
                `admin_user_id` VARCHAR(64) NOT NULL,
                `action` VARCHAR(64) NOT NULL,
                `target_user_id` VARCHAR(128) NULL,
                `reason` TEXT NULL,
                `details` LONGTEXT NULL,
                `ip_address` VARCHAR(45) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_aud_admin` (`admin_user_id`),
                INDEX `idx_aud_action` (`action`),
                INDEX `idx_aud_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        db_ensure_devices_fingerprint_schema();
    } catch (Exception $e) {}
}

function db_ensure_devices_fingerprint_schema() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db = get_db();
        // 1. Check current column type of device_fingerprint in devices
        $q = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'device_fingerprint'");
        $col = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
        if ($col && strtolower($col['DATA_TYPE']) !== 'text') {
            try { $db->exec("ALTER TABLE `devices` DROP INDEX `idx_device_fp`"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE `devices` MODIFY COLUMN `device_fingerprint` TEXT NOT NULL"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE `devices` ADD INDEX `idx_device_fp` (`device_fingerprint`(191))"); } catch (Exception $e) {}
        }
        // 2. Check device_fingerprint_hash column
        $qHash = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'device_fingerprint_hash'");
        if ($qHash && !$qHash->fetch()) {
            try { $db->exec("ALTER TABLE `devices` ADD COLUMN `device_fingerprint_hash` CHAR(64) NULL AFTER `device_fingerprint`"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE `devices` ADD INDEX `idx_device_fp_hash` (`device_fingerprint_hash`)"); } catch (Exception $e) {}
        }
        // 3. Backfill any missing hashes
        try {
            $db->exec("UPDATE `devices` SET `device_fingerprint_hash` = SHA2(`device_fingerprint`, 256) WHERE `device_fingerprint_hash` IS NULL OR `device_fingerprint_hash` = ''");
        } catch (Exception $e) {}
    } catch (Exception $e) {}
}

function db_log_admin_audit($action, $targetUserId, $reason, $details = []) {
    try {
        $db = get_db();
        $auditId = 'aud_' . bin2hex(random_bytes(12));
        $adminUser = $_SESSION['admin_user'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now = date('Y-m-d H:i:s');
        $detailsJson = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare('
            INSERT INTO `admin_audit_logs` (
                `id`, `admin_user_id`, `action`, `target_user_id`, `reason`, `details`, `ip_address`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$auditId, $adminUser, $action, $targetUserId, $reason, $detailsJson, $ip, $now]);
        return $auditId;
    } catch (Exception $e) {
        return null;
    }
}

function db_create_capcut_v2_license($raw_key, $tier, $duration_days, $owner_user = '', $note = '') {
    db_ensure_capcut_license_columns();
    $clean_key = strtoupper(trim($raw_key));
    $last4 = substr($clean_key, -4);
    $lic_id = 'lic_' . bin2hex(random_bytes(10));
    $pepper = db_get_capcut_lookup_pepper();
    $lookup_hash = hash_hmac('sha256', $clean_key, $pepper);
    $secret_hash = password_hash($clean_key, PASSWORD_BCRYPT, ['cost' => 10]);

    $stmt = get_db()->prepare("
        INSERT INTO `licenses` (
            `license_key`, `key_lookup_hash`, `key_secret_hash`, `key_last4`, `license_id`,
            `product`, `tier`, `duration_days`, `status`, `owner_username`, `note`, `created_at`
        ) VALUES (
            :k, :lookup_h, :secret_h, :last4, :lic_id,
            '2toolne.capcut.v2', :tier, :dur, 'active', :owner, :note, NOW()
        )
    ");
    $stmt->execute([
        ':k'        => $clean_key,
        ':lookup_h' => $lookup_hash,
        ':secret_h' => $secret_hash,
        ':last4'    => $last4,
        ':lic_id'   => $lic_id,
        ':tier'     => $tier,
        ':dur'      => $duration_days,
        ':owner'    => !empty($owner_user) ? strtolower(trim($owner_user)) : null,
        ':note'     => $note
    ]);

    // Mandatory admin audit log
    db_log_admin_audit(
        'CAPCUT_KEY_CREATED',
        $owner_user ?: $lic_id,
        'Admin generated new CapCut V2 License Key',
        [
            'license_id'    => $lic_id,
            'key'           => $clean_key,
            'product'       => '2toolne.capcut.v2',
            'tier'          => $tier,
            'duration_days' => $duration_days,
            'owner_user'    => $owner_user
        ]
    );

    return [
        'raw_key'           => $clean_key,
        'license_id'        => $lic_id,
        'masked_key'        => $clean_key,
        'license_key_last4' => $last4
    ];
}

function db_create_license($key, $product, $tier, $duration_days, $owner_user = '', $note = '') {
    if ($product === '2toolne.capcut.v2' || $product === 'CAPCUT_V2') {
        return db_create_capcut_v2_license($key, $tier, $duration_days, $owner_user, $note);
    }
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
    $ok = $stmt->execute([
        ':owner' => !empty($owner_user) ? strtolower(trim($owner_user)) : null,
        ':k'     => trim($key)
    ]);
    if ($ok) {
        db_log_admin_audit('CAPCUT_KEY_ASSIGNED', $owner_user ?: 'unassigned', 'Admin assigned key to user', [
            'target_key' => $key,
            'new_owner'  => $owner_user
        ]);
    }
    return $ok;
}

function db_reset_license_hwid($key) {
    $db = get_db();
    $k = trim($key);
    $stmtLic = $db->prepare("SELECT id, owner_username, hwid FROM `licenses` WHERE `license_key` = :k LIMIT 1");
    $stmtLic->execute([':k' => $k]);
    $lic = $stmtLic->fetch();

    $stmt = $db->prepare("UPDATE `licenses` SET `hwid` = NULL, `device_name` = NULL WHERE `license_key` = :k");
    $ok = $stmt->execute([':k' => $k]);

    if ($lic) {
        if (!empty($lic['hwid'])) {
            $upDev1 = $db->prepare("UPDATE `devices` SET `status` = 'REVOKED' WHERE `device_fingerprint` = ?");
            $upDev1->execute([$lic['hwid']]);
        }
        if (!empty($lic['owner_username'])) {
            $upDev2 = $db->prepare("UPDATE `devices` SET `status` = 'REVOKED' WHERE `user_id` = ?");
            $upDev2->execute([$lic['owner_username']]);
        }
    }

    if ($ok) {
        db_log_admin_audit('CAPCUT_DEVICE_RESET', $key, 'Admin reset device binding / HWID', [
            'target_key' => $key
        ]);
    }
    return $ok;
}

function db_toggle_license_ban($key, $new_status) {
    $stmt = get_db()->prepare("UPDATE `licenses` SET `status` = :st WHERE `license_key` = :k");
    $ok = $stmt->execute([':st' => $new_status, ':k' => trim($key)]);
    if ($ok) {
        $action = ($new_status === 'banned' || $new_status === 'revoked') ? 'CAPCUT_KEY_REVOKED' : 'CAPCUT_KEY_UNBANNED';
        db_log_admin_audit($action, $key, "Admin toggled status to {$new_status}", [
            'target_key' => $key,
            'status'     => $new_status
        ]);
    }
    return $ok;
}

function db_delete_license($key) {
    db_log_admin_audit('CAPCUT_KEY_DELETED', $key, 'Admin deleted license record', [
        'target_key' => $key
    ]);
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
            'username' => $r['username'],
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
            'assigned_key' => $r['issued_key'] ?? '',
            'approved_at' => $r['approved_at'] ?? '',
            'created_at' => $r['created_at']
        ];
    }
    return $result;
}

function db_get_order($order_id) {
    $stmt = get_db()->prepare("SELECT * FROM `orders` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => trim($order_id)]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'id'            => $r['id'],
        'user'          => $r['username'],
        'username'      => $r['username'],
        'fullname'      => $r['fullname'] ?? '',
        'phone'         => $r['phone'] ?? '',
        'product'       => $r['product'] ?? 'SLIDESHOW',
        'package_name'  => $r['package_name'] ?? '',
        'package_price' => $r['package_price'] ?? '',
        'duration_days' => intval($r['duration_days'] ?? 30),
        'tier'          => $r['tier'] ?? 'VIP',
        'memo'          => $r['memo'] ?? '',
        'status'        => $r['status'] ?? 'pending',
        'issued_key'    => $r['issued_key'] ?? '',
        'assigned_key'  => $r['issued_key'] ?? '',
        'approved_at'   => $r['approved_at'] ?? '',
        'created_at'    => $r['created_at']
    ];
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

// ── TOKEN WALLET & PACKAGES ──────────────────────────────────────────

function db_ensure_user_wallet($userId, $initialTokens = 50) {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return false;
        $userId = $u['id'];
    }

    $db = get_db();
    // Check if wallet already exists
    $stmt = $db->prepare("SELECT id, balance FROM `credit_wallets` WHERE `user_id` = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    if ($stmt->fetch()) return true;

    $now = date('Y-m-d H:i:s');

    // Ensure entitlement
    try {
        $ent_stmt = $db->prepare("SELECT id FROM `license_entitlements` WHERE `user_id` = :uid LIMIT 1");
        $ent_stmt->execute([':uid' => $userId]);
        if (!$ent_stmt->fetch()) {
            $licId = 'lic_' . bin2hex(random_bytes(12));
            $db->prepare("INSERT INTO `license_entitlements` (`id`, `user_id`, `plan`, `credit_mode`, `max_devices`, `created_at`) VALUES (?, ?, 'PRO', 'METERED', 3, ?)")
               ->execute([$licId, $userId, $now]);
        }
    } catch (Exception $e) {}

    // Create wallet with initial tokens
    $walId = 'wal_' . bin2hex(random_bytes(12));
    try {
        $db->prepare("INSERT INTO `credit_wallets` (`id`, `user_id`, `balance`, `reserved_balance`, `currency`) VALUES (?, ?, ?, 0, 'TOKEN')")
           ->execute([$walId, $userId, $initialTokens]);

        if ($initialTokens > 0) {
            $txId = 'tx_' . bin2hex(random_bytes(12));
            $db->prepare("INSERT INTO `credit_transactions` (`id`, `user_id`, `amount`, `balance_after`, `type`, `reference_id`, `description`, `created_by`, `created_at`) VALUES (?, ?, ?, ?, 'PROMOTION', 'SIGNUP_BONUS', 'Khuyến mãi tặng 50 Tokens khi đăng ký tài khoản mới', 'SYSTEM', ?)")
               ->execute([$txId, $userId, $initialTokens, $initialTokens, $now]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function db_get_user_wallet($userId) {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return null;
        $userId = $u['id'];
    }

    $stmt = get_db()->prepare("
        SELECT w.balance, w.reserved_balance, w.currency,
               COALESCE(l.plan, 'PRO') as plan,
               COALESCE(l.credit_mode, 'METERED') as credit_mode
        FROM `credit_wallets` w
        LEFT JOIN `license_entitlements` l ON w.user_id = l.user_id
        WHERE w.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        db_ensure_user_wallet($userId, 50);
        return [
            'balance' => 50,
            'reserved_balance' => 0,
            'currency' => 'TOKEN',
            'plan' => 'PRO',
            'credit_mode' => 'METERED'
        ];
    }

    return [
        'balance' => (int)$wallet['balance'],
        'reserved_balance' => (int)$wallet['reserved_balance'],
        'reserved' => (int)$wallet['reserved_balance'],
        'currency' => $wallet['currency'] ?? 'TOKEN',
        'plan' => $wallet['plan'] ?? 'PRO',
        'credit_mode' => $wallet['credit_mode'] ?? 'METERED'
    ];
}

function db_get_user_token_transactions($userId, $limit = 20) {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return [];
        $userId = $u['id'];
    }
    $limit = min(100, max(1, (int)$limit));
    try {
        $stmt = get_db()->prepare("
            SELECT id, amount, balance_after, type, reference_id, description, created_by, created_at
            FROM `credit_transactions`
            WHERE `user_id` = :uid
            ORDER BY `created_at` DESC
            LIMIT " . (int)$limit
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['delta'] = (int)($r['amount'] ?? 0);
            $r['reason'] = $r['description'] ?? '';
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

function db_adjust_user_wallet($userId, $amount, $reason, $adminUsername = 'SYSTEM') {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return ['success' => false, 'error' => 'Không tìm thấy tài khoản người dùng'];
        $userId = $u['id'];
    }
    $tokenDelta = (int)$amount;
    if ($tokenDelta === 0) return ['success' => false, 'error' => 'Số lượng token điều chỉnh không được bằng 0'];
    if (empty($reason) || mb_strlen(trim($reason)) < 5) {
        return ['success' => false, 'error' => 'Bắt buộc nhập lý do điều chỉnh tối thiểu 5 ký tự'];
    }

    db_ensure_user_wallet($userId, 0);

    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT balance FROM `credit_wallets` WHERE `user_id` = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            $db->rollBack();
            return ['success' => false, 'error' => 'Không tìm thấy ví token của người dùng'];
        }

        $currentBalance = (int)$wallet['balance'];
        $newBalance = $currentBalance + $tokenDelta;
        if ($newBalance < 0) {
            $db->rollBack();
            return ['success' => false, 'error' => "Số dư token sau điều chỉnh không thể âm (Hiện có: {$currentBalance} tokens)"];
        }

        // 1. Update wallet
        $stmt = $db->prepare('UPDATE `credit_wallets` SET `balance` = ? WHERE `user_id` = ?');
        $stmt->execute([$newBalance, $userId]);

        // 2. Insert transaction
        $txId = 'tx_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');
        $refId = 'ADM_' . date('YmdHis');

        $stmt = $db->prepare('
            INSERT INTO `credit_transactions` (
                `id`, `user_id`, `amount`, `balance_after`, `type`, `reference_id`,
                `description`, `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, "ADMIN_ADJUSTMENT", ?, ?, ?, ?)
        ');
        $stmt->execute([
            $txId,
            $userId,
            $tokenDelta,
            $newBalance,
            $refId,
            "Admin ({$adminUsername}) điều chỉnh: {$reason}",
            $adminUsername,
            $now
        ]);

        // 3. Admin audit log
        try {
            $auditId = 'aud_' . bin2hex(random_bytes(12));
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $details = json_encode([
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'delta' => $tokenDelta,
                'reference_id' => $refId
            ], JSON_UNESCAPED_UNICODE);

            $stmt = $db->prepare('
                INSERT INTO `admin_audit_logs` (
                    `id`, `admin_user_id`, `action`, `target_user_id`, `reason`, `details`, `ip_address`, `created_at`
                ) VALUES (?, ?, "WALLET_ADJUSTMENT", ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$auditId, $adminUsername, $userId, $reason, $details, $ip, $now]);
        } catch (Exception $e) {}

        $db->commit();
        return [
            'success' => true,
            'old_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'delta' => $tokenDelta
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_settle_user_reserved_tokens($userId, $settleAmount, $actionType = 'CONSUME', $reason = '', $adminUsername = 'SYSTEM') {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return ['success' => false, 'error' => 'Không tìm thấy tài khoản người dùng'];
        $userId = $u['id'];
    }
    $settleAmount = (int)$settleAmount;
    if ($settleAmount <= 0) {
        return ['success' => false, 'error' => 'Số lượng token chốt phải lớn hơn 0'];
    }

    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT balance, reserved_balance FROM `credit_wallets` WHERE `user_id` = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            $db->rollBack();
            return ['success' => false, 'error' => 'Không tìm thấy ví token của người dùng'];
        }

        $currentReserved = (int)$wallet['reserved_balance'];
        $currentBalance  = (int)$wallet['balance'];

        if ($settleAmount > $currentReserved) {
            $db->rollBack();
            return ['success' => false, 'error' => "Số token cần xử lý ({$settleAmount}) lớn hơn số token đang tạm khóa ({$currentReserved})"];
        }

        $newReserved = max(0, $currentReserved - $settleAmount);
        $txId = 'tx_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');
        $cleanReason = trim($reason) ?: ($actionType === 'CONSUME' ? 'Chốt token tạm khóa sang đã sử dụng' : 'Hoàn lại token tạm khóa vào số dư khả dụng');

        if ($actionType === 'CONSUME') {
            $db->prepare('UPDATE `credit_wallets` SET `reserved_balance` = ? WHERE `user_id` = ?')->execute([$newReserved, $userId]);

            $stmt = $db->prepare('
                INSERT INTO `credit_transactions` (
                    `id`, `user_id`, `amount`, `balance_after`, `type`, `reference_id`,
                    `description`, `created_by`, `created_at`
                ) VALUES (?, ?, ?, ?, "CONSUME", ?, ?, ?, ?)
            ');
            $stmt->execute([
                $txId,
                $userId,
                -$settleAmount,
                $currentBalance,
                'SETTLE_' . date('YmdHis'),
                "Admin ({$adminUsername}) chốt token sang đã sử dụng: {$cleanReason}",
                $adminUsername,
                $now
            ]);
            $newBalance = $currentBalance;
        } else {
            $newBalance = $currentBalance + $settleAmount;
            $db->prepare('UPDATE `credit_wallets` SET `balance` = ?, `reserved_balance` = ? WHERE `user_id` = ?')->execute([$newBalance, $newReserved, $userId]);

            $stmt = $db->prepare('
                INSERT INTO `credit_transactions` (
                    `id`, `user_id`, `amount`, `balance_after`, `type`, `reference_id`,
                    `description`, `created_by`, `created_at`
                ) VALUES (?, ?, ?, ?, "REFUND", ?, ?, ?, ?)
            ');
            $stmt->execute([
                $txId,
                $userId,
                $settleAmount,
                $newBalance,
                'REFUND_' . date('YmdHis'),
                "Admin ({$adminUsername}) hoàn trả token tạm khóa về số dư: {$cleanReason}",
                $adminUsername,
                $now
            ]);
        }

        $db->commit();
        return [
            'success' => true,
            'action' => $actionType,
            'settled_amount' => $settleAmount,
            'new_reserved' => $newReserved,
            'new_balance' => $newBalance
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_get_user_consumed_tokens($userId) {
    if (!is_numeric($userId)) {
        $u = db_get_user($userId);
        if (!$u || empty($u['id'])) return 0;
        $userId = $u['id'];
    }
    try {
        $stmt = get_db()->prepare("
            SELECT COALESCE(SUM(ABS(amount)), 0)
            FROM `credit_transactions`
            WHERE `user_id` = ? AND `type` IN ('CONSUME', 'UPSCALE', 'UPSCALE 2K', 'UPSCALE 4K')
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function db_get_all_cloud_spaces() {
    try {
        $stmt = get_db()->query("
            SELECT cs.id, cs.owner_type, cs.owner_id, cs.name, cs.status, cs.expires_at, cs.created_at,
                   COALESCE(csq.effective_quota_bytes, 5368709120) AS effective_quota_bytes,
                   COALESCE(csq.used_bytes, 0) AS used_bytes,
                   COALESCE(csq.reserved_bytes, 0) AS reserved_bytes,
                   COALESCE(csq.base_quota_bytes, 5368709120) AS base_quota_bytes,
                   COALESCE(csq.admin_adjustment_bytes, 0) AS admin_adjustment_bytes,
                   (SELECT COUNT(*) FROM `cloud_files` WHERE cloud_space_id = cs.id AND status NOT IN ('DELETED', 'PURGING')) as files_count,
                   u.username, u.fullname,
                   t.name AS team_name
            FROM `cloud_spaces` cs
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            LEFT JOIN `users` u ON cs.owner_type = 'USER' AND (cs.owner_id = u.id OR cs.owner_id = u.username)
            LEFT JOIN `teams` t ON cs.owner_type = 'TEAM' AND cs.owner_id = t.id
            ORDER BY cs.owner_type DESC, cs.created_at DESC
        ");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        return [];
    }
}


function db_get_token_packages() {
    try {
        $stmt = get_db()->query("SELECT * FROM `token_packages` ORDER BY CAST(`price_vnd` AS UNSIGNED) ASC");
        $rows = $stmt->fetchAll();
        if (!empty($rows)) return $rows;
    } catch (Exception $e) {}

    return [
        ['id' => 'pkg_starter', 'name' => 'Starter Batch', 'tokens' => 1000, 'price_vnd' => 100000, 'bonus_tokens' => 0, 'description' => 'Phù hợp cho sự kiện nhỏ & bộ ảnh mạng xã hội'],
        ['id' => 'pkg_creator', 'name' => 'Creator Pack', 'tokens' => 3000, 'price_vnd' => 250000, 'bonus_tokens' => 200, 'description' => 'Gói phổ biến nhất cho nhiếp ảnh gia & nhà sáng tạo nội dung'],
        ['id' => 'pkg_studio', 'name' => 'Studio Pro', 'tokens' => 10000, 'price_vnd' => 700000, 'bonus_tokens' => 1000, 'description' => 'Tối ưu cho studio chụp ảnh chuyên nghiệp & agency'],
        ['id' => 'pkg_enterprise', 'name' => 'Enterprise Power', 'tokens' => 30000, 'price_vnd' => 1800000, 'bonus_tokens' => 5000, 'description' => 'Hiệu năng tối đa cho dây chuyền sản xuất thương mại'],
    ];
}

// ── 2TOOLNE CLOUD V2 HELPERS ─────────────────────────────────────────

function db_get_cloud_spaces_for_user($user_id) {
    try {
        $db = get_db();
        // 1. Personal space
        $pStmt = $db->prepare('
            SELECT cs.id, cs.name, cs.owner_type, cs.owner_id, cs.status, cs.created_at,
                   "OWNER" AS user_role,
                   csq.base_quota_bytes, csq.addon_quota_bytes, csq.admin_adjustment_bytes,
                   csq.effective_quota_bytes, csq.used_bytes, csq.reserved_bytes
            FROM `cloud_spaces` cs
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            WHERE cs.owner_type = "USER" AND cs.owner_id = ?
        ');
        $pStmt->execute([(string)$user_id]);
        $personal = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Team spaces
        $tStmt = $db->prepare('
            SELECT cs.id, cs.name, cs.owner_type, cs.owner_id, cs.status, cs.created_at,
                   tm.role AS user_role,
                   csq.base_quota_bytes, csq.addon_quota_bytes, csq.admin_adjustment_bytes,
                   csq.effective_quota_bytes, csq.used_bytes, csq.reserved_bytes
            FROM `team_members` tm
            JOIN `teams` t ON tm.team_id = t.id
            JOIN `cloud_spaces` cs ON cs.owner_type = "TEAM" AND cs.owner_id = t.id
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            WHERE tm.user_id = ? AND tm.status = "ACTIVE"
        ');
        $tStmt->execute([(string)$user_id]);
        $teams = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        $merged = array_merge($personal, $teams);
        if (empty($merged)) {
            $spaceId = db_ensure_user_personal_space($user_id);
            if ($spaceId) {
                return db_get_cloud_spaces_for_user($user_id);
            }
        }
        return $merged;
    } catch (Exception $e) {
        return [];
    }
}

function db_ensure_user_personal_space($user_id, $plan = 'BASIC') {
    try {
        $db = get_db();
        $chk = $db->prepare("SELECT id FROM `cloud_spaces` WHERE owner_type = 'USER' AND owner_id = ? LIMIT 1");
        $chk->execute([(string)$user_id]);
        $row = $chk->fetch();
        if ($row) return $row['id'];

        $lp = $db->prepare("SELECT plan FROM `license_entitlements` WHERE user_id = ? LIMIT 1");
        $lp->execute([(string)$user_id]);
        $lrow = $lp->fetch();
        if ($lrow && !empty($lrow['plan'])) $plan = $lrow['plan'];

        $baseQuota = ($plan === 'STUDIO') ? 107374182400 : (($plan === 'PRO') ? 26843545600 : 5368709120);
        $spaceId = 'cs_pers_' . substr(md5($user_id . '_2toolne_cloud'), 0, 16);

        $ins = $db->prepare("INSERT INTO `cloud_spaces` (id, owner_type, owner_id, name, status, created_at) VALUES (?, 'USER', ?, 'Personal Space', 'ACTIVE', NOW())");
        $ins->execute([$spaceId, (string)$user_id]);

        $insQ = $db->prepare("INSERT INTO `cloud_space_quotas` (cloud_space_id, base_quota_bytes, addon_quota_bytes, admin_adjustment_bytes, effective_quota_bytes, used_bytes, reserved_bytes, updated_at) VALUES (?, ?, 0, 0, ?, 0, 0, NOW())");
        $insQ->execute([$spaceId, $baseQuota, $baseQuota]);

        return $spaceId;
    } catch (Exception $e) {
        return null;
    }
}

function db_get_cloud_pool_metrics() {
    try {
        $db = get_db();
        $saStmt = $db->query('
            SELECT 
                COALESCE(SUM(total_capacity_bytes), 0) AS pool_total_physical,
                COALESCE(SUM(used_capacity_bytes), 0) AS pool_used_physical,
                COALESCE(SUM(reserved_capacity_bytes), 0) AS pool_reserved,
                COALESCE(SUM(total_capacity_bytes * safety_reserve_percent / 100), 0) AS pool_safety_buffer,
                COALESCE(SUM(
                    CASE WHEN status = "ACTIVE" AND health_status IN ("HEALTHY", "UNKNOWN")
                    THEN GREATEST(0, CAST((total_capacity_bytes * (100 - safety_reserve_percent) / 100) - used_capacity_bytes - reserved_capacity_bytes AS SIGNED))
                    ELSE 0 END
                ), 0) AS pool_allocatable_free,
                COUNT(*) AS total_accounts,
                COUNT(CASE WHEN status = "ACTIVE" THEN 1 END) AS active_accounts,
                COUNT(CASE WHEN status = "DRAINING" THEN 1 END) AS draining_accounts
            FROM `storage_accounts`
            WHERE status != "DISCONNECTED"
        ');
        $phy = $saStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalPhysical = (float)($phy['pool_total_physical'] ?? 0);
        $usedPhysical  = (float)($phy['pool_used_physical'] ?? 0);
        $freePhysical  = max(0, $totalPhysical - $usedPhysical);
        $reserved      = (float)($phy['pool_reserved'] ?? 0);
        $safetyBuffer  = (float)($phy['pool_safety_buffer'] ?? 0);
        $allocatableFree = (float)($phy['pool_allocatable_free'] ?? 0);

        $lqStmt = $db->query('
            SELECT 
                COALESCE(SUM(effective_quota_bytes), 0) AS total_logical_allocated,
                COALESCE(SUM(used_bytes), 0) AS total_logical_used,
                COUNT(*) AS total_spaces
            FROM `cloud_space_quotas`
        ');
        $log = $lqStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $logicalAllocated = (float)($log['total_logical_allocated'] ?? 0);
        $logicalUsed      = (float)($log['total_logical_used'] ?? 0);

        $overcommitRatio = ($totalPhysical > 0) ? round($logicalAllocated / $totalPhysical, 2) : 1.0;
        $riskLevel = 'SAFE';
        if ($overcommitRatio >= 2.0 || ($totalPhysical > 0 && ($allocatableFree / $totalPhysical) < 0.10)) {
            $riskLevel = 'CRITICAL';
        } elseif ($overcommitRatio >= 1.5 || ($totalPhysical > 0 && ($allocatableFree / $totalPhysical) < 0.25)) {
            $riskLevel = 'WARNING';
        }

        return [
            'POOL_TOTAL_PHYSICAL'           => (int)$totalPhysical,
            'POOL_USED_PHYSICAL'            => (int)$usedPhysical,
            'POOL_FREE_PHYSICAL'            => (int)$freePhysical,
            'POOL_RESERVED'                 => (int)$reserved,
            'POOL_SAFETY_BUFFER'            => (int)$safetyBuffer,
            'POOL_ALLOCATABLE_FREE'         => (int)$allocatableFree,
            'TOTAL_LOGICAL_QUOTA_ALLOCATED' => (int)$logicalAllocated,
            'TOTAL_LOGICAL_USED'            => (int)$logicalUsed,
            'OVERCOMMIT_RATIO'              => $overcommitRatio,
            'RISK_LEVEL'                    => $riskLevel,
            'total_accounts'                => (int)($phy['total_accounts'] ?? 0),
            'active_accounts'               => (int)($phy['active_accounts'] ?? 0),
            'draining_accounts'             => (int)($phy['draining_accounts'] ?? 0),
            'total_spaces'                  => (int)($log['total_spaces'] ?? 0),
        ];
    } catch (Exception $e) {
        return [];
    }
}

function db_get_storage_accounts() {
    try {
        $stmt = get_db()->query("SELECT * FROM `storage_accounts` ORDER BY priority DESC, created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function db_get_cloud_quota_adjustments($space_id = null, $limit = 50) {
    try {
        $db = get_db();
        if ($space_id) {
            $stmt = $db->prepare("
                SELECT cqa.*, cs.name as space_name 
                FROM `cloud_quota_adjustments` cqa
                JOIN `cloud_spaces` cs ON cqa.cloud_space_id = cs.id
                WHERE cqa.cloud_space_id = ?
                ORDER BY cqa.created_at DESC LIMIT ?
            ");
            $stmt->bindValue(1, $space_id, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("
                SELECT cqa.*, cs.name as space_name 
                FROM `cloud_quota_adjustments` cqa
                JOIN `cloud_spaces` cs ON cqa.cloud_space_id = cs.id
                ORDER BY cqa.created_at DESC LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function db_get_users_cloud_data() {
    try {
        $db = get_db();
        $stmt = $db->query("
            SELECT cs.id AS space_id, cs.owner_id AS user_id,
                   COALESCE(csq.effective_quota_bytes, 5368709120) AS effective_quota_bytes,
                   COALESCE(csq.used_bytes, 0) AS used_bytes,
                   COALESCE(csq.reserved_bytes, 0) AS reserved_bytes,
                   COALESCE(csq.base_quota_bytes, 5368709120) AS base_quota_bytes,
                   COALESCE(csq.admin_adjustment_bytes, 0) AS admin_adjustment_bytes,
                   (SELECT COUNT(*) FROM `cloud_files` WHERE cloud_space_id = cs.id AND status NOT IN ('DELETED', 'PURGING')) as files_count
            FROM `cloud_spaces` cs
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            WHERE cs.owner_type = 'USER'
        ");
        $map = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(string)$r['user_id']] = $r;
        }
        return $map;
    } catch (Exception $e) {
        return [];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 🏢 2TOOLNE TEAM & CLOUD QUOTA SERVICE LAYER
// ═══════════════════════════════════════════════════════════════════════════

function db_ensure_team_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = get_db();
        $cols = $db->query("DESCRIBE `teams`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('expires_at', $cols)) {
            $db->exec("ALTER TABLE `teams` ADD COLUMN `expires_at` DATETIME NULL AFTER `status`");
        }
        $csCols = $db->query("DESCRIBE `cloud_spaces`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('expires_at', $csCols)) {
            $db->exec("ALTER TABLE `cloud_spaces` ADD COLUMN `expires_at` DATETIME NULL AFTER `status`");
        }
        $csqCols = $db->query("DESCRIBE `cloud_space_quotas`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('quota_expires_at', $csqCols)) {
            $db->exec("ALTER TABLE `cloud_space_quotas` ADD COLUMN `quota_expires_at` DATETIME NULL AFTER `effective_quota_bytes`");
        }
    } catch (Exception $e) {}
}

function db_get_all_teams() {
    db_ensure_team_schema();
    try {
        $db = get_db();
        $sql = "
            SELECT t.*, 
                   u.username as owner_username, 
                   u.fullname as owner_fullname,
                   cs.id as cloud_space_id,
                   cs.name as cloud_space_name,
                   COALESCE(csq.effective_quota_bytes, 0) as effective_quota_bytes,
                   COALESCE(csq.used_bytes, 0) as used_bytes,
                   (SELECT COUNT(*) FROM `team_members` tm WHERE tm.team_id = t.id AND tm.status = 'ACTIVE') as active_members_count,
                   (SELECT COUNT(*) FROM `team_members` tm WHERE tm.team_id = t.id AND tm.status = 'INVITED') as invited_members_count
            FROM `teams` t
            LEFT JOIN `users` u ON t.owner_user_id = u.id
            LEFT JOIN `cloud_spaces` cs ON cs.owner_type = 'TEAM' AND cs.owner_id = t.id
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            ORDER BY t.created_at DESC
        ";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function db_get_team($team_id) {
    db_ensure_team_schema();
    try {
        $db = get_db();
        $stmt = $db->prepare("
            SELECT t.*, u.username as owner_username, u.fullname as owner_fullname,
                   cs.id as cloud_space_id, cs.name as cloud_space_name,
                   COALESCE(csq.effective_quota_bytes, 0) as effective_quota_bytes,
                   COALESCE(csq.used_bytes, 0) as used_bytes
            FROM `teams` t
            LEFT JOIN `users` u ON t.owner_user_id = u.id
            LEFT JOIN `cloud_spaces` cs ON cs.owner_type = 'TEAM' AND cs.owner_id = t.id
            LEFT JOIN `cloud_space_quotas` csq ON cs.id = csq.cloud_space_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$team_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function db_create_team($name, $owner_user_id, $slots = 2, $duration_days = 90, $quota_gb = 20) {
    db_ensure_team_schema();
    $db = get_db();
    try {
        $team_id = 'team_' . bin2hex(random_bytes(6));
        $slots = max(2, (int)$slots);
        $duration_days = max(1, (int)$duration_days);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));

        // 1. Insert Team
        $stmt = $db->prepare("
            INSERT INTO `teams` (id, name, owner_user_id, member_slots, app_key_count, status, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, NOW())
        ");
        $stmt->execute([$team_id, trim($name), $owner_user_id, $slots, $slots, $expires_at]);

        // 2. Create Team Cloud Space
        $space_id = 'cs_team_' . bin2hex(random_bytes(6));
        $cs_stmt = $db->prepare("
            INSERT INTO `cloud_spaces` (id, owner_type, owner_id, name, status, expires_at, created_at)
            VALUES (?, 'TEAM', ?, ?, 'ACTIVE', ?, NOW())
        ");
        $cs_stmt->execute([$space_id, $team_id, trim($name) . ' Space', $expires_at]);

        // 3. Create Cloud Space Quota
        $quota_bytes = (int)$quota_gb * 1024 * 1024 * 1024;
        $csq_stmt = $db->prepare("
            INSERT INTO `cloud_space_quotas` (cloud_space_id, base_quota_bytes, addon_quota_bytes, admin_adjustment_bytes, effective_quota_bytes, used_bytes, reserved_bytes, quota_expires_at, updated_at)
            VALUES (?, ?, 0, 0, ?, 0, 0, ?, NOW())
        ");
        $csq_stmt->execute([$space_id, $quota_bytes, $quota_bytes, $expires_at]);

        // 4. Add Owner to team_members
        $tm_id = 'tm_' . bin2hex(random_bytes(6));
        $tm_stmt = $db->prepare("
            INSERT INTO `team_members` (id, team_id, user_id, role, status, joined_at)
            VALUES (?, ?, ?, 'OWNER', 'ACTIVE', NOW())
        ");
        $tm_stmt->execute([$tm_id, $team_id, $owner_user_id]);

        return $team_id;
    } catch (Exception $e) {
        return false;
    }
}

function db_get_team_members($team_id) {
    try {
        $db = get_db();
        $stmt = $db->prepare("
            SELECT tm.*, u.username, u.fullname, u.phone, u.role as user_global_role
            FROM `team_members` tm
            JOIN `users` u ON tm.user_id = u.id
            WHERE tm.team_id = ? AND tm.status != 'REMOVED'
            ORDER BY FIELD(tm.role, 'OWNER', 'ADMIN', 'MEMBER'), tm.joined_at ASC
        ");
        $stmt->execute([$team_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function db_add_team_member($team_id, $user_id, $role = 'MEMBER', $status = 'ACTIVE') {
    $db = get_db();
    try {
        $team = db_get_team($team_id);
        if (!$team) return ['success' => false, 'error' => 'Không tìm thấy đội nhóm!'];

        // Check slot limit for active
        if ($status === 'ACTIVE') {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM `team_members` WHERE team_id = ? AND status = 'ACTIVE'");
            $stmtCount->execute([$team_id]);
            $activeCount = (int)$stmtCount->fetchColumn();
            if ($activeCount >= (int)$team['member_slots']) {
                return ['success' => false, 'error' => 'Đội nhóm đã đủ số slot thành viên (' . $team['member_slots'] . ' slots). Vui lòng mua thêm slot!'];
            }
        }

        // Check existing member record
        $stmtEx = $db->prepare("SELECT id, status FROM `team_members` WHERE team_id = ? AND user_id = ? LIMIT 1");
        $stmtEx->execute([$team_id, $user_id]);
        $existing = $stmtEx->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'ACTIVE' && $status === 'ACTIVE') {
                return ['success' => false, 'error' => 'Người dùng này đã là thành viên chính thức trong nhóm!'];
            }
            $upd = $db->prepare("UPDATE `team_members` SET role = ?, status = ?, joined_at = NOW() WHERE id = ?");
            $upd->execute([$role, $status, $existing['id']]);
            return ['success' => true, 'updated' => true];
        }

        $tm_id = 'tm_' . bin2hex(random_bytes(6));
        $ins = $db->prepare("INSERT INTO `team_members` (id, team_id, user_id, role, status, joined_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$tm_id, $team_id, $user_id, $role, $status]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_remove_team_member($team_id, $user_id) {
    $db = get_db();
    try {
        $stmt = $db->prepare("SELECT role FROM `team_members` WHERE team_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$team_id, $user_id]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'error' => 'Không tìm thấy thành viên trong nhóm!'];
        if ($row['role'] === 'OWNER') {
            return ['success' => false, 'error' => 'Không thể xóa Trưởng nhóm (Owner)! Cần chuyển giao quyền trưởng nhóm trước.'];
        }
        $del = $db->prepare("UPDATE `team_members` SET status = 'REMOVED' WHERE team_id = ? AND user_id = ?");
        $del->execute([$team_id, $user_id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_invite_team_member($team_id, $target_username, $inviter_id) {
    $db = get_db();
    try {
        $u_stmt = $db->prepare("SELECT id, username, fullname FROM `users` WHERE `username` = ? OR `id` = ? LIMIT 1");
        $u_stmt->execute([strtolower(trim($target_username)), trim($target_username)]);
        $targetUser = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            return ['success' => false, 'error' => 'Không tìm thấy tài khoản người dùng có username "' . htmlspecialchars($target_username) . '"!'];
        }

        $team = db_get_team($team_id);
        if (!$team) return ['success' => false, 'error' => 'Không tìm thấy thông tin Team!'];

        // Check slot capacity (Active + Invited)
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM `team_members` WHERE team_id = ? AND status IN ('ACTIVE', 'INVITED')");
        $cntStmt->execute([$team_id]);
        $occupiedSlots = (int)$cntStmt->fetchColumn();

        if ($occupiedSlots >= (int)$team['member_slots']) {
            return ['success' => false, 'error' => 'Đã hết slot khả dụng (' . $occupiedSlots . '/' . $team['member_slots'] . '). Hãy mua thêm slot trước khi gửi lời mời!'];
        }

        return db_add_team_member($team_id, $targetUser['id'], 'MEMBER', 'INVITED');
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_get_user_pending_invites($user_id) {
    try {
        $db = get_db();
        $stmt = $db->prepare("
            SELECT tm.id as invite_id, tm.team_id, tm.joined_at as invited_at,
                   t.name as team_name, t.owner_user_id,
                   u.username as owner_username, u.fullname as owner_fullname
            FROM `team_members` tm
            JOIN `teams` t ON tm.team_id = t.id
            JOIN `users` u ON t.owner_user_id = u.id
            WHERE tm.user_id = ? AND tm.status = 'INVITED'
            ORDER BY tm.joined_at DESC
        ");
        $stmt->execute([(string)$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function db_respond_team_invite($invite_id, $user_id, $action) {
    $db = get_db();
    try {
        $stmt = $db->prepare("SELECT tm.*, t.member_slots FROM `team_members` tm JOIN `teams` t ON tm.team_id = t.id WHERE tm.id = ? AND tm.user_id = ? AND tm.status = 'INVITED' LIMIT 1");
        $stmt->execute([$invite_id, (string)$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['success' => false, 'error' => 'Lời mời không hợp lệ hoặc đã được xử lý!'];

        if ($action === 'ACCEPT') {
            $cnt = $db->prepare("SELECT COUNT(*) FROM `team_members` WHERE team_id = ? AND status = 'ACTIVE'");
            $cnt->execute([$row['team_id']]);
            if ((int)$cnt->fetchColumn() >= (int)$row['member_slots']) {
                return ['success' => false, 'error' => 'Rất tiếc! Đội nhóm này hiện tại đã hết slot trống. Vui lòng báo trưởng nhóm mua thêm slot!'];
            }
            $upd = $db->prepare("UPDATE `team_members` SET status = 'ACTIVE', joined_at = NOW() WHERE id = ?");
            $upd->execute([$invite_id]);
            return ['success' => true, 'team_id' => $row['team_id'], 'action' => 'ACCEPT'];
        } else {
            $upd = $db->prepare("UPDATE `team_members` SET status = 'REMOVED' WHERE id = ?");
            $upd->execute([$invite_id]);
            return ['success' => true, 'action' => 'REJECT'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_add_team_slots($team_id, $extra_slots) {
    $db = get_db();
    try {
        $extra_slots = max(1, (int)$extra_slots);
        $stmt = $db->prepare("UPDATE `teams` SET member_slots = member_slots + ?, app_key_count = app_key_count + ? WHERE id = ?");
        $stmt->execute([$extra_slots, $extra_slots, $team_id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_add_cloud_space_quota($space_id, $gb_to_add, $duration_days = 90, $reason = '') {
    $db = get_db();
    try {
        $bytes_to_add = (int)$gb_to_add * 1024 * 1024 * 1024;
        $duration_days = max(1, (int)$duration_days);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));

        $stmt = $db->prepare("
            UPDATE `cloud_space_quotas`
            SET addon_quota_bytes = addon_quota_bytes + ?,
                effective_quota_bytes = base_quota_bytes + addon_quota_bytes + admin_adjustment_bytes,
                quota_expires_at = ?,
                updated_at = NOW()
            WHERE cloud_space_id = ?
        ");
        $stmt->execute([$bytes_to_add, $expires_at, $space_id]);

        // If space was OVER_QUOTA and now has enough space, set ACTIVE
        $chk = $db->prepare("SELECT used_bytes, effective_quota_bytes FROM `cloud_space_quotas` WHERE cloud_space_id = ? LIMIT 1");
        $chk->execute([$space_id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['used_bytes'] < (int)$row['effective_quota_bytes']) {
            $db->prepare("UPDATE `cloud_spaces` SET status = 'ACTIVE' WHERE id = ? AND status = 'OVER_QUOTA'")->execute([$space_id]);
        }

        // Ledger entry
        $adj_id = 'cqa_' . bin2hex(random_bytes(8));
        $db->prepare("
            INSERT INTO `cloud_quota_adjustments` (id, cloud_space_id, delta_bytes, new_effective_bytes, reason, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, 'BILLING_ADDON', NOW())
        ")->execute([$adj_id, $space_id, $bytes_to_add, (int)($row['effective_quota_bytes'] ?? 0), $reason ?: "Mua gói +{$gb_to_add} GB ({$duration_days} ngày)"]);

        return ['success' => true, 'new_effective_bytes' => $row['effective_quota_bytes'] ?? 0];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_get_team_user_role($team_id, $user_id) {
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT role, status FROM `team_members` WHERE team_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$team_id, (string)$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['role'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function db_update_team_member_role($team_id, $user_id, $new_role) {
    $db = get_db();
    try {
        $new_role = strtoupper(trim($new_role));
        if (!in_array($new_role, ['OWNER', 'ADMIN', 'MEMBER'], true)) {
            return ['success' => false, 'error' => 'Vai trò không hợp lệ (phải là OWNER, ADMIN, hoặc MEMBER)!'];
        }

        $team = db_get_team($team_id);
        if (!$team) return ['success' => false, 'error' => 'Không tìm thấy đội nhóm!'];

        // Check if member exists in team
        $stmt = $db->prepare("SELECT id, role, status FROM `team_members` WHERE team_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$team_id, $user_id]);
        $currMember = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currMember) {
            return ['success' => false, 'error' => 'Người dùng không phải là thành viên hoạt động trong nhóm!'];
        }

        if ($new_role === 'OWNER') {
            // Chuyển giao quyền Trưởng nhóm
            $oldOwnerId = $team['owner_user_id'];
            if ($oldOwnerId && $oldOwnerId !== $user_id) {
                $db->prepare("UPDATE `team_members` SET role = 'ADMIN' WHERE team_id = ? AND user_id = ?")->execute([$team_id, $oldOwnerId]);
            }
            $db->prepare("UPDATE `team_members` SET role = 'OWNER' WHERE id = ?")->execute([$currMember['id']]);
            $db->prepare("UPDATE `teams` SET owner_user_id = ? WHERE id = ?")->execute([$user_id, $team_id]);
            return ['success' => true, 'message' => 'Đã chuyển giao quyền Trưởng nhóm thành công!'];
        } else {
            if ($currMember['role'] === 'OWNER') {
                return ['success' => false, 'error' => 'Không thể hạ cấp Trưởng nhóm! Hãy chuyển quyền Trưởng nhóm cho thành viên khác trước.'];
            }
            $upd = $db->prepare("UPDATE `team_members` SET role = ? WHERE id = ?");
            $upd->execute([$new_role, $currMember['id']]);
            return ['success' => true, 'message' => 'Đã cập nhật phân quyền vai trò thành công!'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_update_team_name($team_id, $new_name) {
    try {
        $db = get_db();
        $clean_name = trim($new_name);
        if (empty($clean_name)) {
            return ['success' => false, 'error' => 'Tên đội nhóm không được để trống!'];
        }
        $stmt = $db->prepare("UPDATE `teams` SET name = ? WHERE id = ?");
        $stmt->execute([$clean_name, $team_id]);

        // Đồng bộ cập nhật tên Không gian Cloud tương ứng
        $db->prepare("UPDATE `cloud_spaces` SET name = ? WHERE owner_type = 'TEAM' AND owner_id = ?")
           ->execute([$clean_name . ' Space', $team_id]);

        return ['success' => true, 'new_name' => $clean_name];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function db_delete_team_permanently($team_id) {
    try {
        $db = get_db();
        // 1. Delete members
        $db->prepare("DELETE FROM `team_members` WHERE team_id = ?")->execute([$team_id]);

        // 2. Find and delete associated cloud space & resources
        $stmt = $db->prepare("SELECT id FROM `cloud_spaces` WHERE owner_type = 'TEAM' AND owner_id = ?");
        $stmt->execute([$team_id]);
        $spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($spaces as $s) {
            $db->prepare("DELETE FROM `cloud_space_quotas` WHERE cloud_space_id = ?")->execute([$s['id']]);
            $db->prepare("DELETE FROM `cloud_files` WHERE cloud_space_id = ?")->execute([$s['id']]);
            $db->prepare("DELETE FROM `cloud_folders` WHERE cloud_space_id = ?")->execute([$s['id']]);
            $db->prepare("DELETE FROM `cloud_spaces` WHERE id = ?")->execute([$s['id']]);
        }

        // 3. Delete team
        $db->prepare("DELETE FROM `teams` WHERE id = ?")->execute([$team_id]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>