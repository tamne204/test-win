<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/storage/db.php';

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$key          = trim($input['license_key'] ?? '');
$hwid         = trim($input['hwid'] ?? '');
$device_name  = trim($input['device_name'] ?? 'DESKTOP-CLIENT');
$tool_version = trim($input['tool_version'] ?? '2.0.0');

if (empty($key)) {
    json_out(['ok' => false, 'error_code' => 'EMPTY_KEY', 'message' => 'Vui lòng cung cấp mã bản quyền (license_key).'], 400);
}
if (empty($hwid)) {
    json_out(['ok' => false, 'error_code' => 'EMPTY_HWID', 'message' => 'Vui lòng cung cấp mã phần cứng (hwid).'], 400);
}

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM `licenses` WHERE `license_key` = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        json_out(['ok' => false, 'error_code' => 'KEY_NOT_FOUND', 'message' => 'Mã bản quyền không tồn tại trên hệ thống 2tamne.site.'], 404);
    }

    if (($lic['status'] ?? '') === 'banned') {
        json_out(['ok' => false, 'error_code' => 'KEY_BANNED', 'message' => 'Mã bản quyền này đã bị khóa do vi phạm điều khoản sử dụng.'], 403);
    }

    $now = time();
    $duration_days = intval($lic['duration_days'] ?? 30);
    $is_lifetime = ($duration_days <= 0 || $duration_days >= 9999);
    $product = $lic['product'] ?? (strpos($key, '2TAMNE-LABS-') === 0 ? 'LABS_EXTENSION' : 'SLIDESHOW');

    // 1. FIRST ACTIVATION (HWID is empty)
    if (empty($lic['hwid'])) {
        $activated_at = date('Y-m-d H:i:s', $now);
        if ($is_lifetime) {
            $expires_at = '2099-12-31 23:59:59';
            $days_left = 9999;
        } else {
            $expire_ts = $now + ($duration_days * 86400);
            $expires_at = date('Y-m-d H:i:s', $expire_ts);
            $days_left = $duration_days;
        }

        $up_stmt = $db->prepare("
            UPDATE `licenses` SET
                `hwid` = :hwid,
                `device_name` = :dev,
                `tool_version` = :ver,
                `product` = :prod,
                `status` = 'active',
                `activated_at` = :act_at,
                `expires_at` = :exp_at
            WHERE `license_key` = :k
        ");
        $up_stmt->execute([
            ':hwid'   => $hwid,
            ':dev'    => $device_name,
            ':ver'    => $tool_version,
            ':prod'   => $product,
            ':act_at' => $activated_at,
            ':exp_at' => $expires_at,
            ':k'      => $key
        ]);

        json_out([
            'ok' => true,
            'status' => 'active',
            'message' => 'Kích hoạt bản quyền thành công!',
            'license' => [
                'key' => $key,
                'tier' => $lic['tier'] ?? 'VIP',
                'product' => $product,
                'activated_at' => $activated_at,
                'expires_at' => $expires_at,
                'days_left' => $days_left,
                'max_devices' => 1,
                'features' => ['auto_all', 'turbo_speed', 'vip_support']
            ],
            'session_token' => hash('sha256', $key . $hwid . $now)
        ], 200);
    }

    // 2. KEY ALREADY ACTIVATED - CHECK HWID MATCH
    if ($lic['hwid'] !== $hwid) {
        $p1 = explode('_', $lic['hwid']);
        $p2 = explode('_', $hwid);
        if (!empty($p1[1]) && !empty($p2[1]) && $p1[1] === $p2[1]) {
            $db->prepare("UPDATE `licenses` SET `hwid` = :h, `tool_version` = :v WHERE `license_key` = :k")->execute([':h' => $hwid, ':v' => $tool_version, ':k' => $key]);
        } else {
            json_out([
                'ok' => false,
                'error_code' => 'HWID_MISMATCH',
                'message' => 'Key này đã được kích hoạt trên một thiết bị khác! Vui lòng vào trang 2tamne.site để bấm Đổi Máy (Reset HWID).'
            ], 403);
        }
    }

    // 3. CHECK EXPIRATION
    if (!$is_lifetime) {
        $expire_ts = strtotime($lic['expires_at']);
        if ($now > $expire_ts) {
            $db->prepare("UPDATE `licenses` SET `status` = 'expired' WHERE `license_key` = :k")->execute([':k' => $key]);
            json_out([
                'ok' => false,
                'error_code' => 'KEY_EXPIRED',
                'message' => 'Mã bản quyền của bạn đã hết hạn sử dụng. Vui lòng gia hạn thêm ngày.'
            ], 403);
        }
        $days_left = max(0, ceil(($expire_ts - $now) / 86400));
    } else {
        $days_left = 9999;
    }

    json_out([
        'ok' => true,
        'status' => 'active',
        'message' => 'Xác thực bản quyền thành công!',
        'license' => [
            'key' => $key,
            'tier' => $lic['tier'] ?? 'VIP',
            'product' => $product,
            'activated_at' => $lic['activated_at'],
            'expires_at' => $lic['expires_at'],
            'days_left' => $days_left,
            'max_devices' => 1,
            'features' => ['auto_all', 'turbo_speed', 'vip_support']
        ],
        'session_token' => hash('sha256', $key . $hwid . $now)
    ], 200);

} catch (Exception $e) {
    json_out(['ok' => false, 'error_code' => 'DB_ERROR', 'message' => 'Lỗi kết nối cơ sở dữ liệu.'], 500);
}
