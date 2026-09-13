<?php
// website/sepay_ipn.php
// Instant Payment Notification (IPN) Webhook Handler for SePay Gateway
// Endpoint: https://www.2tamne.site/sepay_ipn.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/storage/db.php';
require_once __DIR__ . '/sepay_config.php';
require_once __DIR__ . '/lib/SepayClient.php';

// ── 1. GHI LOG KIỂM TOÁN WEBHOOK ─────────────────────────────────────
function sepay_log(string $msg, ?array $context = null): void {
    $log_dir = __DIR__ . '/storage';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/sepay_ipn.log';
    $time = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $line = "[{$time}] [{$ip}] {$msg}";
    if ($context !== null) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents($log_file, $line, FILE_APPEND);
}

// ── 2. ĐỌC RAW INPUT & HEADERS ──────────────────────────────────────
$raw_body = file_get_contents('php://input');
$config   = sepay_get_config();

// Thu thập toàn bộ headers từ mọi nguồn có thể
$all_headers = [];
if (function_exists('getallheaders')) {
    $all_headers = getallheaders() ?: [];
} elseif (function_exists('apache_request_headers')) {
    $all_headers = apache_request_headers() ?: [];
}
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
        if (!isset($all_headers[$header_name])) {
            $all_headers[$header_name] = $v;
        }
    }
}
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $all_headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $all_headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

// Tìm Secret Key trong Headers (hỗ trợ X-Secret-Key, Authorization: Apikey..., Authorization: Bearer...)
$received_key = '';
foreach ($all_headers as $hk => $hv) {
    $hk_lower = strtolower((string)$hk);
    if ($hk_lower === 'x-secret-key' || $hk_lower === 'secret-key' || $hk_lower === 'x_secret_key') {
        $received_key = trim((string)$hv);
        break;
    }
    if ($hk_lower === 'authorization') {
        $auth_val = trim((string)$hv);
        if (preg_match('/^(?:Apikey|Bearer)\s+(.+)$/i', $auth_val, $m)) {
            $received_key = trim($m[1]);
            break;
        } elseif (!empty($auth_val)) {
            $received_key = $auth_val;
            break;
        }
    }
}

// Parse body trước để lấy fallback key nếu có
$data = json_decode($raw_body, true);
if (empty($received_key) && is_array($data)) {
    $received_key = trim($data['secret_key'] ?? ($data['api_key'] ?? ''));
}
if (empty($received_key) && !empty($_GET['secret_key'])) {
    $received_key = trim($_GET['secret_key']);
}

// ── 3. XÁC THỰC BẢO MẬT (X-Secret-Key & SePay IP) ─────────────────────
$configured_secret = trim($config['secret_key'] ?? '');
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Danh sách các key hợp lệ (bao gồm key cấu hình và key từ SePay dashboard)
$valid_keys = array_unique(array_filter([
    $configured_secret,
    'spsk_test_4967qwHZZFHRifzhmStsiMZQc5TTycAt',
    'spsk_test_4967qmHZZFHRifzhmStsiMZQc5TTycAt'
]));

$is_valid = false;
foreach ($valid_keys as $vk) {
    if (!empty($received_key) && hash_equals($vk, $received_key)) {
        $is_valid = true;
        break;
    }
}

// Nếu đến từ dải IP chính thức của SePay Webhook Server hoặc localhost
$is_sepay_ip = (
    strpos($client_ip, '172.236.138.') === 0 || 
    $client_ip === '127.0.0.1' || 
    $client_ip === '::1'
);

if (!empty($configured_secret) && !$is_valid && !$is_sepay_ip) {
    sepay_log('XÁC THỰC THẤT BẠI (Invalid X-Secret-Key)', [
        'received_key'    => substr($received_key, 0, 8) . '...',
        'client_ip'       => $client_ip,
        'raw_length'      => strlen($raw_body)
    ]);
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid X-Secret-Key']);
    exit;
}

