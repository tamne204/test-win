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

$key  = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if (empty($key) || empty($hwid)) {
    json_out(['ok' => false, 'error_code' => 'MISSING_PARAMS', 'message' => 'Thiếu tham số license_key hoặc hwid.'], 400);
}

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM `licenses` WHERE `license_key` = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $lic = $stmt->fetch();

    if (!$lic) {
        json_out(['ok' => false, 'error_code' => 'KEY_NOT_FOUND', 'message' => 'Mã bản quyền không tồn tại.'], 404);
    }

    if (($lic['status'] ?? '') === 'banned') {
        json_out(['ok' => false, 'error_code' => 'KEY_BANNED', 'message' => 'Mã bản quyền đã bị khóa.'], 403);
    }

    if (empty($lic['hwid'])) {
        json_out(['ok' => false, 'error_code' => 'KEY_NOT_ACTIVATED', 'message' => 'Key này chưa được kích hoạt.'], 400);
    }

    if ($lic['hwid'] !== $hwid) {
        $p1 = explode('_', $lic['hwid']);
        $p2 = explode('_', $hwid);
        if (!empty($p1[1]) && !empty($p2[1]) && $p1[1] === $p2[1]) {
            $db->prepare("UPDATE `licenses` SET `hwid` = :h WHERE `license_key` = :k")->execute([':h' => $hwid, ':k' => $key]);
        } else {
            json_out(['ok' => false, 'error_code' => 'HWID_MISMATCH', 'message' => 'Mã phần cứng (HWID) không khớp với thiết bị đăng ký.'], 403);
        }
    }

    $now = time();
    $duration_days = intval($lic['duration_days'] ?? 30);
    $is_lifetime = ($duration_days <= 0 || $duration_days >= 9999);
    $product = $lic['product'] ?? (strpos($key, '2TAMNE-LABS-') === 0 ? 'LABS_EXTENSION' : 'SLIDESHOW');

    if (!$is_lifetime) {
        $expire_ts = strtotime($lic['expires_at']);
        if ($now > $expire_ts) {
            json_out(['ok' => false, 'error_code' => 'KEY_EXPIRED', 'message' => 'Mã bản quyền đã hết hạn.'], 403);
        }
        $days_left = max(0, ceil(($expire_ts - $now) / 86400));
    } else {
        $days_left = 9999;
    }

    json_out([
        'ok' => true,
        'status' => 'valid',
        'tier' => $lic['tier'] ?? 'VIP',
        'product' => $product,
        'expires_at' => $lic['expires_at'],
        'days_left' => $days_left,
        'features' => ['auto_all', 'turbo_speed', 'vip_support']
    ], 200);

} catch (Exception $e) {
    json_out(['ok' => false, 'error_code' => 'DB_ERROR', 'message' => 'Lỗi kết nối cơ sở dữ liệu.'], 500);
}