if ($is_sepay_ip && !$is_valid) {
    sepay_log("Cho phép request từ IP SePay ({$client_ip}) để kiểm thử kết nối webhook");
}

// ── 4. PARSE JSON PAYLOAD & XỬ LÝ TEST PING ──────────────────────────
if (!is_array($data)) {
    sepay_log('LỖI: Body không phải JSON hợp lệ', ['raw' => substr($raw_body, 0, 200)]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$notification_type = $data['notification_type'] ?? '';
$order_data        = $data['order'] ?? [];
$transaction_data  = $data['transaction'] ?? [];
$invoice_number    = trim($order_data['order_invoice_number'] ?? ($order_data['id'] ?? ''));
$order_amount      = $order_data['order_amount'] ?? 0;
$transaction_id    = $transaction_data['transaction_id'] ?? ($transaction_data['id'] ?? 'N/A');

sepay_log("Nhận IPN: {$notification_type} | Invoice: {$invoice_number} | Amount: {$order_amount} | TransId: {$transaction_id}");

// Phản hồi 200 OK thành công cho bất kỳ loại thông báo nào khác ORDER_PAID (ví dụ: TEST, PING, CANCEL)
if ($notification_type !== 'ORDER_PAID') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => "Acknowledged notification type: {$notification_type}"]);
    exit;
}

// Nếu đơn hàng test không có invoice number
if (empty($invoice_number)) {
    sepay_log('Thông báo TEST từ SePay (Không có order_invoice_number): Phản hồi 200 OK thành công');
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Test IPN acknowledged successfully']);
    exit;
}

// ── 5. TRA CỨU ĐƠN HÀNG TRONG DATABASE ──────────────────────────────
$order = db_get_order($invoice_number);
if (!$order) {
    // Nếu là đơn test được SePay tự sinh khi bấm "Gửi test" (không có trong DB local)
    sepay_log("Đơn hàng test từ SePay (Không có trong DB): {$invoice_number} - Phản hồi 200 OK thành công");
    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'message'  => "Test IPN acknowledged successfully (Order {$invoice_number} verified as test)",
        'order_id' => $invoice_number
    ]);
    exit;
}

// Chống xử lý trùng lặp (Idempotency)
if (($order['status'] ?? '') === 'approved') {
    sepay_log("Đơn hàng đã được duyệt trước đó: {$invoice_number} (Key/Label: {$order['issued_key']})");
    http_response_code(200);
    echo json_encode([
        'success'    => true,
        'message'    => 'Order already approved',
        'issued_key' => $order['issued_key']
    ]);
    exit;
}

// ── 6. TỰ ĐỘNG KÍCH HOẠT TOKEN HOẶC LICENSE KEY ─────────────────────
$pkg_n         = $order['package_name'] ?? '';
$u             = $order['username'] ?? ($order['user'] ?? '');
$ord_id        = $order['id'];
$prod          = $order['product'] ?? 'SLIDESHOW';
$tier          = $order['tier'] ?? 'VIP';
$days          = intval($order['duration_days'] ?? 30);

$is_token_order = ($prod === 'TOKEN_WALLET' || stripos($pkg_n, 'Token') !== false || stripos($pkg_n, 'Unlimited') !== false);
$is_unlimited   = (stripos($pkg_n, 'Unlimited') !== false || stripos($pkg_n, 'Trọn Đời') !== false);
$is_cloud_storage = ($prod === 'CLOUD_STORAGE' || (stripos($pkg_n, 'Cloud') !== false && stripos($pkg_n, 'GB') !== false));
$is_team_cloud    = ($prod === 'TEAM_CLOUD' || $prod === 'TEAM_WORKSPACE' || stripos($pkg_n, 'Team') !== false || stripos($pkg_n, 'Slot') !== false);
$is_capcut      = ($prod === '2toolne.capcut.v2' || $prod === 'CAPCUT_V2' || stripos($pkg_n, 'CapCut') !== false || stripos($pkg_n, 'AutoEdit') !== false);
$is_2toolne     = !$is_capcut && ($prod === '2TOOLNE' || stripos($pkg_n, '2toolne') !== false);
$is_ext_order   = ($prod === 'LABS_EXTENSION' || stripos($pkg_n, 'Extension') !== false);

$issued_result = '';

try {
    if ($is_token_order) {
        if ($is_unlimited) {
            $pdo = get_db();
            if ($pdo) {
                $user_rec = db_get_user($u);
                $uid = $user_rec['id'] ?? null;
                if ($uid) {
                    $ent = $pdo->prepare("SELECT id FROM `license_entitlements` WHERE `user_id` = ? LIMIT 1");
                    $ent->execute([$uid]);
                    if ($ent->fetch()) {
                        $st = $pdo->prepare("UPDATE `license_entitlements` SET `credit_mode` = 'UNLIMITED', `plan` = 'STUDIO' WHERE `user_id` = ?");
                        $st->execute([$uid]);
                    } else {
                        $licId = 'lic_' . bin2hex(random_bytes(12));
                        $st = $pdo->prepare("INSERT INTO `license_entitlements` (`id`, `user_id`, `plan`, `credit_mode`, `max_devices`, `created_at`) VALUES (?, ?, 'STUDIO', 'UNLIMITED', 3, NOW())");
                        $st->execute([$licId, $uid]);
                    }
                    $txId = 'tx_' . bin2hex(random_bytes(12));
                    $w = db_get_user_wallet($uid);
                    $curBal = $w['balance'] ?? 0;
                    $pdo->prepare("INSERT INTO `credit_transactions` (`id`, `user_id`, `amount`, `balance_after`, `type`, `reference_id`, `description`, `created_by`, `created_at`) VALUES (?, ?, 0, ?, 'UPGRADE', ?, ?, 'SEPAY_AUTO', NOW())")
                        ->execute([$txId, $uid, $curBal, $ord_id, "Kích hoạt thành công gói {$pkg_n} (Đơn {$ord_id})"]);
                }
            }
            $assigned_label = 'UNLIMITED-STUDIO';
        } else {
            $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
            $tokens_to_add = (int)($memo['total_tokens'] ?? 0);
            if ($tokens_to_add <= 0) {
                if (preg_match('/(\d+[\.,]?\d*)\s*Token/ui', $pkg_n, $m)) {
                    $raw_num = str_replace(['.', ','], '', $m[1]);
                    if (intval($raw_num) > 0) {
                        $tokens_to_add = intval($raw_num);
                    }
                } elseif (stripos($pkg_n, '30.000') !== false || stripos($pkg_n, '1.800.000') !== false) {
                    $tokens_to_add = 35000;
                } elseif (stripos($pkg_n, '10.000') !== false || stripos($pkg_n, '700.000') !== false) {
                    $tokens_to_add = 11000;
                } elseif (stripos($pkg_n, '3.000') !== false || stripos($pkg_n, '250.000') !== false) {
                    $tokens_to_add = 3200;
                } elseif (stripos($pkg_n, '1.000') !== false || stripos($pkg_n, '100.000') !== false) {
                    $tokens_to_add = 1000;
                } else {
                    $tokens_to_add = 1000;
                }
            }

            $target_type = $memo['target_type'] ?? 'PERSONAL';
            $team_id = $memo['team_id'] ?? null;

            if ($target_type === 'TEAM' && !empty($team_id)) {
                $pdo = get_db();
                $wStmt = $pdo->prepare('SELECT balance FROM `credit_wallets` WHERE `team_id` = ? FOR UPDATE');
                $wStmt->execute([$team_id]);
                $wRow = $wStmt->fetch();
                if (!$wRow) {
                    $newBal = $tokens_to_add;
                    $pdo->prepare('INSERT INTO `credit_wallets` (`id`, `team_id`, `balance`, `reserved_balance`) VALUES (?, ?, ?, 0)')
                        ->execute(['cw_' . bin2hex(random_bytes(10)), $team_id, $newBal]);
                } else {
                    $newBal = (int)$wRow['balance'] + $tokens_to_add;
                    $pdo->prepare('UPDATE `credit_wallets` SET `balance` = ? WHERE `team_id` = ?')
                        ->execute([$newBal, $team_id]);
                }
                $user_rec = db_get_user($u);
                $uid = $user_rec['id'] ?? $u;
                $txId = 'tx_' . bin2hex(random_bytes(12));
                $pdo->prepare('
                    INSERT INTO `credit_transactions` (`id`, `user_id`, `team_id`, `amount`, `balance_after`, `type`, `reference_id`, `description`, `created_by`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, "TOPUP", ?, ?, "SEPAY_AUTO", NOW())
                ')->execute([$txId, $uid, $team_id, $tokens_to_add, $newBal, $ord_id, "Nạp token Team thành công đơn {$ord_id}: {$pkg_n} (+{$tokens_to_add} Tokens)"]);
                $assigned_label = "+" . number_format($tokens_to_add) . " TOKENS (TEAM)";
            } else {
                db_adjust_user_wallet($u, $tokens_to_add, "Thanh toán thành công đơn {$ord_id}: {$pkg_n} (+{$tokens_to_add} Lượt)", 'SEPAY_AUTO');
                $assigned_label = "+" . number_format($tokens_to_add) . " LƯỢT";
            }
        }
        db_approve_order($ord_id, $assigned_label);
        $issued_result = $assigned_label;
        sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN TOKEN {$ord_id} cho user {$u}: {$assigned_label}");
    } elseif ($is_cloud_storage) {
        $gb_to_add = 20;
        if (preg_match('/(\d+)\s*GB/i', $pkg_n, $mGB)) {
            $gb_to_add = (int)$mGB[1];
        }
        $user_rec = db_get_user($u);
        $uid = $user_rec['id'] ?? null;
        $spId = $uid ? db_ensure_user_personal_space($uid) : null;
        if ($spId) {
            db_add_cloud_space_quota($spId, $gb_to_add, 90, "Thanh toán tự động SePay đơn {$ord_id}: {$pkg_n}");
        }
        $assigned_label = "+{$gb_to_add} GB CLOUD (3 Tháng)";
        db_approve_order($ord_id, $assigned_label);
        $issued_result = $assigned_label;
        sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN CLOUD {$ord_id} cho user {$u}: +{$gb_to_add} GB");
    } elseif ($is_team_cloud) {
        $user_rec = db_get_user($u);
        $uid = $user_rec['id'] ?? null;
        if (stripos($pkg_n, 'Slot') !== false) {
            $slots_to_add = 1;
            if (preg_match('/(\d+)\s*Slot/i', $pkg_n, $mSlot)) {
                $slots_to_add = (int)$mSlot[1];
            }
            $allTeams = db_get_all_teams();
            $ownedTeam = null;
            foreach ($allTeams as $tm) {
                if ($tm['owner_user_id'] === $uid) { $ownedTeam = $tm; break; }
            }
            if ($ownedTeam) {
                db_add_team_slots($ownedTeam['id'], $slots_to_add);
            }
            $assigned_label = "+{$slots_to_add} SLOTS TEAM";
        } else {
            $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
            $planId = $memo['plan_id'] ?? $order['tier'];
            $teamName = $memo['team_name'] ?? ("Team của " . ($user_rec['fullname'] ?: $u));
            $userId = (string)($uid ?: ($memo['user_id'] ?? $u));

            if (file_exists(__DIR__ . '/api/v1/services/TeamProvisioningService.php')) {
                require_once __DIR__ . '/api/v1/services/TeamProvisioningService.php';
                $db = get_db();
                $pStmt = $db->prepare('SELECT * FROM team_plans WHERE id = ? LIMIT 1');
                $pStmt->execute([$planId]);
                $plan = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [
                    'name'              => $pkg_n,
                    'duration_days'     => $days,
                    'member_slots'      => 2,
                    'desktop_key_count' => 2,
                    'storage_bytes'     => 53687091200,
                    'initial_tokens'    => 500,
                ];
                $provRes = TeamProvisioningService::provisionTeam($userId, $teamName, $plan, $order['id'], $order['id']);
                $teamId = $provRes['team']['id'] ?? '';
            } else {
                $teamId = db_create_team($teamName, $userId, 2, 90, 20);
            }
            $assigned_label = "TEAM-WORKSPACE-{$teamId}";
        }
        db_approve_order($ord_id, $assigned_label);
        $issued_result = $assigned_label;
        sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN TEAM CLOUD {$ord_id} cho user {$u}: {$assigned_label}");
    } else {
        // Cấp License Key tương ứng
        if ($is_capcut) {
            $prod_tag = '2toolne.capcut.v2';
            $tier_tag = $tier;
            $days_val = $days;
            $new_key  = generate_capcut_v2_key();
            db_create_capcut_v2_license(
                $new_key,
                $tier_tag,
                $days_val,
                $u,
                "Kích hoạt tự động SePay đơn {$ord_id}: {$pkg_n} ({$order['package_price']})"
            );
            db_approve_order($ord_id, $new_key);
            $issued_result = $new_key;
            sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN KEY CAPCUT V2 {$ord_id} cho user {$u}: Key {$new_key}");
        } elseif ($is_2toolne) {
            $prod_tag = '2TOOLNE';
            $tier_tag = $tier;
            $days_val = $days;
            $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $new_key = "2TOOLNE-{$tier}-{$rnd}";
            db_create_license(
                $new_key,
                $prod_tag,
                $tier_tag,
                $days_val,
                $u,
                "Kích hoạt tự động đơn {$ord_id}: {$pkg_n} ({$order['package_price']})"
            );
            db_approve_order($ord_id, $new_key);
            $issued_result = $new_key;
            sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN KEY {$ord_id} cho user {$u}: Key {$new_key}");
        } elseif ($is_ext_order) {
            $prod_tag = 'LABS_EXTENSION';
            $tier_tag = 'LIFETIME';
            $days_val = 36500;
            $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $new_key = "2TAMNE-LABS-{$rnd}";
            db_create_license(
                $new_key,
                $prod_tag,
                $tier_tag,
                $days_val,
                $u,
                "Kích hoạt tự động đơn {$ord_id}: {$pkg_n} ({$order['package_price']})"
            );
            db_approve_order($ord_id, $new_key);
            $issued_result = $new_key;
            sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN KEY {$ord_id} cho user {$u}: Key {$new_key}");
        } else {
            $prod_tag = 'SLIDESHOW';
            $tier_tag = $tier;
            $days_val = $days;
            $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $new_key = "2TAMNE-{$tier}-{$rnd}";
            db_create_license(
                $new_key,
                $prod_tag,
                $tier_tag,
                $days_val,
                $u,
                "Kích hoạt tự động đơn {$ord_id}: {$pkg_n} ({$order['package_price']})"
            );
            db_approve_order($ord_id, $new_key);
            $issued_result = $new_key;
            sepay_log("ĐÃ DUYỆT TỰ ĐỘNG ĐƠN KEY {$ord_id} cho user {$u}: Key {$new_key}");
        }
    }

    http_response_code(200);
    echo json_encode([
        'success'        => true,
        'message'        => 'Order approved and fulfilled successfully',
        'order_id'       => $ord_id,
        'issued_result'  => $issued_result,
        'transaction_id' => $transaction_id
    ]);
} catch (Exception $e) {
    sepay_log("LỖI XỬ LÝ ĐƠN HÀNG {$ord_id}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
