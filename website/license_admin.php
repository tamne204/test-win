<?php
session_start();
require_once __DIR__ . '/storage/db.php';
require_once __DIR__ . '/sepay_config.php';

// Session Timeout: 4 hours
$timeout_duration = 14400; // 4 hours
$is_admin = !empty($_SESSION['admin_logged']);

if ($is_admin) {
    $last_act = $_SESSION['admin_last_active'] ?? 0;
    if ($last_act > 0 && (time() - $last_act > $timeout_duration)) {
        unset($_SESSION['admin_logged']);
        unset($_SESSION['admin_last_active']);
        session_destroy();
        header("Location: license_admin.php?timeout=1");
        exit;
    }
    $_SESSION['admin_last_active'] = time();
}

$ADMIN_PASSWORD = '@2TamneAdmin2026';

// ── PERMISSIONS & ROLE PRESETS ──────────────────────────────────────
$ALL_PERMISSIONS = [
    'orders.view'        => ['label' => 'Xem lịch sử đơn hàng & nhật ký IPN', 'group' => 'Đơn Hàng'],
    'gateway.manage'     => ['label' => 'Cấu hình cổng thanh toán (SePay)', 'group' => 'Đơn Hàng'],
    'orders.approve'     => ['label' => 'Duyệt đơn chuyển khoản thủ công', 'group' => 'Đơn Hàng'],
    'orders.reject'      => ['label' => 'Từ chối / Hủy đơn thủ công', 'group' => 'Đơn Hàng'],
    'keys.view'          => ['label' => 'Xem danh sách license keys', 'group' => 'License Keys'],
    'keys.create'        => ['label' => 'Tạo mới license key thủ công', 'group' => 'License Keys'],
    'keys.reset_hwid'    => ['label' => 'Reset HWID (đổi máy)', 'group' => 'License Keys'],
    'keys.ban'           => ['label' => 'Khóa / Mở khóa key', 'group' => 'License Keys'],
    'keys.assign'        => ['label' => 'Gán / Đổi user sở hữu key', 'group' => 'License Keys'],
    'keys.delete'        => ['label' => 'Xóa vĩnh viễn key', 'group' => 'License Keys'],
    'users.view'         => ['label' => 'Xem & tìm kiếm user', 'group' => 'Người Dùng'],
    'users.add_key'      => ['label' => 'Cấp key trực tiếp cho user', 'group' => 'Người Dùng'],
    'users.password'     => ['label' => 'Đặt lại mật khẩu user', 'group' => 'Người Dùng'],
    'users.edit'         => ['label' => 'Sửa thông tin user', 'group' => 'Người Dùng'],
    'users.delete'       => ['label' => 'Xóa tài khoản user', 'group' => 'Người Dùng'],
    'users.role'         => ['label' => 'Phân quyền quản trị (Super Admin)', 'group' => 'Người Dùng'],
    'users.cloud_manage' => ['label' => 'Quản lý dung lượng Cloud trong hồ sơ User', 'group' => 'Người Dùng'],
    'tokens.manage'        => ['label' => 'Quản lý token (Xem ví & lịch sử)', 'group' => 'Ví & Token'],
    'tokens.approve_order' => ['label' => 'Duyệt đơn nạp token', 'group' => 'Ví & Token'],
    'tokens.adjust'        => ['label' => 'Điều chỉnh trực tiếp số dư token', 'group' => 'Ví & Token'],
    'features.manage'    => ['label' => 'Xem & xử lý góp ý tính năng', 'group' => 'Góp Ý & Báo Lỗi'],
    'bugs.manage'        => ['label' => 'Xem & xử lý báo cáo lỗi', 'group' => 'Góp Ý & Báo Lỗi'],
    'broadcast.manage'   => ['label' => 'Cấu hình bản cập nhật & Phát thông báo', 'group' => 'Hệ Thống'],
    'cloud.view'         => ['label' => 'Xem số liệu & cụm lưu trữ 2TOOLNE Cloud', 'group' => '2TOOLNE Cloud'],
    'cloud.manage'       => ['label' => 'Quản lý tài khoản Google Drive vật lý trong cụm', 'group' => '2TOOLNE Cloud'],
    'cloud.quota_adjust' => ['label' => 'Điều chỉnh hạn mức dung lượng Space & Người dùng', 'group' => '2TOOLNE Cloud'],
    'teams.view'         => ['label' => 'Xem danh sách đội nhóm (Teams)', 'group' => 'Đội Nhóm (Teams)'],
    'teams.manage'       => ['label' => 'Tạo, sửa, xóa & gán thành viên Team', 'group' => 'Đội Nhóm (Teams)']
];

$ROLE_PRESETS = [
    'super_admin' => [
        'title' => '👑 Super Admin (Toàn quyền)',
        'perms' => array_keys($ALL_PERMISSIONS)
    ],
    'sales' => [
        'title' => '🛒 Nhân Viên Bán Hàng / CSKH',
        'perms' => ['orders.view', 'gateway.manage', 'orders.approve', 'orders.reject', 'keys.view', 'keys.create', 'keys.assign', 'users.view', 'users.add_key', 'tokens.manage', 'tokens.approve_order', 'cloud.view', 'cloud.quota_adjust', 'users.cloud_manage', 'teams.view', 'teams.manage']
    ],
    'tech_support' => [
        'title' => '🛠️ Kỹ Thuật Viên (Hỗ trợ kỹ thuật)',
        'perms' => ['keys.view', 'keys.reset_hwid', 'keys.ban', 'users.view', 'bugs.manage', 'features.manage', 'tokens.manage', 'cloud.view', 'cloud.manage', 'cloud.quota_adjust', 'users.cloud_manage', 'teams.view', 'teams.manage']
    ],
    'content_manager' => [
        'title' => '📢 Quản Trị Nội Dung & Phiên Bản',
        'perms' => ['broadcast.manage', 'features.manage']
    ],
    'custom' => [
        'title' => '⚙️ Tùy Chỉnh Quyền Hạn (Custom)',
        'perms' => []
    ],
    'user' => [
        'title' => '👤 Người Dùng Thường (Không có quyền admin)',
        'perms' => []
    ]
];

if (!function_exists('adm_can')) {
    function adm_can($perm) {
        if (empty($_SESSION['admin_logged'])) return false;
        $role = $_SESSION['admin_role'] ?? 'super_admin';
        if ($role === 'super_admin' || $role === 'admin') return true;
        $perms = $_SESSION['admin_permissions'] ?? [];
        if (in_array($perm, $perms)) return true;
        if ($perm === 'gateway.manage' && in_array('sepay.manage', $perms)) return true;
        if ($perm === 'cloud.view' && (in_array('cloud.manage', $perms) || in_array('cloud.quota_adjust', $perms) || in_array('users.cloud_manage', $perms) || in_array('users.view', $perms))) return true;
        if ($perm === 'cloud.quota_adjust' && (in_array('cloud.manage', $perms) || in_array('users.cloud_manage', $perms))) return true;
        if ($perm === 'cloud.manage' && (in_array('cloud.quota_adjust', $perms) || in_array('users.cloud_manage', $perms))) return true;
        if ($perm === 'users.cloud_manage' && (in_array('cloud.manage', $perms) || in_array('cloud.quota_adjust', $perms) || in_array('cloud.view', $perms))) return true;
        if ($perm === 'teams.view' && (in_array('teams.manage', $perms) || in_array('users.view', $perms) || in_array('users.edit', $perms))) return true;
        if ($perm === 'teams.manage' && (in_array('users.edit', $perms) || in_array('users.role', $perms) || in_array('users.view', $perms))) return true;
        return false;
    }
}

// ── SINGLE SIGN-ON (SSO) FROM index.php ─────────────────────────────
if (empty($_SESSION['admin_logged']) && !empty($_SESSION['user'])) {
    $sso_user = db_get_user($_SESSION['user']);
    if ($sso_user) {
        $sso_role = $sso_user['role'] ?? 'user';
        $sso_perms = $sso_user['permissions'] ?? [];
        if ($sso_role === 'super_admin' || $sso_role === 'admin') {
            $sso_perms = array_keys($ALL_PERMISSIONS);
        }
        if ($sso_role !== 'user' && (!empty($sso_perms) || $sso_role === 'super_admin' || $sso_role === 'admin')) {
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_user'] = $sso_user['username'];
            $_SESSION['admin_fullname'] = $sso_user['fullname'] ?: $sso_user['username'];
            $_SESSION['admin_role'] = $sso_role;
            $_SESSION['admin_permissions'] = $sso_perms;
            $_SESSION['admin_last_active'] = time();
            $is_admin = true;
        }
    }
}

// ── REAL-TIME PERMISSIONS SYNC (Instant database refresh) ────────────
if (!empty($_SESSION['admin_logged']) && !empty($_SESSION['admin_user']) && $_SESSION['admin_user'] !== 'super_admin') {
    $fresh_u = db_get_user($_SESSION['admin_user']);
    if ($fresh_u) {
        $fresh_role = $fresh_u['role'] ?? 'user';
        if ($fresh_role === 'super_admin' || $fresh_role === 'admin') {
            $_SESSION['admin_permissions'] = array_keys($ALL_PERMISSIONS);
        } else {
            $_SESSION['admin_permissions'] = $fresh_u['permissions'] ?? [];
        }
        $_SESSION['admin_role'] = $fresh_role;
        $_SESSION['admin_fullname'] = $fresh_u['fullname'] ?: $fresh_u['username'];
    }
}

// ── ADMIN AJAX ENDPOINT: Live pending count ─────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pending_count') {
    header('Content-Type: application/json');
    if (!$is_admin || !adm_can('orders.view')) { echo json_encode(['count' => 0]); exit; }
    $all_orders = db_get_orders();
    $pcount = 0;
    foreach ($all_orders as $ord) {
        if (($ord['status'] ?? '') === 'pending') $pcount++;
    }
    echo json_encode(['count' => $pcount]);
    exit;
}

// ── Admin PRG Flash Messages ─────────────────────────────────────────
$msg_success = '';
$msg_error   = '';
if (isset($_SESSION['adm_flash_success'])) { $msg_success = $_SESSION['adm_flash_success']; unset($_SESSION['adm_flash_success']); }
if (isset($_SESSION['adm_flash_error']))   { $msg_error   = $_SESSION['adm_flash_error'];   unset($_SESSION['adm_flash_error']);   }
if (isset($_GET['timeout'])) { $msg_success = '⏰ Phiên quản trị đã hết hạn (4 giờ). Vui lòng đăng nhập lại!'; }

if (!function_exists('adm_redirect')) {
    function adm_redirect($type, $msg, $tab = 'orders', $extra_data = []) {
        $is_ajax = !empty($_POST['ajax']) 
            || !empty($_GET['ajax'])
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'status' => $type === 'success' ? 'success' : 'error',
                'message' => strip_tags($msg),
                'html_message' => $msg,
                'tab' => $tab
            ], $extra_data), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION["adm_flash_{$type}"] = $msg;
        header("Location: license_admin.php?tab={$tab}");
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged']);
    unset($_SESSION['admin_user']);
    unset($_SESSION['admin_fullname']);
    unset($_SESSION['admin_role']);
    unset($_SESSION['admin_permissions']);
    unset($_SESSION['admin_last_active']);
    session_destroy();
    header("Location: license_admin.php");
    exit;
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $u = strtolower(trim($_POST['username'] ?? ''));
    $p = $_POST['password'] ?? '';

    // 1. Check Master Admin Password (khi không điền user hoặc điền admin/super_admin)
    if (empty($u) || $u === 'admin' || $u === 'super_admin') {
        if ($p === $ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_user'] = 'super_admin';
            $_SESSION['admin_fullname'] = 'Super Admin';
            $_SESSION['admin_role'] = 'super_admin';
            $_SESSION['admin_permissions'] = array_keys($ALL_PERMISSIONS);
            $_SESSION['admin_last_active'] = time();
            header("Location: license_admin.php");
            exit;
        } elseif (empty($u)) {
            $msg_error = 'Sai mật khẩu quản trị!';
        }
    }

    // 2. Check personal staff / admin account
    if (!empty($u) && empty($msg_error)) {
        $user_rec = db_get_user($u);
        if ($user_rec && password_verify($p, $user_rec['password_hash'])) {
            $u_role = $user_rec['role'] ?? 'user';
            $u_perms = $user_rec['permissions'] ?? [];
            if ($u_role === 'super_admin' || $u_role === 'admin') {
                $u_perms = array_keys($ALL_PERMISSIONS);
            }

            // Must have a non-user role or permissions
            if ($u_role !== 'user' && (!empty($u_perms) || $u_role === 'super_admin' || $u_role === 'admin')) {
                $_SESSION['admin_logged'] = true;
                $_SESSION['admin_user'] = $user_rec['username'];
                $_SESSION['admin_fullname'] = $user_rec['fullname'] ?: $user_rec['username'];
                $_SESSION['admin_role'] = $u_role;
                $_SESSION['admin_permissions'] = $u_perms;
                $_SESSION['admin_last_active'] = time();
                header("Location: license_admin.php");
                exit;
            } else {
                $msg_error = 'Tài khoản này chưa được cấp quyền truy cập hệ thống quản trị!';
            }
        } else {
            $msg_error = 'Tên tài khoản hoặc mật khẩu không chính xác!';
        }
    }
}

// Check logged in
if (!$is_admin && empty($_SESSION['admin_logged'])) {
    $admin_logged_in = false;
} else {
    $admin_logged_in = true;
}

// Load MySQL databases
$licenses_db = db_get_licenses();
$users_db    = db_get_users();
$teams_db    = db_get_all_teams();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();

// ── Admin AJAX Endpoints (GET) ──────────────────────────────────────
if ($admin_logged_in && isset($_GET['action'])) {
    $get_act = $_GET['action'];

    if ($get_act === 'get_user_token_logs') {
        $u_param = trim($_GET['username'] ?? ($_GET['user_id'] ?? ''));
        $target_u = db_get_user($u_param);
        if (!$target_u) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy người dùng']);
            exit;
        }
        $txs = db_get_user_token_transactions($target_u['id'], 100);
        $consumed = db_get_user_consumed_tokens($target_u['id']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'user' => [
                'id' => $target_u['id'],
                'username' => $target_u['username'],
                'fullname' => $target_u['fullname'],
                'token_balance' => (int)$target_u['token_balance'],
                'token_reserved' => (int)$target_u['token_reserved'],
                'token_consumed' => $consumed,
            ],
            'transactions' => $txs
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($get_act === 'get_team_details') {
        $tid = trim($_GET['team_id'] ?? '');
        $team = db_get_team($tid);
        $members = $team ? db_get_team_members($tid) : [];
        if ($team) {
            $quota_bytes = (float)($team['effective_quota_bytes'] ?? 0);
            $used_bytes = (float)($team['used_bytes'] ?? 0);
            $team['quota_gb'] = round($quota_bytes / (1024 * 1024 * 1024), 1);
            $team['used_gb'] = round($used_bytes / (1024 * 1024 * 1024), 2);
            $team['used_percent'] = $quota_bytes > 0 ? min(100, round(($used_bytes / $quota_bytes) * 100, 1)) : 0;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $team ? 'success' : 'error',
            'team' => $team,
            'members' => $members
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($get_act === 'get_sepay_logs') {
        if (!adm_can('orders.view') && !adm_can('gateway.manage')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền xem nhật ký IPN'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sepay_logs = [];
        $sepay_log_path = __DIR__ . '/storage/sepay_ipn.log';
        if (file_exists($sepay_log_path)) {
            $raw_log_lines = @file($sepay_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($raw_log_lines) {
                $sepay_logs = array_slice($raw_log_lines, -25);
                $sepay_logs = array_reverse($sepay_logs);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'logs' => $sepay_logs,
            'count' => count($sepay_logs),
            'timestamp' => date('H:i:s d/m/Y')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($get_act === 'poll_dashboard_updates') {
        if (!adm_can('orders.view') && !adm_can('gateway.manage') && !adm_can('tokens.manage')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fresh_orders = db_get_orders();
        $pending_orders = [];
        $pending_token_orders = [];
        foreach ($fresh_orders as $o) {
            if (($o['status'] ?? '') === 'pending') {
                $pending_orders[] = $o;
                $is_tok = ($o['product'] ?? '') === 'TOKEN_WALLET' || stripos($o['package_name'] ?? '', 'Token') !== false || stripos($o['package_name'] ?? '', 'Unlimited') !== false;
                if ($is_tok) {
                    $pending_token_orders[] = $o;
                }
            }
        }
        $sepay_logs = [];
        $sepay_log_path = __DIR__ . '/storage/sepay_ipn.log';
        if (file_exists($sepay_log_path)) {
            $raw = @file($sepay_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($raw) {
                $sepay_logs = array_slice($raw, -25);
                $sepay_logs = array_reverse($sepay_logs);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'pending_count' => count($pending_orders),
            'pending_orders' => $pending_orders,
            'pending_token_count' => count($pending_token_orders),
            'pending_token_orders' => $pending_token_orders,
            'logs_count' => count($sepay_logs),
            'sepay_logs' => $sepay_logs,
            'can_approve' => adm_can('orders.approve'),
            'can_approve_token' => adm_can('tokens.approve_order'),
            'can_reject' => adm_can('orders.reject'),
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Admin Actions
if ($admin_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // 1. APPROVE ORDER -> AUTO GENERATE KEY / ADD TOKENS
    if ($act === 'approve_order') {
        $ord_id = trim($_POST['order_id'] ?? '');
        $ord_item = null;
        foreach ($orders_db as $o) {
            if (($o['id'] ?? '') === $ord_id && ($o['status'] ?? '') === 'pending') {
                $ord_item = $o;
                break;
            }
        }
        if ($ord_item) {
            $u = $ord_item['user'] ?? '';
            $days = intval($ord_item['duration_days'] ?? 30);
            $tier = $ord_item['tier'] ?? 'VIP';
            
            $pkg_n = $ord_item['package_name'] ?? '';
            $is_token_order = ($ord_item['product'] ?? '') === 'TOKEN_WALLET' || stripos($pkg_n, 'Token') !== false || stripos($pkg_n, 'Unlimited') !== false;
            $is_cloud_storage = ($ord_item['product'] ?? '') === 'CLOUD_STORAGE' || (stripos($pkg_n, 'Cloud') !== false && stripos($pkg_n, 'GB') !== false);
            $is_team_cloud = ($ord_item['product'] ?? '') === 'TEAM_CLOUD' || stripos($pkg_n, 'Team') !== false || stripos($pkg_n, 'Slot') !== false;
            $is_capcut = ($ord_item['product'] ?? '') === '2toolne.capcut.v2' || ($ord_item['product'] ?? '') === 'CAPCUT_V2' || stripos($pkg_n, 'CapCut') !== false || stripos($pkg_n, 'AutoEdit') !== false;
            $is_2toolne = !$is_capcut && (($ord_item['product'] ?? '') === '2TOOLNE' || stripos($pkg_n, '2toolne') !== false);
            $is_ext_order = ($ord_item['product'] ?? '') === 'LABS_EXTENSION' || ($ord_item['tier'] ?? '') === 'LABS_EXTENSION' || stripos($pkg_n, 'Extension') !== false || strpos($ord_item['package_price'] ?? '', '100.000') !== false;

            if ($is_token_order) {
                if (!adm_can('tokens.approve_order')) {
                    adm_redirect('error', '❌ Bạn không có quyền duyệt đơn nạp token!', 'orders');
                }
            } elseif ($is_cloud_storage || $is_team_cloud) {
                if (!adm_can('orders.approve') && !adm_can('cloud.quota_adjust') && !adm_can('teams.manage')) {
                    adm_redirect('error', '❌ Bạn không có quyền duyệt đơn Cloud / Team!', 'orders');
                }
            } else {
                if (!adm_can('orders.approve')) {
                    adm_redirect('error', '❌ Bạn không có quyền duyệt đơn hàng cấp key!', 'orders');
                }
            }
            
            if ($is_token_order) {
                $admin_name = $_SESSION['admin_user'] ?? ($_SESSION['admin_auth_user'] ?? 'super_admin');
                $pkg_n = $ord_item['package_name'] ?? '';
                if (stripos($pkg_n, 'Unlimited') !== false || stripos($pkg_n, '1.800.000') !== false) {
                    db_ensure_user_wallet($u, 0);
                    $pdo = get_db();
                    if ($pdo) {
                        $user_rec = db_get_user($u);
                        $uid = $user_rec['id'] ?? null;
                        if ($uid) {
                            $ent = $pdo->prepare("SELECT id FROM license_entitlements WHERE user_id = ? LIMIT 1");
                            $ent->execute([$uid]);
                            if ($ent->fetch()) {
                                $st = $pdo->prepare("UPDATE license_entitlements SET credit_mode = 'UNLIMITED', plan = 'STUDIO' WHERE user_id = ?");
                                $st->execute([$uid]);
                            } else {
                                $licId = 'lic_' . bin2hex(random_bytes(12));
                                $st = $pdo->prepare("INSERT INTO license_entitlements (id, user_id, plan, credit_mode, max_devices, created_at) VALUES (?, ?, 'STUDIO', 'UNLIMITED', 3, NOW())");
                                $st->execute([$licId, $uid]);
                            }
                            $txId = 'tx_' . bin2hex(random_bytes(12));
                            $w = db_get_user_wallet($uid);
                            $curBal = $w['balance'] ?? 0;
                            $pdo->prepare("INSERT INTO credit_transactions (id, user_id, amount, balance_after, type, reference_id, description, created_by, created_at) VALUES (?, ?, 0, ?, 'UPGRADE', ?, ?, ?, NOW())")
                                ->execute([$txId, $uid, $curBal, $ord_id, "Admin {$admin_name} duyệt đơn nạp {$pkg_n}", $admin_name]);
                        }
                    }
                    $assigned_label = 'UNLIMITED-STUDIO';
                } else {
                    $tokens_to_add = 1000;
                    if (preg_match('/(\d+[\.,]?\d*)\s*Token/ui', $pkg_n, $m)) {
                        $raw_num = str_replace(['.', ','], '', $m[1]);
                        if (intval($raw_num) > 0) {
                            $tokens_to_add = intval($raw_num);
                        }
                    } elseif (stripos($pkg_n, '10.000') !== false || stripos($pkg_n, '700.000') !== false) {
                        $tokens_to_add = 10000;
                    } elseif (stripos($pkg_n, '3.000') !== false || stripos($pkg_n, '250.000') !== false) {
                        $tokens_to_add = 3000;
                    } elseif (stripos($pkg_n, '1.000') !== false || stripos($pkg_n, '100.000') !== false) {
                        $tokens_to_add = 1000;
                    }
                    db_adjust_user_wallet($u, $tokens_to_add, "Admin {$admin_name} duyệt đơn nạp {$ord_id}: {$pkg_n} (+{$tokens_to_add} LƯỢT)", $admin_name);
                    $assigned_label = "+" . number_format($tokens_to_add) . " LƯỢT";
                }
                db_approve_order($ord_id, $assigned_label);
                adm_redirect('success', "🎉 Đã duyệt nạp Token cho đơn <b>{$ord_id}</b> (User: <b>{$u}</b>)!", 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $assigned_label,
                    'issued_key'     => $assigned_label,
                    'user'           => $u,
                    'is_token'       => true
                ]);
            } elseif ($is_cloud_storage) {
                $gb_to_add = 20;
                if (preg_match('/(\d+)\s*GB/i', $pkg_n, $mGB)) {
                    $gb_to_add = (int)$mGB[1];
                }
                $user_rec = db_get_user($u);
                $uid = $user_rec['id'] ?? null;
                $spId = $uid ? db_ensure_user_personal_space($uid) : null;
                if ($spId) {
                    db_add_cloud_space_quota($spId, $gb_to_add, 90, "Admin duyệt đơn {$ord_id}: {$pkg_n}");
                }
                $assigned_label = "+{$gb_to_add} GB CLOUD (3 Tháng)";
                db_approve_order($ord_id, $assigned_label);
                adm_redirect('success', "🎉 Đã duyệt đơn Cloud <b>{$ord_id}</b> (+{$gb_to_add} GB) cho <b>{$u}</b>!", 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $assigned_label,
                    'issued_key'     => $assigned_label,
                    'user'           => $u
                ]);
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
                    $tName = "Team của " . ($user_rec['fullname'] ?: $u);
                    $teamId = db_create_team($tName, $uid, 2, 90, 20);
                    $assigned_label = "TEAM-CLOUD-2SLOTS";
                }
                db_approve_order($ord_id, $assigned_label);
                adm_redirect('success', "🎉 Đã duyệt đơn Team Cloud <b>{$ord_id}</b> cho <b>{$u}</b>!", 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $assigned_label,
                    'issued_key'     => $assigned_label,
                    'user'           => $u
                ]);
            } elseif ($is_capcut) {
                $new_key = generate_capcut_v2_key();
                $prod_tag = '2toolne.capcut.v2';
                $tier_tag = $tier;
                $days_val = $days;
                db_create_capcut_v2_license($new_key, $tier_tag, $days_val, $u, "Đơn hàng {$ord_id}: {$ord_item['package_name']} ({$ord_item['package_price']})");
                db_approve_order($ord_id, $new_key);
                $last4 = substr($new_key, -4);
                $masked_display = "2TL-CAP-****-****-{$last4}";
                $msg = "🎉 <b>Đã duyệt đơn {$ord_id} và cấp Key CapCut V2 thành công!</b><br>"
                     . "Gán cho người dùng: <b>{$u}</b><br>"
                     . "⚠️ <b style='color:#ef4444'>KEY KÍCH HOẠT ĐẦY ĐỦ (Chỉ hiển thị DUY NHẤT 1 LẦN NÀY để bàn giao cho khách):</b><br>"
                     . "<div style='margin:10px 0;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;text-align:center'>"
                     . "<code style='font-size:18px;font-weight:700;color:#10b981;letter-spacing:1px;user-select:all'>{$new_key}</code>"
                     . "</div>"
                     . "Bảng danh sách chỉ lưu cryptographic hash và hiển thị <code>{$masked_display}</code> để bảo mật an toàn.";

                adm_redirect('success', $msg, 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $masked_display,
                    'issued_key'     => $new_key,
                    'user'           => $u,
                    'is_token'       => false,
                    'is_capcut'      => true
                ]);
            } elseif ($is_2toolne) {
                $prod_tag = '2TOOLNE';
                $tier_tag = $tier;
                $days_val = $days;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TOOLNE-{$tier}-{$rnd}";
                db_create_license($new_key, $prod_tag, $tier_tag, $days_val, $u, "Đơn hàng {$ord_id}: {$ord_item['package_name']} ({$ord_item['package_price']})");
                db_approve_order($ord_id, $new_key);

                adm_redirect('success', "🎉 Đã duyệt đơn <b>{$ord_id}</b> và cấp Key <b>{$new_key}</b> cho <b>{$u}</b>!", 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $new_key,
                    'issued_key'     => $new_key,
                    'user'           => $u,
                    'is_token'       => false
                ]);
            } elseif ($is_ext_order) {
                $prod_tag = 'LABS_EXTENSION';
                $tier_tag = 'LIFETIME';
                $days_val = 36500;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TAMNE-LABS-{$rnd}";
                db_create_license($new_key, $prod_tag, $tier_tag, $days_val, $u, "Đơn hàng {$ord_id}: {$ord_item['package_name']} ({$ord_item['package_price']})");
                db_approve_order($ord_id, $new_key);

                adm_redirect('success', "🎉 Đã duyệt đơn <b>{$ord_id}</b> và cấp Key <b>{$new_key}</b> cho <b>{$u}</b>!", 'orders', [
                    'order_id'       => $ord_id,
                    'assigned_label' => $new_key,
                    'issued_key'     => $new_key,
                    'user'           => $u,
                    'is_token'       => false
                ]);
            } else {
                $prod_tag = 'SLIDESHOW';
                $tier_tag = $tier;
                $days_val = $days;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TAMNE-{$tier}-{$rnd}";
            }

            db_create_license($new_key, $prod_tag, $tier_tag, $days_val, $u, "Đơn hàng {$ord_id}: {$ord_item['package_name']} ({$ord_item['package_price']})");
            db_approve_order($ord_id, $new_key);

            adm_redirect('success', "🎉 Đã duyệt đơn <b>{$ord_id}</b> và cấp Key <b>{$new_key}</b> cho <b>{$u}</b>!", 'orders', [
                'order_id'       => $ord_id,
                'assigned_label' => $new_key,
                'issued_key'     => $new_key,
                'user'           => $u,
                'is_token'       => false
            ]);
        }
    }

    // 2. REJECT ORDER
    elseif ($act === 'reject_order') {
        if (!adm_can('orders.reject')) adm_redirect('error', '❌ Bạn không có quyền hủy đơn hàng!', 'orders');
        $ord_id = trim($_POST['order_id'] ?? '');
        db_reject_order($ord_id);
        adm_redirect('success', "Đã hủy đơn <b>{$ord_id}</b>!", 'orders', [
            'order_id' => $ord_id,
            'status'   => 'rejected'
        ]);
    }

    // 3. CREATE KEY MANUALLY
    elseif ($act === 'create_key') {
        if (!adm_can('keys.create')) adm_redirect('error', '❌ Bạn không có quyền tạo license key!', 'keys');
        $product = $_POST['product'] ?? '2TOOLNE';
        $tier = $_POST['tier'] ?? 'VIP';
        $duration = intval($_POST['duration_days'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        $owner_user = trim($_POST['owner_user'] ?? '');
        
        $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        if ($product === '2toolne.capcut.v2' || $product === 'CAPCUT_V2') {
            $new_key = generate_capcut_v2_key();
            $product = '2toolne.capcut.v2';
        } elseif ($product === '2TOOLNE') {
            $new_key = "2TOOLNE-{$tier}-{$rnd}";
        } elseif ($product === 'LABS_EXTENSION') {
            $new_key = "2TAMNE-LABS-{$rnd}";
        } else {
            $new_key = "2TAMNE-{$tier}-{$rnd}";
        }

        $create_res = db_create_license($new_key, $product, $tier, $duration, $owner_user, $note);

        if ($product === '2toolne.capcut.v2') {
            $last4 = substr($new_key, -4);
            $masked_display = "2TL-CAP-****-****-{$last4}";
            $msg = "🎉 <b>Đã tạo License Key CapCut V2 thành công!</b><br>"
                 . "⚠️ <b style='color:#ef4444'>CẢNH BÁO BẢO MẬT QUAN TRỌNG:</b> Key đầy đủ chỉ hiển thị <b>DUY NHẤT 1 LẦN NÀY</b>:<br>"
                 . "<div style='margin:10px 0;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;text-align:center'>"
                 . "<code style='font-size:18px;font-weight:700;color:#10b981;letter-spacing:1px;user-select:all'>{$new_key}</code>"
                 . "</div>"
                 . "Hệ thống chỉ lưu trữ cryptographic hash (HMAC-SHA256 lookup + Bcrypt secret hash) và KHÔNG lưu raw key trong cơ sở dữ liệu. Bảng quản trị sẽ không bao giờ hiển thị lại key đầy đủ. Vui lòng sao chép và lưu trữ ngay!";

            adm_redirect('success', $msg, 'keys', [
                'new_key'       => $new_key,
                'masked_key'    => $masked_display,
                'is_capcut'     => true,
                'product'       => $product,
                'tier'          => $tier,
                'duration_days' => $duration,
                'owner_user'    => $owner_user,
                'note'          => $note,
                'created_at'    => date('Y-m-d H:i:s'),
                'status'        => 'active'
            ]);
        } else {
            adm_redirect('success', "✅ Đã tạo Key <b>{$new_key}</b>" . (!empty($owner_user) ? " → Gán cho <b>{$owner_user}</b>" : ""), 'keys', [
                'new_key'       => $new_key,
                'masked_key'    => $new_key,
                'is_capcut'     => false,
                'product'       => $product,
                'tier'          => $tier,
                'duration_days' => $duration,
                'owner_user'    => $owner_user,
                'note'          => $note,
                'created_at'    => date('Y-m-d H:i:s'),
                'status'        => 'active'
            ]);
        }
    }

    // 4. ASSIGN USER TO KEY
    elseif ($act === 'assign_user') {
        if (!adm_can('keys.assign')) adm_redirect('error', '❌ Bạn không có quyền gán user cho key!', 'keys');
        $key = trim($_POST['key'] ?? '');
        $new_owner = trim($_POST['new_owner'] ?? '');
        db_assign_license_user($key, $new_owner);
        adm_redirect('success', "✅ Đã gán Key <b>{$key}</b> cho <b>" . ($new_owner ?: '(Không gán)') . "</b>!", 'keys', [
            'key'       => $key,
            'new_owner' => $new_owner
        ]);
    }

    // 5. RESET HWID
    elseif ($act === 'reset_hwid') {
        if (!adm_can('keys.reset_hwid')) adm_redirect('error', '❌ Bạn không có quyền reset HWID!', 'keys');
        $key = trim($_POST['key'] ?? '');
        db_reset_license_hwid($key);
        adm_redirect('success', "🔄 Đã Reset HWID cho Key <b>{$key}</b>!", 'keys');
    }

    // 6. TOGGLE BAN/UNBAN KEY
    elseif ($act === 'toggle_ban') {
        if (!adm_can('keys.ban')) adm_redirect('error', '❌ Bạn không có quyền khóa/mở khóa key!', 'keys');
        $key = trim($_POST['key'] ?? '');
        $lic_item = db_get_license($key);
        if ($lic_item) {
            $current_st = $lic_item['status'] ?? 'active';
            $new_st = ($current_st === 'banned') ? 'active' : 'banned';
            db_toggle_license_ban($key, $new_st);
            adm_redirect('success', "🔒 Đã đổi trạng thái Key <b>{$key}</b> → <b>" . strtoupper($new_st) . "</b>!", 'keys');
        }
    }

    // 7. DELETE KEY
    elseif ($act === 'delete_key') {
        if (!adm_can('keys.delete')) adm_redirect('error', '❌ Bạn không có quyền xóa license key!', 'keys');
        $key = trim($_POST['key'] ?? '');
        db_delete_license($key);
        adm_redirect('success', "🗑️ Đã xóa Key <b>{$key}</b>!", 'keys');
    }

    // 8. UPDATE FEATURE REQUEST STATUS
    elseif ($act === 'update_feature_status') {
        if (!adm_can('features.manage')) adm_redirect('error', '❌ Bạn không có quyền xử lý góp ý!', 'features');
        $fid = trim($_POST['feature_id'] ?? '');
        $new_status = trim($_POST['status'] ?? 'Đang xem xét');
        db_update_feature_status($fid, $new_status);
        adm_redirect('success', "✅ Cập nhật phiếu <b>{$fid}</b> → <b>{$new_status}</b>!", 'features');
    }

    // 9. UPDATE BUG REPORT STATUS
    elseif ($act === 'update_bug_status') {
        if (!adm_can('bugs.manage')) adm_redirect('error', '❌ Bạn không có quyền xử lý báo lỗi!', 'bugs');
        $bid = trim($_POST['bug_id'] ?? '');
        $new_status = trim($_POST['status'] ?? 'Đã tiếp nhận');
        db_update_bug_status($bid, $new_status);
        adm_redirect('success', "✅ Cập nhật báo cáo <b>{$bid}</b> → <b>{$new_status}</b>!", 'bugs');
    }

    // 10. DELETE FEATURE REQUEST
    elseif ($act === 'delete_feature') {
        if (!adm_can('features.manage')) adm_redirect('error', '❌ Bạn không có quyền xóa góp ý!', 'features');
        $fid = trim($_POST['feature_id'] ?? '');
        db_delete_feature($fid);
        adm_redirect('success', "🗑️ Đã xóa phiếu!", 'features');
    }

    // 11. DELETE BUG REPORT
    elseif ($act === 'delete_bug') {
        if (!adm_can('bugs.manage')) adm_redirect('error', '❌ Bạn không có quyền xóa báo lỗi!', 'bugs');
        $bid = trim($_POST['bug_id'] ?? '');
        db_delete_bug($bid);
        adm_redirect('success', "🗑️ Đã xóa báo cáo lỗi!", 'bugs');
    }

    // 12. ADMIN: CHANGE USER PASSWORD
    elseif ($act === 'admin_change_user_pw') {
        if (!adm_can('users.password')) adm_redirect('error', '❌ Bạn không có quyền đổi mật khẩu user!', 'users');
        $target_u = trim($_POST['target_user'] ?? '');
        $new_pw = trim($_POST['new_password'] ?? '');
        if (strlen($new_pw) < 6) {
            adm_redirect('error', "❌ Mật khẩu mới phải từ 6 ký tự!", 'users');
        } elseif (!empty($target_u)) {
            db_update_user_password($target_u, password_hash($new_pw, PASSWORD_DEFAULT));
            adm_redirect('success', "🔐 Đã đổi mật khẩu cho <b>{$target_u}</b>!", 'users');
        }
    }

    // 13. ADMIN: DELETE USER ACCOUNT
    elseif ($act === 'admin_delete_user') {
        if (!adm_can('users.delete')) adm_redirect('error', '❌ Bạn không có quyền xóa tài khoản user!', 'users');
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($target_u)) {
            db_delete_user($target_u);
            adm_redirect('success', "🗑️ Đã xóa tài khoản <b>{$target_u}</b>!", 'users', [
                'target_user' => $target_u
            ]);
        }
    }

    // 13.1 ADMIN: UPDATE USER ROLE & PERMISSIONS
    elseif ($act === 'admin_update_user_permissions') {
        if (!adm_can('users.role')) {
            adm_redirect('error', "❌ Chỉ Super Admin mới có quyền phân quyền quản trị!", 'users');
        }
        $target_u = trim($_POST['target_user'] ?? '');
        $role = trim($_POST['role'] ?? 'user');
        $raw_perms = $_POST['permissions'] ?? [];
        if (!is_array($raw_perms)) $raw_perms = [];

        if ($role === 'super_admin') {
            $perms = array_keys($ALL_PERMISSIONS);
        } elseif ($role === 'user') {
            $perms = [];
        } elseif (isset($ROLE_PRESETS[$role]) && empty($raw_perms)) {
            $perms = $ROLE_PRESETS[$role]['perms'];
        } else {
            $perms = array_values(array_intersect($raw_perms, array_keys($ALL_PERMISSIONS)));
        }

        if (!empty($target_u)) {
            db_update_user_permissions($target_u, $role, $perms);
            adm_redirect('success', "🛡️ Đã cập nhật phân quyền thành công cho <b>{$target_u}</b>!", 'users', [
                'target_user' => $target_u,
                'role'        => $role,
                'permissions' => $perms
            ]);
        }
    }

    // 13.2 ADMIN: UPDATE USER PROFILE
    elseif ($act === 'admin_update_user_profile') {
        if (!adm_can('users.edit')) adm_redirect('error', '❌ Bạn không có quyền cập nhật thông tin user!', 'users');
        $target_u = trim($_POST['target_user'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (!empty($target_u)) {
            db_update_user_profile($target_u, $fullname, $phone);
            adm_redirect('success', "✅ Đã cập nhật thông tin cho <b>{$target_u}</b>!", 'users', [
                'target_user' => $target_u,
                'fullname'    => $fullname,
                'phone'       => $phone
            ]);
        }
    }

    // 13.3 ADMIN: ADD KEY DIRECTLY TO USER
    elseif ($act === 'admin_add_user_key') {
        if (!adm_can('users.add_key')) adm_redirect('error', '❌ Bạn không có quyền cấp key cho user!', 'users');
        $target_u = trim($_POST['target_user'] ?? '');
        $product = $_POST['product'] ?? '2TOOLNE';
        $tier = $_POST['tier'] ?? 'VIP';
        $duration = intval($_POST['duration_days'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        
        if (!empty($target_u)) {
            $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $is_capcut = ($product === '2toolne.capcut.v2' || $product === 'CAPCUT_V2');
            if ($is_capcut) {
                $new_key = generate_capcut_v2_key();
                $product = '2toolne.capcut.v2';
            } elseif ($product === '2TOOLNE') {
                $new_key = "2TOOLNE-{$tier}-{$rnd}";
            } elseif ($product === 'LABS_EXTENSION') {
                $new_key = "2TAMNE-LABS-{$rnd}";
            } else {
                $new_key = "2TAMNE-{$tier}-{$rnd}";
            }
            if (empty($note)) {
                $note = "Admin cấp trực tiếp cho {$target_u}";
            }
            db_create_license($new_key, $product, $tier, $duration, $target_u, $note);
            if ($is_capcut) {
                $last4 = substr($new_key, -4);
                $masked_display = "2TL-CAP-****-****-{$last4}";
                $msg = "🎉 <b>Đã cấp Key CapCut V2 cho {$target_u} thành công!</b><br>"
                     . "⚠️ <b style='color:#ef4444'>KEY KÍCH HOẠT ĐẦY ĐỦ (Chỉ hiển thị DUY NHẤT 1 LẦN NÀY):</b><br>"
                     . "<div style='margin:10px 0;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;text-align:center'>"
                     . "<code style='font-size:18px;font-weight:700;color:#10b981;letter-spacing:1px;user-select:all'>{$new_key}</code>"
                     . "</div>"
                     . "Bảng danh sách chỉ lưu cryptographic hash và hiển thị <code>{$masked_display}</code> để bảo mật.";
                adm_redirect('success', $msg, 'users', [
                    'new_key'       => $new_key,
                    'masked_key'    => $masked_display,
                    'target_user'   => $target_u,
                    'product'       => $product,
                    'tier'          => $tier,
                    'duration_days' => $duration,
                    'note'          => $note,
                    'is_capcut'     => true
                ]);
            } else {
                adm_redirect('success', "🎉 Đã cấp Key <b>{$new_key}</b> cho <b>{$target_u}</b>!", 'users', [
                    'new_key'       => $new_key,
                    'target_user'   => $target_u,
                    'product'       => $product,
                    'tier'          => $tier,
                    'duration_days' => $duration,
                    'note'          => $note
                ]);
            }
        }
    }

    // 13.4 ADMIN: RESET HWID FROM USER VIEW
    elseif ($act === 'admin_user_reset_hwid') {
        if (!adm_can('keys.reset_hwid')) adm_redirect('error', '❌ Bạn không có quyền reset HWID!', 'users');
        $key = trim($_POST['key'] ?? '');
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($key)) {
            db_reset_license_hwid($key);
            adm_redirect('success', "🔄 Đã Reset HWID cho Key <b>{$key}</b> của <b>{$target_u}</b>!", 'users', [
                'key'         => $key,
                'target_user' => $target_u
            ]);
        }
    }

    // 13.5 ADMIN: TOGGLE BAN/UNBAN FROM USER VIEW
    elseif ($act === 'admin_user_toggle_ban') {
        if (!adm_can('keys.ban')) adm_redirect('error', '❌ Bạn không có quyền khóa/mở khóa key!', 'users');
        $key = trim($_POST['key'] ?? '');
        $target_u = trim($_POST['target_user'] ?? '');
        $lic_item = db_get_license($key);
        if ($lic_item) {
            $current_st = $lic_item['status'] ?? 'active';
            $new_st = ($current_st === 'banned') ? 'active' : 'banned';
            db_toggle_license_ban($key, $new_st);
            adm_redirect('success', "🔒 Đã đổi trạng thái Key <b>{$key}</b> → <b>" . strtoupper($new_st) . "</b>!", 'users', [
                'key'         => $key,
                'target_user' => $target_u,
                'new_status'  => $new_st
            ]);
        }
    }

    // 13.6 ADMIN: DELETE KEY FROM USER VIEW
    elseif ($act === 'admin_user_delete_key') {
        if (!adm_can('keys.delete')) adm_redirect('error', '❌ Bạn không có quyền xóa key!', 'users');
        $key = trim($_POST['key'] ?? '');
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($key)) {
            db_delete_license($key);
            adm_redirect('success', "🗑️ Đã xóa Key <b>{$key}</b> khỏi tài khoản <b>{$target_u}</b>!", 'users', [
                'key'         => $key,
                'target_user' => $target_u
            ]);
        }
    }

    // 13.7 ADMIN: ADJUST USER TOKEN WALLET
    elseif ($act === 'admin_adjust_user_tokens') {
        if (!adm_can('tokens.adjust')) adm_redirect('error', '❌ Bạn không có quyền điều chỉnh trực tiếp số dư token!', 'users');
        $target_u = trim($_POST['target_user'] ?? '');
        $delta = intval($_POST['amount'] ?? ($_POST['delta'] ?? 0));
        $reason = trim($_POST['reason'] ?? '');
        $admin_name = $_SESSION['admin_user'] ?? ($_SESSION['admin_auth_user'] ?? 'super_admin');

        $res = db_adjust_user_wallet($target_u, $delta, $reason, $admin_name);
        if ($res['success']) {
            adm_redirect('success', "✅ Đã điều chỉnh Token cho <b>{$target_u}</b>! Số dư mới: <b>" . number_format($res['new_balance']) . " Tokens</b> (" . ($delta > 0 ? "+{$delta}" : $delta) . ")", 'users', [
                'target_user' => $target_u,
                'new_balance' => $res['new_balance'],
                'delta'       => $delta,
                'reason'      => $reason,
                'created_at'  => date('Y-m-d H:i:s')
            ]);
        } else {
            adm_redirect('error', "❌ Lỗi điều chỉnh token: " . ($res['error'] ?? 'Không rõ lỗi'), 'users');
        }
    }

    // 13.7.0 ADMIN: SETTLE RESERVED TOKENS (TRANSITION LOCKED TO USED OR REFUND)
    elseif ($act === 'admin_settle_reserved_tokens') {
        if (!adm_can('tokens.adjust') && !adm_can('tokens.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền điều chỉnh số dư token!', 'tokens');
        }
        $target_u = trim($_POST['target_user'] ?? '');
        $amount = (int)($_POST['amount'] ?? 0);
        $settle_type = strtoupper(trim($_POST['settle_type'] ?? 'CONSUME'));
        $reason = trim($_POST['reason'] ?? '');
        $admin_name = $_SESSION['admin_user'] ?? ($_SESSION['admin_auth_user'] ?? 'super_admin');

        $res = db_settle_user_reserved_tokens($target_u, $amount, $settle_type, $reason, $admin_name);
        if ($res['success']) {
            $msg = ($settle_type === 'CONSUME')
                ? "✅ Đã chốt thành công <b>" . number_format($amount) . " Token</b> tạm khóa của <b>{$target_u}</b> sang trạng thái ĐÃ SỬ DỤNG!"
                : "✅ Đã hoàn trả thành công <b>" . number_format($amount) . " Token</b> tạm khóa của <b>{$target_u}</b> về Số dư khả dụng!";
            adm_redirect('success', $msg, 'tokens', [
                'target_user'  => $target_u,
                'new_reserved' => $res['new_reserved'],
                'new_balance'  => $res['new_balance'],
                'action'       => $settle_type,
                'amount'       => $amount
            ]);
        } else {
            adm_redirect('error', "❌ Lỗi xử lý token: " . ($res['error'] ?? 'Không rõ lỗi'), 'tokens');
        }
    }

    // 13.7.1 ADMIN: ADJUST USER CLOUD STORAGE QUOTA
    elseif ($act === 'admin_adjust_user_cloud_quota') {
        if (!adm_can('cloud.quota_adjust') && !adm_can('users.cloud_manage')) {
            adm_redirect('error', '❌ Bạn không có quyền điều chỉnh hạn mức Cloud Storage!', 'users');
        }
        $target_u = trim($_POST['target_user'] ?? ($_POST['user_id'] ?? ''));
        $delta_gb = (float)($_POST['delta_gb'] ?? 0);
        $reason   = trim($_POST['reason'] ?? '');
        $admin_name = $_SESSION['admin_user'] ?? ($_SESSION['admin_auth_user'] ?? 'super_admin');

        $user_row = db_get_user($target_u);
        if (!$user_row) {
            adm_redirect('error', '❌ Không tìm thấy người dùng!', 'users');
        }
        $user_id = (string)$user_row['id'];
        $space_id = db_ensure_user_personal_space($user_id);
        if (!$space_id) {
            adm_redirect('error', '❌ Không thể khởi tạo không gian lưu trữ cho user này!', 'users');
        }

        $delta_bytes = (int)round($delta_gb * 1073741824);
        if ($delta_bytes === 0) {
            adm_redirect('error', '❌ Số dung lượng điều chỉnh (+/- GB) không được bằng 0!', 'users');
        }
        if (strlen($reason) < 5) {
            adm_redirect('error', '❌ Vui lòng nhập lý do điều chỉnh tối thiểu 5 ký tự!', 'users');
        }

        require_once __DIR__ . '/api/v1/storage/CloudQuotaManager.php';
        $qm = new CloudQuotaManager();
        $type = ($delta_bytes >= 0) ? 'ADMIN_GRANT' : 'ADMIN_REDUCTION';
        try {
            $res = $qm->adjustQuota($space_id, $delta_bytes, $type, $reason, $admin_name);
            $new_effective = (float)($res['new_effective_bytes'] ?? 0);
            $new_quota_gb = round($new_effective / 1073741824, 2);
            $delta_str = ($delta_gb > 0 ? "+{$delta_gb}" : "{$delta_gb}") . ' GB';
            adm_redirect('success', "✅ Đã điều chỉnh dung lượng Cloud cho <b>{$target_u}</b> ({$delta_str})! Hạn mức mới: <b>{$new_quota_gb} GB</b>", 'users', [
                'target_user'         => $target_u,
                'space_id'            => $space_id,
                'new_effective_bytes' => $new_effective,
                'new_quota_gb'        => $new_quota_gb,
                'delta_gb'            => $delta_gb,
                'reason'              => $reason,
                'created_at'          => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            adm_redirect('error', '❌ Lỗi điều chỉnh dung lượng: ' . $e->getMessage(), 'users');
        }
    }

    // 13.7.2 ADMIN: CREATE TEAM
    elseif ($act === 'admin_create_team') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền tạo đội nhóm!', 'teams');
        }
        $name = trim($_POST['team_name'] ?? '');
        $owner = trim($_POST['owner_user_id'] ?? '');
        $slots = max(2, (int)($_POST['member_slots'] ?? 2));
        $days = max(1, (int)($_POST['duration_days'] ?? 90));
        $quota_gb = max(5, (int)($_POST['quota_gb'] ?? 20));

        if (empty($name) || empty($owner)) {
            adm_redirect('error', '❌ Tên đội nhóm và Trưởng nhóm không được để trống!', 'teams');
        }

        $u_row = db_get_user($owner);
        $owner_uid = $u_row ? $u_row['id'] : $owner;

        $t_id = db_create_team($name, $owner_uid, $slots, $days, $quota_gb);
        if ($t_id) {
            adm_redirect('success', "🎉 Đã tạo thành công Team <b>" . htmlspecialchars($name) . "</b> ({$slots} slots, {$quota_gb}GB)! Trưởng nhóm: <b>" . htmlspecialchars($owner) . "</b>", 'teams', ['team_id' => $t_id, 'team_name' => $name]);
        } else {
            adm_redirect('error', '❌ Lỗi khởi tạo Team, vui lòng thử lại!', 'teams');
        }
    }

    // 13.7.3 ADMIN: ADD TEAM MEMBERS (BATCH)
    elseif ($act === 'admin_add_team_members') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền gán thành viên vào Team!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $user_ids = $_POST['selected_users'] ?? ($_POST['user_ids'] ?? []);
        $role = in_array($_POST['role'] ?? '', ['OWNER', 'ADMIN', 'MEMBER']) ? $_POST['role'] : 'MEMBER';

        if (empty($team_id) || empty($user_ids)) {
            adm_redirect('error', '❌ Vui lòng chọn ít nhất 1 người dùng để thêm vào Team!', 'teams');
        }

        if (!is_array($user_ids)) $user_ids = [$user_ids];
        $success_count = 0;
        $errors = [];

        foreach ($user_ids as $uid) {
            $u_row = db_get_user($uid);
            $target_uid = $u_row ? $u_row['id'] : $uid;
            $res = db_add_team_member($team_id, $target_uid, $role, 'ACTIVE');
            if (!empty($res['success'])) {
                $success_count++;
            } else {
                $errors[] = $res['error'] ?? 'Lỗi không xác định';
            }
        }

        if ($success_count > 0) {
            $msg = "✅ Đã gán thành công <b>{$success_count}</b> thành viên vào Team!";
            if (!empty($errors)) $msg .= " (" . implode(', ', array_unique($errors)) . ")";
            adm_redirect('success', $msg, 'teams', ['team_id' => $team_id, 'count' => $success_count]);
        } else {
            adm_redirect('error', '❌ Thất bại: ' . implode(', ', array_unique($errors)), 'teams', ['team_id' => $team_id]);
        }
    }

    // 13.7.4 ADMIN: REMOVE TEAM MEMBER
    elseif ($act === 'admin_remove_team_member') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền xóa thành viên!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $user_id = trim($_POST['user_id'] ?? '');
        $res = db_remove_team_member($team_id, $user_id);
        if (!empty($res['success'])) {
            adm_redirect('success', '✅ Đã xóa thành viên khỏi Team!', 'teams', ['team_id' => $team_id, 'user_id' => $user_id]);
        } else {
            adm_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi xóa thành viên'), 'teams', ['team_id' => $team_id, 'user_id' => $user_id]);
        }
    }

    // 13.7.4.2 ADMIN: UPDATE TEAM MEMBER ROLE (PHÂN QUYỀN VAI TRÒ TEAM)
    elseif ($act === 'admin_update_team_member_role') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền phân quyền thành viên Team!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $user_id = trim($_POST['user_id'] ?? '');
        $new_role = strtoupper(trim($_POST['role'] ?? 'MEMBER'));
        $res = db_update_team_member_role($team_id, $user_id, $new_role);
        if (!empty($res['success'])) {
            adm_redirect('success', "✅ {$res['message']}", 'teams', ['team_id' => $team_id, 'user_id' => $user_id, 'new_role' => $new_role]);
        } else {
            adm_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi cập nhật vai trò'), 'teams', ['team_id' => $team_id, 'user_id' => $user_id]);
        }
    }

    // 13.7.5 ADMIN: ADD TEAM SLOTS
    elseif ($act === 'admin_add_team_slots') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền thêm slot!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $extra = max(1, (int)($_POST['extra_slots'] ?? 1));
        $res = db_add_team_slots($team_id, $extra);
        if (!empty($res['success'])) {
            adm_redirect('success', "✅ Đã mở rộng thêm <b>+{$extra}</b> slots cho Team!", 'teams', ['team_id' => $team_id, 'extra_slots' => $extra]);
        } else {
            adm_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi thêm slot'), 'teams', ['team_id' => $team_id]);
        }
    }

    // 13.7.5.5 ADMIN: UPDATE TEAM STATUS (ACTIVE / SUSPENDED)
    elseif ($act === 'admin_update_team_status') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền đổi trạng thái Team!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $status = strtoupper(trim($_POST['status'] ?? 'ACTIVE'));
        if (!in_array($status, ['ACTIVE', 'SUSPENDED'])) {
            $status = 'ACTIVE';
        }

        $db = get_db();
        $db->prepare("UPDATE `teams` SET status = ? WHERE id = ?")->execute([$status, $team_id]);
        $space_status = ($status === 'ACTIVE') ? 'ACTIVE' : 'SUSPENDED';
        $db->prepare("UPDATE `cloud_spaces` SET status = ? WHERE owner_type = 'TEAM' AND owner_id = ?")->execute([$space_status, $team_id]);

        $stLabel = ($status === 'ACTIVE') ? '🟢 Đang Hoạt Động' : '🟡 Tạm Khóa';
        adm_redirect('success', "✅ Đã cập nhật trạng thái Team thành: <b>{$stLabel}</b>!", 'teams', ['team_id' => $team_id, 'new_status' => $status]);
    }

    // 13.7.6 ADMIN: DELETE TEAM PERMANENTLY
    elseif ($act === 'admin_delete_team') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền xóa Team!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $res = db_delete_team_permanently($team_id);
        adm_redirect('success', '🗑️ Đã xóa vĩnh viễn Team và dọn dẹp toàn bộ dữ liệu liên quan!', 'teams', ['team_id' => $team_id, 'permanent' => true]);
    }

    // 13.7.7 ADMIN: RENAME TEAM
    elseif ($act === 'admin_rename_team') {
        if (!adm_can('teams.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền đổi tên Team!', 'teams');
        }
        $team_id = trim($_POST['team_id'] ?? '');
        $new_name = trim($_POST['new_name'] ?? '');
        $res = db_update_team_name($team_id, $new_name);
        if ($res['success']) {
            adm_redirect('success', '🎉 Đã đổi tên Team thành: ' . htmlspecialchars($res['new_name']), 'teams', ['team_id' => $team_id, 'new_name' => $res['new_name']]);
        } else {
            adm_redirect('error', '❌ ' . ($res['error'] ?? 'Lỗi đổi tên Team'), 'teams', ['team_id' => $team_id]);
        }
    }

    // 13.8 ADMIN: SAVE SEPAY PAYMENT GATEWAY CONFIG
    elseif ($act === 'save_sepay_config') {
        if (!adm_can('gateway.manage')) {
            adm_redirect('error', '❌ Bạn không có quyền cấu hình Cổng thanh toán SePay!', 'orders');
        }
        $m_id = trim($_POST['merchant_id'] ?? '');
        $s_key = trim($_POST['secret_key'] ?? '');
        $m_env = trim($_POST['env'] ?? 'production');
        sepay_save_config([
            'merchant_id' => $m_id,
            'secret_key'  => $s_key,
            'env'         => in_array($m_env, ['production', 'sandbox']) ? $m_env : 'production',
        ]);
        adm_redirect('success', '✅ Đã lưu cấu hình Cổng thanh toán SePay thành công!', 'orders', ['saved' => true]);
    }

    // 14. SAVE VERSION CONFIG
    elseif ($act === 'save_version_config') {
        if (!adm_can('broadcast.manage')) adm_redirect('error', '❌ Bạn không có quyền cấu hình phiên bản!', 'version');
        $ver = trim($_POST['app_version'] ?? '2.0.0');
        $url = trim($_POST['download_url'] ?? '');
        $notes = trim($_POST['release_notes'] ?? '');
        $sys_config['app_version'] = $ver;
        $sys_config['download_url'] = $url;
        $sys_config['release_notes'] = $notes;
        db_save_system_config($sys_config);
        adm_redirect('success', "✅ Đã lưu cấu hình phiên bản <b>v{$ver}</b> thành công!", 'version');
    }

    // 15. SEND 1-CLICK UPDATE BROADCAST
    elseif ($act === 'send_update_broadcast') {
        if (!adm_can('broadcast.manage')) adm_redirect('error', '❌ Bạn không có quyền phát thông báo cập nhật!', 'version');
        $ver = trim($sys_config['app_version'] ?? '2.0.0');
        $url = trim($sys_config['download_url'] ?? "/downloads/2toolne_macOS_latest.zip");
        $notes = trim($sys_config['release_notes'] ?? "Đã có bản cập nhật mới v{$ver} với nhiều cải tiến và sửa lỗi. Vui lòng cập nhật ngay để có trải nghiệm tốt nhất!");
        
        $nid = 'NOTIC_VER_' . strtoupper(bin2hex(random_bytes(3)));
        $sys_config['broadcast_notice'] = [
            'id' => $nid,
            'active' => true,
            'type' => 'update',
            'title' => "🚀 ĐÃ CÓ BẢN CẬP NHẬT MỚI: v{$ver} CHÍNH THỨC!",
            'content' => $notes,
            'button_text' => "📥 Tải Bản Cài Đặt (v{$ver})",
            'button_url' => $url,
            'created_at' => date('Y-m-d H:i:s'),
            'read_count' => 0
        ];
        db_save_system_config($sys_config);
        adm_redirect('success', "📢 Đã phát thông báo cập nhật <b>v{$ver}</b> toàn hệ thống thành công!", 'version');
    }

    // 16. SAVE CUSTOM BROADCAST NOTICE
    elseif ($act === 'save_broadcast_notice') {
        if (!adm_can('broadcast.manage')) adm_redirect('error', '❌ Bạn không có quyền tạo thông báo!', 'version');
        $title = trim($_POST['notice_title'] ?? '');
        $content = trim($_POST['notice_content'] ?? '');
        $type = trim($_POST['notice_type'] ?? 'info');
        $btn_text = trim($_POST['button_text'] ?? '');
        $btn_url = trim($_POST['button_url'] ?? '');
        $is_active = isset($_POST['is_active']) && $_POST['is_active'] === '1';
        
        if (empty($title) || empty($content)) {
            adm_redirect('error', '❌ Vui lòng nhập đầy đủ tiêu đề và nội dung thông báo!', 'version');
        } else {
            $nid = 'NOTIC_' . strtoupper(bin2hex(random_bytes(3)));
            $sys_config['broadcast_notice'] = [
                'id' => $nid,
                'active' => $is_active,
                'type' => $type,
                'title' => $title,
                'content' => $content,
                'button_text' => $btn_text,
                'button_url' => $btn_url,
                'created_at' => date('Y-m-d H:i:s'),
                'read_count' => 0
            ];
            db_save_system_config($sys_config);
            adm_redirect('success', "📢 Đã lưu và " . ($is_active ? "<b>PHÁT THÔNG BÁO</b>" : "lưu tạm") . " toàn hệ thống!", 'version');
        }
    }

    // 17. TOGGLE BROADCAST ACTIVE STATUS
    elseif ($act === 'toggle_broadcast_notice') {
        if (!adm_can('broadcast.manage')) adm_redirect('error', '❌ Bạn không có quyền bật/tắt thông báo!', 'version');
        $cur_st = $sys_config['broadcast_notice']['active'] ?? false;
        $sys_config['broadcast_notice']['active'] = !$cur_st;
        db_save_system_config($sys_config);
        $new_status_str = !$cur_st ? "BẬT (ĐANG PHÁT)" : "TẮT";
        adm_redirect('success', "Đã chuyển trạng thái thông báo thành: <b>{$new_status_str}</b>", 'version');
    }
}

// Recalculate metrics
$pending_orders = array_filter($orders_db, fn($x) => ($x['status'] ?? '') === 'pending');
$pending_token_orders = array_filter($pending_orders, fn($x) => ($x['product'] ?? '') === 'TOKEN_WALLET' || stripos($x['package_name'] ?? '', 'Token') !== false || stripos($x['package_name'] ?? '', 'Unlimited') !== false);
$count_capcut  = count(array_filter($licenses_db, fn($x, $k) => ($x['product']??'') === '2toolne.capcut.v2' || ($x['product']??'') === 'CAPCUT_V2' || strpos((string)$k, '2TL-CAP-') === 0, ARRAY_FILTER_USE_BOTH));
$count_2toolne = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === '2TOOLNE' || strpos($x['license_key']??'', '2TOOLNE-') === 0));
$count_video   = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === 'SLIDESHOW' || (strpos($x['license_key']??'', '2TAMNE-') === 0 && strpos($x['license_key']??'', '2TAMNE-LABS-') !== 0)));
$count_ext     = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === 'LABS_EXTENSION' || strpos($x['license_key']??'', '2TAMNE-LABS-') === 0));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Portal — 2tamne.site Software Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="globals.css?v=<?= filemtime(__DIR__ . '/globals.css') ?>">
    <style>
        .admin-header {
            background: var(--surface-1);
            border-bottom: 1px solid var(--border);
            min-height: 56px;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .admin-metrics-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        @media (max-width: 768px) {
            .admin-user-info-text {
                display: none !important;
            }
            .admin-metrics-bar {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px;
            }
        }
        @media (max-width: 480px) {
            .admin-metrics-bar {
                grid-template-columns: 1fr !important;
            }
        }
        .metric-tile {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
        }
        .metric-title {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--muted-foreground);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }
        .metric-val {
            font-size: 22px;
            font-weight: 700;
            color: var(--foreground);
            letter-spacing: -0.02em;
        }
        .admin-tab-nav {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .admin-tab-btn {
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted-foreground);
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .admin-tab-btn:hover {
            color: var(--foreground);
        }
        .admin-tab-btn.active {
            color: var(--foreground);
            border-bottom-color: var(--emerald);
            font-weight: 600;
        }
        .admin-tab-content {
            display: none;
        }
        .admin-tab-content.active {
            display: block;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--surface-2);
            border: 1px solid var(--emerald);
            color: var(--foreground);
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
            display: none;
            align-items: center;
            gap: 8px;
            z-index: 99999;
            animation: modalFadeIn 0.2s ease;
        }
        .user-link {
            color: var(--emerald);
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .user-link:hover {
            color: #34d399;
            text-decoration: underline;
        }
        .modal-subnav {
            display: flex;
            gap: 6px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 16px;
            padding-bottom: 10px;
            overflow-x: auto;
        }
        .modal-subnav-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--muted-foreground);
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .modal-subnav-btn:hover {
            color: var(--foreground);
            border-color: var(--border-strong);
        }
        .modal-subnav-btn.active {
            background: var(--surface-2);
            color: var(--emerald);
            border-color: var(--emerald);
        }
        .user-modal-pane {
            display: none;
        }
        .user-modal-pane.active {
            display: block;
        }
        /* ═══ 2TOOLNE CLOUD ADMIN STYLES ═══ */
        .cloud-admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .cloud-stat-card {
            background: var(--surface-2) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-sm);
            padding: 16px !important;
            margin: 0 !important;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            position: relative;
            overflow: hidden;
        }
        .cloud-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
        }
        .cloud-stat-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--muted-foreground);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .cloud-stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--foreground);
            font-family: var(--font-mono);
            line-height: 1.2;
            margin: 4px 0 6px 0;
            letter-spacing: -0.02em;
        }
        .cloud-stat-sub {
            font-size: 11.5px;
            color: var(--muted-foreground);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .cloud-quota-track {
            height: 6px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            overflow: hidden;
        }
        .cloud-quota-fill {
            height: 100%;
            background: var(--emerald);
            border-radius: 999px;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>

    <?php if (!$admin_logged_in): ?>
        <!-- ═══ ADMIN LOGIN SCREEN ═══ -->
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
            <div class="card" style="max-width:420px;width:100%">
                <div class="card-header" style="justify-content:center;text-align:center;flex-direction:column;gap:6px">
                    <div style="width:36px;height:36px;border-radius:8px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:16px">2</div>
                    <div class="card-title" style="font-size:17px">2tamne.site Admin Portal</div>
                    <div class="text-subtle" style="font-size:12px">Đăng nhập tài khoản quản trị hoặc Mật khẩu Master</div>
                </div>
                <div class="card-body">
                    <?php if ($msg_error): ?>
                        <div class="alert alert-danger"><?= $msg_error ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="admin_login" value="1">
                        <div class="form-group">
                            <label class="form-label" style="font-size:12px">TÀI KHOẢN (Để trống nếu dùng Mật khẩu Master):</label>
                            <input type="text" name="username" class="form-input" placeholder="Nhập username cá nhân hoặc để trống..." autocomplete="username">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-size:12px">MẬT KHẨU:</label>
                            <input type="password" name="password" class="form-input" placeholder="Mật khẩu tài khoản hoặc Mật khẩu Master..." required autofocus autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-emerald" style="width:100%;height:40px;font-weight:700">Đăng Nhập Quản Trị</button>
                    </form>
                    <div style="margin-top:14px;padding:10px 12px;background:var(--surface-2);border-radius:var(--radius-sm);font-size:11.5px;color:var(--muted-foreground);line-height:1.5;border:1px solid var(--border)">
                        💡 <b>Gợi ý đăng nhập:</b><br>
                        • <b>Super Admin:</b> Chỉ cần nhập Mật khẩu Master tối cao (bỏ trống ô tài khoản).<br>
                        • <b>Nhân viên / Quản trị viên:</b> Nhập Tên tài khoản và Mật khẩu cá nhân đã được phân quyền.
                    </div>
                </div>
                <div class="card-footer" style="text-align:center;font-size:12px;color:var(--muted-foreground)">
                    Phiên làm việc an toàn có thời hạn 4 giờ
                </div>
            </div>
        </div>
    <?php else: 
        $cur_user = $_SESSION['admin_user'] ?? 'super_admin';
        $cur_fullname = $_SESSION['admin_fullname'] ?? 'Super Admin';
        $cur_role = $_SESSION['admin_role'] ?? 'super_admin';

        $can_orders   = adm_can('orders.view') || adm_can('gateway.manage') || adm_can('orders.approve') || adm_can('orders.reject');
        $can_keys     = adm_can('keys.view');
        $can_tokens   = adm_can('tokens.manage');
        $can_users    = adm_can('users.view');
        $can_features = adm_can('features.manage');
        $can_bugs     = adm_can('bugs.manage');
        $can_version  = adm_can('broadcast.manage');
        $can_cloud    = adm_can('cloud.view') || adm_can('cloud.manage') || adm_can('cloud.quota_adjust');
        $can_teams    = adm_can('teams.view') || adm_can('teams.manage');

        $cloud_metrics  = $can_cloud ? db_get_cloud_pool_metrics() : [];
        $cloud_accounts = $can_cloud ? db_get_storage_accounts() : [];
        $cloud_spaces   = $can_cloud ? db_get_all_cloud_spaces() : [];
        $cloud_ledger   = $can_cloud ? db_get_cloud_quota_adjustments(null, 50) : [];

        $active_tab = 'orders';
        $req_tab = $_GET['tab'] ?? '';
        $can_tab = function($t) use ($can_orders, $can_keys, $can_tokens, $can_users, $can_teams, $can_features, $can_bugs, $can_version, $can_cloud) {
            if ($t === 'orders') return $can_orders;
            if ($t === 'keys') return $can_keys;
            if ($t === 'tokens') return $can_tokens;
            if ($t === 'users') return $can_users;
            if ($t === 'teams' || $t === 'team') return $can_teams;
            if ($t === 'features') return $can_features;
            if ($t === 'bugs') return $can_bugs;
            if ($t === 'version') return $can_version;
            if ($t === 'cloud') return $can_cloud;
            return false;
        };
        if ($req_tab && $can_tab($req_tab)) {
            $active_tab = ($req_tab === 'team') ? 'teams' : $req_tab;
        } elseif (!$can_orders) {
            if ($can_keys) $active_tab = 'keys';
            elseif ($can_tokens) $active_tab = 'tokens';
            elseif ($can_users) $active_tab = 'users';
            elseif ($can_teams) $active_tab = 'teams';
            elseif ($can_features) $active_tab = 'features';
            elseif ($can_bugs) $active_tab = 'bugs';
            elseif ($can_version) $active_tab = 'version';
            elseif ($can_cloud) $active_tab = 'cloud';
        }
    ?>
        <!-- ═══ ADMIN DASHBOARD ═══ -->
        <header class="admin-header">
            <div class="container" style="display:flex;justify-content:space-between;align-items:center;width:100%">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:24px;height:24px;border-radius:6px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:12px">2</div>
                    <b style="font-size:14px;color:var(--foreground)">2tamne Admin</b>
                    
                    <div style="display:flex;align-items:center;gap:6px;padding-left:8px;border-left:1px solid var(--border)">
                        <span class="admin-user-info-text" style="font-size:12.5px;color:var(--foreground);font-weight:600">👤 <?= htmlspecialchars($cur_fullname) ?></span>
                        <?php if ($cur_role === 'super_admin' || $cur_role === 'admin'): ?>
                            <span class="badge badge-purple" style="font-size:10.5px">👑 SUPER ADMIN</span>
                        <?php elseif ($cur_role === 'sales'): ?>
                            <span class="badge badge-emerald" style="font-size:10.5px">🛒 BÁN HÀNG</span>
                        <?php elseif ($cur_role === 'tech_support'): ?>
                            <span class="badge badge-info" style="font-size:10.5px">🛠️ KỸ THUẬT</span>
                        <?php elseif ($cur_role === 'content_manager'): ?>
                            <span class="badge badge-warning" style="font-size:10.5px">📢 NỘI DUNG</span>
                        <?php else: ?>
                            <span class="badge badge-primary" style="font-size:10.5px">⚙️ TÙY CHỈNH</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:12px">
                    <?php if ($can_orders): ?>
                        <span id="header-pending-badge" class="badge badge-warning" style="animation:pulseGlow 2s infinite;<?= count($pending_orders) > 0 ? '' : 'display:none;' ?>">
                            ⏳ <span id="header-pending-count"><?= count($pending_orders) ?></span> đơn chờ duyệt
                        </span>
                    <?php endif; ?>
                    <a href="index.php" target="_blank" class="btn btn-outline btn-xs">🌐 Xem Website</a>
                    <a href="?logout=1" class="btn btn-outline btn-xs" style="color:var(--danger)">Đăng Xuất</a>
                </div>
            </div>
        </header>

        <main class="container" style="padding-top:16px;padding-bottom:48px">
            <!-- FLASH MESSAGES -->
            <?php if ($msg_success): ?>
                <div class="alert alert-success"><?= $msg_success ?></div>
            <?php endif; ?>
            <?php if ($msg_error): ?>
                <div class="alert alert-danger"><?= $msg_error ?></div>
            <?php endif; ?>

            <!-- TELEMETRY METRICS -->
            <div class="admin-metrics-bar">
                <?php if ($can_keys): ?>
                    <div class="metric-tile">
                        <div class="metric-title">Tổng License Keys</div>
                        <div class="metric-val"><?= count($licenses_db) ?></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-title">🚀 2toolne Studio</div>
                        <div class="metric-val" style="color:var(--emerald)"><?= $count_2toolne ?></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-title">🎬 Tool Video AI</div>
                        <div class="metric-val" style="color:var(--primary)"><?= $count_video ?></div>
                    </div>
                    <div class="metric-tile">
                        <div class="metric-title">🖼️ Extension Labs</div>
                        <div class="metric-val" style="color:var(--info)"><?= $count_ext ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($can_tokens): ?>
                    <?php
                    $sys_tot_tok = 0;
                    foreach ($users_db as $ud) { $sys_tot_tok += (int)($ud['token_balance'] ?? 0); }
                    ?>
                    <div class="metric-tile">
                        <div class="metric-title">🪙 Tổng Token Ví</div>
                        <div class="metric-val" style="color:#facc15"><?= number_format($sys_tot_tok) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($can_users): ?>
                    <div class="metric-tile">
                        <div class="metric-title">Tổng Người Dùng</div>
                        <div class="metric-val"><?= count($users_db) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($can_orders): ?>
                    <div class="metric-tile" id="metric-tile-pending-orders">
                        <div class="metric-title">Đơn Chờ Duyệt</div>
                        <div class="metric-val" id="metric-val-pending" style="color:<?= count($pending_orders) > 0 ? 'var(--warning)' : 'var(--muted-foreground)' ?>">
                            <?= count($pending_orders) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ADMIN TABS -->
            <nav class="admin-tab-nav">
                <?php if ($can_orders): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'orders' ? 'active' : '' ?>" id="atab-btn-orders" onclick="switchAdminTab('atab-orders', 'atab-btn-orders')">
                        <span>🛒</span> <span id="atab-text-orders">Đơn Hàng & Cổng Thanh Toán <?= count($pending_orders) > 0 ? '(' . count($pending_orders) . ')' : '' ?></span>
                    </button>
                <?php endif; ?>
                <?php if ($can_keys): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'keys' ? 'active' : '' ?>" id="atab-btn-keys" onclick="switchAdminTab('atab-keys', 'atab-btn-keys')">
                        <span>🔑</span> Quản Lý License (<?= count($licenses_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_tokens): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'tokens' ? 'active' : '' ?>" id="atab-btn-tokens" onclick="switchAdminTab('atab-tokens', 'atab-btn-tokens')">
                        <span>🪙</span> <span id="atab-text-tokens">Ví & Token <?= count($pending_token_orders) > 0 ? '(' . count($pending_token_orders) . ')' : '' ?></span>
                    </button>
                <?php endif; ?>
                <?php if ($can_users): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'users' ? 'active' : '' ?>" id="atab-btn-users" onclick="switchAdminTab('atab-users', 'atab-btn-users')">
                        <span>👥</span> Người Dùng (<?= count($users_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_teams): ?>
                    <button class="admin-tab-btn <?= ($active_tab === 'teams' || $active_tab === 'team') ? 'active' : '' ?>" id="atab-btn-teams" onclick="switchAdminTab('atab-teams', 'atab-btn-teams')">
                        <span>🏢</span> Đội Nhóm (Teams) (<?= count($teams_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_features): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'features' ? 'active' : '' ?>" id="atab-btn-features" onclick="switchAdminTab('atab-features', 'atab-btn-features')">
                        <span>💡</span> Góp Ý (<?= count($features_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_bugs): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'bugs' ? 'active' : '' ?>" id="atab-btn-bugs" onclick="switchAdminTab('atab-bugs', 'atab-btn-bugs')">
                        <span>🐞</span> Báo Lỗi (<?= count($bugs_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_version): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'version' ? 'active' : '' ?>" id="atab-btn-version" onclick="switchAdminTab('atab-version', 'atab-btn-version')">
                        <span>🚀</span> Phiên Bản & Thông Báo
                    </button>
                <?php endif; ?>
                <?php if ($can_cloud): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'cloud' ? 'active' : '' ?>" id="atab-btn-cloud" onclick="switchAdminTab('atab-cloud', 'atab-btn-cloud')">
                        <span>☁️</span> 2TOOLNE Cloud
                    </button>
                <?php endif; ?>
            </nav>

            <!-- ═══ TAB 1: ORDERS & PAYMENT GATEWAY ═══ -->
            <div id="atab-orders" class="admin-tab-content <?= $active_tab === 'orders' ? 'active' : '' ?>">

                <!-- 1. CỔNG THANH TOÁN CHÍNH SEPAY (VIETQR & IPN TỰ ĐỘNG) -->
                <?php 
                $can_manage_gateway = adm_can('gateway.manage');
                $can_view_orders    = adm_can('orders.view');
                $sepay_cfg = sepay_get_config();
                $sepay_ipn_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site') . '/sepay_ipn.php';
                $sepay_is_ready = !empty($sepay_cfg['merchant_id']) && !empty($sepay_cfg['secret_key']);

                $sepay_logs = [];
                $sepay_log_path = __DIR__ . '/storage/sepay_ipn.log';
                if (file_exists($sepay_log_path)) {
                    $raw_log_lines = @file($sepay_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($raw_log_lines) {
                        $sepay_logs = array_slice($raw_log_lines, -25);
                        $sepay_logs = array_reverse($sepay_logs);
                    }
                }
                ?>
                <?php if ($can_manage_gateway): ?>
                <div class="card" style="margin-bottom:20px;border-color:rgba(14, 165, 233, 0.45);background:var(--surface-2)">
                    <div class="card-header" style="background:rgba(14, 165, 233, 0.08);padding:14px 18px;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="card-title" style="color:#38bdf8;font-size:15px">
                                ⚡ Cổng Thanh Toán Chính SePay (VietQR & IPN Webhook Tự Động Duyệt)
                            </div>
                            <?php if ($sepay_is_ready): ?>
                                <span class="badge" style="background:rgba(16, 185, 129, 0.15);color:#10b981;border:1px solid rgba(16, 185, 129, 0.35)">🟢 Đang Hoạt Động (<?= strtoupper($sepay_cfg['env'] ?? 'PROD') ?>)</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(234, 179, 8, 0.15);color:#facc15;border:1px solid rgba(234, 179, 8, 0.35)">🟡 Chưa Điền Secret Key</span>
                            <?php endif; ?>
                        </div>
                        <a href="https://my.sepay.vn" target="_blank" class="btn btn-outline btn-xs" style="color:#38bdf8;border-color:rgba(56, 189, 248, 0.4)">
                            🔗 Mở Dashboard SePay (my.sepay.vn)
                        </a>
                    </div>
                    <div class="card-body" style="padding:18px">
                        <!-- LINK IPN WEBHOOK HIGHLIGHT -->
                        <div style="background:rgba(15, 23, 42, 0.7);border:1px solid rgba(56, 189, 248, 0.3);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:16px">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px">
                                <span style="font-size:12.5px;font-weight:700;color:#e2e8f0">🔗 ĐƯỜNG DẪN IPN WEBHOOK CẦN CẤU HÌNH TRÊN SEPAY:</span>
                                <span style="font-size:11.5px;color:var(--muted-foreground)">Phương thức: <b>POST</b> | Xác thực: <b>Secret Key</b></span>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="text" id="sepay-ipn-input" class="form-input font-mono" value="<?= htmlspecialchars($sepay_ipn_url) ?>" readonly style="font-size:13px;background:rgba(0,0,0,0.4);color:#38bdf8;font-weight:600">
                                <button type="button" class="btn btn-primary btn-sm" onclick="copyText(document.getElementById('sepay-ipn-input').value)" style="white-space:nowrap;padding:7px 14px">
                                    📋 Sao Chép Link IPN
                                </button>
                            </div>
                            <div style="font-size:11.5px;color:#94a3b8;margin-top:6px">
                                📌 <b>Hướng dẫn kết nối:</b> Đăng nhập <a href="https://my.sepay.vn" target="_blank" style="color:#38bdf8">my.sepay.vn</a> → Vào menu <b>Cổng thanh toán</b> → <b>Cấu hình</b> → tab <b>IPN</b> → Dán URL trên và chọn xác thực <b>Secret Key</b>.
                            </div>
                        </div>

                        <!-- SEPAY CONFIG FORM -->
                        <form method="POST" onsubmit="handleAjaxSaveSepay(event, this)" style="margin-bottom:16px;background:var(--surface-1);padding:14px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                            <input type="hidden" name="action" value="save_sepay_config">
                            <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:var(--foreground);display:flex;align-items:center;gap:6px">
                                <span>⚙️ Cấu Hình Thông Tin Kết Nối SePay</span>
                                <span class="badge badge-emerald" style="font-size:10px">Quyền: gateway.manage</span>
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin-bottom:12px">
                                <div>
                                    <label class="form-label" style="font-size:11.5px">MERCHANT ID (Mã đơn vị):</label>
                                    <input type="text" name="merchant_id" class="form-input font-mono" placeholder="Ví dụ: SP-LIVE-XXXXXX" value="<?= htmlspecialchars($sepay_cfg['merchant_id'] ?? '') ?>" required style="font-size:12.5px">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11.5px">SECRET KEY (Khóa bí mật IPN):</label>
                                    <input type="text" name="secret_key" class="form-input font-mono" placeholder="Ví dụ: spsk_live_..." value="<?= htmlspecialchars($sepay_cfg['secret_key'] ?? '') ?>" required style="font-size:12.5px">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:11.5px">MÔI TRƯỜNG HOẠT ĐỘNG:</label>
                                    <select name="env" class="form-input" style="font-size:12.5px">
                                        <option value="production" <?= ($sepay_cfg['env'] ?? '') === 'production' ? 'selected' : '' ?>>Production (Cổng thanh toán thật - Khuyên dùng)</option>
                                        <option value="sandbox" <?= ($sepay_cfg['env'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox (Môi trường thử nghiệm)</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display:flex;justify-content:flex-end">
                                <button type="submit" class="btn btn-emerald btn-sm">💾 Lưu Cấu Hình SePay</button>
                            </div>
                        </form>

                        <!-- RECENT IPN WEBHOOK LOGS -->
                        <div>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                <span class="sepay-logs-title" style="font-size:12px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase">
                                    📜 Nhật Ký IPN Webhook Nhận Được Gần Nhất (<?= count($sepay_logs) ?> sự kiện)
                                </span>
                                <button type="button" class="btn btn-subtle btn-xs btn-refresh-sepay-logs" onclick="refreshSepayLogs(this)">🔄 Tải lại log</button>
                            </div>
                            <div class="sepay-logs-container" style="background:#090d16;border:1px solid #1e293b;border-radius:6px;padding:10px 12px;max-height:160px;overflow-y:auto;font-family:monospace;font-size:11px;color:#94a3b8;line-height:1.5">
                                <?php if (empty($sepay_logs)): ?>
                                    <div style="color:#64748b;font-style:italic">Chưa có thông báo IPN nào được ghi nhận. Khi có giao dịch qua SePay, các sự kiện webhook tự động xử lý và kết quả sẽ hiển thị chi tiết tại đây.</div>
                                <?php else: ?>
                                    <?php foreach ($sepay_logs as $log_line): ?>
                                        <div style="margin-bottom:3px;white-space:pre-wrap;border-bottom:1px dashed #1e293b;padding-bottom:2px">
                                            <?= htmlspecialchars($log_line) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($can_view_orders): ?>
                <!-- NẾU CHỈ CÓ QUYỀN XEM ĐƠN HÀNG: CHỈ HIỂN THỊ LOGS -->
                <div class="card" style="margin-bottom:20px;border-color:var(--border);background:var(--surface-2)">
                    <div class="card-header" style="justify-content:space-between;align-items:center;padding:12px 18px;display:flex">
                        <div class="card-title sepay-logs-title" style="font-size:14px;color:var(--foreground)">
                            📜 Nhật Ký IPN Webhook Nhận Được Gần Nhất (<?= count($sepay_logs) ?> sự kiện)
                        </div>
                        <button type="button" class="btn btn-subtle btn-xs btn-refresh-sepay-logs" onclick="refreshSepayLogs(this)">🔄 Tải lại log</button>
                    </div>
                    <div class="card-body" style="padding:14px 18px">
                        <div class="sepay-logs-container" style="background:#090d16;border:1px solid #1e293b;border-radius:6px;padding:10px 12px;max-height:160px;overflow-y:auto;font-family:monospace;font-size:11px;color:#94a3b8;line-height:1.5">
                            <?php if (empty($sepay_logs)): ?>
                                <div style="color:#64748b;font-style:italic">Chưa có thông báo IPN nào được ghi nhận. Khi có giao dịch qua SePay, các sự kiện webhook tự động xử lý và kết quả sẽ hiển thị chi tiết tại đây.</div>
                            <?php else: ?>
                                <?php foreach ($sepay_logs as $log_line): ?>
                                    <div style="margin-bottom:3px;white-space:pre-wrap;border-bottom:1px dashed #1e293b;padding-bottom:2px">
                                        <?= htmlspecialchars($log_line) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 2. PHẦN MỞ RỘNG: DUYỆT ĐƠN CHUYỂN KHOẢN THỦ CÔNG (CHỈ HIỆN KHI ĐƯỢC CẤP QUYỀN DUYỆT) -->
                <?php 
                $can_approve_manual = adm_can('orders.approve') || adm_can('tokens.approve_order');
                if ($can_approve_manual): 
                    $pending_count = count($pending_orders);
                ?>
                <details class="card" id="card-pending-orders" style="margin-bottom:20px;border-color:<?= $pending_count > 0 ? 'rgba(234, 179, 8, 0.45)' : 'var(--border)' ?>" <?= $pending_count > 0 ? 'open' : '' ?>>
                    <summary style="padding:14px 16px;cursor:pointer;font-weight:700;display:flex;justify-content:space-between;align-items:center;background:<?= $pending_count > 0 ? 'rgba(234, 179, 8, 0.08)' : 'var(--surface-1)' ?>">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span style="font-size:14px;color:<?= $pending_count > 0 ? 'var(--warning)' : 'var(--foreground)' ?>">
                                ⚡ Mở Rộng: Duyệt Đơn Chuyển Khoản Thủ Công
                            </span>
                            <span class="badge badge-pending-count <?= $pending_count > 0 ? 'badge-warning' : '' ?>" style="<?= $pending_count > 0 ? 'animation:pulseGlow 2s infinite' : 'background:rgba(255,255,255,0.06);color:var(--muted-foreground)' ?>">
                                <?= $pending_count > 0 ? "⏳ {$pending_count} đơn đang chờ duyệt tay" : "0 đơn chờ duyệt" ?>
                            </span>
                            <button type="button" class="btn btn-outline btn-xs" onclick="event.stopPropagation(); triggerManualOrderPoll(this);" style="font-size:11px;padding:2px 8px;margin-left:4px;border-radius:4px" title="Kiểm tra ngay đơn mới không cần F5">
                                🔄 Kiểm tra đơn mới
                            </button>
                        </div>
                        <span style="font-size:11.5px;color:var(--muted-foreground)">Bấm để đóng/mở danh sách duyệt</span>
                    </summary>
                    <div class="card-body" style="padding:0;border-top:1px solid var(--border)">
                        <div id="pending-orders-empty-state" class="empty-state" style="padding:24px;text-align:center;color:var(--muted-foreground);font-size:13px;display:<?= empty($pending_orders) ? 'block' : 'none' ?>">
                            ✅ Hiện không có đơn chuyển khoản thủ công nào đang chờ duyệt. Toàn bộ đơn thanh toán qua Cổng SePay đều đã được xử lý tự động!
                        </div>
                        <div class="table-responsive" id="pending-orders-table-wrapper" style="display:<?= empty($pending_orders) ? 'none' : 'block' ?>">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn</th>
                                        <th>Người Mua</th>
                                        <th>Sản Phẩm / Gói</th>
                                        <th>Số Tiền</th>
                                        <th>Thời Hạn</th>
                                        <th>Thời Gian</th>
                                        <th>Thao Tác Duyệt Tay</th>
                                    </tr>
                                </thead>
                                <tbody id="pending-orders-tbody">
                                    <?php foreach ($pending_orders as $ord): 
                                        $is_tok_ord = ($ord['product'] ?? '') === 'TOKEN_WALLET' || stripos($ord['package_name'] ?? '', 'Token') !== false || stripos($ord['package_name'] ?? '', 'Unlimited') !== false;
                                        $can_appr_this = $is_tok_ord ? adm_can('tokens.approve_order') : adm_can('orders.approve');
                                    ?>
                                        <tr id="pending-order-row-<?= htmlspecialchars($ord['id']) ?>" class="pending-order-row">
                                            <td><code class="font-mono text-primary" style="font-weight:700"><?= htmlspecialchars($ord['id']) ?></code></td>
                                            <td>
                                                <b><?= htmlspecialchars($ord['user']) ?></b>
                                                <div class="text-subtle" style="font-size:11.5px"><?= htmlspecialchars($ord['fullname'] ?: '') ?> · <?= htmlspecialchars($ord['phone'] ?: '') ?></div>
                                            </td>
                                            <td>
                                                <b><?= htmlspecialchars($ord['package_name']) ?></b>
                                                <div style="font-size:11px">
                                                    <?php 
                                                    $ord_p = $ord['product'] ?? 'SLIDESHOW';
                                                    $is_p_capcut = ($ord_p === '2toolne.capcut.v2' || $ord_p === 'CAPCUT_V2' || stripos($ord['package_name'] ?? '', 'CapCut') !== false || stripos($ord['package_name'] ?? '', 'AutoEdit') !== false);
                                                    ?>
                                                    <?php if ($is_p_capcut): ?>
                                                        <span class="badge badge-purple" style="background:#7c3aed;color:#fff">🎬 CapCut V2</span>
                                                    <?php else: ?>
                                                        <span class="badge"><?= htmlspecialchars($ord_p) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><b style="color:var(--emerald)"><?= htmlspecialchars($ord['package_price']) ?></b></td>
                                            <td><?= $ord['duration_days'] ?> ngày (<?= htmlspecialchars($ord['tier'] ?? 'VIP') ?>)</td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($ord['created_at']) ?></td>
                                            <td>
                                                <div style="display:flex;gap:6px">
                                                    <?php if ($can_appr_this): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this)">
                                                            <input type="hidden" name="action" value="approve_order">
                                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                            <button type="submit" class="btn btn-emerald btn-xs">
                                                                <?= $is_tok_ord ? '🪙 Duyệt Nạp Token' : '⚡ Duyệt & Cấp Key' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (adm_can('orders.reject')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this, true)">
                                                            <input type="hidden" name="action" value="reject_order">
                                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                            <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
                <?php endif; ?>

                <!-- 3. LỊCH SỬ GIAO DỊCH TOÀN HỆ THỐNG (CHỈ HIỆN LỊCH SỬ GIAO DỊCH & NHẬT KÝ ĐƠN HÀNG) -->
                <div class="card">
                    <div class="card-header" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="card-title">📋 Lịch Sử Giao Dịch & Đơn Hàng (<span id="main-orders-total-count"><?= count($orders_db) ?></span>)</div>
                            <span class="badge badge-info" id="order-filter-status">Hiển thị tất cả</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="text" id="order-search-input" class="form-input" style="font-size:12.5px;padding:6px 12px;width:240px" placeholder="🔍 Tìm mã đơn, user, gói..." onkeyup="filterOrderTable()">
                        </div>
                    </div>

                    <!-- DATE & STATUS FILTER TOOLBAR -->
                    <div style="padding:10px 16px;background:var(--surface-2);border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                            <span style="font-size:12px;font-weight:600;color:var(--muted-foreground);display:inline-flex;align-items:center;gap:4px">
                                📅 Duyệt theo ngày:
                            </span>
                            <div style="display:flex;align-items:center;gap:6px">
                                <input type="date" id="order-date-from" class="form-input" style="font-size:12px;padding:4px 8px;width:130px" onchange="filterOrderTable()" title="Từ ngày">
                                <span style="color:var(--muted-foreground);font-size:12px">→</span>
                                <input type="date" id="order-date-to" class="form-input" style="font-size:12px;padding:4px 8px;width:130px" onchange="filterOrderTable()" title="Đến ngày">
                            </div>
                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                <button type="button" class="btn btn-outline btn-xs filter-date-preset-adm" onclick="setAdminDatePreset('today', this)">Hôm nay</button>
                                <button type="button" class="btn btn-outline btn-xs filter-date-preset-adm" onclick="setAdminDatePreset('yesterday', this)">Hôm qua</button>
                                <button type="button" class="btn btn-outline btn-xs filter-date-preset-adm" onclick="setAdminDatePreset('7days', this)">7 ngày qua</button>
                                <button type="button" class="btn btn-outline btn-xs filter-date-preset-adm" onclick="setAdminDatePreset('this_month', this)">Tháng này</button>
                                <button type="button" class="btn btn-outline btn-xs filter-date-preset-adm active" onclick="setAdminDatePreset('all', this)">Tất cả</button>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px">
                            <select id="order-status-filter" class="form-input" style="font-size:12px;padding:4px 8px;width:140px" onchange="filterOrderTable()">
                                <option value="">Tất cả trạng thái</option>
                                <option value="pending">⏳ Chờ duyệt</option>
                                <option value="approved">✅ Đã duyệt</option>
                                <option value="rejected">❌ Đã hủy</option>
                            </select>
                        </div>
                    </div>

                    <div class="card-body" style="padding:0">
                        <?php if (empty($orders_db)): ?>
                            <div class="empty-state">Chưa có giao dịch nào được ghi nhận.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Mã Đơn</th>
                                            <th>Người Mua</th>
                                            <th>Sản Phẩm / Gói</th>
                                            <th>Số Tiền</th>
                                            <th>Thời Hạn</th>
                                            <th>Thời Gian</th>
                                            <th>Trạng Thái</th>
                                            <th>Kết Quả / Key Cấp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders_db as $ord): 
                                            $st = $ord['status'] ?? 'pending';
                                            $search_text = strtolower(($ord['id'] ?? '') . ' ' . ($ord['user'] ?? '') . ' ' . ($ord['fullname'] ?? '') . ' ' . ($ord['package_name'] ?? '') . ' ' . ($ord['issued_key'] ?? '') . ' ' . ($ord['memo'] ?? ''));
                                            $dt = substr($ord['created_at'] ?? '', 0, 10);
                                        ?>
                                            <tr class="order-table-row" id="main-order-row-<?= htmlspecialchars($ord['id']) ?>" data-search="<?= htmlspecialchars($search_text) ?>" data-date="<?= $dt ?>" data-status="<?= htmlspecialchars($st) ?>">
                                                <td><code class="font-mono text-primary" style="font-weight:700"><?= htmlspecialchars($ord['id']) ?></code></td>
                                                <td>
                                                    <b><?= htmlspecialchars($ord['user']) ?></b>
                                                    <div class="text-subtle" style="font-size:11.5px"><?= htmlspecialchars($ord['fullname'] ?: '') ?> · <?= htmlspecialchars($ord['phone'] ?: '') ?></div>
                                                </td>
                                                <td>
                                                    <b><?= htmlspecialchars($ord['package_name']) ?></b>
                                                    <div style="font-size:11px"><span class="badge"><?= htmlspecialchars($ord['product'] ?? 'SLIDESHOW') ?></span></div>
                                                </td>
                                                <td><b style="color:var(--emerald)"><?= htmlspecialchars($ord['package_price']) ?></b></td>
                                                <td><?= $ord['duration_days'] ?> ngày (<?= htmlspecialchars($ord['tier'] ?? 'VIP') ?>)</td>
                                                <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($ord['created_at']) ?></td>
                                                <td>
                                                    <span class="badge order-status-badge <?= $st === 'approved' ? 'badge-active' : ($st === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                                                        <?= $st === 'approved' ? '✅ ĐÃ DUYỆT' : ($st === 'rejected' ? '❌ ĐÃ HỦY' : '⏳ CHỜ DUYỆT') ?>
                                                    </span>
                                                </td>
                                                <td class="order-result-td">
                                                    <?php if (!empty($ord['issued_key'])): ?>
                                                        <code class="font-mono" style="font-size:11px;color:var(--emerald)"><?= htmlspecialchars($ord['issued_key']) ?></code>
                                                    <?php elseif ($st === 'approved'): ?>
                                                        <span class="badge badge-active" style="font-size:10px">TỰ ĐỘNG KÍCH HOẠT</span>
                                                    <?php elseif ($st === 'pending'): ?>
                                                        <span class="text-subtle" style="font-size:11px">Chờ thanh toán / duyệt</span>
                                                    <?php else: ?>
                                                        <span class="text-subtle" style="font-size:11px">Đã đóng</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div id="admin-orders-no-match" class="empty-state" style="display:none;padding:24px;text-align:center;color:var(--muted-foreground);font-size:13px">
                                🔍 Không tìm thấy giao dịch nào phù hợp với bộ lọc ngày hoặc từ khóa.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB 2: LICENSE KEYS ═══ -->
            <div id="atab-keys" class="admin-tab-content <?= $active_tab === 'keys' ? 'active' : '' ?>">
                <?php if (adm_can('keys.create')): ?>
                    <!-- CREATE KEY FORM -->
                    <div class="card" style="margin-bottom:20px">
                        <div class="card-header">
                            <div class="card-title">➕ Tạo License Key Thủ Công & Gán User</div>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="create-key-form" onsubmit="handleAjaxCreateKey(event, this)">
                                <input type="hidden" name="action" value="create_key">
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label class="form-label">SẢN PHẨM:</label>
                                        <select name="product" class="form-select">
                                            <option value="2toolne.capcut.v2">🎬 2toolne AutoEdit for CapCut (Product V2)</option>
                                            <option value="2TOOLNE">🚀 2toolne — AI YouTube Production Studio</option>
                                            <option value="SLIDESHOW">🎬 Tool Video AI (Slideshow Builder)</option>
                                            <option value="LABS_EXTENSION">🖼️ Extension Google Labs (Tải Ảnh 2K/4K)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">GÓI BẢN QUYỀN:</label>
                                        <select name="tier" class="form-select">
                                            <option value="VIP">💎 VIP</option>
                                            <option value="LIFETIME">👑 LIFETIME (Vĩnh Viễn)</option>
                                            <option value="TRIAL">🎁 TRIAL (Dùng Thử)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">THỜI HẠN (NGÀY):</label>
                                        <select name="duration_days" class="form-select">
                                            <option value="30">30 Ngày (1 Tháng)</option>
                                            <option value="365">365 Ngày (1 Năm)</option>
                                            <option value="36500">Vĩnh Viễn (Lifetime)</option>
                                            <option value="3">3 Ngày (Dùng Thử)</option>
                                            <option value="7">7 Ngày</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">GÁN CHO USER:</label>
                                        <select name="owner_user" class="form-select">
                                            <option value="">-- (Chưa gán / Cấp ngoài) --</option>
                                            <?php foreach ($users_db as $u_id => $u_data): ?>
                                                <option value="<?= htmlspecialchars($u_id) ?>">
                                                    <?= htmlspecialchars($u_id) ?> (<?= htmlspecialchars($u_data['fullname'] ?: 'No name') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="grid-column: span 2">
                                        <label class="form-label">GHI CHÚ KHÁCH HÀNG / ZALO:</label>
                                        <input type="text" name="note" class="form-input" placeholder="Ví dụ: Khách anh Nam mua qua Zalo 09xx...">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-emerald" style="margin-top:6px">⚡ Tạo Và Gán Key Ngay</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- LICENSE LIST TABLE -->
                <div class="card">
                    <div class="card-header" style="flex-wrap:wrap;gap:10px">
                        <div class="card-title" id="keys-total-count-title">🔑 Danh Sách License Key Toàn Hệ Thống (<?= count($licenses_db) ?>)</div>
                        <input type="text" id="key-search-input" class="form-input" placeholder="🔍 Tìm kiếm key, user, ghi chú..." style="max-width:260px;height:32px" onkeyup="filterKeyTable()">
                    </div>
                    <div class="card-body" style="padding:0">
                        <!-- SUBTAB PRODUCT FILTER -->
                        <div style="display:flex;gap:6px;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;background:var(--surface-2)">
                            <button type="button" class="btn btn-primary btn-xs subtab-prod-btn active" onclick="filterKeysByProduct('ALL', this)">
                                ✨ Tất Cả (<?= count($licenses_db) ?>)
                            </button>
                            <button type="button" class="btn btn-outline btn-xs subtab-prod-btn" onclick="filterKeysByProduct('2toolne.capcut.v2', this)" style="border-color:#8b5cf6;color:#8b5cf6">
                                🎬 CapCut V2 (<?= $count_capcut ?>)
                            </button>
                            <button type="button" class="btn btn-outline btn-xs subtab-prod-btn" onclick="filterKeysByProduct('2TOOLNE', this)" style="border-color:var(--emerald);color:var(--emerald)">
                                🚀 2toolne Studio (<?= $count_2toolne ?>)
                            </button>
                            <button type="button" class="btn btn-outline btn-xs subtab-prod-btn" onclick="filterKeysByProduct('SLIDESHOW', this)" style="border-color:var(--primary);color:var(--primary)">
                                🎬 Slideshow AI (<?= $count_video ?>)
                            </button>
                            <button type="button" class="btn btn-outline btn-xs subtab-prod-btn" onclick="filterKeysByProduct('LABS_EXTENSION', this)" style="border-color:var(--info);color:var(--info)">
                                🖼️ Labs Extension (<?= $count_ext ?>)
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="data-table" id="licenses-table">
                                <thead>
                                    <tr>
                                        <th>License Key</th>
                                        <th>Sản Phẩm</th>
                                        <th>Gói</th>
                                        <th>Thời Hạn</th>
                                        <th>Tài Khoản Sở Hữu</th>
                                        <th>Trạng Thái</th>
                                        <th>Thiết Bị (HWID)</th>
                                        <th>Ghi Chú</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody id="licenses-tbody">
                                    <?php foreach (array_reverse($licenses_db, true) as $k => $lic): 
                                        $status = $lic['status'] ?? 'active';
                                        $tier = $lic['tier'] ?? 'VIP';
                                        $hwid = $lic['hwid'] ?? '';
                                        $owner = $lic['owner_user'] ?? '';
                                        $is_capcut = ($lic['product'] ?? '') === '2toolne.capcut.v2' || ($lic['product'] ?? '') === 'CAPCUT_V2' || strpos((string)$k, '2TL-CAP-') === 0;
                                        $is_2toolne = ($lic['product'] ?? '') === '2TOOLNE' || strpos((string)$k, '2TOOLNE-') === 0;
                                        $is_ext = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos((string)$k, '2TAMNE-LABS-') === 0;
                                        $prod_row_tag = $is_capcut ? '2toolne.capcut.v2' : ($is_2toolne ? '2TOOLNE' : ($is_ext ? 'LABS_EXTENSION' : 'SLIDESHOW'));

                                        $display_key = (string)$k;
                                    ?>
                                        <tr class="lic-row license-row-<?= htmlspecialchars($k) ?>" id="license-row-<?= htmlspecialchars($k) ?>" data-product="<?= $prod_row_tag ?>" data-search="<?= strtolower(htmlspecialchars($display_key . ' ' . $owner . ' ' . ($lic['note'] ?? ''))) ?>">
                                            <td>
                                                <div style="display:flex;align-items:center;gap:6px">
                                                    <code class="font-mono text-primary" style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($display_key) ?></code>
                                                    <button class="btn btn-outline btn-xs" onclick="copyText('<?= htmlspecialchars($k) ?>')" title="Sao chép">📋</button>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($is_capcut): ?>
                                                    <span class="badge badge-purple">🎬 CapCut V2</span>
                                                <?php elseif ($is_2toolne): ?>
                                                    <span class="badge badge-active">🚀 2toolne</span>
                                                <?php elseif ($is_ext): ?>
                                                    <span class="badge badge-info">🖼️ Labs Ext</span>
                                                <?php else: ?>
                                                    <span class="badge badge-purple">🎬 Slideshow</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?= $tier === 'TRIAL' ? 'badge-trial' : 'badge-active' ?>"><?= htmlspecialchars($tier) ?></span></td>
                                            <td style="font-size:12px;color:var(--muted-foreground)">
                                                <?= $lic['expires_at'] ? (strpos($lic['expires_at'], '2099') !== false ? '👑 Vĩnh viễn' : htmlspecialchars($lic['expires_at'])) : ($lic['duration_days'] . ' ngày') ?>
                                            </td>
                                            <td class="owner-cell-<?= htmlspecialchars($k) ?>">
                                                <?php if ($owner): ?>
                                                    <span class="badge badge-primary">👤 <?= htmlspecialchars($owner) ?></span>
                                                    <?php if (adm_can('keys.assign')): ?>
                                                        <button class="btn btn-outline btn-xs" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '<?= htmlspecialchars($owner) ?>')">Đổi</button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">(Chưa gán)</span>
                                                    <?php if (adm_can('keys.assign')): ?>
                                                        <button class="btn btn-outline btn-xs" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '')">➕ Gán</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="status-cell-<?= htmlspecialchars($k) ?>">
                                                <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-danger' ?>">
                                                    <?= strtoupper($status) ?>
                                                </span>
                                            </td>
                                            <td class="hwid-cell-<?= htmlspecialchars($k) ?>">
                                                <?php if ($hwid): ?>
                                                    <code style="color:var(--info);font-size:11px"><?= substr($hwid, 0, 10) ?>...</code>
                                                    <?php if (adm_can('keys.reset_hwid')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Reset HWID cho key này?">
                                                            <input type="hidden" name="action" value="reset_hwid">
                                                            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                            <button type="submit" class="btn btn-outline btn-xs" title="Reset HWID">🔄</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted" style="font-size:11.5px"><?= htmlspecialchars($lic['note'] ?? '') ?></td>
                                            <td>
                                                <div style="display:flex;gap:4px">
                                                    <?php if (adm_can('keys.ban')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                                            <input type="hidden" name="action" value="toggle_ban">
                                                            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                            <button type="submit" class="btn btn-outline btn-xs">
                                                                <?= $status === 'banned' ? '🔓 Mở' : '🔒 Khóa' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (adm_can('keys.delete')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xác nhận XÓA vĩnh viễn key này?">
                                                            <input type="hidden" name="action" value="delete_key">
                                                            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                            <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!adm_can('keys.ban') && !adm_can('keys.delete')): ?>
                                                        <span class="text-subtle" style="font-size:11px">-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: TOKENS & WALLETS ═══ -->
            <?php if ($can_tokens): ?>
            <div id="atab-tokens" class="admin-tab-content <?= $active_tab === 'tokens' ? 'active' : '' ?>">
                <!-- METRICS FOR TOKENS -->
                <?php
                $tot_tokens = 0;
                $tot_reserved = 0;
                $count_unlimited = 0;
                foreach ($users_db as $u_n => $u_d) {
                    $tot_tokens += (int)($u_d['token_balance'] ?? 0);
                    $tot_reserved += (int)($u_d['token_reserved'] ?? 0);
                }
                ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:16px">
                    <div class="card" style="margin:0;padding:14px;background:var(--surface-2);border-color:rgba(234, 179, 8, 0.4)">
                        <div style="font-size:11.5px;color:var(--muted-foreground);text-transform:uppercase;font-weight:700">Tổng Token Khả Dụng Hệ Thống</div>
                        <div style="font-size:26px;font-weight:800;color:#facc15;margin-top:2px"><?= number_format($tot_tokens) ?></div>
                        <div style="font-size:11.5px;color:var(--muted-foreground)">Tokens đang lưu hành trên toàn bộ ví</div>
                    </div>
                    <div class="card" style="margin:0;padding:14px;background:var(--surface-2);border-color:var(--border)">
                        <div style="font-size:11.5px;color:var(--muted-foreground);text-transform:uppercase;font-weight:700">Tokens Tạm Khóa (Đang Render)</div>
                        <div style="font-size:26px;font-weight:800;color:var(--info);margin-top:2px"><?= number_format($tot_reserved) ?></div>
                        <div style="font-size:11.5px;color:var(--muted-foreground)">Giữ an toàn khi khách đang chạy batch</div>
                    </div>
                    <div class="card" style="margin:0;padding:14px;background:var(--surface-2);border-color:var(--border)">
                        <div style="font-size:11.5px;color:var(--muted-foreground);text-transform:uppercase;font-weight:700">Đơn Nạp Token Chờ Duyệt</div>
                        <div id="metric-val-pending-tokens" style="font-size:26px;font-weight:800;color:<?= count($pending_token_orders) > 0 ? 'var(--warning)' : 'var(--muted-foreground)' ?>;margin-top:2px"><?= count($pending_token_orders) ?></div>
                        <div style="font-size:11.5px;color:var(--muted-foreground)">Đơn nạp tiền qua chuyển khoản</div>
                    </div>
                </div>

                <!-- PENDING TOKEN ORDERS CARD (SYNCED DYNAMICALLY) -->
                <div class="card" id="card-pending-token-orders" style="margin-bottom:16px;border-color:rgba(234, 179, 8, 0.5);display:<?= count($pending_token_orders) > 0 ? 'block' : 'none' ?>">
                    <div class="card-header" style="background:rgba(234, 179, 8, 0.08);padding:12px 16px">
                        <div class="card-title" id="card-title-pending-tokens" style="color:#facc15;font-size:14px">
                            ⏳ Đơn Nạp Token Chờ Duyệt (<?= count($pending_token_orders) ?> đơn)
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table" style="font-size:12.5px">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn</th>
                                        <th>Người Mua</th>
                                        <th>Gói Nạp</th>
                                        <th>Số Tiền</th>
                                        <th>Thời Gian</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody id="pending-token-orders-tbody">
                                    <?php foreach ($pending_token_orders as $ord): ?>
                                    <tr id="pending-token-order-row-<?= htmlspecialchars($ord['id']) ?>" class="pending-token-order-row">
                                        <td><code class="font-mono text-primary" style="font-weight:700"><?= htmlspecialchars($ord['id']) ?></code></td>
                                        <td>
                                            <b><?= htmlspecialchars($ord['user']) ?></b>
                                            <?php if (!empty($ord['phone'])): ?><br><span class="text-subtle" style="font-size:11px">📞 <?= htmlspecialchars($ord['phone']) ?></span><?php endif; ?>
                                        </td>
                                        <td><span class="badge" style="background:rgba(234, 179, 8, 0.15);color:#facc15;border:1px solid rgba(234, 179, 8, 0.35);font-weight:700"><?= htmlspecialchars($ord['package_name']) ?></span></td>
                                        <td style="color:var(--emerald);font-weight:700"><?= htmlspecialchars($ord['package_price']) ?> đ</td>
                                        <td class="text-subtle" style="font-size:11.5px"><?= htmlspecialchars($ord['created_at']) ?></td>
                                        <td>
                                            <div style="display:flex;gap:6px">
                                                <?php if (adm_can('tokens.approve_order')): ?>
                                                    <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this)">
                                                        <input type="hidden" name="action" value="approve_order">
                                                        <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                        <button type="submit" class="btn btn-emerald btn-xs">🪙 Duyệt Nạp Token</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (adm_can('orders.reject')): ?>
                                                    <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this, true)">
                                                        <input type="hidden" name="action" value="reject_order">
                                                        <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                        <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- WALLET LIST FOR ALL USERS -->
                <div class="card">
                    <div class="card-header" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="card-title">🪙 Danh Sách Ví & Số Dư Token Người Dùng (<?= count($users_db) ?>)</div>
                            <span class="badge badge-info" id="token-filter-status">Hiển thị tất cả</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;max-width:340px;width:100%">
                            <input type="text" id="token-search-input" class="form-input" style="font-size:12.5px;padding:6px 12px" placeholder="🔍 Tìm user, họ tên, số dư..." onkeyup="filterTokenTable()">
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tài Khoản (Bấm xem log)</th>
                                        <th>Họ Và Tên</th>
                                        <th>Số Dư Khả Dụng</th>
                                        <th>Tạm Khóa (Hold)</th>
                                        <th>Đã Sử Dụng</th>
                                        <th>Chế Độ</th>
                                        <th>Gói Bản Quyền</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users_db as $u_name => $u_data): 
                                        $u_bal = (int)($u_data['token_balance'] ?? 50);
                                        $u_res = (int)($u_data['token_reserved'] ?? 0);
                                        $u_consumed = db_get_user_consumed_tokens($u_data['id'] ?? $u_name);
                                        $w_info = db_get_user_wallet($u_name);
                                        $cmode = $w_info['credit_mode'] ?? 'METERED';
                                        $cplan = strtoupper($w_info['plan'] ?? 'free');
                                    ?>
                                    <tr class="token-table-row" id="token-table-row-<?= htmlspecialchars($u_name) ?>" data-search="<?= strtolower(htmlspecialchars($u_name . ' ' . ($u_data['fullname'] ?? '') . ' ' . $u_bal)) ?>">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px">
                                                <div style="width:28px;height:28px;border-radius:50%;background:rgba(234, 179, 8, 0.15);border:1px solid rgba(234, 179, 8, 0.3);display:flex;align-items:center;justify-content:center;font-size:14px">🪙</div>
                                                <a href="javascript:void(0)" onclick="openUserTokenLogsModal('<?= htmlspecialchars($u_name) ?>')" title="Bấm để xem chi tiết lịch sử biến động số dư token" style="color:var(--foreground);font-weight:700;text-decoration:underline">
                                                    <?= htmlspecialchars($u_name) ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size:12.5px"><?= htmlspecialchars($u_data['fullname'] ?: 'Chưa cập nhật') ?></span>
                                            <?php if (!empty($u_data['phone'])): ?>
                                                <br><span class="text-subtle" style="font-size:11px">📞 <?= htmlspecialchars($u_data['phone']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="user-token-balance-val" style="font-size:15px;font-weight:800;color:#facc15">
                                                🪙 <?= number_format($u_bal) ?>
                                            </span>
                                            <span class="text-subtle" style="font-size:11px"> tokens</span>
                                        </td>
                                        <td>
                                            <?php if ($u_res > 0): ?>
                                                <span class="badge badge-warning" style="font-size:11px;cursor:pointer" onclick="openSettleTokenModal('<?= htmlspecialchars($u_name) ?>', <?= $u_res ?>)" title="Bấm để chuyển sang Đã sử dụng hoặc Hoàn lại">
                                                    🔒 <?= number_format($u_res) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-subtle" style="font-size:11px">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:rgba(59,130,246,0.12);color:#60a5fa;font-weight:700;font-size:11.5px">
                                                <?= number_format($u_consumed) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($cmode === 'UNLIMITED'): ?>
                                                <span class="badge badge-purple" style="font-size:10.5px">👑 UNLIMITED</span>
                                            <?php else: ?>
                                                <span class="badge badge-outline" style="font-size:10.5px">METERED</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $cplan === 'STUDIO' ? 'badge-purple' : ($cplan === 'PRO' ? 'badge-primary' : 'badge-outline') ?>" style="font-size:10.5px">
                                                <?= $cplan ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                                <button class="btn btn-outline btn-xs" onclick="openUserTokenLogsModal('<?= htmlspecialchars($u_name) ?>')" title="Xem toàn bộ lịch sử biến động số dư">
                                                    📜 Lịch Sử Log
                                                </button>
                                                <?php if (adm_can('tokens.adjust')): ?>
                                                    <button class="btn btn-warning btn-xs" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-wallet')" title="Nạp / Trừ Token">
                                                        ⚡ Nạp / Trừ
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline btn-xs" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-keys')" title="Xem license keys">
                                                    🔑 Keys
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ TAB 3: USERS & TEAMS ═══ -->
            <?php if ($can_users): ?>
            <div id="atab-users" class="admin-tab-content <?= $active_tab === 'users' ? 'active' : '' ?>">
                <?php 
                    $users_cloud_data = db_get_users_cloud_data(); 
                    if (!isset($fmtC)) {
                        $fmtC = function($b) {
                            if ($b <= 0) return '0 B';
                            if ($b < 1024) return $b . ' B';
                            if ($b < 1048576) return round($b / 1024, 1) . ' KB';
                            if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
                            return round($b / 1073741824, 1) . ' GB';
                        };
                    }
                ?>

                <!-- SUBTAB SELECTOR (MINITABS) -->
                <div class="user-subtab-bar" style="display:flex;gap:10px;margin-bottom:18px;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:12px">
                    <button type="button" class="btn btn-sm btn-primary" id="btn-subtab-users" onclick="switchAdminTab('atab-users', 'atab-btn-users')">
                        👥 Danh Sách Tài Khoản (<?= count($users_db) ?>)
                    </button>
                    <?php if ($can_teams): ?>
                    <button type="button" class="btn btn-sm btn-outline" id="btn-subtab-teams" onclick="switchAdminTab('atab-teams', 'atab-btn-teams')">
                        🏢 Quản Lý Đội Nhóm (Teams) (<span id="teams-count-badge"><?= count($teams_db) ?></span>)
                    </button>
                    <?php endif; ?>
                </div>

                <!-- SUBTAB 1: USERS LIST -->
                <div id="subtab-users" class="user-subtab-pane">
                    <div class="card">
                        <div class="card-header" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="card-title">👥 Danh Sách Tài Khoản Người Dùng (<span id="user-count-total"><?= count($users_db) ?></span>)</div>
                            <span class="badge badge-info" id="user-filter-status">Hiển thị tất cả</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;max-width:340px;width:100%">
                            <input type="text" id="user-search-input" class="form-input" style="font-size:12.5px;padding:6px 12px" placeholder="🔍 Tìm kiếm user, họ tên, SĐT, IP..." onkeyup="filterUserTable()">
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tài Khoản</th>
                                        <th>Họ & Tên</th>
                                        <th>SĐT / Zalo</th>
                                        <th>Quyền Hạn</th>
                                        <th>Số Key</th>
                                        <th>Ví Token</th>
                                        <th>Dung Lượng Cloud</th>
                                        <th>IP Đăng Ký</th>
                                        <th>Ngày Tham Gia</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users_db as $u_name => $u_data): 
                                        $u_role = $u_data['role'] ?? 'user';
                                        $search_blob = strtolower($u_name . ' ' . ($u_data['fullname'] ?? '') . ' ' . ($u_data['phone'] ?? '') . ' ' . ($u_data['registered_ip'] ?? ''));
                                    ?>
                                        <tr class="user-table-row" id="user-row-<?= htmlspecialchars($u_name) ?>" data-search="<?= htmlspecialchars($search_blob) ?>">
                                            <td>
                                                <a href="javascript:void(0)" class="user-link" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>')" title="Bấm để quản lý chi tiết user này">
                                                    👤 <b><?= htmlspecialchars($u_name) ?></b>
                                                </a>
                                            </td>
                                            <td class="user-cell-fullname"><?= htmlspecialchars($u_data['fullname'] ?: '(Chưa có)') ?></td>
                                            <td class="user-cell-phone"><?= htmlspecialchars($u_data['phone'] ?: '(Chưa có)') ?></td>
                                            <td class="user-cell-role">
                                                <?php if ($u_role === 'super_admin' || $u_role === 'admin'): ?>
                                                    <span class="badge badge-purple" style="font-size:11px">👑 SUPER ADMIN</span>
                                                <?php elseif ($u_role === 'sales'): ?>
                                                    <span class="badge badge-emerald" style="font-size:11px">🛒 BÁN HÀNG</span>
                                                <?php elseif ($u_role === 'tech_support'): ?>
                                                    <span class="badge badge-info" style="font-size:11px">🛠️ KỸ THUẬT</span>
                                                <?php elseif ($u_role === 'content_manager'): ?>
                                                    <span class="badge badge-warning" style="font-size:11px">📢 NỘI DUNG</span>
                                                <?php elseif ($u_role === 'custom'): ?>
                                                    <span class="badge badge-primary" style="font-size:11px">⚙️ TÙY CHỈNH</span>
                                                <?php else: ?>
                                                    <span class="badge" style="font-size:11px;color:var(--muted-foreground)">👤 USER</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-active" id="user-keys-badge-<?= htmlspecialchars($u_name) ?>" style="cursor:pointer" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-keys')" title="Bấm để xem danh sách key">
                                                    🔑 <?= count($u_data['keys'] ?? []) ?> keys
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (adm_can('tokens.manage')): ?>
                                                    <span class="badge" style="background:rgba(234, 179, 8, 0.12);border:1px solid rgba(234, 179, 8, 0.35);color:#facc15;font-weight:700;font-size:11px;cursor:pointer" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-wallet')" title="Bấm để xem ví & điều chỉnh token">
                                                        🪙 <?= number_format($u_data['token_balance'] ?? 50) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">***</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $u_id_str = (string)($u_data['id'] ?? '');
                                                    $c_data = $users_cloud_data[$u_id_str] ?? null;
                                                    $c_eff  = (float)($c_data['effective_quota_bytes'] ?? 5368709120);
                                                    $c_used = (float)($c_data['used_bytes'] ?? 0);
                                                    $c_pct  = $c_eff > 0 ? min(100, round(($c_used / $c_eff) * 100)) : 0;
                                                    $fmtC = function($b) {
                                                        if ($b <= 0) return '0 B';
                                                        if ($b < 1024) return $b . ' B';
                                                        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
                                                        if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
                                                        return round($b / 1073741824, 1) . ' GB';
                                                    };
                                                    $c_pct_color = ($c_pct >= 90) ? '#ef4444' : (($c_pct >= 70) ? '#f59e0b' : '#10b981');
                                                ?>
                                                <div style="cursor:pointer;min-width:125px" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-cloud')" title="Bấm để xem chi tiết & điều chỉnh dung lượng Cloud">
                                                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-bottom:3px">
                                                        <span id="user-cloud-txt-<?= htmlspecialchars($u_name) ?>" style="font-weight:700;color:#38bdf8">☁️ <?= $fmtC($c_used) ?></span>
                                                        <span id="user-cloud-tot-<?= htmlspecialchars($u_name) ?>" class="text-subtle">/ <?= $fmtC($c_eff) ?></span>
                                                    </div>
                                                    <div style="width:100%;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,0.05)">
                                                        <div id="user-cloud-bar-<?= htmlspecialchars($u_name) ?>" style="width:<?= max(3, $c_pct) ?>%;height:100%;background:<?= $c_pct_color ?>;border-radius:999px;transition:width 0.3s ease"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><code><?= htmlspecialchars($u_data['registered_ip'] ?? 'N/A') ?></code></td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($u_data['created_at']) ?></td>
                                            <td>
                                                <div style="display:flex;gap:4px">
                                                    <button class="btn btn-emerald btn-xs" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>')" title="Quản lý chi tiết">⚙️ Quản Lý</button>
                                                    <?php if (adm_can('users.password')): ?>
                                                        <button class="btn btn-outline btn-xs" onclick="openAdminPwModal('<?= htmlspecialchars($u_name) ?>')">🔐 Đổi MK</button>
                                                    <?php endif; ?>
                                                    <?php if (adm_can('users.delete')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xóa vĩnh viễn user <?= htmlspecialchars($u_name) ?>?">
                                                            <input type="hidden" name="action" value="admin_delete_user">
                                                            <input type="hidden" name="target_user" value="<?= htmlspecialchars($u_name) ?>">
                                                            <button type="submit" class="btn btn-danger btn-xs">🗑️ Xóa</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- END SUBTAB 1 -->
            </div>
            </div>
            <?php endif; ?>

            <!-- ═══ TAB: TEAMS MANAGEMENT (STANDALONE ADMIN TAB) ═══ -->
            <?php if ($can_teams): ?>
            <div id="atab-teams" class="admin-tab-content <?= ($active_tab === 'teams' || $active_tab === 'team') ? 'active' : '' ?>">
                <!-- MINITAB NAVIGATOR -->
                <div class="user-subtab-bar" style="display:flex;gap:10px;margin-bottom:18px;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:12px">
                    <button type="button" class="btn btn-sm btn-outline" onclick="switchAdminTab('atab-users', 'atab-btn-users')">
                        👥 Danh Sách Tài Khoản (<?= count($users_db) ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-primary">
                        🏢 Quản Lý Đội Nhóm (Teams) (<span id="teams-count-badge"><?= count($teams_db) ?></span>)
                    </button>
                </div>

                <div class="card">
                    <div class="card-header" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="card-title">🏢 Danh Sách Đội Nhóm — Teams (<span id="teams-count-total"><?= count($teams_db) ?></span>)</div>
                            <span class="badge badge-info" id="teams-filter-status">Hiển thị tất cả</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <input type="text" id="team-search-input" class="form-input" style="font-size:12.5px;padding:6px 12px;width:240px" placeholder="🔍 Tìm kiếm Team, Trưởng nhóm..." onkeyup="filterTeamTable()">
                            <?php if (adm_can('teams.manage')): ?>
                            <button type="button" class="btn btn-emerald btn-sm" onclick="openCreateTeamModal()" style="display:inline-flex;align-items:center;gap:6px">
                                <span>➕</span> Tạo Team Mới
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tên Đội Nhóm</th>
                                        <th>Trưởng Nhóm (Owner)</th>
                                        <th>Thành Viên / Slot</th>
                                        <th>Không Gian Cloud</th>
                                        <th>Trạng Thái</th>
                                        <th>Thời Hạn (3 Tháng)</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody id="teams-table-body">
                                    <?php if (empty($teams_db)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align:center;padding:32px;color:var(--muted-foreground)">
                                                Chưa có Đội Nhóm nào được tạo. Hãy bấm <b>"Tạo Team Mới"</b> để khởi tạo nhóm đầu tiên!
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($teams_db as $tm): 
                                            $t_id = $tm['id'];
                                            $t_name = $tm['name'];
                                            $t_owner = $tm['owner_username'] ?: $tm['owner_user_id'];
                                            $t_owner_full = $tm['owner_fullname'] ?: $t_owner;
                                            $t_slots = (int)$tm['member_slots'];
                                            $t_active = (int)($tm['active_members_count'] ?? 1);
                                            $t_invited = (int)($tm['invited_members_count'] ?? 0);
                                            $t_slot_pct = min(100, round(($t_active / max(1, $t_slots)) * 100));
                                            $t_eff_bytes = (float)($tm['effective_quota_bytes'] ?? 0);
                                            $t_used_bytes = (float)($tm['used_bytes'] ?? 0);
                                            $t_cloud_pct = $t_eff_bytes > 0 ? min(100, round(($t_used_bytes / $t_eff_bytes) * 100)) : 0;
                                            $search_blob = strtolower($t_name . ' ' . $t_owner . ' ' . $t_owner_full . ' ' . $t_id);
                                        ?>
                                            <tr class="team-table-row" id="team-row-<?= htmlspecialchars($t_id) ?>" data-search="<?= htmlspecialchars($search_blob) ?>">
                                                <td>
                                                    <div style="font-weight:700;font-size:13.5px;color:var(--foreground);display:flex;align-items:center;gap:6px">
                                                        <span>🏢</span> <span id="team-name-text-<?= htmlspecialchars($t_id) ?>"><?= htmlspecialchars($t_name) ?></span>
                                                        <?php if (adm_can('teams.manage')): ?>
                                                        <button type="button" class="btn btn-outline btn-xs" style="padding:1px 5px;font-size:11px;border-radius:4px" onclick="promptRenameTeam('<?= htmlspecialchars($t_id) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>')" title="Đổi tên team">✏️</button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size:11px;color:var(--muted-foreground)">ID: <code><?= htmlspecialchars($t_id) ?></code></div>
                                                </td>
                                                <td>
                                                    <div style="font-weight:600;color:var(--emerald);display:flex;align-items:center;gap:4px">
                                                        <span>👑</span> <?= htmlspecialchars($t_owner_full) ?>
                                                    </div>
                                                    <div style="font-size:11px;color:var(--muted-foreground)">@<?= htmlspecialchars($t_owner) ?></div>
                                                </td>
                                                <td>
                                                    <div style="min-width:130px">
                                                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;margin-bottom:3px">
                                                            <span style="font-weight:700;color:<?= $t_active >= $t_slots ? '#ef4444' : '#10b981' ?>" id="team-slot-badge-<?= htmlspecialchars($t_id) ?>">
                                                                👥 <?= $t_active ?> / <?= $t_slots ?> slots
                                                            </span>
                                                            <?php if ($t_invited > 0): ?>
                                                                <span class="badge badge-warning" style="font-size:10px">+<?= $t_invited ?> mời</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="width:100%;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,0.05)">
                                                            <div style="width:<?= max(5, $t_slot_pct) ?>%;height:100%;background:<?= $t_active >= $t_slots ? '#ef4444' : '#10b981' ?>;border-radius:999px"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="min-width:130px">
                                                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-bottom:3px">
                                                            <span style="font-weight:700;color:#38bdf8" id="team-cloud-used-<?= htmlspecialchars($t_id) ?>">☁️ <?= $fmtC($t_used_bytes) ?></span>
                                                            <span class="text-subtle" id="team-cloud-eff-<?= htmlspecialchars($t_id) ?>">/ <?= $fmtC($t_eff_bytes) ?></span>
                                                        </div>
                                                        <div style="width:100%;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,0.05)">
                                                            <div id="team-cloud-bar-<?= htmlspecialchars($t_id) ?>" style="width:<?= max(3, $t_cloud_pct) ?>%;height:100%;background:#38bdf8;border-radius:999px"></div>
                                                        </div>
                                                        <?php if (adm_can('cloud.quota_adjust') && !empty($tm['cloud_space_id'])): ?>
                                                        <button type="button" class="btn btn-outline btn-xs" style="margin-top:5px;padding:1px 6px;font-size:10.5px;width:100%;display:flex;align-items:center;justify-content:center;gap:4px" onclick="openAdjustQuotaForSpace('<?= htmlspecialchars($tm['cloud_space_id']) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>')" title="Điều chỉnh dung lượng Không gian Cloud cho team này">
                                                            <span>⚖️</span> Chỉnh Quota
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (adm_can('teams.manage')): ?>
                                                    <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                                        <input type="hidden" name="action" value="admin_update_team_status">
                                                        <input type="hidden" name="team_id" value="<?= htmlspecialchars($t_id) ?>">
                                                        <select name="status" class="form-select team-status-select" style="font-size:11.5px;padding:3px 8px;border-radius:6px;min-width:130px;background:<?= ($tm['status'] ?? '') === 'ACTIVE' ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)' ?>;border-color:<?= ($tm['status'] ?? '') === 'ACTIVE' ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)' ?>;color:<?= ($tm['status'] ?? '') === 'ACTIVE' ? 'var(--emerald)' : 'var(--warning)' ?>;font-weight:600" onchange="handleUniversalAdminAjaxForm(null, this.form)">
                                                            <option value="ACTIVE" <?= ($tm['status'] ?? '') === 'ACTIVE' ? 'selected' : '' ?>>🟢 Đang Hoạt Động</option>
                                                            <option value="SUSPENDED" <?= ($tm['status'] ?? '') !== 'ACTIVE' ? 'selected' : '' ?>>🟡 Tạm Khóa</option>
                                                        </select>
                                                    </form>
                                                    <?php else: ?>
                                                        <?php if (($tm['status'] ?? '') === 'ACTIVE'): ?>
                                                            <span class="badge badge-emerald" style="font-size:11px">🟢 Đang Hoạt Động</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning" style="font-size:11px">🟡 Tạm Khóa</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-size:11.5px;color:var(--foreground)">
                                                        <?= !empty($tm['expires_at']) ? date('d/m/Y', strtotime($tm['expires_at'])) : '(Vô thời hạn)' ?>
                                                    </div>
                                                    <div style="font-size:10.5px;color:var(--muted-foreground)">
                                                        Tạo: <?= date('d/m/Y', strtotime($tm['created_at'])) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                                                        <button type="button" class="btn btn-primary btn-xs" onclick="openManageTeamMembersModal('<?= htmlspecialchars($t_id) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>', <?= $t_slots ?>)">
                                                            👥 Quản Lý
                                                        </button>
                                                        <?php if (adm_can('teams.manage')): ?>
                                                        <button type="button" class="btn btn-emerald btn-xs" onclick="openAddTeamUsersPopup('<?= htmlspecialchars($t_id) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>')">
                                                            ➕ Gán User
                                                        </button>
                                                        <button type="button" class="btn btn-outline btn-xs" onclick="promptAddTeamSlots('<?= htmlspecialchars($t_id) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>')">
                                                            ➕ Slot
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-xs" onclick="confirmDeleteTeamPermanently('<?= htmlspecialchars($t_id) ?>', '<?= htmlspecialchars(addslashes($t_name)) ?>')" title="Xóa Vĩnh Viễn Team">
                                                            🗑️ Xóa
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ TAB 4: FEATURE REQUESTS ═══ -->
            <div id="atab-features" class="admin-tab-content <?= $active_tab === 'features' ? 'active' : '' ?>">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">💡 Danh Sách Góp Ý Nâng Cấp Tính Năng (<?= count($features_db) ?>)</div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Người Gửi</th>
                                        <th>Tiêu Đề</th>
                                        <th>Nội Dung Chi Tiết</th>
                                        <th>Trạng Thái</th>
                                        <th>Ngày Gửi</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($features_db as $f): ?>
                                        <tr id="feature-row-<?= htmlspecialchars($f['id']) ?>">
                                            <td><code><?= htmlspecialchars($f['id']) ?></code></td>
                                            <td><b><?= htmlspecialchars($f['user']) ?></b></td>
                                            <td><b><?= htmlspecialchars($f['title']) ?></b></td>
                                            <td class="text-muted" style="max-width:320px"><?= htmlspecialchars($f['description']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                                    <input type="hidden" name="action" value="update_feature_status">
                                                    <input type="hidden" name="feature_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                    <select name="status" class="form-select" style="font-size:11.5px;padding:4px 8px" onchange="handleUniversalAdminAjaxForm(null, this.form)">
                                                        <option value="Đang xem xét" <?= ($f['status']??'') === 'Đang xem xét' ? 'selected' : '' ?>>Đang xem xét</option>
                                                        <option value="Đang phát triển" <?= ($f['status']??'') === 'Đang phát triển' ? 'selected' : '' ?>>Đang phát triển</option>
                                                        <option value="Đã hoàn thành" <?= ($f['status']??'') === 'Đã hoàn thành' ? 'selected' : '' ?>>Đã hoàn thành</option>
                                                        <option value="Từ chối" <?= ($f['status']??'') === 'Từ chối' ? 'selected' : '' ?>>Từ chối</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($f['created_at']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xóa phiếu này?">
                                                    <input type="hidden" name="action" value="delete_feature">
                                                    <input type="hidden" name="feature_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                    <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB 5: BUG REPORTS ═══ -->
            <div id="atab-bugs" class="admin-tab-content <?= $active_tab === 'bugs' ? 'active' : '' ?>">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🐞 Danh Sách Báo Cáo Lỗi Kỹ Thuật (<?= count($bugs_db) ?>)</div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Người Báo</th>
                                        <th>Tiêu Đề</th>
                                        <th>Error Code</th>
                                        <th>Nội Dung Chi Tiết</th>
                                        <th>Trạng Thái</th>
                                        <th>Ngày Báo</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bugs_db as $b): ?>
                                        <tr id="bug-row-<?= htmlspecialchars($b['id']) ?>">
                                            <td><code><?= htmlspecialchars($b['id']) ?></code></td>
                                            <td><b><?= htmlspecialchars($b['user']) ?></b></td>
                                            <td><b><?= htmlspecialchars($b['title']) ?></b></td>
                                            <td><code style="color:var(--danger)"><?= htmlspecialchars($b['error_code'] ?: 'N/A') ?></code></td>
                                            <td class="text-muted" style="max-width:320px"><?= htmlspecialchars($b['description']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                                    <input type="hidden" name="action" value="update_bug_status">
                                                    <input type="hidden" name="bug_id" value="<?= htmlspecialchars($b['id']) ?>">
                                                    <select name="status" class="form-select" style="font-size:11.5px;padding:4px 8px" onchange="handleUniversalAdminAjaxForm(null, this.form)">
                                                        <option value="Đã tiếp nhận" <?= ($b['status']??'') === 'Đã tiếp nhận' ? 'selected' : '' ?>>Đã tiếp nhận</option>
                                                        <option value="Đang xử lý" <?= ($b['status']??'') === 'Đang xử lý' ? 'selected' : '' ?>>Đang xử lý</option>
                                                        <option value="Đã sửa xong" <?= ($b['status']??'') === 'Đã sửa xong' ? 'selected' : '' ?>>Đã sửa xong</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($b['created_at']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xóa báo cáo này?">
                                                    <input type="hidden" name="action" value="delete_bug">
                                                    <input type="hidden" name="bug_id" value="<?= htmlspecialchars($b['id']) ?>">
                                                    <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB 6: VERSION & BROADCASTS ═══ -->
            <div id="atab-version" class="admin-tab-content <?= $active_tab === 'version' ? 'active' : '' ?>">
                <div class="card" style="margin-bottom:20px">
                    <div class="card-header">
                        <div class="card-title">🚀 Cấu Hình Phiên Bản & 1-Click Update Broadcast</div>
                    </div>
                    <div class="card-body">
                        <form method="POST" style="margin-bottom:16px" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                            <input type="hidden" name="action" value="save_version_config">
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label">PHIÊN BẢN HIỆN TẠI:</label>
                                    <input type="text" name="app_version" class="form-input" value="<?= htmlspecialchars($sys_config['app_version'] ?? '1.0.0') ?>" required>
                                </div>
                                <div class="form-group" style="grid-column: span 2">
                                    <label class="form-label">ĐƯỜNG DẪN TẢI XUỐNG (DOWNLOAD URL):</label>
                                    <input type="text" name="download_url" class="form-input" value="<?= htmlspecialchars($sys_config['download_url'] ?? '/downloads/2toolne_macOS_latest.zip') ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 3">
                                    <label class="form-label">GHI CHÚ PHÁT HÀNH (RELEASE NOTES):</label>
                                    <textarea name="release_notes" class="form-textarea" rows="3"><?= htmlspecialchars($sys_config['release_notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Lưu Cấu Hình Phiên Bản</button>
                        </form>

                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Phát thông báo cập nhật mới cho TOÀN BỘ người dùng truy cập web?">
                            <input type="hidden" name="action" value="send_update_broadcast">
                            <button type="submit" class="btn btn-emerald">📢 Phát Thông Báo Cập Nhật Mới (1-Click Broadcast)</button>
                        </form>
                    </div>
                </div>

                <!-- CUSTOM BROADCAST COMPOSER -->
                <div class="card">
                    <div class="card-header" style="justify-content:space-between">
                        <div class="card-title">📢 Soạn Thông Báo Toàn Hệ Thống Tùy Chỉnh</div>
                        <?php if (!empty($sys_config['broadcast_notice'])): ?>
                            <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                <input type="hidden" name="action" value="toggle_broadcast_notice">
                                <button type="submit" class="btn btn-outline btn-xs">
                                    <?= !empty($sys_config['broadcast_notice']['active']) ? '🔴 Tắt Thông Báo' : '🟢 Bật Thông Báo' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                            <input type="hidden" name="action" value="save_broadcast_notice">
                            <div class="form-grid-3">
                                <div class="form-group" style="grid-column: span 2">
                                    <label class="form-label">TIÊU ĐỀ THÔNG BÁO:</label>
                                    <input type="text" name="notice_title" class="form-input" placeholder="Ví dụ: 🛠️ Bảo trì máy chủ..." required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">LOẠI THÔNG BÁO:</label>
                                    <select name="notice_type" class="form-select">
                                        <option value="info">📢 Thông tin (Info)</option>
                                        <option value="update">🚀 Cập nhật (Update)</option>
                                        <option value="alert">⚠️ Khẩn cấp (Alert)</option>
                                        <option value="maintenance">🛠️ Bảo trì (Maintenance)</option>
                                        <option value="promo">🎁 Khuyến mại (Promo)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="grid-column: span 3">
                                    <label class="form-label">NỘI DUNG THÔNG BÁO:</label>
                                    <textarea name="notice_content" class="form-textarea" rows="4" placeholder="Nhập nội dung chi tiết..." required></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">CHỮ NÚT BẤM (TÙY CHỌN):</label>
                                    <input type="text" name="button_text" class="form-input" placeholder="Ví dụ: Tải Ngay">
                                </div>
                                <div class="form-group" style="grid-column: span 2">
                                    <label class="form-label">LINK NÚT BẤM (TÙY CHỌN):</label>
                                    <input type="text" name="button_url" class="form-input" placeholder="https://...">
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                                <input type="checkbox" name="is_active" id="is_active_cb" value="1" checked>
                                <label for="is_active_cb" style="font-size:13px;cursor:pointer">Kích hoạt và phát ngay lập tức trên website</label>
                            </div>
                            <button type="submit" class="btn btn-primary">Lưu & Phát Thông Báo</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB 8: 2TOOLNE CLOUD STORAGE POOL & QUOTA LEDGER ═══ -->
            <?php if ($can_cloud): ?>
            <div id="atab-cloud" class="admin-tab-content <?= $active_tab === 'cloud' ? 'active' : '' ?>">
                <!-- SECTION 1: HEADER & ACTIONS -->
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--border)">
                    <div>
                        <h2 style="font-size:18px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px;color:var(--foreground)">
                            <span style="font-size:22px">☁️</span> Quản Trị Cụm Lưu Trữ 2TOOLNE Cloud
                        </h2>
                        <p class="text-muted" style="margin:4px 0 0;font-size:13px;line-height:1.4">
                            Giám sát cụm tài khoản Google Drive vật lý, hạn mức người dùng, và điều chỉnh dung lượng.
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modal-admin-add-storage-account')" style="display:inline-flex;align-items:center;gap:6px;font-weight:600">
                            <span>+</span> Thêm Tài Khoản Drive
                        </button>
                        <button type="button" class="btn btn-emerald btn-sm" onclick="openModal('modal-admin-adjust-quota')" style="display:inline-flex;align-items:center;gap:6px;font-weight:600">
                            <span>⚖️</span> Điều Chỉnh Hạn Mức
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="reloadCloudAdminMetrics()" style="display:inline-flex;align-items:center;gap:6px;font-weight:600">
                            <span>🔄</span> Làm Mới
                        </button>
                    </div>
                </div>

                <!-- SECTION 2: 9 METRIC CARDS (STORAGE POOL ENGINE) -->
                <?php
                    $m = $cloud_metrics;
                    $fmtB = function($bytes) {
                        if ($bytes <= 0) return '0 GB';
                        $gb = $bytes / (1024 * 1024 * 1024);
                        if ($gb >= 1024) return round($gb / 1024, 2) . ' TB';
                        return round($gb, 1) . ' GB';
                    };
                    $riskLevel = $m['RISK_LEVEL'] ?? 'SAFE';
                    $riskClass = $riskLevel === 'CRITICAL' ? 'badge-danger' : ($riskLevel === 'WARNING' ? 'badge-warning' : 'badge-active');
                    $riskBorder = $riskLevel === 'CRITICAL' ? '#ef4444' : ($riskLevel === 'WARNING' ? '#f59e0b' : '#10b981');
                ?>
                <div class="cloud-admin-grid">
                    <!-- 1. Tổng Dung Lượng Vật Lý -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #3b82f6 !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>1. Tổng Dung Lượng Vật Lý</span>
                                <span style="font-size:14px">🗄️</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-total-physical"><?= $fmtB($m['POOL_TOTAL_PHYSICAL'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Cụm <b><?= ($m['total_accounts'] ?? 0) ?></b> tài khoản (<b><?= ($m['active_accounts'] ?? 0) ?></b> Active)</span>
                        </div>
                    </div>

                    <!-- 2. Đã Dùng Vật Lý -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #6366f1 !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>2. Đã Dùng Vật Lý</span>
                                <span style="font-size:14px">📊</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-used-physical" style="color:#818cf8"><?= $fmtB($m['POOL_USED_PHYSICAL'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Dung lượng thực tế trên Drive</span>
                        </div>
                    </div>

                    <!-- 3. Trống Vật Lý -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #10b981 !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>3. Trống Vật Lý</span>
                                <span style="font-size:14px">💾</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-free-physical" style="color:#34d399"><?= $fmtB($m['POOL_FREE_PHYSICAL'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Tổng trống chưa trừ buffer</span>
                        </div>
                    </div>

                    <!-- 4. Đang Giữ Chỗ Tải Lên -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #f59e0b !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>4. Đang Giữ Chỗ Tải Lên</span>
                                <span style="font-size:14px">⏳</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-reserved" style="color:#fbbf24"><?= $fmtB($m['POOL_RESERVED'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Upload reservations chưa commit</span>
                        </div>
                    </div>

                    <!-- 5. Ngưỡng An Toàn (10%) -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #64748b !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>5. Ngưỡng An Toàn (10%)</span>
                                <span style="font-size:14px">🛡️</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-safety-buffer" style="color:var(--muted-foreground)"><?= $fmtB($m['POOL_SAFETY_BUFFER'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Dự trữ đệm tránh lỗi Drive đầy</span>
                        </div>
                    </div>

                    <!-- 6. Khả Dụng Thực Tế -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #059669 !important">
                        <div>
                            <div class="cloud-stat-label" style="color:var(--emerald)">
                                <span>6. Khả Dụng Thực Tế</span>
                                <span class="badge badge-active" style="font-size:10px;padding:2px 6px">Ghi Tiếp</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-allocatable-free" style="color:var(--emerald)"><?= $fmtB($m['POOL_ALLOCATABLE_FREE'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Dung lượng cho phép ghi tiếp</span>
                        </div>
                    </div>

                    <!-- 7. Hạn Mức Ảo Đã Cấp -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #a855f7 !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>7. Hạn Mức Ảo Đã Cấp</span>
                                <span style="font-size:14px">📐</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-logical-allocated" style="color:#c084fc"><?= $fmtB($m['TOTAL_LOGICAL_QUOTA_ALLOCATED'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Tổng hạn mức <b><?= ($m['total_spaces'] ?? 0) ?></b> spaces</span>
                        </div>
                    </div>

                    <!-- 8. Dung Lượng Ảo Đã Dùng -->
                    <div class="card cloud-stat-card" style="border-left:4px solid #0ea5e9 !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>8. Dung Lượng Ảo Đã Dùng</span>
                                <span style="font-size:14px">📂</span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-logical-used" style="color:#38bdf8"><?= $fmtB($m['TOTAL_LOGICAL_USED'] ?? 0) ?></div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Người dùng đã lưu trữ</span>
                        </div>
                    </div>

                    <!-- 9. Tỷ Lệ Overcommit -->
                    <div class="card cloud-stat-card" style="border-left:4px solid <?= $riskBorder ?> !important">
                        <div>
                            <div class="cloud-stat-label">
                                <span>9. Tỷ Lệ Overcommit</span>
                                <span class="badge <?= $riskClass ?>" id="cadm-risk-badge" style="font-size:11px;font-weight:700;padding:2px 8px"><?= htmlspecialchars($riskLevel) ?></span>
                            </div>
                            <div class="cloud-stat-value" id="cadm-overcommit-ratio">
                                <span id="cadm-overcommit-val"><?= number_format($m['OVERCOMMIT_RATIO'] ?? 1.0, 2) ?>x</span>
                            </div>
                        </div>
                        <div class="cloud-stat-sub">
                            <span>Cấp vượt an toàn &lt; 1.5x</span>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: PHYSICAL STORAGE ACCOUNTS POOL TABLE -->
                <div class="card" style="margin-bottom:24px;border:1px solid var(--border);background:var(--surface-1);border-radius:var(--radius-md);overflow:hidden">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--surface-2)">
                        <h4 style="font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;color:var(--foreground)">
                            <span>🗄️</span> Danh Sách Tài Khoản Google Drive Vật Lý (Physical Pool)
                        </h4>
                        <span class="badge badge-info" id="cadm-accounts-count-badge" style="font-size:12px;padding:3px 10px"><?= count($cloud_accounts) ?> Tài khoản</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" style="margin:0;width:100%">
                            <thead>
                                <tr>
                                    <th style="padding:12px 16px">ID Tài Khoản</th>
                                    <th style="padding:12px 16px">Tên Định Danh / Chi Tiết</th>
                                    <th style="padding:12px 16px">Tổng Dung Lượng</th>
                                    <th style="padding:12px 16px">Đã Sử Dụng</th>
                                    <th style="padding:12px 16px">Đệm An Toàn</th>
                                    <th style="padding:12px 16px">Sức Khỏe</th>
                                    <th style="padding:12px 16px">Trạng Thái</th>
                                    <th style="padding:12px 16px;text-align:right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody id="cadm-accounts-tbody">
                                <?php if (empty($cloud_accounts)): ?>
                                    <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted-foreground);font-size:13px">Chưa có tài khoản Google Drive nào trong cụm lưu trữ. Hãy bấm <b>"+ Thêm Tài Khoản Drive"</b> phía trên để bắt đầu kết nối OAuth.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($cloud_accounts as $sa): ?>
                                        <?php
                                            $saTot = (float)($sa['total_capacity_bytes'] ?? 0);
                                            $saUse = (float)($sa['used_capacity_bytes'] ?? 0);
                                            $pct = $saTot > 0 ? round(($saUse / $saTot) * 100, 1) : 0;
                                            $hStatus = $sa['health_status'] ?? 'UNKNOWN';
                                            $hClass = ($hStatus === 'HEALTHY') ? 'badge-active' : (($hStatus === 'DEGRADED') ? 'badge-warning' : 'badge-danger');
                                            $lastCheck = !empty($sa['last_health_check']) ? date('H:i d/m', strtotime($sa['last_health_check'])) : 'Chưa kiểm tra';

                                            $saEmail = '';
                                            if (!empty($sa['encrypted_credentials'])) {
                                                try {
                                                    require_once __DIR__ . '/api/v1/storage/CryptoService.php';
                                                    $creds = CryptoService::decryptJson($sa['encrypted_credentials']);
                                                    $saEmail = $creds['user_email'] ?? '';
                                                } catch (Throwable $e) {}
                                            }
                                        ?>
                                        <tr id="cadm-account-row-<?= htmlspecialchars($sa['id']) ?>">
                                            <td style="padding:14px 16px">
                                                <code class="font-mono" style="background:rgba(59,130,246,0.12);color:#60a5fa;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:600">
                                                    <?= htmlspecialchars($sa['id']) ?>
                                                </code>
                                            </td>
                                            <td style="padding:14px 16px">
                                                <div style="font-weight:600;color:var(--foreground);display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                                    <span>📁</span>
                                                    <span><?= htmlspecialchars($sa['display_alias'] ?? ($sa['label'] ?? 'Storage Account')) ?></span>
                                                    <?php if ($saEmail): ?>
                                                        <span class="badge" style="background:rgba(59,130,246,0.1);color:#60a5fa;font-size:11px;font-weight:normal;padding:1px 6px">📧 <?= htmlspecialchars($saEmail) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size:11.5px;color:var(--muted-foreground);margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                                    <span>Thư mục riêng:
                                                        <?php if (!empty($sa['root_folder_id']) && $sa['root_folder_id'] !== 'root'): ?>
                                                            <a href="https://drive.google.com/drive/folders/<?= urlencode($sa['root_folder_id']) ?>" target="_blank" style="color:#38bdf8;font-weight:600;text-decoration:underline" title="Mở trực tiếp thư mục riêng trên Google Drive">
                                                                📁 2TOOLNE Cloud Storage ↗
                                                            </a>
                                                        <?php else: ?>
                                                            <code style="font-size:11px;color:var(--muted-subtle)">Chưa gán</code>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span>•</span>
                                                    <span>Tệp: <b style="color:var(--foreground)"><?= number_format((int)($sa['active_file_count'] ?? 0)) ?></b> active</span>
                                                </div>
                                            </td>
                                            <td style="padding:14px 16px" class="font-mono" style="font-weight:600;font-size:13px" id="cadm-account-tot-<?= htmlspecialchars($sa['id']) ?>"><?= $fmtB($saTot) ?></td>
                                            <td style="padding:14px 16px">
                                                <div class="font-mono" style="font-size:12.5px;font-weight:600" id="cadm-account-used-<?= htmlspecialchars($sa['id']) ?>">
                                                    <?= $fmtB($saUse) ?> <span style="font-size:11px;color:var(--muted-foreground);font-weight:normal">(<?= $pct ?>%)</span>
                                                </div>
                                                <div style="height:6px;width:120px;background:rgba(255,255,255,0.06);border-radius:999px;overflow:hidden;margin-top:6px">
                                                    <div id="cadm-account-bar-<?= htmlspecialchars($sa['id']) ?>" style="height:100%;width:<?= min(100, $pct) ?>%;background:<?= $pct > 90 ? 'var(--danger)' : ($pct > 75 ? 'var(--warning)' : 'var(--emerald)') ?>;border-radius:999px"></div>
                                                </div>
                                            </td>
                                            <td style="padding:14px 16px;font-size:12.5px;color:var(--muted-foreground)">
                                                <span class="badge" style="background:rgba(255,255,255,0.05);color:var(--muted-foreground);font-size:11px">
                                                    <?= (int)($sa['safety_reserve_percent'] ?? 10) ?>%
                                                </span>
                                            </td>
                                            <td style="padding:14px 16px">
                                                <span id="cadm-account-health-<?= htmlspecialchars($sa['id']) ?>" class="badge <?= $hClass ?>" style="font-size:11px;padding:3px 8px" title="Kiểm tra gần nhất: <?= $lastCheck ?>">
                                                    <?= htmlspecialchars($hStatus) ?>
                                                </span>
                                            </td>
                                            <td style="padding:14px 16px">
                                                <span id="cadm-account-status-<?= htmlspecialchars($sa['id']) ?>">
                                                    <?php if ($sa['status'] === 'ACTIVE'): ?>
                                                        <span class="badge badge-active" style="font-size:11px;padding:3px 8px">● Active</span>
                                                    <?php elseif ($sa['status'] === 'DRAINING'): ?>
                                                        <span class="badge badge-warning" style="font-size:11px;padding:3px 8px">⏳ Draining</span>
                                                    <?php elseif ($sa['status'] === 'DISABLED'): ?>
                                                        <span class="badge badge-danger" style="font-size:11px;padding:3px 8px">🚫 Disabled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger" style="font-size:11px;padding:3px 8px"><?= htmlspecialchars($sa['status']) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td style="padding:14px 16px;text-align:right">
                                                <div id="cadm-account-actions-<?= htmlspecialchars($sa['id']) ?>" style="display:inline-flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                                                    <button type="button" class="btn btn-outline btn-xs" onclick="refreshAccountUsage('<?= $sa['id'] ?>', this)" title="Làm mới dung lượng thực tế từ Google Drive">
                                                        🔄 Làm Mới
                                                    </button>
                                                    <button type="button" class="btn btn-outline btn-xs" onclick="checkAccountHealth('<?= $sa['id'] ?>', this)" title="Kiểm tra kết nối và thư mục lưu trữ">
                                                        🩺 Kiểm Tra
                                                    </button>
                                                    <?php if ($sa['status'] === 'ACTIVE'): ?>
                                                        <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#f59e0b;border-color:rgba(245,158,11,0.3)" onclick="setAccountStatus('<?= $sa['id'] ?>', 'DRAINING')" title="Chuyển sang DRAINING: Không nhận file mới, chỉ đọc">
                                                            Rút (Drain)
                                                        </button>
                                                        <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)" onclick="setAccountStatus('<?= $sa['id'] ?>', 'DISABLED')" title="Vô hiệu hóa tài khoản">
                                                            Khóa
                                                        </button>
                                                    <?php elseif ($sa['status'] === 'DRAINING'): ?>
                                                        <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:var(--emerald);border-color:rgba(16,185,129,0.3)" onclick="setAccountStatus('<?= $sa['id'] ?>', 'ACTIVE')" title="Kích hoạt lại">
                                                            Kích Hoạt
                                                        </button>
                                                        <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)" onclick="setAccountStatus('<?= $sa['id'] ?>', 'DISABLED')" title="Vô hiệu hóa tài khoản">
                                                            Khóa
                                                        </button>
                                                    <?php elseif ($sa['status'] === 'DISABLED'): ?>
                                                        <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:var(--emerald);border-color:rgba(16,185,129,0.3)" onclick="setAccountStatus('<?= $sa['id'] ?>', 'ACTIVE')" title="Mở khóa và kích hoạt lại">
                                                            Mở Khóa
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-outline btn-xs" style="color:var(--danger);border-color:rgba(239,68,68,0.3)" onclick="disconnectAccountSafe('<?= $sa['id'] ?>', <?= (int)$saUse ?>)" title="Ngắt kết nối an toàn">
                                                        Ngắt
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SECTION 3.5: LOGICAL CLOUD SPACES (USER & TEAM) -->
                <div class="card" style="border:1px solid var(--border);background:var(--surface-1);border-radius:var(--radius-md);overflow:hidden;margin-bottom:24px">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--surface-2);flex-wrap:wrap;gap:10px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <h4 style="font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;color:var(--foreground)">
                                <span>🗂️</span> Danh Sách Không Gian Lưu Trữ Cloud (User & Team Spaces)
                            </h4>
                            <span class="badge badge-primary" style="font-size:12px;padding:3px 10px"><?= count($cloud_spaces) ?> Không gian</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="text" id="cadm-spaces-search-input" class="form-input" style="font-size:12.5px;padding:5px 12px;width:220px" placeholder="🔍 Tìm kiếm Space, user, team..." onkeyup="filterCloudSpacesTable()">
                            <?php if (adm_can('cloud.quota_adjust')): ?>
                            <button type="button" class="btn btn-emerald btn-sm" onclick="openModal('modal-admin-adjust-quota')" style="display:inline-flex;align-items:center;gap:6px">
                                <span>⚖️</span> Điều Chỉnh Hạn Mức
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" style="margin:0;width:100%">
                            <thead>
                                <tr>
                                    <th style="padding:12px 16px">Không Gian (Space)</th>
                                    <th style="padding:12px 16px">Chủ Sở Hữu / Loại</th>
                                    <th style="padding:12px 16px">Trạng Thái</th>
                                    <th style="padding:12px 16px">Số File</th>
                                    <th style="padding:12px 16px;min-width:180px">Dung Lượng / Hạn Mức</th>
                                    <th style="padding:12px 16px">Điều Chỉnh Admin</th>
                                    <th style="padding:12px 16px">Thời Gian Hết Hạn</th>
                                    <th style="padding:12px 16px;text-align:right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody id="cadm-spaces-tbody">
                                <?php if (empty($cloud_spaces)): ?>
                                    <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted-foreground);font-size:13px">Chưa có Không gian Cloud nào được khởi tạo.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($cloud_spaces as $sp): ?>
                                        <?php
                                            $quotaBytes = (float)($sp['effective_quota_bytes'] ?? 5368709120);
                                            $usedBytes  = (float)($sp['used_bytes'] ?? 0);
                                            $pct = $quotaBytes > 0 ? min(100, round(($usedBytes / $quotaBytes) * 100, 1)) : 0;
                                            $barColor = $pct >= 90 ? '#ef4444' : ($pct >= 75 ? '#f59e0b' : '#10b981');
                                            $adjBytes = (float)($sp['admin_adjustment_bytes'] ?? 0);
                                            $adjGb = round($adjBytes / (1024 * 1024 * 1024), 1);
                                            $isTeam = ($sp['owner_type'] ?? '') === 'TEAM';
                                            $ownerName = $isTeam ? ($sp['team_name'] ?: 'Team #' . $sp['owner_id']) : ($sp['fullname'] ?: $sp['username'] ?: $sp['owner_id']);
                                            $ownerSub  = $isTeam ? 'Đội Nhóm (Team)' : '@' . ($sp['username'] ?: $sp['owner_id']);
                                            $searchBlob = strtolower(($sp['id'] ?? '') . ' ' . ($sp['name'] ?? '') . ' ' . $ownerName . ' ' . $ownerSub);
                                            $st = $sp['status'] ?? 'ACTIVE';
                                            $stBadge = $st === 'ACTIVE' ? '<span class="badge badge-emerald" style="font-size:10px">🟢 ACTIVE</span>' : ($st === 'READ_ONLY' ? '<span class="badge badge-warning" style="font-size:10px">🟡 CHỈ ĐỌC</span>' : '<span class="badge badge-danger" style="font-size:10px">🔴 ' . htmlspecialchars($st) . '</span>');
                                        ?>
                                        <tr class="cadm-space-row" data-search="<?= htmlspecialchars($searchBlob) ?>">
                                            <td style="padding:12px 16px">
                                                <div style="font-weight:700;font-size:13.5px;color:var(--foreground);display:flex;align-items:center;gap:6px">
                                                    <span><?= $isTeam ? '🏢' : '👤' ?></span> <?= htmlspecialchars($sp['name'] ?: ($isTeam ? 'Không Gian Team' : 'Không Gian Cá Nhân')) ?>
                                                </div>
                                                <div style="font-size:11px;color:var(--muted-subtle);font-family:var(--font-mono)"><code><?= htmlspecialchars($sp['id']) ?></code></div>
                                            </td>
                                            <td style="padding:12px 16px">
                                                <div style="font-weight:600;font-size:12.5px;color:var(--foreground)"><?= htmlspecialchars($ownerName) ?></div>
                                                <div style="font-size:11px;color:var(--muted-foreground)"><?= htmlspecialchars($ownerSub) ?></div>
                                            </td>
                                            <td style="padding:12px 16px"><?= $stBadge ?></td>
                                            <td style="padding:12px 16px">
                                                <span class="badge badge-outline" style="font-size:11px;font-family:var(--font-mono)">
                                                    📄 <?= (int)($sp['files_count'] ?? 0) ?>
                                                </span>
                                            </td>
                                            <td style="padding:12px 16px">
                                                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;margin-bottom:4px">
                                                    <span style="font-weight:700;color:var(--foreground)">
                                                        <?= round($usedBytes / 1073741824, 2) ?> GB / <?= round($quotaBytes / 1073741824, 1) ?> GB
                                                    </span>
                                                    <span style="font-size:10.5px;color:<?= $barColor ?>;font-weight:700"><?= $pct ?>%</span>
                                                </div>
                                                <div style="width:100%;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,0.05)">
                                                    <div style="width:<?= max(3, $pct) ?>%;height:100%;background:<?= $barColor ?>;border-radius:999px"></div>
                                                </div>
                                            </td>
                                            <td style="padding:12px 16px">
                                                <?php if ($adjGb != 0): ?>
                                                    <span class="badge" style="font-size:11px;font-family:var(--font-mono);font-weight:700;background:<?= $adjGb > 0 ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)' ?>;color:<?= $adjGb > 0 ? 'var(--emerald)' : 'var(--danger)' ?>">
                                                        <?= $adjGb > 0 ? "+{$adjGb}" : $adjGb ?> GB
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">0 GB</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px 16px;font-size:11.5px;color:var(--muted-foreground)">
                                                <?= !empty($sp['expires_at']) ? htmlspecialchars(substr($sp['expires_at'], 0, 10)) : 'Vĩnh viễn' ?>
                                            </td>
                                            <td style="padding:12px 16px;text-align:right">
                                                <?php if (adm_can('cloud.quota_adjust')): ?>
                                                    <button type="button" class="btn btn-outline btn-xs" onclick="openAdjustQuotaForSpace('<?= htmlspecialchars($sp['id']) ?>', '<?= htmlspecialchars(addslashes($sp['name'] ?: $ownerName)) ?>')" title="Điều chỉnh hạn mức GB cho không gian này">
                                                        ⚖️ Điều Chỉnh
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SECTION 4: QUOTA ADJUSTMENTS LEDGER -->
                <div class="card" style="border:1px solid var(--border);background:var(--surface-1);border-radius:var(--radius-md);overflow:hidden">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--surface-2)">
                        <h4 style="font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;color:var(--foreground)">
                            <span>📜</span> Sổ Nhật Ký Điều Chỉnh Hạn Mức Dung Lượng (Audit Ledger)
                        </h4>
                        <span class="badge badge-primary" id="cadm-ledger-count-badge" style="font-size:12px;padding:3px 10px"><?= count($cloud_ledger) ?> Bản ghi gần nhất</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" style="margin:0;width:100%">
                            <thead>
                                <tr>
                                    <th style="padding:12px 16px">Mã GD</th>
                                    <th style="padding:12px 16px">Không Gian (Space)</th>
                                    <th style="padding:12px 16px">Mức Điều Chỉnh (Delta)</th>
                                    <th style="padding:12px 16px">Hạn Mức Sau Sửa</th>
                                    <th style="padding:12px 16px">Lý Do Bắt Buộc</th>
                                    <th style="padding:12px 16px">Quản Trị Viên</th>
                                    <th style="padding:12px 16px">Thời Gian</th>
                                </tr>
                            </thead>
                            <tbody id="cadm-ledger-tbody">
                                <?php if (empty($cloud_ledger)): ?>
                                    <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted-foreground);font-size:13px">Chưa có giao dịch điều chỉnh hạn mức nào được thực hiện.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($cloud_ledger as $entry): ?>
                                        <?php
                                            $delta = (float)($entry['delta_bytes'] ?? 0);
                                            $deltaGb = round($delta / (1024 * 1024 * 1024), 1);
                                            $deltaSign = $delta >= 0 ? '+' : '';
                                            $deltaColor = $delta >= 0 ? 'var(--emerald)' : 'var(--danger)';
                                            $deltaBg = $delta >= 0 ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
                                            $deltaBorder = $delta >= 0 ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)';
                                        ?>
                                        <tr>
                                            <td style="padding:14px 16px"><code class="font-mono text-subtle" style="font-size:11px"><?= htmlspecialchars($entry['id']) ?></code></td>
                                            <td style="padding:14px 16px">
                                                <div style="font-weight:600;color:var(--foreground)"><?= htmlspecialchars($entry['space_name'] ?: $entry['cloud_space_id']) ?></div>
                                                <div class="font-mono text-subtle" style="font-size:10.5px;margin-top:2px"><?= htmlspecialchars($entry['cloud_space_id']) ?></div>
                                            </td>
                                            <td style="padding:14px 16px">
                                                <span class="badge" style="background:<?= $deltaBg ?>;color:<?= $deltaColor ?>;border:1px solid <?= $deltaBorder ?>;font-weight:700;font-family:var(--font-mono)">
                                                    <?= $deltaSign ?><?= $deltaGb ?> GB
                                                </span>
                                            </td>
                                            <td style="padding:14px 16px" class="font-mono" style="font-weight:600"><?= $fmtB($entry['balance_after_bytes'] ?? ($entry['new_effective_quota_bytes'] ?? 0)) ?></td>
                                            <td style="padding:14px 16px;font-size:12.5px;max-width:280px;word-break:break-word;line-height:1.4">
                                                <?= htmlspecialchars($entry['reason']) ?>
                                            </td>
                                            <td style="padding:14px 16px">
                                                <span class="badge badge-info" style="font-size:11px">👤 <?= htmlspecialchars($entry['admin_user_id'] ?? ($entry['admin_actor'] ?? 'SYSTEM')) ?></span>
                                            </td>
                                            <td style="padding:14px 16px;font-size:12px;color:var(--muted-foreground)"><?= htmlspecialchars($entry['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>

        <!-- ═══ ADMIN MODALS ═══ -->

        <!-- MODAL: ADD PHYSICAL STORAGE ACCOUNT (GOOGLE OAUTH 2.0) -->
        <div id="modal-admin-add-storage-account" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:480px">
                <div class="modal-header">
                    <div class="modal-title">🔗 Kết Nối Tài Khoản Google Drive Vào Cụm</div>
                    <button class="modal-close" onclick="closeModal('modal-admin-add-storage-account')">&times;</button>
                </div>
                <div class="modal-body">
                    <form onsubmit="startGoogleOAuthConnect(event)">
                        <div class="form-group">
                            <label class="form-label">TÊN ĐỊNH DANH (DISPLAY ALIAS):</label>
                            <input type="text" id="m-sa-label" class="form-input" value="Google Drive Primary" placeholder="Ví dụ: Google Drive Operator 01" required>
                            <div style="font-size:11px;color:var(--muted-subtle);margin-top:4px">Tên hiển thị để nhận diện tài khoản này trong bảng điều khiển Cụm Lưu Trữ.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ĐỆM DỰ TRỮ AN TOÀN (% CAPACITY BUFFER):</label>
                            <input type="number" id="m-sa-buffer" class="form-input" value="10" min="0" max="50" required>
                            <div style="font-size:11px;color:var(--muted-subtle);margin-top:4px">Phần trăm dung lượng giữ lại làm đệm an toàn, không phân bổ cho tệp mới (mặc định 10%).</div>
                        </div>

                        <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.2);border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:12px;line-height:1.45;color:var(--muted-foreground)">
                            <div style="font-weight:600;color:#60a5fa;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                                <span>🔒</span> Xác Thực Google OAuth 2.0 Tự Động
                            </div>
                            <div>
                                Khi bấm kết nối, hệ thống sẽ chuyển hướng an toàn tới Google. Sau khi bạn cấp quyền, hệ thống sẽ <b>tự động lấy hạn mức dung lượng thực tế</b> và tự động tạo thư mục lưu trữ riêng biệt <code>2toolne_cloud_storage_root</code>.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px;font-weight:600;padding:11px" id="m-sa-submit-btn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/></svg>
                            Kết Nối Với Google Drive
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL: ADJUST SPACE QUOTA -->
        <div id="modal-admin-adjust-quota" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:480px">
                <div class="modal-header">
                    <div class="modal-title">⚖️ Điều Chỉnh Hạn Mức Dung Lượng (Space Quota)</div>
                    <button class="modal-close" onclick="closeModal('modal-admin-adjust-quota')">&times;</button>
                </div>
                <div class="modal-body">
                    <form onsubmit="submitAdjustQuota(event)">
                        <div class="form-group">
                            <label class="form-label">MÃ KHÔNG GIAN (SPACE ID):</label>
                            <input type="text" id="m-adj-space-id" class="form-input font-mono" placeholder="cs_pers_... hoặc cs_team_..." required>
                            <div style="font-size:11px;color:var(--muted-subtle);margin-top:4px">Nhập ID của Không gian cá nhân hoặc Không gian nhóm cần điều chỉnh.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">DUNG LƯỢNG ĐIỀU CHỈNH (+ HOẶC - GB):</label>
                            <input type="number" id="m-adj-delta-gb" class="form-input" placeholder="Ví dụ: 10 (tăng 10GB) hoặc -5 (giảm 5GB)" step="0.5" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">LÝ DO ĐIỀU CHỈNH <span style="color:var(--danger)">* (BẮT BUỘC)</span>:</label>
                            <textarea id="m-adj-reason" class="form-textarea" rows="3" placeholder="Lý do tăng/giảm dung lượng (lưu vết vào Audit Ledger)..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-emerald" style="width:100%" id="m-adj-submit-btn">💾 Xác Nhận Điều Chỉnh</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: USER TOKEN BALANCE LOG & AUDIT ═══ -->
        <div id="modal-admin-user-token-log" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:800px;width:95%">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
                            <span>🪙</span> Lịch Sử Biến Động Số Dư Token: <b id="m-toklog-username" style="color:#facc15">...</b>
                        </div>
                        <div style="font-size:12px;color:var(--muted-foreground);margin-top:2px" id="m-toklog-fullname">...</div>
                    </div>
                    <button class="modal-close" onclick="closeModal('modal-admin-user-token-log')">&times;</button>
                </div>
                <div class="modal-body" style="padding-top:12px">
                    <!-- SUMMARY BAR -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:10px;margin-bottom:16px;background:var(--surface-2);padding:12px 16px;border-radius:var(--radius-md);border:1px solid var(--border)">
                        <div>
                            <div style="font-size:11px;color:var(--muted-foreground)">SỐ DƯ KHẢ DỤNG</div>
                            <div style="font-size:16px;font-weight:800;color:#facc15" id="m-toklog-balance">0 tokens</div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-foreground)">TẠM KHÓA (HOLD)</div>
                            <div style="font-size:16px;font-weight:800;color:#f59e0b" id="m-toklog-reserved">0 tokens</div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted-foreground)">ĐÃ SỬ DỤNG</div>
                            <div style="font-size:16px;font-weight:800;color:#60a5fa" id="m-toklog-consumed">0 tokens</div>
                        </div>
                    </div>

                    <!-- TRANSACTIONS TABLE -->
                    <div class="table-responsive" style="max-height:360px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
                        <table class="data-table" style="margin:0;width:100%">
                            <thead>
                                <tr>
                                    <th style="padding:10px 14px">Thời Gian</th>
                                    <th style="padding:10px 14px">Loại Giao Dịch</th>
                                    <th style="padding:10px 14px">Biến Động</th>
                                    <th style="padding:10px 14px">Số Dư Sau</th>
                                    <th style="padding:10px 14px">Diễn Giải / Lý Do</th>
                                    <th style="padding:10px 14px">Người Tạo</th>
                                </tr>
                            </thead>
                            <tbody id="m-toklog-tbody">
                                <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted-foreground)">Đang tải lịch sử...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: CREATE TEAM ═══ -->
        <div id="modal-admin-create-team" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:500px">
                <div class="modal-header">
                    <div class="modal-title">🏢 Khởi Tạo Đội Nhóm (Team) Mới</div>
                    <button class="modal-close" onclick="closeModal('modal-admin-create-team')">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                        <input type="hidden" name="action" value="admin_create_team">
                        <div class="form-group">
                            <label class="form-label">TÊN ĐỘI NHÓM (TEAM NAME): <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="team_name" class="form-input" placeholder="Ví dụ: Studio Wedding Sài Gòn" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">CHỌN TRƯỞNG NHÓM (OWNER): <span style="color:var(--danger)">*</span></label>
                            <select name="owner_user_id" class="form-select" required>
                                <option value="">-- Chọn tài khoản làm Trưởng nhóm --</option>
                                <?php foreach ($users_db as $u_name => $u_data): ?>
                                    <option value="<?= htmlspecialchars($u_name) ?>">
                                        👤 <?= htmlspecialchars($u_name) ?> (<?= htmlspecialchars($u_data['fullname'] ?: 'Chưa đặt tên') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label">SỐ SLOT THÀNH VIÊN:</label>
                                <input type="number" name="member_slots" class="form-input" value="2" min="2" max="100" required>
                                <div style="font-size:11px;color:var(--muted-foreground);margin-top:3px">Mặc định gói cơ bản: 2 slots</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">DUNG LƯỢNG KHO (GB):</label>
                                <input type="number" name="quota_gb" class="form-input" value="20" min="5" max="2000" required>
                                <div style="font-size:11px;color:var(--muted-foreground);margin-top:3px">Mặc định: 20 GB</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">THỜI HẠN HOẠT ĐỘNG (NGÀY):</label>
                            <input type="number" name="duration_days" class="form-input" value="90" min="1" max="3650" required>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:3px">Mặc định chu kỳ: 90 ngày (3 tháng)</div>
                        </div>
                        <button type="submit" class="btn btn-emerald" style="width:100%;margin-top:8px">🚀 Khởi Tạo Team Ngay</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: MANAGE TEAM MEMBERS ═══ -->
        <div id="modal-admin-manage-team-members" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:760px;width:95%">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" id="m-team-manage-title" style="display:flex;align-items:center;gap:8px">
                            <span>🏢</span> Quản Lý Team: <b id="m-team-header-name">...</b>
                        </div>
                        <div style="font-size:12px;color:var(--muted-foreground);margin-top:4px" id="m-team-manage-sub">Mã nhóm: <code id="m-team-header-id">...</code></div>
                    </div>
                    <button class="modal-close" onclick="closeModal('modal-admin-manage-team-members')">&times;</button>
                </div>
                <div class="modal-body" style="padding-top:14px">
                    <!-- 3-Card Summary Grid -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:12px;margin-bottom:16px">
                        <!-- Card 1: Team Name -->
                        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px 14px;display:flex;flex-direction:column;justify-content:space-between">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Đội Nhóm</div>
                                <div id="m-team-card-name" style="font-weight:700;font-size:14px;color:var(--foreground);word-break:break-word">...</div>
                                <div id="m-team-card-owner" style="font-size:11.5px;color:var(--muted-foreground);margin-top:3px">...</div>
                            </div>
                            <?php if (adm_can('teams.manage')): ?>
                            <button type="button" class="btn btn-outline btn-xs" onclick="promptRenameTeamFromModal()" style="margin-top:10px;width:100%;display:flex;align-items:center;justify-content:center;gap:4px">
                                <span>✏️</span> Đổi Tên Team
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Card 2: Cloud Quota -->
                        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px 14px;display:flex;flex-direction:column;justify-content:space-between">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Dung Lượng Cloud</div>
                                <div id="m-team-quota-text" style="font-weight:700;font-size:13.5px;color:#38bdf8">0 GB / 20 GB</div>
                                <div style="width:100%;height:6px;background:var(--surface-3);border-radius:999px;overflow:hidden;margin-top:6px;border:1px solid rgba(255,255,255,0.05)">
                                    <div id="m-team-quota-bar" style="width:0%;height:100%;background:#38bdf8;border-radius:999px"></div>
                                </div>
                            </div>
                            <?php if (adm_can('cloud.quota_adjust')): ?>
                            <button type="button" class="btn btn-outline btn-xs" onclick="promptAdjustTeamQuotaFromModal()" style="margin-top:10px;width:100%;display:flex;align-items:center;justify-content:center;gap:4px">
                                <span>⚖️</span> Chỉnh Dung Lượng GB
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Card 3: Slots -->
                        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px 14px;display:flex;flex-direction:column;justify-content:space-between">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--muted-foreground);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Chỗ Ngồi (Slots)</div>
                                <div id="m-team-slots-text" style="font-weight:700;font-size:13.5px;color:#10b981">...</div>
                                <div id="m-team-slots-sub" style="font-size:11.5px;color:var(--muted-foreground);margin-top:3px">...</div>
                            </div>
                            <?php if (adm_can('teams.manage')): ?>
                            <button type="button" class="btn btn-outline btn-xs" onclick="promptAddTeamSlotsFromModal()" style="margin-top:10px;width:100%;display:flex;align-items:center;justify-content:center;gap:4px">
                                <span>➕</span> Thêm Chỗ Ngồi
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Members Section Header -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                        <div style="font-weight:700;font-size:13px;color:var(--foreground);display:flex;align-items:center;gap:6px">
                            <span>👥</span> Danh Sách Thành Viên (<span id="m-team-members-count">0</span>)
                        </div>
                        <?php if (adm_can('teams.manage')): ?>
                        <button type="button" class="btn btn-emerald btn-xs" onclick="openAddTeamUsersPopup()" style="display:inline-flex;align-items:center;gap:5px">
                            <span>➕</span> Gán User Vào Team
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
                        <table class="data-table" style="margin:0">
                            <thead>
                                <tr>
                                    <th>Thành Viên</th>
                                    <th>Vai Trò</th>
                                    <th>Trạng Thái</th>
                                    <th>Tham Gia</th>
                                    <th style="text-align:right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody id="m-team-members-tbody">
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:24px">Đang tải danh sách thành viên...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Modal Footer Actions -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:12px;border-top:1px solid var(--border)">
                        <?php if (adm_can('teams.manage')): ?>
                        <button type="button" class="btn btn-danger btn-xs" onclick="confirmDeleteTeamPermanentlyFromModal()" style="display:inline-flex;align-items:center;gap:5px">
                            <span>🗑️</span> Xóa Vĩnh Viễn Team Này
                        </button>
                        <?php else: ?>
                        <div></div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('modal-admin-manage-team-members')">Đóng</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: ADD USERS TO TEAM (POPUP CHECKBOX WITH SEARCH) ═══ -->
        <div id="modal-admin-add-team-users" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:560px;width:95%">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
                            <span>👥</span> Gán Người Dùng Vào Team
                        </div>
                        <div style="font-size:12px;color:var(--muted-foreground);margin-top:2px" id="m-add-users-team-name">...</div>
                    </div>
                    <button class="modal-close" onclick="closeModal('modal-admin-add-team-users')">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                        <input type="hidden" name="action" value="admin_add_team_members">
                        <input type="hidden" name="team_id" id="m-add-users-team-id">

                        <div class="form-group">
                            <label class="form-label">TÌM KIẾM TÀI KHOẢN NGƯỜI DÙNG:</label>
                            <input type="text" id="add-team-user-search-input" class="form-input" placeholder="🔍 Gõ ID/Username (ví dụ: hieunekkk), Họ tên, SĐT..." onkeyup="filterAddTeamUserCheckboxes()">
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:4px">Tick chọn một hoặc nhiều tài khoản dưới đây để gán vào nhóm.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">VAI TRÒ TRONG ĐỘI NHÓM:</label>
                            <select name="role" class="form-select">
                                <option value="MEMBER">👤 Thành Viên Thường (MEMBER) — Tải lên & xem kho tệp chung</option>
                                <option value="ADMIN">🛡️ Quản Trị Viên (ADMIN) — Được mời/xóa thành viên khác</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                <label class="form-label" style="margin:0">DANH SÁCH USER HỆ THỐNG:</label>
                                <span style="font-size:11.5px;color:var(--muted-foreground)" id="add-team-user-selected-count">Đã chọn: 0 user</span>
                            </div>
                            <div id="add-team-user-checkbox-list" style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md);padding:8px;background:var(--surface-1)">
                                <?php foreach ($users_db as $u_name => $u_data): 
                                    $u_full = $u_data['fullname'] ?: $u_name;
                                    $u_phone = $u_data['phone'] ?: '';
                                    $search_tag = strtolower($u_name . ' ' . $u_full . ' ' . $u_phone . ' ' . ($u_data['id'] ?? ''));
                                ?>
                                    <label class="add-team-user-item" data-search="<?= htmlspecialchars($search_tag) ?>" style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:6px;cursor:pointer;transition:background 0.2s;margin-bottom:4px;border:1px solid transparent">
                                        <input type="checkbox" name="selected_users[]" value="<?= htmlspecialchars($u_name) ?>" class="add-team-user-cb" onchange="updateAddTeamUserSelectedCount()" style="width:16px;height:16px;accent-color:var(--emerald)">
                                        <div style="flex:1;min-width:0">
                                            <div style="display:flex;align-items:center;gap:6px">
                                                <b style="font-size:13px;color:var(--foreground)"><?= htmlspecialchars($u_name) ?></b>
                                                <span class="badge badge-outline" style="font-size:10px"><?= htmlspecialchars($u_data['role'] ?? 'user') ?></span>
                                            </div>
                                            <div style="font-size:11.5px;color:var(--muted-foreground)">
                                                <?= htmlspecialchars($u_full) ?> <?= !empty($u_phone) ? '• 📞 ' . htmlspecialchars($u_phone) : '' ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-emerald" style="width:100%">
                            ✅ Xác Nhận Gán Vào Team
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══ MODAL: ADD TEAM SLOTS ═══ -->
        <div id="modal-admin-add-team-slots" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:420px">
                <div class="modal-header">
                    <div class="modal-title">➕ Mở Rộng Slot Thành Viên</div>
                    <button class="modal-close" onclick="closeModal('modal-admin-add-team-slots')">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                        <input type="hidden" name="action" value="admin_add_team_slots">
                        <input type="hidden" name="team_id" id="m-slots-team-id">
                        <div class="form-group">
                            <label class="form-label">TÊN ĐỘI NHÓM:</label>
                            <input type="text" id="m-slots-team-name" class="form-input" readonly style="background:rgba(0,0,0,0.3)">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SỐ LƯỢNG SLOT MỞ RỘNG THÊM:</label>
                            <input type="number" name="extra_slots" class="form-input" value="1" min="1" max="50" required>
                            <div style="font-size:11px;color:var(--muted-foreground);margin-top:4px">Số slot mới sẽ cộng trực tiếp vào giới hạn thành viên của nhóm.</div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%">💾 Cập Nhật Slot</button>
                    </form>
                </div>
            </div>
        </div>

        <?php if (adm_can('keys.assign')): ?>
            <!-- MODAL ASSIGN KEY TO USER -->
            <div id="modal-admin-assign" class="modal-backdrop">
                <div class="modal-dialog" style="max-width:420px">
                    <div class="modal-header">
                        <div class="modal-title">👤 Gán / Đổi User Cho Key</div>
                        <button class="modal-close" onclick="closeModal('modal-admin-assign')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                            <input type="hidden" name="action" value="assign_user">
                            <input type="hidden" name="key" id="assign-key-input">
                            <div class="form-group">
                                <label class="form-label">LICENSE KEY:</label>
                                <code id="assign-key-label" class="font-mono text-primary" style="font-weight:700">...</code>
                            </div>
                            <div class="form-group">
                                <label class="form-label">CHỌN USER SỞ HỮU:</label>
                                <select name="new_owner" id="assign-user-select" class="form-select">
                                    <option value="">-- (Gỡ gán / Không gán) --</option>
                                    <?php foreach ($users_db as $u_id => $u_data): ?>
                                        <option value="<?= htmlspecialchars($u_id) ?>">
                                            <?= htmlspecialchars($u_id) ?> (<?= htmlspecialchars($u_data['fullname'] ?: 'No name') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-emerald" style="width:100%">Lưu Gán User</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (adm_can('users.password')): ?>
            <!-- MODAL ADMIN CHANGE USER PW -->
            <div id="modal-admin-pw" class="modal-backdrop">
                <div class="modal-dialog" style="max-width:400px">
                    <div class="modal-header">
                        <div class="modal-title">🔐 Đổi Mật Khẩu User</div>
                        <button class="modal-close" onclick="closeModal('modal-admin-pw')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this, { onSuccess: () => closeModal('modal-admin-pw') })">
                            <input type="hidden" name="action" value="admin_change_user_pw">
                            <input type="hidden" name="target_user" id="admin-target-user-input">
                            <div class="form-group">
                                <label class="form-label">TÀI KHOẢN:</label>
                                <b id="admin-target-user-label" class="text-primary">...</b>
                            </div>
                            <div class="form-group">
                                <label class="form-label">MẬT KHẨU MỚI (TỐI THIỂU 6 KÝ TỰ):</label>
                                <input type="password" name="new_password" class="form-input" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:100%">Cập Nhật Mật Khẩu</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- MODAL: DETAILED USER MANAGEMENT -->
        <div id="modal-manage-user" class="modal-backdrop">
            <div class="modal-dialog" style="max-width:880px;width:95%">
                <div class="modal-header" style="align-items:flex-start">
                    <div>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:40px;height:40px;border-radius:50%;background:var(--surface-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:20px">👤</div>
                            <div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <h3 class="modal-title" id="m-user-username" style="font-size:18px;margin:0">...</h3>
                                    <span id="m-user-role-badge" class="badge">...</span>
                                </div>
                                <div style="font-size:12px;color:var(--muted-foreground);margin-top:2px" id="m-user-subinfo">...</div>
                            </div>
                        </div>
                    </div>
                    <button class="modal-close" onclick="closeModal('modal-manage-user')">&times;</button>
                </div>
                <div class="modal-body" style="padding-top:10px">
                    <!-- SUBNAV TABS -->
                    <div class="modal-subnav">
                        <button type="button" class="modal-subnav-btn active" id="btn-usertab-keys" onclick="switchUserModalTab('usertab-keys')">
                            🔑 License Keys (<span id="m-user-keys-count">0</span>)
                        </button>
                        <?php if (adm_can('tokens.manage')): ?>
                            <button type="button" class="modal-subnav-btn" id="btn-usertab-wallet" onclick="switchUserModalTab('usertab-wallet')">
                                🪙 Ví & Token (<span id="m-user-tokens-count" style="color:#facc15;font-weight:700">0</span>)
                            </button>
                        <?php endif; ?>
                        <?php if (adm_can('users.cloud_manage') || adm_can('cloud.quota_adjust') || adm_can('cloud.view')): ?>
                            <button type="button" class="modal-subnav-btn" id="btn-usertab-cloud" onclick="switchUserModalTab('usertab-cloud')">
                                ☁️ Dung Lượng Cloud (<span id="m-user-cloud-summary" style="color:#38bdf8;font-weight:700">0 / 5 GB</span>)
                            </button>
                        <?php endif; ?>
                        <button type="button" class="modal-subnav-btn" id="btn-usertab-orders" onclick="switchUserModalTab('usertab-orders')">
                            📜 Lịch Sử Đơn Hàng (<span id="m-user-orders-count">0</span>)
                        </button>
                        <?php if (adm_can('users.role') || adm_can('users.edit') || adm_can('users.password') || adm_can('users.delete')): ?>
                            <button type="button" class="modal-subnav-btn" id="btn-usertab-roles" onclick="switchUserModalTab('usertab-roles')">
                                🛡️ Phân Quyền & Bảo Mật
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 1: KEYS -->
                    <div id="usertab-keys" class="user-modal-pane active">
                        <?php if (adm_can('users.add_key')): ?>
                            <!-- FORM: ADD KEY TO THIS USER -->
                            <div class="card" style="margin-bottom:16px;background:var(--surface-2);border-color:var(--border)">
                                <div class="card-header" style="padding:10px 14px">
                                    <div class="card-title" style="font-size:13px">➕ Cấp Key Mới Cho Tài Khoản Này</div>
                                </div>
                                <div class="card-body" style="padding:12px 14px">
                                    <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                        <input type="hidden" name="action" value="admin_add_user_key">
                                        <input type="hidden" name="target_user" id="m-addkey-user">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:10px;margin-bottom:10px">
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">SẢN PHẨM:</label>
                                                <select name="product" class="form-select" style="font-size:12px;padding:6px 10px">
                                                    <option value="2toolne.capcut.v2">🎬 2toolne AutoEdit for CapCut (Product V2)</option>
                                                    <option value="2TOOLNE">🚀 2toolne Studio</option>
                                                    <option value="SLIDESHOW">🎬 Slideshow Builder AI</option>
                                                    <option value="LABS_EXTENSION">🧩 Labs Extension</option>
                                                </select>
                                            </div>
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">GÓI / HẠNG KEY:</label>
                                                <select name="tier" class="form-select" style="font-size:12px;padding:6px 10px">
                                                    <option value="VIP">VIP</option>
                                                    <option value="TRIAL">TRIAL (Dùng thử)</option>
                                                    <option value="LIFETIME">LIFETIME (Vĩnh viễn)</option>
                                                </select>
                                            </div>
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">THỜI HẠN (NGÀY):</label>
                                                <select name="duration_days" class="form-select" style="font-size:12px;padding:6px 10px">
                                                    <option value="3">3 ngày (Dùng thử)</option>
                                                    <option value="30" selected>30 ngày (1 tháng)</option>
                                                    <option value="90">90 ngày (3 tháng)</option>
                                                    <option value="365">365 ngày (1 năm)</option>
                                                    <option value="36500">36500 ngày (Vĩnh viễn)</option>
                                                </select>
                                            </div>
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">GHI CHÚ:</label>
                                                <input type="text" name="note" class="form-input" placeholder="Lý do cấp key..." style="font-size:12px;padding:6px 10px">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-emerald btn-sm">+ Cấp Key Ngay</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- LIST OF KEYS -->
                        <div class="table-responsive" style="max-height:300px;overflow-y:auto">
                            <table class="data-table" style="font-size:12px">
                                <thead>
                                    <tr>
                                        <th>License Key</th>
                                        <th>Sản Phẩm</th>
                                        <th>Gói</th>
                                        <th>Hạn Dùng</th>
                                        <th>HWID (Thiết Bị)</th>
                                        <th>Trạng Thái</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody id="m-user-keys-tbody">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB: WALLET & TOKENS -->
                    <?php if (adm_can('tokens.manage')): ?>
                    <div id="usertab-wallet" class="user-modal-pane">
                        <!-- OVERVIEW & METRICS -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:12px;margin-bottom:16px">
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:rgba(234, 179, 8, 0.3)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Số Dư Khả Dụng</div>
                                <div style="font-size:24px;font-weight:800;color:#facc15;margin-top:2px" id="m-wallet-balance">0</div>
                                <div style="font-size:11px;color:var(--muted-foreground)">Tokens tiêu chuẩn</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Khóa Tạm Hàng Đợi</div>
                                <div style="font-size:24px;font-weight:800;color:var(--info);margin-top:2px" id="m-wallet-reserved">0</div>
                                <div style="font-size:11px;color:var(--muted-foreground)">Tokens đang giữ chỗ</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Chế Độ Tính Phí</div>
                                <div style="font-size:16px;font-weight:700;margin-top:4px" id="m-wallet-mode">METERED</div>
                                <div style="font-size:11px;color:var(--emerald)">2K: 1 token · 4K: 2 tokens</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Gói Bản Quyền</div>
                                <div style="font-size:16px;font-weight:700;margin-top:4px;color:var(--purple)" id="m-wallet-plan">PRO</div>
                                <div style="font-size:11px;color:var(--muted-foreground)">Tối đa 3 thiết bị</div>
                            </div>
                        </div>

                        <?php if (adm_can('tokens.adjust')): ?>
                            <!-- FORM: ADJUST WALLET TOKENS -->
                            <div class="card" style="margin-bottom:16px;background:var(--surface-2);border-color:var(--border)">
                                <div class="card-header" style="padding:10px 14px">
                                    <div class="card-title" style="font-size:13px">⚡ Điều Chỉnh / Cộng / Trừ Token Cho User Này</div>
                                </div>
                                <div class="card-body" style="padding:12px 14px">
                                    <form method="POST" id="m-wallet-adjust-form" onsubmit="handleAjaxAdjustTokens(event, this)">
                                        <input type="hidden" name="action" value="admin_adjust_user_tokens">
                                        <input type="hidden" name="target_user" id="m-wallet-adjust-target-user">
                                        <div style="display:grid;grid-template-columns:180px 1fr auto;gap:10px;align-items:flex-end">
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">SỐ LƯỢNG TOKEN (+/-):</label>
                                                <input type="number" name="amount" class="form-input" placeholder="VD: 500 hoặc -100" required style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">LÝ DO ĐIỀU CHỈNH (BẮT BUỘC &ge; 5 KÝ TỰ):</label>
                                                <input type="text" name="reason" class="form-input" placeholder="Lý do điều chỉnh (lưu vào nhật ký thanh tra audit log)..." required minlength="5" style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <button type="submit" class="btn btn-warning btn-sm" style="height:35px;white-space:nowrap">
                                                💾 Lưu Biến Động
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- TRANSACTION LEDGER TABLE -->
                        <div class="card" style="margin:0;background:var(--surface-1);border-color:var(--border)">
                            <div class="card-header" style="padding:10px 14px">
                                <div class="card-title" style="font-size:13px">📜 Lịch Sử Biến Động Token Của User (credit_transactions)</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive" style="max-height:260px;overflow-y:auto">
                                    <table class="data-table" style="font-size:12px">
                                        <thead>
                                            <tr>
                                                <th>Mã GD</th>
                                                <th>Loại</th>
                                                <th>Biến Động</th>
                                                <th>Số Dư Sau</th>
                                                <th>Lý Do / Ghi Chú</th>
                                                <th>Thời Gian</th>
                                            </tr>
                                        </thead>
                                        <tbody id="m-user-tokens-tbody">
                                            <!-- Populated via JS -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- TAB: CLOUD STORAGE & QUOTA -->
                    <?php if (adm_can('users.cloud_manage') || adm_can('cloud.quota_adjust') || adm_can('cloud.view')): ?>
                    <div id="usertab-cloud" class="user-modal-pane">
                        <!-- OVERVIEW & METRICS -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:12px;margin-bottom:16px">
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:rgba(56, 189, 248, 0.35)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Hạn Mức Khả Dụng</div>
                                <div style="font-size:24px;font-weight:800;color:#38bdf8;margin-top:2px" id="m-cloud-quota-effective">5.0 GB</div>
                                <div style="font-size:11px;color:var(--muted-foreground)" id="m-cloud-space-id-sub">cs_pers_...</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Dung Lượng Đã Dùng</div>
                                <div style="font-size:24px;font-weight:800;color:var(--foreground);margin-top:2px" id="m-cloud-quota-used">0 B</div>
                                <div style="font-size:11px;color:var(--muted-foreground)" id="m-cloud-quota-pct-sub">0% dung lượng</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Dung Lượng Còn Trống</div>
                                <div style="font-size:24px;font-weight:800;color:var(--emerald);margin-top:2px" id="m-cloud-quota-free">5.0 GB</div>
                                <div style="font-size:11px;color:var(--emerald)">Khả dụng ghi file mới</div>
                            </div>
                            <div class="card" style="margin:0;padding:12px 14px;background:var(--surface-2);border-color:var(--border)">
                                <div style="font-size:11px;color:var(--muted-foreground);text-transform:uppercase;font-weight:600">Tệp Đang Lưu Trữ</div>
                                <div style="font-size:24px;font-weight:800;color:var(--purple);margin-top:2px" id="m-cloud-files-count">0</div>
                                <div style="font-size:11px;color:var(--muted-foreground)">Trong thư mục riêng</div>
                            </div>
                        </div>

                        <!-- PROGRESS BAR -->
                        <div class="card" style="margin:0 0 16px 0;padding:12px 16px;background:var(--surface-2);border-color:var(--border)">
                            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:6px">
                                <span>Tiến trình sử dụng: <b id="m-cloud-quota-bar-txt" style="color:var(--foreground)">0 B / 5 GB</b></span>
                                <span id="m-cloud-quota-bar-pct" style="font-weight:700;color:var(--emerald);font-family:var(--font-mono)">0%</span>
                            </div>
                            <div style="width:100%;height:8px;background:var(--surface-3);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,0.06)">
                                <div id="m-cloud-quota-fill-bar" style="width:0%;height:100%;background:linear-gradient(90deg, #10b981 0%, #38bdf8 100%);border-radius:999px;transition:width 0.4s ease, background 0.3s ease"></div>
                            </div>
                        </div>

                        <?php if (adm_can('cloud.quota_adjust') || adm_can('users.cloud_manage')): ?>
                            <!-- FORM: ADJUST CLOUD QUOTA -->
                            <div class="card" style="margin-bottom:16px;background:var(--surface-2);border-color:var(--border)">
                                <div class="card-header" style="padding:10px 14px">
                                    <div class="card-title" style="font-size:13px">⚡ Điều Chỉnh Hạn Mức Dung Lượng Cho User Này (+/- GB)</div>
                                </div>
                                <div class="card-body" style="padding:12px 14px">
                                    <form method="POST" id="m-cloud-adjust-form" onsubmit="handleAjaxAdjustCloudQuota(event, this)">
                                        <input type="hidden" name="action" value="admin_adjust_user_cloud_quota">
                                        <input type="hidden" name="target_user" id="m-cloud-adjust-target-user">
                                        <div style="display:grid;grid-template-columns:180px 1fr auto;gap:10px;align-items:flex-end">
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">DUNG LƯỢNG (+/- GB):</label>
                                                <input type="number" step="0.5" name="delta_gb" class="form-input" placeholder="VD: 5 hoặc -2" required style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">LÝ DO ĐIỀU CHỈNH (BẮT BUỘC &ge; 5 KÝ TỰ):</label>
                                                <input type="text" name="reason" class="form-input" placeholder="Lý do thay đổi hạn mức (lưu vào sổ cái audit ledger)..." required minlength="5" style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm" style="height:35px;white-space:nowrap">
                                                💾 Lưu Biến Động
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- CLOUD QUOTA ADJUSTMENT LEDGER TABLE -->
                        <div class="card" style="margin:0;background:var(--surface-1);border-color:var(--border)">
                            <div class="card-header" style="padding:10px 14px">
                                <div class="card-title" style="font-size:13px">📜 Sổ Cái Biến Động Dung Lượng Của User (cloud_quota_adjustments)</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive" style="max-height:260px;overflow-y:auto">
                                    <table class="data-table" style="font-size:12px">
                                        <thead>
                                            <tr>
                                                <th>Mã GD</th>
                                                <th>Loại</th>
                                                <th>Biến Động</th>
                                                <th>Hạn Mức Sau</th>
                                                <th>Lý Do / Ghi Chú</th>
                                                <th>Admin</th>
                                                <th>Thời Gian</th>
                                            </tr>
                                        </thead>
                                        <tbody id="m-user-cloud-ledger-tbody">
                                            <!-- Populated via JS -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- TAB 2: ORDERS -->
                    <div id="usertab-orders" class="user-modal-pane">
                        <div class="table-responsive" style="max-height:350px;overflow-y:auto">
                            <table class="data-table" style="font-size:12px">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn</th>
                                        <th>Gói Mua</th>
                                        <th>Số Tiền</th>
                                        <th>Trạng Thái</th>
                                        <th>Key Đã Cấp</th>
                                        <th>Ngày Tạo</th>
                                    </tr>
                                </thead>
                                <tbody id="m-user-orders-tbody">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 3: ROLES & SECURITY -->
                    <div id="usertab-roles" class="user-modal-pane">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                            <?php if (adm_can('users.role')): ?>
                                <!-- ROLE & PERMISSIONS SETTING -->
                                <div class="card" style="background:var(--surface-2);border-color:var(--border);grid-column:1 / -1">
                                    <div class="card-header" style="padding:12px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                                        <div class="card-title" style="font-size:13.5px;display:flex;align-items:center;gap:6px">
                                            🛡️ Phân Quyền Quản Trị Web (Role & Permissions)
                                        </div>
                                        <span class="text-subtle" style="font-size:11.5px">Chỉ Super Admin được sửa phân quyền</span>
                                    </div>
                                    <div class="card-body" style="padding:14px">
                                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                            <input type="hidden" name="action" value="admin_update_user_permissions">
                                            <input type="hidden" name="target_user" id="m-perms-target-user">

                                            <!-- 1. VAI TRÒ MẪU -->
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:12px;margin-bottom:14px">
                                                <div class="form-group" style="margin:0">
                                                    <label class="form-label" style="font-size:11.5px;font-weight:700">1. CHỌN VAI TRÒ MẪU (ROLE PRESET):</label>
                                                    <select name="role" id="m-role-preset-select" class="form-select" onchange="onRolePresetChange(this.value)">
                                                        <option value="user">👤 Người Dùng Thường (Không có quyền admin)</option>
                                                        <option value="super_admin">👑 Super Admin (Toàn quyền hệ thống)</option>
                                                        <option value="sales">🛒 Nhân Viên Bán Hàng / CSKH</option>
                                                        <option value="tech_support">🛠️ Kỹ Thuật Viên (Hỗ trợ kỹ thuật)</option>
                                                        <option value="content_manager">📢 Quản Trị Nội Dung & Phiên Bản</option>
                                                        <option value="custom">⚙️ Tùy Chỉnh Quyền Hạn (Custom)</option>
                                                    </select>
                                                </div>
                                                <div style="font-size:11.5px;color:var(--muted-foreground);display:flex;align-items:center;background:var(--surface-1);padding:8px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                    💡 <i>Khi chọn vai trò mẫu, các checkbox bên dưới sẽ tự động được chọn. Bạn vẫn có thể tùy ý tích/bỏ tích thêm từng quyền lẻ.</i>
                                                </div>
                                            </div>

                                            <!-- 2. LƯỚI 18 CHECKBOX QUYỀN CHI TIẾT -->
                                            <div style="margin-bottom:14px">
                                                <label class="form-label" style="font-size:11.5px;font-weight:700;margin-bottom:8px">2. TÙY CHỌN CÁC QUYỀN HẠN CHI TIẾT:</label>
                                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:10px">
                                                    <!-- NHÓM 1: ĐƠN HÀNG -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--primary);margin-bottom:6px">🛒 Đơn Hàng & Cổng Thanh Toán</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="orders.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem đơn hàng & IPN
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="gateway.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Cấu hình cổng thanh toán (SePay)
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="orders.approve" class="m-perm-cb" onchange="onPermCheckboxChange()"> Duyệt đơn chuyển khoản thủ công
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="orders.reject" class="m-perm-cb" onchange="onPermCheckboxChange()"> Từ chối / Hủy đơn
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 2: LICENSE KEYS -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--emerald);margin-bottom:6px">🔑 License Keys</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem danh sách key
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.create" class="m-perm-cb" onchange="onPermCheckboxChange()"> Tạo key thủ công
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.reset_hwid" class="m-perm-cb" onchange="onPermCheckboxChange()"> Reset HWID (đổi máy)
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.ban" class="m-perm-cb" onchange="onPermCheckboxChange()"> Khóa / Mở khóa key
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.assign" class="m-perm-cb" onchange="onPermCheckboxChange()"> Gán / Đổi user cho key
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="keys.delete" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xóa vĩnh viễn key
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 3: NGƯỜI DÙNG -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--info);margin-bottom:6px">👥 Người Dùng</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem & tìm kiếm user
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.add_key" class="m-perm-cb" onchange="onPermCheckboxChange()"> Cấp key trực tiếp
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.password" class="m-perm-cb" onchange="onPermCheckboxChange()"> Đổi mật khẩu user
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.edit" class="m-perm-cb" onchange="onPermCheckboxChange()"> Sửa thông tin hồ sơ
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.delete" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xóa tài khoản user
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.cloud_manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Quản lý dung lượng Cloud
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.role" class="m-perm-cb" onchange="onPermCheckboxChange()"> Phân quyền quản trị
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 4: VÍ & TOKEN -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid rgba(234, 179, 8, 0.35)">
                                                        <div style="font-size:11.5px;font-weight:700;color:#facc15;margin-bottom:6px">🪙 Ví & Token</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="tokens.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Quản lý token (Xem ví & lịch sử)
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="tokens.approve_order" class="m-perm-cb" onchange="onPermCheckboxChange()"> Duyệt đơn nạp token
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="tokens.adjust" class="m-perm-cb" onchange="onPermCheckboxChange()"> Điều chỉnh trực tiếp số dư token
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 5: 2TOOLNE CLOUD -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid rgba(56, 189, 248, 0.35)">
                                                        <div style="font-size:11.5px;font-weight:700;color:#38bdf8;margin-bottom:6px">☁️ 2TOOLNE Cloud (Kho Lưu Trữ)</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="cloud.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem số liệu & cụm lưu trữ
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="cloud.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Quản lý tài khoản Google Drive
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="cloud.quota_adjust" class="m-perm-cb" onchange="onPermCheckboxChange()"> Điều chỉnh hạn mức Space
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 6: HỖ TRỢ & HỆ THỐNG -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--warning);margin-bottom:6px">💡 Hỗ Trợ & Hệ Thống</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="features.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xử lý góp ý tính năng
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="bugs.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xử lý báo cáo lỗi
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="broadcast.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Cập nhật & Thông báo
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM 7: ĐỘI NHÓM (TEAMS) -->
                                                    <div style="background:var(--surface-1);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--emerald);margin-bottom:6px">🏢 Đội Nhóm (Teams)</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="teams.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem danh sách đội nhóm (Teams)
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="teams.manage" class="m-perm-cb" onchange="onPermCheckboxChange()"> Tạo, sửa, xóa & gán thành viên Team
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>

                                            <button type="submit" class="btn btn-primary btn-sm">💾 Lưu Thiết Lập Phân Quyền</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (adm_can('users.edit')): ?>
                                <!-- PROFILE EDIT -->
                                <div class="card" style="background:var(--surface-2);border-color:var(--border)">
                                    <div class="card-header" style="padding:10px 14px">
                                        <div class="card-title" style="font-size:13px">👤 Cập Nhật Thông Tin</div>
                                    </div>
                                    <div class="card-body" style="padding:14px">
                                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                            <input type="hidden" name="action" value="admin_update_user_profile">
                                            <input type="hidden" name="target_user" id="m-profile-target-user">
                                            <div class="form-group">
                                                <label class="form-label" style="font-size:11px">HỌ VÀ TÊN:</label>
                                                <input type="text" name="fullname" id="m-profile-fullname" class="form-input" style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label" style="font-size:11px">SĐT / ZALO:</label>
                                                <input type="text" name="phone" id="m-profile-phone" class="form-input" style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <button type="submit" class="btn btn-outline btn-sm">Lưu Thông Tin</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (adm_can('users.password')): ?>
                                <!-- PASSWORD CHANGE -->
                                <div class="card" style="background:var(--surface-2);border-color:var(--border)">
                                    <div class="card-header" style="padding:10px 14px">
                                        <div class="card-title" style="font-size:13px">🔐 Đặt Lại Mật Khẩu Mới</div>
                                    </div>
                                    <div class="card-body" style="padding:14px">
                                        <form method="POST" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                            <input type="hidden" name="action" value="admin_change_user_pw">
                                            <input type="hidden" name="target_user" id="m-pw-target-user">
                                            <div class="form-group">
                                                <label class="form-label" style="font-size:11px">MẬT KHẨU MỚI (TỪ 6 KÝ TỰ):</label>
                                                <input type="password" name="new_password" class="form-input" required minlength="6" style="font-size:12px;padding:6px 10px">
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Đặt Lại Mật Khẩu</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (adm_can('users.delete')): ?>
                                <!-- DANGER ZONE -->
                                <div class="card" style="background:rgba(239, 68, 68, 0.05);border-color:rgba(239, 68, 68, 0.3)">
                                    <div class="card-header" style="padding:10px 14px">
                                        <div class="card-title" style="font-size:13px;color:var(--danger)">⚠️ Vùng Nguy Hiểm</div>
                                    </div>
                                    <div class="card-body" style="padding:14px">
                                        <p class="text-muted" style="font-size:12px;margin-bottom:12px">Xóa vĩnh viễn tài khoản người dùng khỏi hệ thống.</p>
                                        <form method="POST" id="m-delete-user-form" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Bạn có CHẮC CHẮN muốn xóa vĩnh viễn tài khoản này?">
                                            <input type="hidden" name="action" value="admin_delete_user">
                                            <input type="hidden" name="target_user" id="m-delete-target-user">
                                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Xóa Vĩnh Viễn Tài Khoản</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Prepare detailed user data for client-side modal
        $users_full_data = [];
        $users_cloud_data = $users_cloud_data ?? db_get_users_cloud_data();
        $all_user_adjustments = [];
        try {
            $db = get_db();
            $adj_stmt = $db->query("
                SELECT cqa.*, cs.owner_id AS user_id
                FROM `cloud_quota_adjustments` cqa
                JOIN `cloud_spaces` cs ON cqa.cloud_space_id = cs.id
                WHERE cs.owner_type = 'USER'
                ORDER BY cqa.created_at DESC
            ");
            while ($arow = $adj_stmt->fetch(PDO::FETCH_ASSOC)) {
                $all_user_adjustments[(string)$arow['user_id']][] = $arow;
            }
        } catch (Exception $e) {}

        foreach ($users_db as $u_name => $u_data) {
            $u_id_str = (string)($u_data['id'] ?? '');
            $u_cloud = $users_cloud_data[$u_id_str] ?? null;
            $u_adjs = $all_user_adjustments[$u_id_str] ?? [];

            $u_keys = [];
            foreach ($licenses_db as $k_code => $k_lic) {
                if (($k_lic['owner_user'] ?? '') === $u_name) {
                    $u_keys[] = array_merge(['key' => $k_code], $k_lic);
                }
            }
            $u_orders = [];
            foreach ($orders_db as $o) {
                if (($o['user'] ?? '') === $u_name) {
                    $u_orders[] = $o;
                }
            }
            $u_wallet = db_get_user_wallet($u_name);
            $u_txs = db_get_user_token_transactions($u_name, 50);

            $users_full_data[$u_name] = [
                'username'              => $u_name,
                'user_id'               => $u_data['id'] ?? null,
                'fullname'              => $u_data['fullname'] ?? '',
                'phone'                 => $u_data['phone'] ?? '',
                'role'                  => $u_data['role'] ?? 'user',
                'permissions'           => $u_data['permissions'] ?? [],
                'registered_ip'         => $u_data['registered_ip'] ?? '',
                'created_at'            => $u_data['created_at'] ?? '',
                'keys'                  => $u_keys,
                'orders'                => $u_orders,
                'token_balance'         => (int)($u_wallet['balance'] ?? 0),
                'token_reserved'        => (int)($u_wallet['reserved_balance'] ?? ($u_wallet['reserved'] ?? 0)),
                'credit_mode'           => $u_wallet['credit_mode'] ?? 'METERED',
                'plan'                  => $u_wallet['plan'] ?? 'free',
                'token_transactions'    => $u_txs,
                'cloud_space_id'        => $u_cloud['space_id'] ?? ('cs_pers_' . substr(md5($u_id_str . '_2toolne_cloud'), 0, 16)),
                'cloud_effective_quota' => (int)($u_cloud['effective_quota_bytes'] ?? 5368709120),
                'cloud_used_bytes'      => (int)($u_cloud['used_bytes'] ?? 0),
                'cloud_reserved_bytes'  => (int)($u_cloud['reserved_bytes'] ?? 0),
                'cloud_base_quota'      => (int)($u_cloud['base_quota_bytes'] ?? 5368709120),
                'cloud_adjustment_bytes'=> (int)($u_cloud['admin_adjustment_bytes'] ?? 0),
                'cloud_files_count'     => (int)($u_cloud['files_count'] ?? 0),
                'cloud_adjustments'     => $u_adjs
            ];
        }
        ?>
        <script>
            const USERS_DATA = <?= json_encode($users_full_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const ROLE_PRESETS = <?= json_encode($ROLE_PRESETS, JSON_UNESCAPED_UNICODE) ?>;

            function onRolePresetChange(role) {
                const preset = ROLE_PRESETS[role];
                const checkboxes = document.querySelectorAll('.m-perm-cb');
                if (role === 'super_admin') {
                    checkboxes.forEach(cb => cb.checked = true);
                } else if (role === 'user') {
                    checkboxes.forEach(cb => cb.checked = false);
                } else if (preset && Array.isArray(preset.perms)) {
                    checkboxes.forEach(cb => {
                        cb.checked = preset.perms.includes(cb.value);
                    });
                }
            }

            function onPermCheckboxChange() {
                const checkboxes = document.querySelectorAll('.m-perm-cb');
                const checkedValues = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                const select = document.getElementById('m-role-preset-select');
                if (!select) return;

                if (checkedValues.length === 0) {
                    select.value = 'user';
                    return;
                }
                if (checkedValues.length === checkboxes.length) {
                    select.value = 'super_admin';
                    return;
                }

                // Check preset matches
                for (const rKey of ['sales', 'tech_support', 'content_manager']) {
                    const p = ROLE_PRESETS[rKey];
                    if (p && Array.isArray(p.perms)) {
                        const s1 = [...checkedValues].sort().join(',');
                        const s2 = [...p.perms].sort().join(',');
                        if (s1 === s2) {
                            select.value = rKey;
                            return;
                        }
                    }
                }
                select.value = 'custom';
            }
        </script>

        <!-- TOAST -->
        <div id="toast">📋 Đã sao chép!</div>

        <script>
            const CURRENT_ADMIN_PERMS = <?= json_encode([
                'orders_view'          => adm_can('orders.view'),
                'orders_approve'       => adm_can('orders.approve'),
                'orders_reject'        => adm_can('orders.reject'),
                'keys_view'            => adm_can('keys.view'),
                'keys_create'          => adm_can('keys.create'),
                'keys_reset_hwid'      => adm_can('keys.reset_hwid'),
                'keys_ban'             => adm_can('keys.ban'),
                'keys_assign'          => adm_can('keys.assign'),
                'keys_delete'          => adm_can('keys.delete'),
                'users_view'           => adm_can('users.view'),
                'users_add_key'        => adm_can('users.add_key'),
                'users_password'       => adm_can('users.password'),
                'users_edit'           => adm_can('users.edit'),
                'users_delete'         => adm_can('users.delete'),
                'users_role'           => adm_can('users.role'),
                'tokens_manage'        => adm_can('tokens.manage'),
                'tokens_adjust'        => adm_can('tokens.adjust'),
                'tokens_approve_order' => adm_can('tokens.approve_order'),
                'features_manage'      => adm_can('features.manage'),
                'bugs_manage'          => adm_can('bugs.manage'),
                'broadcast_manage'     => adm_can('broadcast.manage'),
                'cloud_view'           => adm_can('cloud.view'),
                'cloud_manage'         => adm_can('cloud.manage'),
                'cloud_quota_adjust'   => adm_can('cloud.quota_adjust'),
                'users_cloud_manage'   => adm_can('users.cloud_manage')
            ]) ?>;

            function openModal(id) {
                const el = document.getElementById(id);
                if (el) el.classList.add('active');
            }
            function closeModal(id) {
                const el = document.getElementById(id);
                if (el) el.classList.remove('active');
            }
            function showToast(text) {
                const t = document.getElementById('toast');
                if (t) {
                    t.textContent = text;
                    t.style.display = 'flex';
                    setTimeout(() => { t.style.display = 'none'; }, 2200);
                }
            }
            function copyText(txt) {
                navigator.clipboard.writeText(txt).then(() => {
                    showToast('📋 Đã sao chép: ' + txt);
                }).catch(() => {
                    showToast('📋 Đã sao chép: ' + txt);
                });
            }

            function openAssignModal(key, currentOwner) {
                document.getElementById('assign-key-input').value = key;
                document.getElementById('assign-key-label').textContent = key;
                document.getElementById('assign-user-select').value = currentOwner || '';
                openModal('modal-admin-assign');
            }

            function openAdminPwModal(username) {
                document.getElementById('admin-target-user-input').value = username;
                document.getElementById('admin-target-user-label').textContent = username;
                openModal('modal-admin-pw');
            }

            function switchAdminTab(tabId, btnId) {
                document.querySelectorAll('.admin-tab-content').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.admin-tab-btn').forEach(el => el.classList.remove('active'));
                const t = document.getElementById(tabId);
                const b = document.getElementById(btnId);
                if (t) t.classList.add('active');
                if (b) b.classList.add('active');
            }

            function filterKeysByProduct(prodType, btnEl) {
                document.querySelectorAll('.subtab-prod-btn').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline');
                });
                btnEl.classList.add('active', 'btn-primary');
                btnEl.classList.remove('btn-outline');

                document.querySelectorAll('.lic-row').forEach(row => {
                    if (prodType === 'ALL' || row.getAttribute('data-product') === prodType) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            function filterKeyTable() {
                const q = document.getElementById('key-search-input').value.toLowerCase().trim();
                document.querySelectorAll('.lic-row').forEach(row => {
                    const searchStr = row.getAttribute('data-search') || '';
                    if (!q || searchStr.includes(q)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            function switchUserModalTab(paneId) {
                document.querySelectorAll('.user-modal-pane').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.modal-subnav-btn').forEach(b => b.classList.remove('active'));
                const p = document.getElementById(paneId);
                if (p) p.classList.add('active');
                const btn = document.getElementById('btn-' + paneId);
                if (btn) btn.classList.add('active');
            }

            function escapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatBytes(bytes) {
                if (bytes === null || bytes === undefined || isNaN(bytes)) return '0 B';
                bytes = Number(bytes);
                if (bytes <= 0) return '0 B';
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let i = 0;
                while (bytes >= 1024 && i < units.length - 1) {
                    bytes /= 1024;
                    i++;
                }
                return (i === 0 ? bytes : bytes.toFixed(i >= 3 ? 2 : 1)) + ' ' + units[i];
            }

            function openUserManageModal(username, initialTab = 'usertab-keys') {
                const u = (typeof USERS_DATA !== 'undefined') ? USERS_DATA[username] : null;
                if (!u) {
                    alert('Không tìm thấy thông tin của user: ' + username);
                    return;
                }
                if (initialTab === 'usertab-wallet' && !CURRENT_ADMIN_PERMS.tokens_manage) {
                    initialTab = 'usertab-keys';
                }
                if (initialTab === 'usertab-cloud' && !CURRENT_ADMIN_PERMS.users_cloud_manage && !CURRENT_ADMIN_PERMS.cloud_quota_adjust && !CURRENT_ADMIN_PERMS.cloud_view) {
                    initialTab = 'usertab-keys';
                }

                document.getElementById('m-user-username').textContent = u.username;
                const roleBadge = document.getElementById('m-user-role-badge');
                if (roleBadge) {
                    const roleTitles = {
                        'super_admin': '👑 SUPER ADMIN',
                        'admin': '👑 SUPER ADMIN',
                        'sales': '🛒 BÁN HÀNG',
                        'tech_support': '🛠️ KỸ THUẬT',
                        'content_manager': '📢 NỘI DUNG',
                        'custom': '⚙️ TÙY CHỈNH',
                        'user': '👤 USER'
                    };
                    const roleClasses = {
                        'super_admin': 'badge-purple',
                        'admin': 'badge-purple',
                        'sales': 'badge-emerald',
                        'tech_support': 'badge-info',
                        'content_manager': 'badge-warning',
                        'custom': 'badge-primary',
                        'user': 'badge-outline'
                    };
                    roleBadge.textContent = roleTitles[u.role] || '👤 USER';
                    roleBadge.className = 'badge ' + (roleClasses[u.role] || 'badge-outline');
                }
                document.getElementById('m-user-subinfo').textContent =
                    (u.fullname || 'Chưa cập nhật họ tên') + ' • SĐT: ' + (u.phone || 'N/A') + ' • IP: ' + (u.registered_ip || 'N/A') + ' • Đăng ký: ' + (u.created_at || 'N/A');

                document.getElementById('m-user-keys-count').textContent = u.keys.length;
                document.getElementById('m-user-orders-count').textContent = u.orders.length;

                // Token wallet metrics
                const tokenBal = Number(u.token_balance || 0);
                const tokenRes = Number(u.token_reserved || 0);
                const tokenTxs = Array.isArray(u.token_transactions) ? u.token_transactions : [];
                if (document.getElementById('m-user-tokens-count')) {
                    document.getElementById('m-user-tokens-count').textContent = tokenBal.toLocaleString('vi-VN');
                }
                if (document.getElementById('m-wallet-balance')) {
                    document.getElementById('m-wallet-balance').textContent = tokenBal.toLocaleString('vi-VN');
                }
                if (document.getElementById('m-wallet-reserved')) {
                    document.getElementById('m-wallet-reserved').textContent = tokenRes.toLocaleString('vi-VN');
                }
                if (document.getElementById('m-wallet-mode')) {
                    document.getElementById('m-wallet-mode').textContent = u.credit_mode || 'METERED';
                }
                if (document.getElementById('m-wallet-plan')) {
                    document.getElementById('m-wallet-plan').textContent = (u.plan || 'free').toUpperCase();
                }
                if (document.getElementById('m-wallet-adjust-target-user')) {
                    document.getElementById('m-wallet-adjust-target-user').value = u.username;
                }

                if (document.getElementById('m-addkey-user')) document.getElementById('m-addkey-user').value = u.username;
                if (document.getElementById('m-profile-target-user')) document.getElementById('m-profile-target-user').value = u.username;
                if (document.getElementById('m-profile-fullname')) document.getElementById('m-profile-fullname').value = u.fullname || '';
                if (document.getElementById('m-profile-phone')) document.getElementById('m-profile-phone').value = u.phone || '';
                if (document.getElementById('m-pw-target-user')) document.getElementById('m-pw-target-user').value = u.username;
                if (document.getElementById('m-delete-target-user')) document.getElementById('m-delete-target-user').value = u.username;

                // Sync Permissions Tab
                if (document.getElementById('m-perms-target-user')) document.getElementById('m-perms-target-user').value = u.username;
                const presetSelect = document.getElementById('m-role-preset-select');
                if (presetSelect) {
                    const uRole = (u.role === 'admin') ? 'super_admin' : (u.role || 'user');
                    presetSelect.value = uRole;

                    const checkboxes = document.querySelectorAll('.m-perm-cb');
                    if (uRole === 'super_admin') {
                        checkboxes.forEach(cb => cb.checked = true);
                    } else if (uRole === 'user') {
                        checkboxes.forEach(cb => cb.checked = false);
                    } else {
                        const uPerms = Array.isArray(u.permissions) ? u.permissions : [];
                        checkboxes.forEach(cb => {
                            cb.checked = uPerms.includes(cb.value);
                        });
                    }
                }

                // Render Keys table
                const keysTbody = document.getElementById('m-user-keys-tbody');
                if (u.keys.length === 0) {
                    keysTbody.innerHTML = '<tr><td colspan="7" class="empty-state" style="padding:24px;text-align:center">User này chưa sở hữu License Key nào.</td></tr>';
                } else {
                    keysTbody.innerHTML = u.keys.map(k => {
                        const isBanned = k.status === 'banned';
                        const hwidLabel = k.hwid ? `<code style="color:var(--info);font-size:11px">${k.hwid.substring(0, 10)}...</code>` : '<span style="color:var(--emerald);font-size:11px">Chưa kích hoạt</span>';
                        const statusBadge = isBanned ? '<span class="badge badge-danger">BANNED</span>' : '<span class="badge badge-active">ACTIVE</span>';

                        let keyActionBtns = '';
                        if (CURRENT_ADMIN_PERMS.keys_reset_hwid) {
                            keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Reset liên kết HWID cho key ${k.key}?">
                                <input type="hidden" name="action" value="admin_user_reset_hwid">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-outline btn-xs" title="Reset HWID">🔄 Đổi Máy</button>
                            </form>`;
                        }
                        if (CURRENT_ADMIN_PERMS.keys_ban) {
                            keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                <input type="hidden" name="action" value="admin_user_toggle_ban">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-${isBanned ? 'emerald' : 'outline'} btn-xs" title="${isBanned ? 'Mở Khóa Key' : 'Khóa Key'}">${isBanned ? '🔓 Mở' : '🔒 Khóa'}</button>
                            </form>`;
                        }
                        if (CURRENT_ADMIN_PERMS.keys_delete) {
                            keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xác nhận xóa vĩnh viễn key ${k.key}?">
                                <input type="hidden" name="action" value="admin_user_delete_key">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-danger btn-xs" title="Xóa Key">🗑️</button>
                            </form>`;
                        }
                        if (!keyActionBtns) keyActionBtns = '<span class="text-subtle" style="font-size:11px">-</span>';

                        const isCapCutKey = (k.product === '2toolne.capcut.v2' || k.product === 'CAPCUT_V2' || (k.key && k.key.startsWith('2TL-CAP-')));
                        const modalDisplayKey = k.key;
                        const keyCopyButtonOrBadge = `<button type="button" class="btn btn-outline btn-xs" onclick="copyText('${k.key}')" title="Sao chép">📋</button>`;

                        return `<tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:4px">
                                    <code class="font-mono" style="color:var(--info);font-size:11.5px;font-weight:600">${modalDisplayKey}</code>
                                    ${keyCopyButtonOrBadge}
                                </div>
                            </td>
                            <td><span class="badge ${isCapCutKey ? 'badge-purple' : ''}">${k.product}</span></td>
                            <td><span class="badge badge-purple">${k.tier}</span></td>
                            <td style="font-size:11.5px;color:var(--muted-foreground)">${k.expires_at ? k.expires_at : (k.duration_days + ' ngày')}</td>
                            <td class="m-user-key-hwid">${hwidLabel}</td>
                            <td class="m-user-key-status">${statusBadge}</td>
                            <td>
                                <div style="display:flex;gap:4px;flex-wrap:wrap">
                                    ${keyActionBtns}
                                </div>
                            </td>
                        </tr>`;
                    }).join('');
                }

                // Render Orders table
                const ordersTbody = document.getElementById('m-user-orders-tbody');
                if (u.orders.length === 0) {
                    ordersTbody.innerHTML = '<tr><td colspan="6" class="empty-state" style="padding:24px;text-align:center">Chưa có giao dịch / đơn hàng nào từ user này.</td></tr>';
                } else {
                    ordersTbody.innerHTML = u.orders.map(o => {
                        let stBadge = '<span class="badge badge-warning">CHỜ DUYỆT</span>';
                        if (o.status === 'approved') stBadge = '<span class="badge badge-active">ĐÃ DUYỆT</span>';
                        if (o.status === 'rejected') stBadge = '<span class="badge badge-danger">ĐÃ TỪ CHỐI</span>';

                        return `<tr>
                            <td><code style="font-size:11.5px">${o.id}</code></td>
                            <td><b>${o.package_name}</b></td>
                            <td style="color:var(--emerald);font-weight:600">${o.package_price}</td>
                            <td>${stBadge}</td>
                            <td>${(o.issued_key || o.assigned_key) ? `<code class="font-mono" style="color:var(--info);font-size:11px">${o.issued_key || o.assigned_key}</code>` : '<span class="text-subtle">-</span>'}</td>
                            <td class="text-subtle" style="font-size:11.5px">${o.created_at}</td>
                        </tr>`;
                    }).join('');
                }

                // Render Token Transactions table
                const tokensTbody = document.getElementById('m-user-tokens-tbody');
                if (tokensTbody) {
                    if (tokenTxs.length === 0) {
                        tokensTbody.innerHTML = '<tr><td colspan="6" class="empty-state" style="padding:24px;text-align:center">Chưa có giao dịch token nào cho user này.</td></tr>';
                    } else {
                        tokensTbody.innerHTML = tokenTxs.map(t => {
                            const delta = Number(t.amount !== undefined ? t.amount : (t.delta || 0));
                            const deltaStr = (delta > 0 ? '+' : '') + delta.toLocaleString('vi-VN');
                            const deltaClass = delta > 0 ? 'text-emerald' : (delta < 0 ? 'text-danger' : 'text-subtle');
                            const afterBal = Number(t.balance_after || 0).toLocaleString('vi-VN');
                            const tTypeUpper = String(t.type || '').toUpperCase();
                            let tTypeBadge = `<span class="badge badge-outline">${escapeHtml(t.type || 'N/A')}</span>`;
                            if (tTypeUpper === 'UPSCALE 4K' || (tTypeUpper === 'UPSCALE' && delta === -2)) {
                                tTypeBadge = '<span class="badge badge-purple" style="font-weight:700">🚀 UPSCALE 4K</span>';
                            } else if (tTypeUpper === 'UPSCALE 2K' || (tTypeUpper === 'UPSCALE' && delta === -1)) {
                                tTypeBadge = '<span class="badge badge-info" style="font-weight:700">⚡ UPSCALE 2K</span>';
                            } else if (tTypeUpper.includes('UPSCALE')) {
                                tTypeBadge = '<span class="badge badge-purple">UPSCALE</span>';
                            } else if (t.type === 'COMMIT') {
                                tTypeBadge = '<span class="badge badge-purple">COMMIT</span>';
                            } else if (t.type === 'RESERVE') {
                                tTypeBadge = '<span class="badge badge-warning">RESERVE</span>';
                            } else if (t.type === 'RELEASE' || t.type === 'REFUND' || t.type === 'RESERVATION_RELEASE') {
                                tTypeBadge = '<span class="badge badge-info">' + escapeHtml(t.type) + '</span>';
                            } else if (t.type === 'ADMIN_ADJUST' || t.type === 'ADMIN_ADJUSTMENT' || t.type === 'PROMOTION' || t.type === 'UPGRADE') {
                                tTypeBadge = '<span class="badge badge-emerald">' + escapeHtml(t.type) + '</span>';
                            }

                            let desc = t.description || t.reason || t.metadata_json || '-';
                            if (desc === 'Local image upscale completed') {
                                desc = (delta === -2) ? '4K upscale completed' : ((delta === -1) ? '2K upscale completed' : desc);
                            }

                            return `<tr>
                                <td><code style="font-size:11px">#${escapeHtml(t.id)}</code></td>
                                <td>${tTypeBadge}</td>
                                <td class="${deltaClass}" style="font-weight:700">${deltaStr}</td>
                                <td><b>${afterBal}</b></td>
                                <td style="max-width:240px;word-break:break-word;font-size:12px">${escapeHtml(desc)}</td>
                                <td class="text-subtle" style="font-size:11px">${escapeHtml(t.created_at || '-')}</td>
                            </tr>`;
                        }).join('');
                    }
                }

                // Render Cloud Storage Quota & Metrics
                const cEff = Number(u.cloud_effective_quota || 5368709120);
                const cUsed = Number(u.cloud_used_bytes || 0);
                const cFree = Math.max(0, cEff - cUsed);
                const cFiles = Number(u.cloud_files_count || 0);
                const cPct = cEff > 0 ? Math.min(100, Math.round((cUsed / cEff) * 100)) : 0;

                const mCloudSummary = document.getElementById('m-user-cloud-summary');
                if (mCloudSummary) mCloudSummary.textContent = `${formatBytes(cUsed)} / ${formatBytes(cEff)}`;

                const mCloudEff = document.getElementById('m-cloud-quota-effective');
                if (mCloudEff) mCloudEff.textContent = formatBytes(cEff);

                const mCloudSpaceSub = document.getElementById('m-cloud-space-id-sub');
                if (mCloudSpaceSub) mCloudSpaceSub.textContent = u.cloud_space_id || 'Chưa khởi tạo';

                const mCloudUsed = document.getElementById('m-cloud-quota-used');
                if (mCloudUsed) mCloudUsed.textContent = formatBytes(cUsed);

                const mCloudPctSub = document.getElementById('m-cloud-quota-pct-sub');
                if (mCloudPctSub) mCloudPctSub.textContent = `${cPct}% hạn mức`;

                const mCloudFree = document.getElementById('m-cloud-quota-free');
                if (mCloudFree) mCloudFree.textContent = formatBytes(cFree);

                const mCloudFiles = document.getElementById('m-cloud-files-count');
                if (mCloudFiles) mCloudFiles.textContent = cFiles.toLocaleString('vi-VN');

                const mCloudBarTxt = document.getElementById('m-cloud-quota-bar-txt');
                if (mCloudBarTxt) mCloudBarTxt.textContent = `${formatBytes(cUsed)} / ${formatBytes(cEff)}`;

                const mCloudBarPct = document.getElementById('m-cloud-quota-bar-pct');
                if (mCloudBarPct) {
                    mCloudBarPct.textContent = `${cPct}%`;
                    mCloudBarPct.style.color = (cPct >= 90) ? '#ef4444' : ((cPct >= 70) ? '#f59e0b' : 'var(--emerald)');
                }

                const mCloudFillBar = document.getElementById('m-cloud-quota-fill-bar');
                if (mCloudFillBar) {
                    mCloudFillBar.style.width = `${Math.max(2, cPct)}%`;
                    mCloudFillBar.style.background = (cPct >= 90) ? '#ef4444' : ((cPct >= 70) ? '#f59e0b' : 'linear-gradient(90deg, #10b981 0%, #38bdf8 100%)');
                }

                const mCloudAdjustTarget = document.getElementById('m-cloud-adjust-target-user');
                if (mCloudAdjustTarget) mCloudAdjustTarget.value = u.username;

                // Render Cloud Ledger
                const cloudLedgerTbody = document.getElementById('m-user-cloud-ledger-tbody');
                if (cloudLedgerTbody) {
                    const adjs = Array.isArray(u.cloud_adjustments) ? u.cloud_adjustments : [];
                    if (adjs.length === 0) {
                        cloudLedgerTbody.innerHTML = '<tr><td colspan="7" class="empty-state" style="padding:20px;text-align:center">Chưa có lịch sử điều chỉnh hạn mức nào.</td></tr>';
                    } else {
                        cloudLedgerTbody.innerHTML = adjs.map(a => {
                            const dBytes = Number(a.delta_bytes || 0);
                            const dGb = Math.round((dBytes / 1073741824) * 100) / 100;
                            const dStr = (dGb > 0 ? '+' : '') + dGb + ' GB';
                            const dClass = dGb > 0 ? 'text-emerald' : (dGb < 0 ? 'text-danger' : 'text-subtle');
                            const afterGb = Math.round((Number(a.new_effective_bytes || 0) / 1073741824) * 100) / 100 + ' GB';
                            return `
                                <tr>
                                    <td><code style="font-size:11px">${escapeHtml(a.id || 'N/A')}</code></td>
                                    <td><span class="badge ${a.type === 'ADMIN_GRANT' ? 'badge-emerald' : 'badge-danger'}">${escapeHtml(a.type || 'ADJUST')}</span></td>
                                    <td class="${dClass}" style="font-weight:700">${dStr}</td>
                                    <td><b>${afterGb}</b></td>
                                    <td style="max-width:240px;word-break:break-word;font-size:12px">${escapeHtml(a.reason || '')}</td>
                                    <td><span class="badge badge-purple" style="font-size:10.5px">${escapeHtml(a.created_by || 'Admin')}</span></td>
                                    <td class="text-subtle" style="font-size:11px">${escapeHtml(a.created_at || '')}</td>
                                </tr>
                            `;
                        }).join('');
                    }
                }

                switchUserModalTab(initialTab || 'usertab-keys');
                openModal('modal-manage-user');
            }

            async function handleAjaxAdjustCloudQuota(event, form) {
                event.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '⏳ Đang lưu...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const targetUser = formData.get('target_user');

                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();

                    if (data.status === 'success') {
                        showToast(data.message || '✅ Đã cập nhật hạn mức Cloud!');
                        const newBytes = Number(data.new_effective_bytes || 0);

                        // Update modal UI
                        const mEff = document.getElementById('m-cloud-quota-effective');
                        if (mEff) mEff.textContent = formatBytes(newBytes);
                        const mSummary = document.getElementById('m-user-cloud-summary');

                        if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                            USERS_DATA[targetUser].cloud_effective_quota = newBytes;
                            const cUsed = Number(USERS_DATA[targetUser].cloud_used_bytes || 0);
                            const cFree = Math.max(0, newBytes - cUsed);
                            const cPct = newBytes > 0 ? Math.min(100, Math.round((cUsed / newBytes) * 100)) : 0;
                            
                            const mUsed = document.getElementById('m-cloud-quota-used');
                            if (mUsed) mUsed.textContent = formatBytes(cUsed);
                            const mFree = document.getElementById('m-cloud-quota-free');
                            if (mFree) mFree.textContent = formatBytes(cFree);
                            const mPctSub = document.getElementById('m-cloud-quota-pct-sub');
                            if (mPctSub) mPctSub.textContent = `${cPct}% hạn mức`;

                            const mBarTxt = document.getElementById('m-cloud-quota-bar-txt');
                            if (mBarTxt) mBarTxt.textContent = `${formatBytes(cUsed)} / ${formatBytes(newBytes)}`;
                            const mBarPct = document.getElementById('m-cloud-quota-bar-pct');
                            if (mBarPct) {
                                mBarPct.textContent = `${cPct}%`;
                                mBarPct.style.color = (cPct >= 90) ? '#ef4444' : ((cPct >= 70) ? '#f59e0b' : 'var(--emerald)');
                            }
                            const mFillBar = document.getElementById('m-cloud-quota-fill-bar');
                            if (mFillBar) {
                                mFillBar.style.width = `${Math.max(2, cPct)}%`;
                                mFillBar.style.background = cPct >= 90 ? '#ef4444' : (cPct >= 70 ? '#f59e0b' : 'linear-gradient(90deg, #10b981 0%, #38bdf8 100%)');
                            }
                            if (mSummary) mSummary.textContent = `${formatBytes(cUsed)} / ${formatBytes(newBytes)}`;

                            // Update row in main user table
                            const rowTxt = document.getElementById(`user-cloud-txt-${targetUser}`);
                            if (rowTxt) rowTxt.textContent = `☁️ ${formatBytes(cUsed)}`;
                            const rowTot = document.getElementById(`user-cloud-tot-${targetUser}`);
                            if (rowTot) rowTot.textContent = `/ ${formatBytes(newBytes)}`;
                            const rowBar = document.getElementById(`user-cloud-bar-${targetUser}`);
                            if (rowBar) {
                                rowBar.style.width = `${Math.max(3, cPct)}%`;
                                rowBar.style.background = cPct >= 90 ? '#ef4444' : (cPct >= 70 ? '#f59e0b' : '#10b981');
                            }
                        }

                        // Prepend row in modal cloud ledger
                        const ledgerTbody = document.getElementById('m-user-cloud-ledger-tbody');
                        if (ledgerTbody) {
                            const deltaGb = Number(data.delta_gb || 0);
                            const deltaStr = (deltaGb > 0 ? '+' : '') + deltaGb + ' GB';
                            const deltaClass = deltaGb > 0 ? 'text-emerald' : (deltaGb < 0 ? 'text-danger' : 'text-subtle');
                            const newRowHtml = `
                                <tr style="background:rgba(56, 189, 248, 0.08);animation:fadeIn 0.4s ease">
                                    <td><code style="font-size:11px">#Vừa xong</code></td>
                                    <td><span class="badge ${deltaGb >= 0 ? 'badge-emerald' : 'badge-danger'}">${deltaGb >= 0 ? 'ADMIN_GRANT' : 'ADMIN_REDUCTION'}</span></td>
                                    <td class="${deltaClass}" style="font-weight:700">${deltaStr}</td>
                                    <td><b>${formatBytes(newBytes)}</b></td>
                                    <td style="max-width:240px;word-break:break-word;font-size:12px">${escapeHtml(data.reason || '')}</td>
                                    <td><span class="badge badge-purple" style="font-size:10.5px">Admin</span></td>
                                    <td class="text-subtle" style="font-size:11px">Vừa xong</td>
                                </tr>
                            `;
                            ledgerTbody.insertAdjacentHTML('afterbegin', newRowHtml);
                        }

                        // Reset input
                        const deltaInput = form.querySelector('[name="delta_gb"]');
                        const reasonInput = form.querySelector('[name="reason"]');
                        if (deltaInput) deltaInput.value = '';
                        if (reasonInput) reasonInput.value = '';
                    } else {
                        showToast(data.message || 'Lỗi khi điều chỉnh dung lượng', 'error');
                    }
                } catch (err) {
                    showToast('Lỗi mạng: ' + err.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = origBtnText;
                    }
                }
            }

            function filterOrderTable() {
                const q = (document.getElementById('order-search-input')?.value || '').toLowerCase().trim();
                const fromDate = document.getElementById('order-date-from')?.value || '';
                const toDate = document.getElementById('order-date-to')?.value || '';
                const status = document.getElementById('order-status-filter')?.value || '';

                let visibleCount = 0;
                const rows = document.querySelectorAll('.order-table-row');
                rows.forEach(r => {
                    const s = r.getAttribute('data-search') || '';
                    const d = r.getAttribute('data-date') || '';
                    const st = r.getAttribute('data-status') || '';

                    const matchQ = !q || s.includes(q);
                    const matchFrom = !fromDate || d >= fromDate;
                    const matchTo = !toDate || d <= toDate;
                    const matchStatus = !status || st === status;

                    if (matchQ && matchFrom && matchTo && matchStatus) {
                        r.style.display = '';
                        visibleCount++;
                    } else {
                        r.style.display = 'none';
                    }
                });

                const statusEl = document.getElementById('order-filter-status');
                if (statusEl) {
                    if (q || fromDate || toDate || status) {
                        statusEl.textContent = `Khớp ${visibleCount} / ${rows.length}`;
                        statusEl.className = 'badge badge-warning';
                    } else {
                        statusEl.textContent = `Hiển thị tất cả (${rows.length})`;
                        statusEl.className = 'badge badge-info';
                    }
                }

                const noMatch = document.getElementById('admin-orders-no-match');
                if (noMatch) {
                    noMatch.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
                }
            }

            function setAdminDatePreset(preset, btn) {
                document.querySelectorAll('.filter-date-preset-adm').forEach(b => b.classList.remove('active'));
                if (btn) btn.classList.add('active');

                const fromInput = document.getElementById('order-date-from');
                const toInput = document.getElementById('order-date-to');
                if (!fromInput || !toInput) return;

                const today = new Date();
                const formatDate = (d) => {
                    const year = d.getFullYear();
                    const month = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                };

                if (preset === 'today') {
                    const todayStr = formatDate(today);
                    fromInput.value = todayStr;
                    toInput.value = todayStr;
                } else if (preset === 'yesterday') {
                    const yest = new Date(today);
                    yest.setDate(yest.getDate() - 1);
                    const yestStr = formatDate(yest);
                    fromInput.value = yestStr;
                    toInput.value = yestStr;
                } else if (preset === '7days') {
                    const past = new Date(today);
                    past.setDate(past.getDate() - 6);
                    fromInput.value = formatDate(past);
                    toInput.value = formatDate(today);
                } else if (preset === 'this_month') {
                    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                    fromInput.value = formatDate(firstDay);
                    toInput.value = formatDate(today);
                } else { // 'all'
                    fromInput.value = '';
                    toInput.value = '';
                }

                filterOrderTable();
            }

            function filterTokenTable() {
                const q = (document.getElementById('token-search-input').value || '').toLowerCase().trim();
                let visibleCount = 0;
                const rows = document.querySelectorAll('.token-table-row');
                rows.forEach(r => {
                    const s = r.getAttribute('data-search') || '';
                    if (!q || s.includes(q)) {
                        r.style.display = '';
                        visibleCount++;
                    } else {
                        r.style.display = 'none';
                    }
                });
                const statusEl = document.getElementById('token-filter-status');
                if (statusEl) {
                    statusEl.textContent = q ? `Khớp ${visibleCount} / ${rows.length}` : 'Hiển thị tất cả';
                }
            }

            function filterUserTable() {
                const q = (document.getElementById('user-search-input').value || '').toLowerCase().trim();
                let visibleCount = 0;
                const rows = document.querySelectorAll('.user-table-row');
                rows.forEach(r => {
                    const s = r.getAttribute('data-search') || '';
                    if (!q || s.includes(q)) {
                        r.style.display = '';
                        visibleCount++;
                    } else {
                        r.style.display = 'none';
                    }
                });
                const statusEl = document.getElementById('user-filter-status');
                if (statusEl) {
                    statusEl.textContent = q ? `Khớp ${visibleCount} / ${rows.length}` : 'Hiển thị tất cả';
                }
            }

            function switchUserSubTab(subtabId) {
                document.querySelectorAll('.user-subtab-pane').forEach(el => el.style.display = 'none');
                const btnUsers = document.getElementById('btn-subtab-users');
                const btnTeams = document.getElementById('btn-subtab-teams');
                if (btnUsers) {
                    btnUsers.classList.toggle('btn-primary', subtabId === 'subtab-users');
                    btnUsers.classList.toggle('btn-outline', subtabId !== 'subtab-users');
                }
                if (btnTeams) {
                    btnTeams.classList.toggle('btn-primary', subtabId === 'subtab-teams');
                    btnTeams.classList.toggle('btn-outline', subtabId !== 'subtab-teams');
                }
                const pane = document.getElementById(subtabId);
                if (pane) pane.style.display = 'block';
            }

            function filterTeamTable() {
                const q = (document.getElementById('team-search-input')?.value || '').toLowerCase().trim();
                let visibleCount = 0;
                const rows = document.querySelectorAll('.team-table-row');
                rows.forEach(r => {
                    const s = r.getAttribute('data-search') || '';
                    if (!q || s.includes(q)) {
                        r.style.display = '';
                        visibleCount++;
                    } else {
                        r.style.display = 'none';
                    }
                });
                const statusEl = document.getElementById('teams-filter-status');
                if (statusEl) {
                    statusEl.textContent = q ? `Khớp ${visibleCount} / ${rows.length}` : 'Hiển thị tất cả';
                }
            }

            let currentActiveTeamId = null;
            let currentActiveTeamName = '';
            let currentActiveTeamData = null;

            function openCreateTeamModal() {
                openModal('modal-admin-create-team');
            }

            function openManageTeamMembersModal(teamId, teamName, slots) {
                currentActiveTeamId = teamId;
                if (teamName) currentActiveTeamName = teamName;
                
                const headerName = document.getElementById('m-team-header-name');
                const headerId = document.getElementById('m-team-header-id');
                const cardName = document.getElementById('m-team-card-name');
                const cardOwner = document.getElementById('m-team-card-owner');
                const quotaText = document.getElementById('m-team-quota-text');
                const quotaBar = document.getElementById('m-team-quota-bar');
                const slotsText = document.getElementById('m-team-slots-text');
                const slotsSub = document.getElementById('m-team-slots-sub');
                const countSpan = document.getElementById('m-team-members-count');
                const tbody = document.getElementById('m-team-members-tbody');

                if (headerName) headerName.textContent = currentActiveTeamName || teamId;
                if (headerId) headerId.textContent = teamId;
                if (cardName) cardName.textContent = currentActiveTeamName || 'Đội Nhóm';
                if (cardOwner) cardOwner.textContent = 'Đang tải chủ nhóm...';
                if (quotaText) quotaText.textContent = 'Đang tải dung lượng...';
                if (quotaBar) quotaBar.style.width = '0%';
                if (slotsText) slotsText.textContent = `Đang tải... / ${slots || 2} slots`;
                if (slotsSub) slotsSub.textContent = 'Đang kiểm tra...';
                if (countSpan) countSpan.textContent = '...';
                if (tbody) tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px">⏳ Đang tải thông tin thành viên và dung lượng...</td></tr>`;
                
                openModal('modal-admin-manage-team-members');

                fetch(`?action=get_team_details&team_id=${encodeURIComponent(teamId)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (!data || data.status !== 'success' || !data.team) {
                            if (tbody) tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--danger)">❌ Không thể tải thông tin Team</td></tr>`;
                            return;
                        }
                        const t = data.team;
                        currentActiveTeamData = t;
                        currentActiveTeamName = t.name || currentActiveTeamName;
                        if (headerName) headerName.textContent = currentActiveTeamName;
                        if (cardName) cardName.textContent = currentActiveTeamName;
                        if (cardOwner) {
                            const ownerFull = t.owner_fullname ? `${t.owner_fullname} (@${t.owner_username})` : `@${t.owner_username}`;
                            cardOwner.innerHTML = `Chủ nhóm: <b style="color:var(--emerald)">👑 ${escapeHtml(ownerFull)}</b>`;
                        }

                        // Storage Quota Card
                        const qGb = t.quota_gb || 0;
                        const uGb = t.used_gb || 0;
                        const uPct = t.used_percent || 0;
                        if (quotaText) quotaText.textContent = `☁️ ${uGb} GB / ${qGb} GB (${uPct}%)`;
                        if (quotaBar) {
                            quotaBar.style.width = `${Math.max(2, Math.min(100, uPct))}%`;
                            quotaBar.style.background = uPct >= 90 ? '#ef4444' : (uPct >= 75 ? '#f59e0b' : '#38bdf8');
                        }

                        // Also update storage in table row if exists
                        const rowUsed = document.getElementById('team-cloud-used-' + teamId);
                        const rowEff = document.getElementById('team-cloud-eff-' + teamId);
                        const rowBar = document.getElementById('team-cloud-bar-' + teamId);
                        if (rowUsed) rowUsed.textContent = `☁️ ${uGb} GB`;
                        if (rowEff) rowEff.textContent = `/ ${qGb} GB`;
                        if (rowBar) rowBar.style.width = `${Math.max(2, Math.min(100, uPct))}%`;

                        // Slots Card
                        const members = data.members || [];
                        const activeCount = members.filter(m => m.status === 'ACTIVE').length;
                        const totalSlots = parseInt(t.member_slots) || 2;
                        const emptySlots = Math.max(0, totalSlots - activeCount);
                        if (slotsText) slotsText.textContent = `👥 ${activeCount} / ${totalSlots} slots`;
                        if (slotsSub) slotsSub.textContent = emptySlots > 0 ? `${emptySlots} slot trống còn lại` : 'Đã sử dụng hết toàn bộ slot';
                        if (countSpan) countSpan.textContent = String(members.length);

                        const rowSlotBadge = document.getElementById('team-slot-badge-' + teamId);
                        if (rowSlotBadge) rowSlotBadge.textContent = `👥 ${activeCount} / ${totalSlots} slots`;

                        if (members.length === 0) {
                            if (tbody) tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted-foreground)">Chưa có thành viên nào trong nhóm.</td></tr>`;
                            return;
                        }

                        const canManageTeams = <?= adm_can('teams.manage') ? 'true' : 'false' ?>;
                        if (tbody) {
                            tbody.innerHTML = members.map(m => {
                                const isOwner = m.role === 'OWNER';
                                const isAdmin = m.role === 'ADMIN';
                                const roleBadge = isOwner 
                                    ? '<span class="badge badge-emerald" style="font-size:10px">👑 OWNER</span>' 
                                    : (isAdmin ? '<span class="badge badge-purple" style="font-size:10px">🛡️ ADMIN</span>' : '<span class="badge" style="font-size:10px;color:var(--muted-foreground)">👤 MEMBER</span>');
                                
                                let roleSelector = roleBadge;
                                if (canManageTeams) {
                                    roleSelector = `
                                        <select class="form-input" style="padding:2px 6px;font-size:11px;height:24px;width:auto;display:inline-block;border-radius:4px" onchange="adminChangeTeamMemberRole('${escapeHtml(teamId)}', '${escapeHtml(m.user_id)}', this.value, '${escapeHtml(m.username)}')">
                                            <option value="MEMBER" ${m.role === 'MEMBER' ? 'selected' : ''}>👤 Member</option>
                                            <option value="ADMIN" ${m.role === 'ADMIN' ? 'selected' : ''}>🛡️ Admin</option>
                                            <option value="OWNER" ${m.role === 'OWNER' ? 'selected' : ''}>👑 Owner</option>
                                        </select>
                                    `;
                                }

                                const statusBadge = m.status === 'ACTIVE' 
                                    ? '<span class="badge badge-emerald" style="font-size:10px">🟢 Hoạt Động</span>' 
                                    : '<span class="badge badge-warning" style="font-size:10px">🟡 Đã Mời</span>';
                                
                                const removeBtn = !isOwner ? `
                                    <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xóa user @${escapeHtml(m.username)} khỏi Team?">
                                        <input type="hidden" name="action" value="admin_remove_team_member">
                                        <input type="hidden" name="team_id" value="${escapeHtml(teamId)}">
                                        <input type="hidden" name="user_id" value="${escapeHtml(m.user_id)}">
                                        <button type="submit" class="btn btn-danger btn-xs" title="Xóa khỏi team">🗑️ Xóa</button>
                                    </form>
                                ` : '<span class="text-subtle" style="font-size:11px">Chủ nhóm</span>';

                                return `
                                    <tr>
                                        <td>
                                            <b style="color:var(--foreground);font-size:13px">${escapeHtml(m.fullname || m.username)}</b>
                                            <div style="font-size:11px;color:var(--muted-foreground)">@${escapeHtml(m.username)}</div>
                                        </td>
                                        <td>${roleSelector}</td>
                                        <td>${statusBadge}</td>
                                        <td style="font-size:11.5px;color:var(--muted-foreground)">${m.joined_at ? m.joined_at.substring(0, 10) : ''}</td>
                                        <td style="text-align:right">${removeBtn}</td>
                                    </tr>
                                `;
                            }).join('');
                        }
                    })
                    .catch(() => {
                        if (tbody) tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--danger)">❌ Lỗi kết nối tải thông tin Team</td></tr>`;
                    });
            }

            function promptRenameTeam(teamId, oldName) {
                if (!teamId) return;
                const newName = prompt('Nhập tên mới cho Team:', oldName || '');
                if (!newName || !newName.trim() || newName.trim() === oldName) return;

                const fd = new FormData();
                fd.append('action', 'admin_rename_team');
                fd.append('team_id', teamId);
                fd.append('new_name', newName.trim());
                fd.append('ajax', '1');

                fetch('license_admin.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        showToast(data.message || '🎉 Đã đổi tên Team thành công!', 'success');
                        const updatedName = data.new_name || newName.trim();
                        const rowEl = document.getElementById('team-name-text-' + teamId);
                        if (rowEl) rowEl.textContent = updatedName;

                        if (currentActiveTeamId === teamId) {
                            currentActiveTeamName = updatedName;
                            if (currentActiveTeamData) currentActiveTeamData.name = updatedName;
                            const headerName = document.getElementById('m-team-header-name');
                            const cardName = document.getElementById('m-team-card-name');
                            if (headerName) headerName.textContent = updatedName;
                            if (cardName) cardName.textContent = updatedName;
                        }
                    } else {
                        showToast((data && data.message) ? data.message : 'Lỗi đổi tên Team', 'error');
                    }
                })
                .catch(err => {
                    showToast('Lỗi kết nối đổi tên Team: ' + err, 'error');
                });
            }

            function promptRenameTeamFromModal() {
                if (!currentActiveTeamId) return;
                promptRenameTeam(currentActiveTeamId, currentActiveTeamName);
            }

            function promptAdjustTeamQuotaFromModal() {
                if (!currentActiveTeamData || !currentActiveTeamData.cloud_space_id) {
                    showToast('Không tìm thấy Mã Không Gian Cloud của team này!', 'warning');
                    return;
                }
                openAdjustQuotaForSpace(currentActiveTeamData.cloud_space_id, currentActiveTeamName);
            }

            function promptAddTeamSlotsFromModal() {
                if (!currentActiveTeamId) return;
                promptAddTeamSlots(currentActiveTeamId, currentActiveTeamName);
            }

            function confirmDeleteTeamPermanently(teamId, teamName) {
                if (!teamId) return;
                const ok = confirm(`⚠️ CẢNH BÁO XÓA VĨNH VIỄN TEAM:\n\nBạn có CHẮC CHẮN muốn XÓA VĨNH VIỄN Team "${teamName}"?\n\nToàn bộ dữ liệu thành viên, quyền hạn và Không gian Cloud lưu trữ của Team sẽ bị xóa hoàn toàn khỏi cơ sở dữ liệu!`);
                if (!ok) return;

                const fd = new FormData();
                fd.append('action', 'admin_delete_team');
                fd.append('team_id', teamId);
                fd.append('permanent', '1');
                fd.append('ajax', '1');

                fetch('license_admin.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        showToast(data.message || '🗑️ Đã xóa vĩnh viễn Team thành công!', 'success');
                        const row = document.getElementById('team-row-' + teamId);
                        if (row) {
                            row.style.transition = 'all 0.35s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'translateX(20px)';
                            setTimeout(() => row.remove(), 350);
                        }
                        if (currentActiveTeamId === teamId) {
                            closeModal('modal-admin-manage-team-members');
                        }
                    } else {
                        showToast((data && data.message) ? data.message : 'Lỗi xóa Team', 'error');
                    }
                })
                .catch(err => {
                    showToast('Lỗi kết nối xóa Team: ' + err, 'error');
                });
            }

            function confirmDeleteTeamPermanentlyFromModal() {
                if (!currentActiveTeamId) return;
                confirmDeleteTeamPermanently(currentActiveTeamId, currentActiveTeamName);
            }

            function adminChangeTeamMemberRole(teamId, userId, newRole, username) {
                let confirmMsg = `Đổi vai trò của @${username} sang ${newRole}?`;
                if (newRole === 'OWNER') {
                    confirmMsg = `⚠️ Chuyển giao quyền Trưởng nhóm (OWNER) cho @${username}?\nTrưởng nhóm cũ sẽ tự động trở thành ADMIN.`;
                }
                if (!confirm(confirmMsg)) {
                    openManageTeamMembersModal(teamId, currentActiveTeamName, 0);
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'admin_update_team_member_role');
                formData.append('team_id', teamId);
                formData.append('user_id', userId);
                formData.append('role', newRole);
                formData.append('ajax', '1');

                fetch('license_admin.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        showToast(data.message || '✅ Đã cập nhật vai trò thành công!', 'success');
                    } else {
                        showToast((data && data.message) ? data.message : 'Lỗi cập nhật vai trò', 'error');
                    }
                    openManageTeamMembersModal(teamId, currentActiveTeamName, 0);
                })
                .catch(err => {
                    showToast('Lỗi cập nhật vai trò: ' + err, 'error');
                    openManageTeamMembersModal(teamId, currentActiveTeamName, 0);
                });
            }

            function openAddTeamUsersPopup(teamId, teamName) {
                if (teamId) {
                    currentActiveTeamId = teamId;
                    if (teamName) currentActiveTeamName = teamName;
                }
                if (!currentActiveTeamId) return;
                document.getElementById('m-add-users-team-id').value = currentActiveTeamId;
                document.getElementById('m-add-users-team-name').textContent = `Đang gán vào: ${currentActiveTeamName}`;
                // Uncheck all
                document.querySelectorAll('.add-team-user-cb').forEach(cb => cb.checked = false);
                document.getElementById('add-team-user-search-input').value = '';
                filterAddTeamUserCheckboxes();
                updateAddTeamUserSelectedCount();
                openModal('modal-admin-add-team-users');
            }

            function filterAddTeamUserCheckboxes() {
                const q = (document.getElementById('add-team-user-search-input')?.value || '').toLowerCase().trim();
                const items = document.querySelectorAll('.add-team-user-item');
                items.forEach(item => {
                    const s = item.getAttribute('data-search') || '';
                    if (!q || s.includes(q)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            function updateAddTeamUserSelectedCount() {
                const checked = document.querySelectorAll('.add-team-user-cb:checked').length;
                const el = document.getElementById('add-team-user-selected-count');
                if (el) el.textContent = `Đã chọn: ${checked} user`;
            }

            function promptAddTeamSlots(teamId, teamName) {
                document.getElementById('m-slots-team-id').value = teamId;
                document.getElementById('m-slots-team-name').value = teamName;
                openModal('modal-admin-add-team-slots');
            }

            // ═══ USER TOKEN LOG & SETTLE MODALS ═══
            window.currentViewingTokenUser = null;
            window.currentViewingTokenUserData = null;

            function openUserTokenLogsModal(username) {
                if (!username) return;
                window.currentViewingTokenUser = username;
                const modal = document.getElementById('modal-admin-user-token-log');
                if (!modal) return;

                const userSpan = document.getElementById('m-toklog-username');
                const fullSpan = document.getElementById('m-toklog-fullname');
                const balSpan  = document.getElementById('m-toklog-balance');
                const resSpan  = document.getElementById('m-toklog-reserved');
                const conSpan  = document.getElementById('m-toklog-consumed');
                const tbody    = document.getElementById('m-toklog-tbody');

                if (userSpan) userSpan.textContent = '@' + username;
                if (fullSpan) fullSpan.textContent = 'Đang tải thông tin...';
                if (balSpan) balSpan.textContent = '...';
                if (resSpan) resSpan.textContent = '...';
                if (conSpan) conSpan.textContent = '...';
                if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted-foreground)">⏳ Đang tải lịch sử giao dịch...</td></tr>';

                openModal('modal-admin-user-token-log');

                fetch('?action=get_user_token_logs&username=' + encodeURIComponent(username))
                    .then(res => res.json())
                    .then(res => {
                        if (res.status !== 'success' || !res.user) {
                            if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--danger)">❌ ${escapeHtml(res.message || 'Không thể tải lịch sử token')}</td></tr>`;
                            return;
                        }
                        const u = res.user;
                        window.currentViewingTokenUserData = u;
                        if (fullSpan) fullSpan.textContent = u.fullname ? `${u.fullname} (#ID: ${u.id})` : `#ID: ${u.id}`;
                        if (balSpan) balSpan.textContent = Number(u.token_balance || 0).toLocaleString('vi-VN') + ' tokens';
                        if (resSpan) resSpan.textContent = Number(u.token_reserved || 0).toLocaleString('vi-VN') + ' tokens';
                        if (conSpan) conSpan.textContent = Number(u.token_consumed || 0).toLocaleString('vi-VN') + ' tokens';

                        const txs = res.transactions || [];
                        if (txs.length === 0) {
                            if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted-foreground)">Chưa có giao dịch biến động số dư nào.</td></tr>';
                            return;
                        }

                        if (tbody) {
                            tbody.innerHTML = txs.map(tx => {
                                const delta = Number(tx.delta || tx.amount || 0);
                                const deltaSign = delta > 0 ? '+' : '';
                                const deltaColor = delta > 0 ? 'var(--emerald)' : (delta < 0 ? 'var(--danger)' : 'var(--muted-foreground)');
                                const typeBadge = formatTxTypeBadge(tx.type);
                                const balAfter = tx.balance_after !== null && tx.balance_after !== undefined ? Number(tx.balance_after).toLocaleString('vi-VN') : '—';
                                const createdBy = tx.created_by ? `<span class="badge badge-outline" style="font-size:10px">${escapeHtml(tx.created_by)}</span>` : '<span class="text-subtle" style="font-size:11px">Hệ thống</span>';

                                return `
                                    <tr>
                                        <td style="font-size:11.5px;color:var(--muted-foreground);white-space:nowrap">${escapeHtml(tx.created_at || '')}</td>
                                        <td>${typeBadge}</td>
                                        <td style="font-weight:700;color:${deltaColor};font-family:var(--font-mono);white-space:nowrap">${deltaSign}${delta.toLocaleString('vi-VN')}</td>
                                        <td style="font-family:var(--font-mono);font-size:12px;color:var(--foreground)">${balAfter}</td>
                                        <td style="font-size:12px;max-width:240px;word-break:break-word">${escapeHtml(tx.reason || tx.description || '—')}</td>
                                        <td>${createdBy}</td>
                                    </tr>
                                `;
                            }).join('');
                        }
                    })
                    .catch(() => {
                        if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--danger)">❌ Lỗi mạng khi tải lịch sử biến động số dư</td></tr>`;
                    });
            }

            function formatTxTypeBadge(type) {
                const t = (type || '').toUpperCase();
                if (t === 'DEPOSIT') return '<span class="badge badge-emerald" style="font-size:10px">💳 Nạp Token</span>';
                if (t === 'CONSUME') return '<span class="badge badge-primary" style="font-size:10px">✅ Tiêu Thụ</span>';
                if (t === 'REFUND') return '<span class="badge badge-info" style="font-size:10px">↩️ Hoàn Trả</span>';
                if (t === 'HOLD') return '<span class="badge badge-warning" style="font-size:10px">🔒 Tạm Khóa</span>';
                if (t === 'RELEASE') return '<span class="badge badge-info" style="font-size:10px">🔓 Mở Khóa</span>';
                if (t === 'UPSCALE') return '<span class="badge badge-purple" style="font-size:10px">⚡ Render Upscale</span>';
                if (t === 'MANUAL_ADJUST') return '<span class="badge badge-warning" style="font-size:10px">⚖️ Điều Chỉnh Admin</span>';
                if (t === 'DEDUCT') return '<span class="badge badge-danger" style="font-size:10px">🔻 Trừ Token</span>';
                return `<span class="badge badge-outline" style="font-size:10px">${escapeHtml(t || 'GIAO DỊCH')}</span>`;
            }

            function refreshCurrentTokenLog() {
                if (window.currentViewingTokenUser) {
                    openUserTokenLogsModal(window.currentViewingTokenUser);
                }
            }

            function openAdjustQuotaForSpace(spaceId, spaceName) {
                const inputId = document.getElementById('m-adj-space-id');
                const inputDelta = document.getElementById('m-adj-delta-gb');
                const inputReason = document.getElementById('m-adj-reason');
                if (inputId) inputId.value = spaceId || '';
                if (inputDelta) inputDelta.value = '';
                if (inputReason) inputReason.value = spaceName ? ('Điều chỉnh dung lượng không gian ' + spaceName) : '';
                openModal('modal-admin-adjust-quota');
            }

            function filterCloudSpacesTable() {
                const q = (document.getElementById('cadm-spaces-search-input')?.value || '').toLowerCase().trim();
                const rows = document.querySelectorAll('.cadm-space-row');
                rows.forEach(r => {
                    const s = r.getAttribute('data-search') || '';
                    r.style.display = (!q || s.includes(q)) ? '' : 'none';
                });
            }

            // Auto-open tab from URL ?tab= & handle OAuth notifications
            (function() {
                const urlParams = new URLSearchParams(location.search);
                const urlTab = urlParams.get('tab');
                const tabMap = {
                    'orders':   ['atab-orders', 'atab-btn-orders'],
                    'keys':     ['atab-keys', 'atab-btn-keys'],
                    'tokens':   ['atab-tokens', 'atab-btn-tokens'],
                    'users':    ['atab-users', 'atab-btn-users'],
                    'teams':    ['atab-teams', 'atab-btn-teams'],
                    'team':     ['atab-teams', 'atab-btn-teams'],
                    'features': ['atab-features', 'atab-btn-features'],
                    'bugs':     ['atab-bugs', 'atab-btn-bugs'],
                    'version':  ['atab-version', 'atab-btn-version'],
                    'cloud':    ['atab-cloud', 'atab-btn-cloud']
                };

                window.addEventListener('DOMContentLoaded', () => {
                    if (urlTab && tabMap[urlTab]) {
                        switchAdminTab(tabMap[urlTab][0], tabMap[urlTab][1]);
                    }
                    const urlSubTab = urlParams.get('subtab');
                    if (urlSubTab === 'teams' && typeof switchUserSubTab === 'function') {
                        switchUserSubTab('subtab-teams');
                    }

                    // Handle OAuth redirect notifications
                    if (urlParams.get('oauth_success') === '1') {
                        showToast('🎉 Kết nối Google Drive thành công! Dung lượng thực tế và thư mục lưu trữ đã được đồng bộ.', 'success', 6000);
                        if (window.history && window.history.replaceState) {
                            urlParams.delete('oauth_success');
                            const cleanQuery = urlParams.toString() ? ('?' + urlParams.toString()) : '';
                            window.history.replaceState({}, document.title, window.location.pathname + cleanQuery);
                        }
                    } else if (urlParams.get('oauth_error')) {
                        const err = urlParams.get('oauth_error');
                        showToast('❌ Lỗi kết nối Google Drive: ' + decodeURIComponent(err), 'danger', 8000);
                        if (window.history && window.history.replaceState) {
                            urlParams.delete('oauth_error');
                            const cleanQuery = urlParams.toString() ? ('?' + urlParams.toString()) : '';
                            window.history.replaceState({}, document.title, window.location.pathname + cleanQuery);
                        }
                    }
                });
            })();

            // ══════════════════════════════════════════════════════════
            // ☁️ 2TOOLNE CLOUD STORAGE ADMIN ENGINE
            // ══════════════════════════════════════════════════════════
            function formatAdminBytes(bytes) {
                if (!bytes || bytes <= 0) return '0 GB';
                const gb = bytes / (1024 * 1024 * 1024);
                if (gb >= 1024) return (gb / 1024).toFixed(2) + ' TB';
                return gb.toFixed(1) + ' GB';
            }

            function reloadCloudAdminMetrics() {
                fetch('/api/v1/cloud/admin/pool/metrics')
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success || !data.metrics) return;
                        const m = data.metrics;
                        const tPhy = document.getElementById('cadm-total-physical');
                        const uPhy = document.getElementById('cadm-used-physical');
                        const fPhy = document.getElementById('cadm-free-physical');
                        const resv = document.getElementById('cadm-reserved');
                        const safe = document.getElementById('cadm-safety-buffer');
                        const free = document.getElementById('cadm-allocatable-free');
                        const lAlc = document.getElementById('cadm-logical-allocated');
                        const lUsd = document.getElementById('cadm-logical-used');

                        if (tPhy) tPhy.textContent = formatAdminBytes(m.POOL_TOTAL_PHYSICAL);
                        if (uPhy) uPhy.textContent = formatAdminBytes(m.POOL_USED_PHYSICAL);
                        if (fPhy) fPhy.textContent = formatAdminBytes(m.POOL_FREE_PHYSICAL);
                        if (resv) resv.textContent = formatAdminBytes(m.POOL_RESERVED);
                        if (safe) safe.textContent = formatAdminBytes(m.POOL_SAFETY_BUFFER);
                        if (free) free.textContent = formatAdminBytes(m.POOL_ALLOCATABLE_FREE);
                        if (lAlc) lAlc.textContent = formatAdminBytes(m.TOTAL_LOGICAL_QUOTA_ALLOCATED);
                        if (lUsd) lUsd.textContent = formatAdminBytes(m.TOTAL_LOGICAL_USED);
                        
                        const ratioVal = document.getElementById('cadm-overcommit-val');
                        const badgeEl = document.getElementById('cadm-risk-badge');
                        if (ratioVal) {
                            ratioVal.textContent = Number(m.OVERCOMMIT_RATIO).toFixed(2) + 'x';
                        }
                        if (badgeEl) {
                            badgeEl.textContent = m.RISK_LEVEL || 'SAFE';
                            badgeEl.className = 'badge ' + (m.RISK_LEVEL === 'CRITICAL' ? 'badge-danger' : (m.RISK_LEVEL === 'WARNING' ? 'badge-warning' : 'badge-active'));
                        }
                        showToast('✅ Đã làm mới số liệu Cụm Lưu Trữ Cloud');
                    })
                    .catch(() => showToast('Lỗi cập nhật số liệu Cloud', 'danger'));
            }

            function setAccountStatus(accountId, newStatus) {
                const confirmMsg = (newStatus === 'DRAINING')
                    ? 'Chuyển tài khoản sang trạng thái DRAINING: Tài khoản này sẽ KHÔNG nhận thêm tệp tải lên mới, nhưng các tệp đã lưu vẫn đọc/tải về bình thường. Tiếp tục?'
                    : `Bạn có muốn đổi trạng thái tài khoản sang "${newStatus}"?`;
                if (!confirm(confirmMsg)) return;

                fetch(`/api/v1/cloud/admin/accounts/${accountId}/status`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: newStatus })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast(`✅ Đã cập nhật trạng thái sang ${newStatus}!`);
                        const statusSpan = document.getElementById(`cadm-account-status-${accountId}`);
                        if (statusSpan) {
                            if (newStatus === 'ACTIVE') {
                                statusSpan.innerHTML = '<span class="badge badge-active" style="font-size:11px;padding:3px 8px">● Active</span>';
                            } else if (newStatus === 'DRAINING') {
                                statusSpan.innerHTML = '<span class="badge badge-warning" style="font-size:11px;padding:3px 8px">⏳ Draining</span>';
                            } else if (newStatus === 'DISABLED') {
                                statusSpan.innerHTML = '<span class="badge badge-danger" style="font-size:11px;padding:3px 8px">🚫 Disabled</span>';
                            }
                        }
                        // Update action buttons on this row
                        const actionsWrap = document.getElementById(`cadm-account-actions-${accountId}`);
                        if (actionsWrap) {
                            actionsWrap.querySelectorAll('.btn-status-action').forEach(b => b.remove());
                            let newBtns = '';
                            if (newStatus === 'ACTIVE') {
                                newBtns = `
                                    <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#f59e0b;border-color:rgba(245,158,11,0.3)" onclick="setAccountStatus('${accountId}', 'DRAINING')" title="Chuyển sang DRAINING: Không nhận file mới, chỉ đọc">Rút (Drain)</button>
                                    <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)" onclick="setAccountStatus('${accountId}', 'DISABLED')" title="Vô hiệu hóa tài khoản">Khóa</button>
                                `;
                            } else if (newStatus === 'DRAINING') {
                                newBtns = `
                                    <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:var(--emerald);border-color:rgba(16,185,129,0.3)" onclick="setAccountStatus('${accountId}', 'ACTIVE')" title="Kích hoạt lại">Kích Hoạt</button>
                                    <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:#94a3b8;border-color:rgba(148,163,184,0.3)" onclick="setAccountStatus('${accountId}', 'DISABLED')" title="Vô hiệu hóa tài khoản">Khóa</button>
                                `;
                            } else if (newStatus === 'DISABLED') {
                                newBtns = `
                                    <button type="button" class="btn btn-outline btn-xs btn-status-action" style="color:var(--emerald);border-color:rgba(16,185,129,0.3)" onclick="setAccountStatus('${accountId}', 'ACTIVE')" title="Mở khóa và kích hoạt lại">Mở Khóa</button>
                                `;
                            }
                            const disconnBtn = actionsWrap.querySelector('button:last-child');
                            if (disconnBtn) {
                                disconnBtn.insertAdjacentHTML('beforebegin', newBtns);
                            } else {
                                actionsWrap.insertAdjacentHTML('beforeend', newBtns);
                            }
                        }
                        if (typeof reloadCloudAdminMetrics === 'function') reloadCloudAdminMetrics();
                    } else {
                        showToast(data.message || 'Lỗi cập nhật', 'danger');
                    }
                })
                .catch(() => showToast('Lỗi mạng', 'danger'));
            }

            function disconnectAccountSafe(accountId, usedBytes) {
                if (usedBytes > 0) {
                    alert('⚠️ BẢO VỆ AN TOÀN (Safe Disconnect Guard):\nTài khoản này hiện vẫn còn ' + formatAdminBytes(usedBytes) + ' dữ liệu của người dùng. Vui lòng chuyển sang DRAINING và di chuyển toàn bộ dữ liệu trước khi ngắt kết nối!');
                    return;
                }
                if (!confirm('Bạn có chắc chắn muốn ngắt kết nối vĩnh viễn tài khoản lưu trữ này khỏi Cụm?')) return;

                fetch(`/api/v1/cloud/admin/accounts/${accountId}/disconnect`, { method: 'POST' })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('✅ Đã ngắt kết nối tài khoản an toàn!');
                            const row = document.getElementById(`cadm-account-row-${accountId}`);
                            if (row) {
                                row.style.transition = 'all 0.4s ease';
                                row.style.opacity = '0';
                                row.style.transform = 'translateX(20px)';
                                setTimeout(() => row.remove(), 400);
                            }
                            if (typeof reloadCloudAdminMetrics === 'function') reloadCloudAdminMetrics();
                        } else {
                            showToast(data.message || 'Không thể ngắt kết nối', 'danger');
                        }
                    })
                    .catch(() => showToast('Lỗi mạng', 'danger'));
            }

            function startGoogleOAuthConnect(e) {
                if (e) e.preventDefault();
                const btn = document.getElementById('m-sa-submit-btn');
                const aliasInput = document.getElementById('m-sa-label');
                const bufferInput = document.getElementById('m-sa-buffer');

                const alias = aliasInput ? aliasInput.value.trim() : 'Google Drive Primary';
                const buffer = bufferInput ? parseInt(bufferInput.value, 10) : 10;

                if (!alias) {
                    showToast('Vui lòng nhập Tên định danh cho tài khoản', 'warning');
                    return;
                }

                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '⏳ Đang chuyển hướng tới Google...';
                }

                const connectUrl = '/api/v1/admin/cloud/google/connect?alias=' + encodeURIComponent(alias) + '&safety_reserve=' + encodeURIComponent(buffer);
                window.location.href = connectUrl;
            }

            function refreshAccountUsage(accountId, btn) {
                const origText = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '⏳';
                }

                fetch(`/api/v1/cloud/admin/accounts/${encodeURIComponent(accountId)}/refresh-usage`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(res => res.json())
                .then(data => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                    if (data.success) {
                        showToast(`✅ ${data.message || 'Đã làm mới dung lượng thành công!'}`);
                        if (data.used_capacity_bytes !== undefined && data.total_capacity_bytes !== undefined) {
                            const tot = Number(data.total_capacity_bytes);
                            const use = Number(data.used_capacity_bytes);
                            const pct = tot > 0 ? ((use / tot) * 100).toFixed(1) : 0;
                            const usedEl = document.getElementById(`cadm-account-used-${accountId}`);
                            if (usedEl) {
                                usedEl.innerHTML = `${formatAdminBytes(use)} <span style="font-size:11px;color:var(--muted-foreground);font-weight:normal">(${pct}%)</span>`;
                            }
                            const barEl = document.getElementById(`cadm-account-bar-${accountId}`);
                            if (barEl) {
                                barEl.style.width = Math.min(100, pct) + '%';
                                barEl.style.background = pct > 90 ? 'var(--danger)' : (pct > 75 ? 'var(--warning)' : 'var(--emerald)');
                            }
                            const totEl = document.getElementById(`cadm-account-tot-${accountId}`);
                            if (totEl) {
                                totEl.textContent = formatAdminBytes(tot);
                            }
                        }
                        if (typeof reloadCloudAdminMetrics === 'function') reloadCloudAdminMetrics();
                    } else {
                        showToast(data.message || 'Không thể làm mới dung lượng', 'danger');
                    }
                })
                .catch(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                    showToast('Lỗi mạng khi gọi API làm mới dung lượng', 'danger');
                });
            }

            function checkAccountHealth(accountId, btn) {
                const origText = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '🩺 ...';
                }

                fetch(`/api/v1/cloud/admin/accounts/${encodeURIComponent(accountId)}/health-check`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(res => res.json())
                .then(data => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                    const hSpan = document.getElementById(`cadm-account-health-${accountId}`);
                    if (data.success && data.health_status === 'HEALTHY') {
                        showToast(`✅ ${data.message || 'Tài khoản hoạt động rất tốt!'} (${data.response_time_ms}ms)`);
                        if (hSpan) {
                            hSpan.className = 'badge badge-active';
                            hSpan.textContent = `HEALTHY (${data.response_time_ms}ms)`;
                        }
                    } else {
                        showToast(`⚠️ Cảnh báo sức khỏe: ${data.error_message || data.message || 'Trạng thái bất thường'}`, 'danger', 6000);
                        if (hSpan) {
                            hSpan.className = 'badge badge-danger';
                            hSpan.textContent = data.health_status || 'DEGRADED';
                        }
                    }
                    if (typeof reloadCloudAdminMetrics === 'function') reloadCloudAdminMetrics();
                })
                .catch(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origText;
                    }
                    showToast('Lỗi mạng khi kiểm tra kết nối tài khoản', 'danger');
                });
            }

            function submitAdjustQuota(e) {
                e.preventDefault();
                const btn = document.getElementById('m-adj-submit-btn');
                const spaceId = document.getElementById('m-adj-space-id').value.trim();
                const deltaGb = parseFloat(document.getElementById('m-adj-delta-gb').value);
                const reason = document.getElementById('m-adj-reason').value.trim();

                if (!spaceId) {
                    showToast('Vui lòng nhập Space ID', 'warning');
                    return;
                }
                if (isNaN(deltaGb) || deltaGb === 0) {
                    showToast('Mức điều chỉnh GB phải khác 0', 'warning');
                    return;
                }
                if (!reason) {
                    showToast('Lý do điều chỉnh là BẮT BUỘC', 'danger');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '⏳ Đang ghi nhận...';

                const deltaBytes = Math.round(deltaGb * 1024 * 1024 * 1024);

                fetch(`/api/v1/cloud/admin/spaces/${encodeURIComponent(spaceId)}/adjust-quota`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        delta_bytes: deltaBytes,
                        reason: reason
                    })
                })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.textContent = '💾 Xác Nhận Điều Chỉnh';
                    if (data.success) {
                        showToast('🎉 Đã điều chỉnh hạn mức và ghi nhật ký Audit Ledger thành công!');
                        closeModal('modal-admin-adjust-quota');
                        // Prepend row in audit ledger
                        const ledgerTbody = document.getElementById('cadm-ledger-tbody');
                        if (ledgerTbody) {
                            const deltaSign = deltaGb > 0 ? '+' : '';
                            const deltaColor = deltaGb > 0 ? 'var(--emerald)' : 'var(--danger)';
                            const deltaBg = deltaGb > 0 ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
                            const deltaBorder = deltaGb > 0 ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)';
                            const newEffectiveFormatted = formatAdminBytes(data.new_effective_bytes || deltaBytes);
                            const newRow = `
                                <tr style="background:rgba(16,185,129,0.08);animation:fadeIn 0.5s ease">
                                    <td style="padding:14px 16px"><code class="font-mono text-subtle" style="font-size:11px">#Vừa xong</code></td>
                                    <td style="padding:14px 16px"><div class="font-mono text-subtle" style="font-size:11px">${escapeHtml(spaceId)}</div></td>
                                    <td style="padding:14px 16px">
                                        <span class="badge" style="background:${deltaBg};color:${deltaColor};border:1px solid ${deltaBorder};font-weight:700;font-family:var(--font-mono)">
                                            ${deltaSign}${deltaGb} GB
                                        </span>
                                    </td>
                                    <td style="padding:14px 16px" class="font-mono" style="font-weight:600">${newEffectiveFormatted}</td>
                                    <td style="padding:14px 16px;font-size:12.5px">${escapeHtml(reason)}</td>
                                    <td style="padding:14px 16px"><span class="badge badge-info" style="font-size:11px">👤 ADMIN</span></td>
                                    <td style="padding:14px 16px;font-size:12px;color:var(--muted-foreground)">Vừa xong</td>
                                </tr>
                            `;
                            ledgerTbody.insertAdjacentHTML('afterbegin', newRow);
                        }
                        if (typeof reloadCloudAdminMetrics === 'function') reloadCloudAdminMetrics();
                        if (window.currentActiveTeamData && window.currentActiveTeamData.cloud_space_id === spaceId) {
                            if (typeof openManageTeamMembersModal === 'function') {
                                openManageTeamMembersModal(window.currentActiveTeamId, window.currentActiveTeamName, 0);
                            }
                        }
                    } else {
                        showToast(data.message || 'Không thể điều chỉnh hạn mức', 'danger');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.textContent = '💾 Xác Nhận Điều Chỉnh';
                    showToast('Lỗi mạng khi điều chỉnh hạn mức', 'danger');
                });
            }

            // ══════════════════════════════════════════════════════════
            // ⚡ FETCH API & REAL-TIME DASHBOARD ENGINE
            // ══════════════════════════════════════════════════════════

            // 1. TOAST NOTIFICATION SYSTEM
            function showToast(message, type = 'success', duration = 3500) {
                let container = document.getElementById('admin-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'admin-toast-container';
                    container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:999999;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:380px;width:calc(100% - 40px)';
                    document.body.appendChild(container);
                }

                const toast = document.createElement('div');
                toast.style.cssText = 'pointer-events:auto;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 10px 25px rgba(0,0,0,0.5);display:flex;align-items:flex-start;justify-content:space-between;gap:10px;transition:all 0.3s cubic-bezier(0.16, 1, 0.3, 1);transform:translateX(50px);opacity:0;line-height:1.45;word-break:break-word;backdrop-filter:blur(10px)';

                let bg = 'rgba(15, 23, 42, 0.95)', border = '#334155', color = '#f8fafc', icon = 'ℹ️';
                if (type === 'success') {
                    bg = 'rgba(6, 78, 59, 0.95)';
                    border = '#10b981';
                    color = '#ecfdf5';
                    icon = '✅';
                } else if (type === 'error') {
                    bg = 'rgba(127, 29, 29, 0.95)';
                    border = '#ef4444';
                    color = '#fef2f2';
                    icon = '❌';
                } else if (type === 'warning') {
                    bg = 'rgba(120, 53, 15, 0.95)';
                    border = '#f59e0b';
                    color = '#fffbeb';
                    icon = '⚠️';
                }

                toast.style.background = bg;
                toast.style.border = '1px solid ' + border;
                toast.style.color = color;

                toast.innerHTML = `
                    <div style="display:flex;gap:10px;align-items:flex-start">
                        <span style="font-size:16px;line-height:1">${icon}</span>
                        <div>${message}</div>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:${color};cursor:pointer;font-size:14px;opacity:0.6;padding:0;line-height:1">&times;</button>
                `;

                container.appendChild(toast);
                requestAnimationFrame(() => {
                    toast.style.transform = 'translateX(0)';
                    toast.style.opacity = '1';
                });

                if (duration > 0) {
                    setTimeout(() => {
                        toast.style.transform = 'translateX(50px)';
                        toast.style.opacity = '0';
                        setTimeout(() => toast.remove(), 300);
                    }, duration);
                }
            }

            // 2. LIVE IPN LOG REFRESH
            async function refreshSepayLogs(btn) {
                const origText = btn ? btn.textContent : '';
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = '⏳ Đang tải...';
                }
                try {
                    const res = await fetch('license_admin.php?action=get_sepay_logs', { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data && data.status === 'success') {
                        document.querySelectorAll('.sepay-logs-title').forEach(el => {
                            el.textContent = `📜 Nhật Ký IPN Webhook Nhận Được Gần Nhất (${data.count} sự kiện)`;
                        });
                        document.querySelectorAll('.sepay-logs-container').forEach(c => {
                            if (!data.logs || data.logs.length === 0) {
                                c.innerHTML = '<div style="color:#64748b;font-style:italic">Chưa có thông báo IPN nào được ghi nhận. Khi có giao dịch qua SePay, các sự kiện webhook tự động xử lý và kết quả sẽ hiển thị chi tiết tại đây.</div>';
                            } else {
                                c.innerHTML = data.logs.map(line => `
                                    <div style="margin-bottom:3px;white-space:pre-wrap;border-bottom:1px dashed #1e293b;padding-bottom:2px">
                                        ${escapeHtml(line)}
                                    </div>
                                `).join('');
                            }
                        });
                        showToast(`✅ Đã cập nhật nhật ký IPN mới nhất (${data.count} sự kiện)!`, 'success', 2500);
                    } else {
                        showToast(data.message || 'Lỗi khi tải log', 'error');
                    }
                } catch (e) {
                    showToast('Lỗi mạng khi tải nhật ký IPN: ' + e.message, 'error');
                } finally {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                }
            }

            // 3. LIVE ORDER APPROVAL / REJECTION
            async function handleAjaxOrderAction(e, form, isReject = false) {
                e.preventDefault();
                const ordId = form.querySelector('[name="order_id"]')?.value || '';
                if (isReject && !confirm(`Xác nhận hủy đơn hàng ${ordId}?`)) {
                    return false;
                }

                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '⏳ Đang xử lý...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const data = await res.json();

                    if (data && data.status === 'success') {
                        showToast(data.html_message || data.message, 'success');

                        // 1. Remove pending row with animation
                        const pendingRows = document.querySelectorAll(`#pending-order-row-${ordId}, #pending-token-order-row-${ordId}`);
                        pendingRows.forEach(r => {
                            r.style.transition = 'all 0.35s ease';
                            r.style.opacity = '0';
                            r.style.transform = 'translateX(20px)';
                            setTimeout(() => {
                                r.remove();
                                // Check if pending table is empty
                                const tbody = document.getElementById('pending-orders-tbody');
                                if (tbody && tbody.querySelectorAll('.pending-order-row').length === 0) {
                                    const wrapper = document.getElementById('pending-orders-table-wrapper');
                                    const emptyState = document.getElementById('pending-orders-empty-state');
                                    if (wrapper) wrapper.style.display = 'none';
                                    if (emptyState) emptyState.style.display = 'block';
                                }
                                const tokTbody = document.getElementById('pending-token-orders-tbody');
                                if (tokTbody && tokTbody.querySelectorAll('.pending-token-order-row').length === 0) {
                                    const tokCard = document.getElementById('card-pending-token-orders');
                                    if (tokCard) tokCard.style.display = 'none';
                                }
                            }, 350);
                        });

                        // 2. Decrement pending badges and UI elements
                        let curPendingCount = 0;
                        const match = document.querySelector('.badge-pending-count')?.textContent.match(/\d+/);
                        if (match) {
                            curPendingCount = Math.max(0, parseInt(match[0]) - 1);
                        }
                        lastKnownPendingCount = curPendingCount;
                        updatePendingUIElements(curPendingCount);

                        // 3. Update main orders table row
                        const mainRow = document.getElementById(`main-order-row-${ordId}`);
                        if (mainRow) {
                            const badge = mainRow.querySelector('.order-status-badge');
                            if (badge) {
                                if (isReject) {
                                    badge.className = 'badge order-status-badge badge-danger';
                                    badge.textContent = '❌ ĐÃ HỦY';
                                    mainRow.setAttribute('data-status', 'rejected');
                                } else {
                                    badge.className = 'badge order-status-badge badge-active';
                                    badge.textContent = '✅ ĐÃ DUYỆT';
                                    mainRow.setAttribute('data-status', 'approved');
                                }
                            }
                            const resultTd = mainRow.querySelector('.order-result-td');
                            if (resultTd && data.assigned_label) {
                                resultTd.innerHTML = `<code class="font-mono" style="font-size:11px;color:var(--emerald)">${escapeHtml(data.assigned_label)}</code>`;
                            }
                        }
                    } else {
                        showToast(data.message || 'Lỗi khi xử lý đơn hàng', 'error');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = origBtnText;
                        }
                    }
                } catch (err) {
                    showToast('Lỗi kết nối máy chủ: ' + err.message, 'error');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = origBtnText;
                    }
                }
            }

            // 4. LIVE ADJUST USER TOKENS (MODAL)
            async function handleAjaxAdjustTokens(e, form) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '⏳ Đang lưu...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const data = await res.json();

                    if (data && data.status === 'success') {
                        showToast(data.html_message || data.message, 'success');

                        const newBalFormatted = Number(data.new_balance || 0).toLocaleString('vi-VN');
                        const targetUser = data.target_user || '';

                        // Update numbers in modal
                        const mBal = document.getElementById('m-wallet-balance');
                        if (mBal) mBal.textContent = newBalFormatted;
                        const mTokCount = document.getElementById('m-user-tokens-count');
                        if (mTokCount) mTokCount.textContent = newBalFormatted;

                        // Prepend row in modal ledger
                        const tokensTbody = document.getElementById('m-user-tokens-tbody');
                        if (tokensTbody) {
                            const delta = Number(data.delta || 0);
                            const deltaStr = (delta > 0 ? '+' : '') + delta.toLocaleString('vi-VN');
                            const deltaClass = delta > 0 ? 'text-emerald' : (delta < 0 ? 'text-danger' : 'text-subtle');
                            const newRowHtml = `
                                <tr style="background:rgba(16, 185, 129, 0.08);animation:fadeIn 0.4s ease">
                                    <td><code style="font-size:11px">#Vừa xong</code></td>
                                    <td><span class="badge badge-emerald">ADMIN_ADJUST</span></td>
                                    <td class="${deltaClass}" style="font-weight:700">${deltaStr}</td>
                                    <td><b>${newBalFormatted}</b></td>
                                    <td style="max-width:240px;word-break:break-word;font-size:12px">${escapeHtml(data.reason || '')}</td>
                                    <td class="text-subtle" style="font-size:11px">Vừa xong</td>
                                </tr>
                            `;
                            tokensTbody.insertAdjacentHTML('afterbegin', newRowHtml);
                        }

                        // Update USERS_DATA in memory
                        if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                            USERS_DATA[targetUser].token_balance = Number(data.new_balance || 0);
                        }

                        // Update user row in main Tokens tab table
                        const userRow = document.getElementById(`token-table-row-${targetUser}`);
                        if (userRow) {
                            const balEl = userRow.querySelector('.user-token-balance-val');
                            if (balEl) balEl.innerHTML = `🪙 ${newBalFormatted}`;
                        }

                        // Reset input fields
                        const amtInput = form.querySelector('[name="amount"]');
                        const reasonInput = form.querySelector('[name="reason"]');
                        if (amtInput) amtInput.value = '';
                        if (reasonInput) reasonInput.value = '';
                    } else {
                        showToast(data.message || 'Lỗi khi cập nhật token', 'error');
                    }
                } catch (err) {
                    showToast('Lỗi mạng: ' + err.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = origBtnText;
                    }
                }
            }

            // 5. LIVE CREATE LICENSE KEY
            async function handleAjaxCreateKey(e, form) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '⏳ Đang tạo...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const data = await res.json();

                    if (data && data.status === 'success') {
                        if (data.is_capcut) {
                            showToast(`
                                <div style="max-width:440px;text-align:left">
                                    ${data.html_message || data.message}
                                    <div style="margin-top:10px;display:flex;gap:8px">
                                        <button type="button" class="btn btn-emerald btn-xs" onclick="copyText('${data.new_key}')">📋 Sao Chép Key Nhanh (Chỉ hiện 1 lần!)</button>
                                    </div>
                                </div>
                            `, 'warning', 25000);
                        } else {
                            showToast(`
                                <div>
                                    ${data.html_message || data.message}
                                    <div style="margin-top:6px">
                                        <button type="button" class="btn btn-primary btn-xs" onclick="copyText('${data.new_key}')">📋 Sao Chép Key Nhanh</button>
                                    </div>
                                </div>
                            `, 'success', 6000);
                        }

                        // Prepend row in licenses table
                        const tbody = document.getElementById('licenses-tbody');
                        if (tbody) {
                            const isCapCut = !!data.is_capcut;
                            const displayKey = data.new_key;
                            const copyBadgeOrBtn = `<button type="button" class="btn btn-outline btn-xs" onclick="copyText('${data.new_key}')" title="Sao chép">📋</button>`;
                            const actionCopyBtn = `<button type="button" class="btn btn-primary btn-xs" onclick="copyText('${data.new_key}')">📋 Sao Chép</button>`;

                            const newKeyRow = `
                                <tr style="background:rgba(16, 185, 129, 0.08);animation:fadeIn 0.5s ease" class="license-row" data-product="${escapeHtml(data.product)}" data-search="${(displayKey + ' ' + (data.owner_user || '') + ' ' + (data.note || '')).toLowerCase()}">
                                    <td>
                                        <div style="display:flex;align-items:center;gap:6px">
                                            <code class="font-mono text-primary" style="font-weight:700;font-size:12.5px">${displayKey}</code>
                                            ${copyBadgeOrBtn}
                                        </div>
                                    </td>
                                    <td><span class="badge ${isCapCut ? 'badge-purple' : ''}">${escapeHtml(data.product)}</span></td>
                                    <td><span class="badge badge-purple">${escapeHtml(data.tier)}</span></td>
                                    <td style="font-size:12px">${data.duration_days} ngày</td>
                                    <td style="font-size:12px">${data.owner_user ? `<b>${escapeHtml(data.owner_user)}</b>` : '<span class="text-subtle">(Chưa gán)</span>'}</td>
                                    <td><span class="badge badge-active">HOẠT ĐỘNG</span></td>
                                    <td><span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span></td>
                                    <td style="font-size:11.5px;max-width:180px">${escapeHtml(data.note || '-')}</td>
                                    <td>
                                        ${actionCopyBtn}
                                    </td>
                                </tr>
                            `;
                            tbody.insertAdjacentHTML('afterbegin', newKeyRow);
                        }

                        // Update key counter
                        const titleEl = document.getElementById('keys-total-count-title');
                        if (titleEl) {
                            const match = titleEl.textContent.match(/\d+/);
                            if (match) {
                                const newCount = parseInt(match[0]) + 1;
                                titleEl.textContent = `🔑 Danh Sách License Key Toàn Hệ Thống (${newCount})`;
                            }
                        }

                        // Reset form note
                        const noteInput = form.querySelector('[name="note"]');
                        if (noteInput) noteInput.value = '';
                    } else {
                        showToast(data.message || 'Lỗi khi tạo key', 'error');
                    }
                } catch (err) {
                    showToast('Lỗi mạng: ' + err.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = origBtnText;
                    }
                }
            }

            // 6. LIVE SAVE SEPAY CONFIG
            async function handleAjaxSaveSepay(e, form) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const origBtnText = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = '⏳ Đang lưu...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const data = await res.json();
                    if (data && data.status === 'success') {
                        showToast('✅ Đã lưu cấu hình Cổng thanh toán SePay thành công!', 'success');
                    } else {
                        showToast(data.message || 'Lỗi khi lưu cấu hình SePay', 'error');
                    }
                } catch (err) {
                    showToast('Lỗi mạng: ' + err.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = origBtnText;
                    }
                }
            }

            // 7. UNIVERSAL ADMIN AJAX FORM HANDLER (ZERO RELOAD)
            async function handleUniversalAdminAjaxForm(e, form, options = {}) {
                if (e) e.preventDefault();
                const confirmMsg = form.getAttribute('data-confirm') || options.confirm;
                if (confirmMsg && !confirm(confirmMsg)) {
                    return false;
                }

                const submitBtn = form.querySelector('button[type="submit"]') || form.querySelector('button');
                const origHtml = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '⏳ Đang lưu...';
                }

                try {
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    const res = await fetch('license_admin.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    });
                    const data = await res.json();

                    if (data && data.status === 'success') {
                        showToast(data.html_message || data.message || 'Thao tác thành công!', 'success');
                        if (typeof options.onSuccess === 'function') {
                            options.onSuccess(data, form);
                        } else {
                            handleAutoDomUpdateForAction(formData.get('action'), formData, data, form);
                        }
                    } else {
                        showToast((data && data.message) ? data.message : 'Lỗi khi thực hiện thao tác', 'error');
                    }
                } catch (err) {
                    showToast('Lỗi kết nối máy chủ: ' + err.message, 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = origHtml;
                    }
                }
                return false;
            }

            function findLicenseKeyElements(prefix, key) {
                if (!key) return [];
                const safeKey = String(key);
                try {
                    const escaped = (window.CSS && typeof CSS.escape === 'function')
                        ? CSS.escape(prefix + '-' + safeKey)
                        : (prefix + '-' + safeKey).replace(/([!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~])/g, '\\$1');
                    const list = document.querySelectorAll('.' + escaped);
                    if (list.length > 0) return Array.from(list);
                } catch (e) {}

                // Fallback: safe scan without CSS selector parse error on asterisks (*)
                const results = [];
                document.querySelectorAll(`[class*="${prefix}"]`).forEach(el => {
                    if (el.classList.contains(prefix + '-' + safeKey)) {
                        results.push(el);
                    }
                });
                return results;
            }

            function findLicenseRowElements(key) {
                if (!key) return [];
                const safeKey = String(key);
                try {
                    const escaped = (window.CSS && typeof CSS.escape === 'function')
                        ? CSS.escape('license-row-' + safeKey)
                        : ('license-row-' + safeKey).replace(/([!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~])/g, '\\$1');
                    const list = document.querySelectorAll('.' + escaped);
                    if (list.length > 0) return Array.from(list);
                } catch (e) {}

                const results = [];
                const searchNeedle = safeKey.toLowerCase();
                document.querySelectorAll('.lic-row, .license-row').forEach(tr => {
                    if (tr.classList.contains('license-row-' + safeKey) || (tr.dataset && tr.dataset.search && tr.dataset.search.includes(searchNeedle))) {
                        results.push(tr);
                    }
                });
                return results;
            }

            function handleAutoDomUpdateForAction(action, formData, data, form) {
                switch (action) {
                    case 'reset_hwid': {
                        const key = formData.get('key');
                        findLicenseKeyElements('hwid-cell', key).forEach(el => {
                            el.innerHTML = '<span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span>';
                        });
                        break;
                    }
                    case 'toggle_ban': {
                        const key = formData.get('key');
                        findLicenseKeyElements('status-cell', key).forEach(el => {
                            const isNowBanned = el.textContent.includes('HOẠT ĐỘNG') || el.textContent.includes('ACTIVE');
                            if (isNowBanned) {
                                el.innerHTML = '<span class="badge badge-danger">BANNED</span>';
                            } else {
                                el.innerHTML = '<span class="badge badge-active">HOẠT ĐỘNG</span>';
                            }
                        });
                        const btn = form ? form.querySelector('button') : null;
                        if (btn) {
                            btn.textContent = btn.textContent.includes('Khóa') ? '🔓 Mở' : '🔒 Khóa';
                        }
                        break;
                    }
                    case 'delete_key': {
                        const key = formData.get('key');
                        findLicenseRowElements(key).forEach(tr => {
                            tr.style.transition = 'all 0.35s ease';
                            tr.style.opacity = '0';
                            tr.style.transform = 'translateX(20px)';
                            setTimeout(() => tr.remove(), 350);
                        });
                        const titleEl = document.getElementById('keys-total-count-title');
                        if (titleEl) {
                            const match = titleEl.textContent.match(/\d+/);
                            if (match) {
                                const newCount = Math.max(0, parseInt(match[0]) - 1);
                                titleEl.textContent = `🔑 Danh Sách License Key Toàn Hệ Thống (${newCount})`;
                            }
                        }
                        break;
                    }
                    case 'assign_user': {
                        const key = formData.get('key');
                        const newOwner = formData.get('new_owner') || '';
                        closeModal('modal-admin-assign');
                        findLicenseKeyElements('owner-cell', key).forEach(el => {
                            if (newOwner) {
                                el.innerHTML = `<span class="badge badge-primary">👤 ${escapeHtml(newOwner)}</span> <button class="btn btn-outline btn-xs" onclick="openAssignModal('${escapeHtml(key)}', '${escapeHtml(newOwner)}')">Đổi</button>`;
                            } else {
                                el.innerHTML = `<span class="text-subtle" style="font-size:11px">(Chưa gán)</span> <button class="btn btn-outline btn-xs" onclick="openAssignModal('${escapeHtml(key)}', '')">➕ Gán</button>`;
                            }
                        });
                        break;
                    }
                    case 'admin_user_reset_hwid': {
                        const key = formData.get('key');
                        const row = form ? form.closest('tr') : null;
                        if (row) {
                            const hwidEl = row.querySelector('.m-user-key-hwid');
                            if (hwidEl) {
                                hwidEl.innerHTML = '<span style="color:var(--emerald);font-size:11px">Chưa kích hoạt</span>';
                            } else {
                                const cells = row.querySelectorAll('td');
                                if (cells.length >= 5) cells[4].innerHTML = '<span style="color:var(--emerald);font-size:11px">Chưa kích hoạt</span>';
                            }
                        }
                        findLicenseKeyElements('hwid-cell', key).forEach(el => {
                            el.innerHTML = '<span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span>';
                        });
                        break;
                    }
                    case 'admin_user_toggle_ban': {
                        const key = formData.get('key');
                        const isNowBanned = data.new_status === 'banned';
                        const row = form ? form.closest('tr') : null;
                        if (row) {
                            const statusEl = row.querySelector('.m-user-key-status');
                            if (statusEl) {
                                statusEl.innerHTML = isNowBanned ? '<span class="badge badge-danger">BANNED</span>' : '<span class="badge badge-active">ACTIVE</span>';
                            } else {
                                const cells = row.querySelectorAll('td');
                                if (cells.length >= 6) cells[5].innerHTML = isNowBanned ? '<span class="badge badge-danger">BANNED</span>' : '<span class="badge badge-active">ACTIVE</span>';
                            }
                            const btn = form.querySelector('button');
                            if (btn) {
                                btn.className = `btn btn-${isNowBanned ? 'emerald' : 'outline'} btn-xs`;
                                btn.innerHTML = isNowBanned ? '🔓 Mở' : '🔒 Khóa';
                                btn.title = isNowBanned ? 'Mở Khóa Key' : 'Khóa Key';
                            }
                        }
                        findLicenseKeyElements('status-cell', key).forEach(el => {
                            el.innerHTML = isNowBanned ? '<span class="badge badge-danger">BANNED</span>' : '<span class="badge badge-active">HOẠT ĐỘNG</span>';
                        });
                        break;
                    }
                    case 'admin_user_delete_key': {
                        const key = formData.get('key');
                        const targetUser = formData.get('target_user');
                        const row = form ? form.closest('tr') : null;
                        if (row) {
                            row.style.transition = 'all 0.3s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 300);
                        }
                        const countEl = document.getElementById('m-user-keys-count');
                        if (countEl) {
                            countEl.textContent = Math.max(0, parseInt(countEl.textContent || '0') - 1);
                        }
                        const userKeysBadge = document.getElementById(`user-keys-badge-${targetUser}`);
                        if (userKeysBadge) {
                            const match = userKeysBadge.textContent.match(/\d+/);
                            if (match) {
                                userKeysBadge.innerHTML = `🔑 ${Math.max(0, parseInt(match[0]) - 1)} keys`;
                            }
                        }
                        findLicenseRowElements(key).forEach(tr => {
                            tr.style.transition = 'all 0.35s ease';
                            tr.style.opacity = '0';
                            tr.style.transform = 'translateX(20px)';
                            setTimeout(() => tr.remove(), 350);
                        });
                        const titleEl = document.getElementById('keys-total-count-title');
                        if (titleEl) {
                            const match = titleEl.textContent.match(/\d+/);
                            if (match) {
                                const newCount = Math.max(0, parseInt(match[0]) - 1);
                                titleEl.textContent = `🔑 Danh Sách License Key Toàn Hệ Thống (${newCount})`;
                            }
                        }
                        break;
                    }
                    case 'admin_change_user_pw': {
                        const pwInput = form.querySelector('[name="new_password"]');
                        if (pwInput) pwInput.value = '';
                        break;
                    }
                    case 'admin_update_user_profile': {
                        const targetUser = formData.get('target_user');
                        const fullname = formData.get('fullname');
                        const phone = formData.get('phone');
                        const subinfo = document.getElementById('m-user-subinfo');
                        if (subinfo) {
                            subinfo.textContent = `${fullname || '(Chưa có tên)'} • SĐT: ${phone || '(Chưa có)'}`;
                        }
                        if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                            USERS_DATA[targetUser].fullname = fullname;
                            USERS_DATA[targetUser].phone = phone;
                        }
                        const userRow = document.getElementById(`user-row-${targetUser}`);
                        if (userRow) {
                            const fnEl = userRow.querySelector('.user-cell-fullname');
                            if (fnEl) fnEl.textContent = fullname || '(Chưa có)';
                            const phEl = userRow.querySelector('.user-cell-phone');
                            if (phEl) phEl.textContent = phone || '(Chưa có)';
                        }
                        break;
                    }
                    case 'admin_update_user_permissions': {
                        const targetUser = formData.get('target_user');
                        const role = formData.get('role') || 'user';
                        const roleLabels = {
                            'super_admin': '<span class="badge badge-purple" style="font-size:11px">👑 SUPER ADMIN</span>',
                            'sales': '<span class="badge badge-emerald" style="font-size:11px">🛒 BÁN HÀNG</span>',
                            'tech_support': '<span class="badge badge-info" style="font-size:11px">🛠️ KỸ THUẬT</span>',
                            'content_manager': '<span class="badge badge-warning" style="font-size:11px">📢 NỘI DUNG</span>',
                            'custom': '<span class="badge badge-primary" style="font-size:11px">⚙️ TÙY CHỈNH</span>',
                            'user': '<span class="badge" style="font-size:11px;color:var(--muted-foreground)">👤 USER</span>'
                        };
                        const badgeHtml = roleLabels[role] || roleLabels['user'];
                        const mRoleBadge = document.getElementById('m-user-role-badge');
                        if (mRoleBadge) mRoleBadge.innerHTML = badgeHtml;

                        const userRow = document.getElementById(`user-row-${targetUser}`);
                        if (userRow) {
                            const roleCell = userRow.querySelector('.user-cell-role');
                            if (roleCell) roleCell.innerHTML = badgeHtml;
                        }

                        if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                            USERS_DATA[targetUser].role = role;
                            if (data.permissions) {
                                USERS_DATA[targetUser].permissions = data.permissions;
                            }
                        }
                        break;
                    }
                    case 'admin_delete_user':
                    case 'delete_user': {
                        const targetUser = formData.get('target_user');
                        closeModal('modal-manage-user');
                        const userRow = document.getElementById(`user-row-${targetUser}`);
                        if (userRow) {
                            userRow.style.transition = 'all 0.35s ease';
                            userRow.style.opacity = '0';
                            userRow.style.transform = 'translateX(20px)';
                            setTimeout(() => userRow.remove(), 350);
                        }
                        if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                            delete USERS_DATA[targetUser];
                        }
                        break;
                    }
                    case 'update_feature_status': {
                        const fid = formData.get('feature_id');
                        const newStatus = formData.get('status');
                        const badge = document.getElementById(`feature-status-badge-${fid}`);
                        if (badge) {
                            badge.textContent = newStatus;
                        }
                        break;
                    }
                    case 'delete_feature': {
                        const fid = formData.get('feature_id');
                        const row = document.getElementById(`feature-row-${fid}`);
                        if (row) {
                            row.style.transition = 'all 0.35s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 350);
                        }
                        break;
                    }
                    case 'update_bug_status': {
                        const bid = formData.get('bug_id');
                        const newStatus = formData.get('status');
                        const badge = document.getElementById(`bug-status-badge-${bid}`);
                        if (badge) {
                            badge.textContent = newStatus;
                        }
                        break;
                    }
                    case 'delete_bug': {
                        const bid = formData.get('bug_id');
                        const row = document.getElementById(`bug-row-${bid}`);
                        if (row) {
                            row.style.transition = 'all 0.35s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 350);
                        }
                        break;
                    }
                    case 'admin_add_user_key': {
                        if (data.new_key) {
                            const targetUser = data.target_user || formData.get('target_user') || '';
                            const tbody = document.getElementById('m-user-keys-tbody');
                            if (tbody) {
                                const emptyEl = tbody.querySelector('.empty-state');
                                if (emptyEl) tbody.innerHTML = '';

                                let keyActionBtns = '';
                                if (CURRENT_ADMIN_PERMS.keys_reset_hwid) {
                                    keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Reset liên kết HWID cho key ${data.new_key}?">
                                        <input type="hidden" name="action" value="admin_user_reset_hwid">
                                        <input type="hidden" name="key" value="${data.new_key}">
                                        <input type="hidden" name="target_user" value="${escapeHtml(targetUser)}">
                                        <button type="submit" class="btn btn-outline btn-xs" title="Reset HWID">🔄 Đổi Máy</button>
                                    </form> `;
                                }
                                if (CURRENT_ADMIN_PERMS.keys_ban) {
                                    keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                        <input type="hidden" name="action" value="admin_user_toggle_ban">
                                        <input type="hidden" name="key" value="${data.new_key}">
                                        <input type="hidden" name="target_user" value="${escapeHtml(targetUser)}">
                                        <button type="submit" class="btn btn-outline btn-xs" title="Khóa Key">🔒 Khóa</button>
                                    </form> `;
                                }
                                if (CURRENT_ADMIN_PERMS.keys_delete) {
                                    keyActionBtns += `<form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xác nhận xóa vĩnh viễn key ${data.new_key}?">
                                        <input type="hidden" name="action" value="admin_user_delete_key">
                                        <input type="hidden" name="key" value="${data.new_key}">
                                        <input type="hidden" name="target_user" value="${escapeHtml(targetUser)}">
                                        <button type="submit" class="btn btn-danger btn-xs" title="Xóa Key">🗑️</button>
                                    </form>`;
                                }
                                if (!keyActionBtns) keyActionBtns = '<span class="text-subtle" style="font-size:11px">-</span>';

                                const newRow = `
                                    <tr style="background:rgba(16, 185, 129, 0.08);animation:fadeIn 0.5s ease">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:4px">
                                                <code class="font-mono text-primary" style="font-weight:700;font-size:11.5px">${escapeHtml(data.new_key)}</code>
                                                <button type="button" class="btn btn-outline btn-xs" onclick="copyText('${data.new_key}')" title="Sao chép">📋</button>
                                            </div>
                                        </td>
                                        <td><span class="badge">${escapeHtml(data.product || '2TOOLNE')}</span></td>
                                        <td><span class="badge badge-purple">${escapeHtml(data.tier || 'VIP')}</span></td>
                                        <td style="font-size:11.5px;color:var(--muted-foreground)">${escapeHtml(String(data.duration_days || 30))} ngày</td>
                                        <td class="m-user-key-hwid"><span style="color:var(--emerald);font-size:11px">Chưa kích hoạt</span></td>
                                        <td class="m-user-key-status"><span class="badge badge-active">ACTIVE</span></td>
                                        <td>
                                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                                ${keyActionBtns}
                                            </div>
                                        </td>
                                    </tr>
                                `;
                                tbody.insertAdjacentHTML('afterbegin', newRow);
                            }
                            const countEl = document.getElementById('m-user-keys-count');
                            if (countEl) {
                                countEl.textContent = parseInt(countEl.textContent || '0') + 1;
                            }
                            const userKeysBadge = document.getElementById(`user-keys-badge-${targetUser}`);
                            if (userKeysBadge) {
                                const match = userKeysBadge.textContent.match(/\d+/);
                                const newCnt = match ? parseInt(match[0]) + 1 : 1;
                                userKeysBadge.innerHTML = `🔑 ${newCnt} keys`;
                            }
                            // Also insert in main licenses table
                            const mainTbody = document.getElementById('licenses-tbody');
                            if (mainTbody) {
                                const mainRow = `
                                    <tr style="background:rgba(16, 185, 129, 0.08);animation:fadeIn 0.5s ease" class="license-row license-row-${data.new_key}" id="license-row-${data.new_key}" data-product="${escapeHtml(data.product || '2TOOLNE')}" data-search="${(data.new_key + ' ' + (targetUser || '')).toLowerCase()}">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:6px">
                                                <code class="font-mono text-primary" style="font-weight:700;font-size:12.5px">${data.new_key}</code>
                                                <button type="button" class="btn btn-outline btn-xs" onclick="copyText('${data.new_key}')" title="Sao chép">📋</button>
                                            </div>
                                        </td>
                                        <td><span class="badge">${escapeHtml(data.product || '2TOOLNE')}</span></td>
                                        <td><span class="badge badge-purple">${escapeHtml(data.tier || 'VIP')}</span></td>
                                        <td style="font-size:12px">${data.duration_days || 30} ngày</td>
                                        <td class="owner-cell-${data.new_key}">
                                            <span class="badge badge-primary">👤 ${escapeHtml(targetUser)}</span>
                                            <button class="btn btn-outline btn-xs" onclick="openAssignModal('${data.new_key}', '${escapeHtml(targetUser)}')">Đổi</button>
                                        </td>
                                        <td class="status-cell-${data.new_key}"><span class="badge badge-active">HOẠT ĐỘNG</span></td>
                                        <td class="hwid-cell-${data.new_key}"><span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span></td>
                                        <td style="font-size:11.5px;max-width:180px">${escapeHtml(data.note || '-')}</td>
                                        <td>
                                            <div style="display:flex;gap:4px">
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)">
                                                    <input type="hidden" name="action" value="toggle_ban">
                                                    <input type="hidden" name="key" value="${data.new_key}">
                                                    <button type="submit" class="btn btn-outline btn-xs">🔒 Khóa</button>
                                                </form>
                                                <form method="POST" style="display:inline" onsubmit="handleUniversalAdminAjaxForm(event, this)" data-confirm="Xác nhận XÓA vĩnh viễn key này?">
                                                    <input type="hidden" name="action" value="delete_key">
                                                    <input type="hidden" name="key" value="${data.new_key}">
                                                    <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                `;
                                mainTbody.insertAdjacentHTML('afterbegin', mainRow);
                                const titleEl = document.getElementById('keys-total-count-title');
                                if (titleEl) {
                                    const match = titleEl.textContent.match(/\d+/);
                                    if (match) {
                                        const newCount = parseInt(match[0]) + 1;
                                        titleEl.textContent = `🔑 Danh Sách License Key Toàn Hệ Thống (${newCount})`;
                                    }
                                }
                            }
                            if (typeof USERS_DATA !== 'undefined' && USERS_DATA[targetUser]) {
                                USERS_DATA[targetUser].keys = USERS_DATA[targetUser].keys || [];
                                USERS_DATA[targetUser].keys.unshift({
                                    key: data.new_key,
                                    product: data.product || '2TOOLNE',
                                    tier: data.tier || 'VIP',
                                    duration_days: data.duration_days || 30,
                                    status: 'active',
                                    hwid: ''
                                });
                            }
                        }
                        break;
                    }
                    case 'toggle_broadcast_notice': {
                        const btn = form ? form.querySelector('button') : null;
                        if (btn) {
                            const isNowOn = btn.textContent.includes('Bật');
                            btn.textContent = isNowOn ? '🔴 Tắt Thông Báo' : '🟢 Bật Thông Báo';
                        }
                        break;
                    }
                    case 'admin_add_team_members': {
                        closeModal('modal-admin-add-team-users');
                        const tid = formData.get('team_id') || currentActiveTeamId;
                        if (tid && typeof openManageTeamMembersModal === 'function') {
                            openManageTeamMembersModal(tid, currentActiveTeamName, 0);
                        }
                        break;
                    }
                    case 'admin_remove_team_member': {
                        const tid = formData.get('team_id') || currentActiveTeamId;
                        if (tid && typeof openManageTeamMembersModal === 'function') {
                            openManageTeamMembersModal(tid, currentActiveTeamName, 0);
                        }
                        break;
                    }
                    case 'admin_update_team_member_role': {
                        const tid = formData.get('team_id') || currentActiveTeamId;
                        if (tid && typeof openManageTeamMembersModal === 'function') {
                            openManageTeamMembersModal(tid, currentActiveTeamName, 0);
                        }
                        break;
                    }
                    case 'admin_add_team_slots': {
                        closeModal('modal-admin-add-team-slots');
                        const tid = formData.get('team_id') || currentActiveTeamId;
                        if (tid && typeof openManageTeamMembersModal === 'function') {
                            openManageTeamMembersModal(tid, currentActiveTeamName, 0);
                        }
                        break;
                    }
                    case 'admin_delete_team': {
                        const tid = formData.get('team_id') || (data && data.team_id);
                        if (tid) {
                            const row = document.getElementById('team-row-' + tid);
                            if (row) {
                                row.style.transition = 'all 0.35s ease';
                                row.style.opacity = '0';
                                row.style.transform = 'translateX(20px)';
                                setTimeout(() => row.remove(), 350);
                            }
                            if (currentActiveTeamId === tid) {
                                closeModal('modal-admin-manage-team-members');
                            }
                        }
                        break;
                    }
                    case 'admin_rename_team': {
                        const tid = formData.get('team_id') || (data && data.team_id);
                        const newName = (data && data.new_name) || formData.get('new_name');
                        if (tid && newName) {
                            const rowText = document.getElementById('team-name-text-' + tid);
                            if (rowText) rowText.textContent = newName;
                            if (currentActiveTeamId === tid) {
                                currentActiveTeamName = newName;
                                if (currentActiveTeamData) currentActiveTeamData.name = newName;
                                const headerName = document.getElementById('m-team-header-name');
                                const cardName = document.getElementById('m-team-card-name');
                                if (headerName) headerName.textContent = newName;
                                if (cardName) cardName.textContent = newName;
                            }
                        }
                        break;
                    }
                    case 'admin_update_team_status': {
                        const sel = form ? form.querySelector('select[name="status"]') : null;
                        if (sel) {
                            const isAct = sel.value === 'ACTIVE';
                            sel.style.background = isAct ? 'rgba(16,185,129,0.1)' : 'rgba(245,158,11,0.1)';
                            sel.style.borderColor = isAct ? 'rgba(16,185,129,0.3)' : 'rgba(245,158,11,0.3)';
                            sel.style.color = isAct ? 'var(--emerald)' : 'var(--warning)';
                        }
                        break;
                    }
                    default:
                        break;
                }
            }

            // 8. BACKGROUND DASHBOARD POLLING & LIVE ORDER SYNC ENGINE
            const originalDocumentTitle = document.title;
            let lastKnownPendingCount = <?= isset($pending_orders) ? count($pending_orders) : 0 ?>;
            let lastKnownLogsCount = <?= isset($sepay_logs) ? count($sepay_logs) : 0 ?>;

            // Audio notification chime using Web Audio API (zero external file dependency)
            function playNotificationChime() {
                try {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtx) return;
                    const ctx = new AudioCtx();
                    if (ctx.state === 'suspended') {
                        ctx.resume();
                    }
                    const now = ctx.currentTime;
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(587.33, now); // D5
                    osc.frequency.exponentialRampToValueAtTime(880, now + 0.12); // A5

                    gain.gain.setValueAtTime(0, now);
                    gain.gain.linearRampToValueAtTime(0.2, now + 0.03);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.45);

                    osc.connect(gain);
                    gain.connect(ctx.destination);

                    osc.start(now);
                    osc.stop(now + 0.45);
                } catch (e) {
                    // Ignore autoplay restrictions
                }
            }

            // Sync all pending badges, header count, metrics, and document title
            function updatePendingUIElements(count) {
                // 1. All pending badges
                document.querySelectorAll('.badge-pending-count').forEach(b => {
                    if (count > 0) {
                        b.textContent = `⏳ ${count} đơn đang chờ duyệt tay`;
                        b.className = 'badge badge-pending-count badge-warning';
                        b.style.animation = 'pulseGlow 2s infinite';
                        b.style.background = '';
                        b.style.color = '';
                    } else {
                        b.textContent = '0 đơn chờ duyệt';
                        b.className = 'badge badge-pending-count';
                        b.style.animation = 'none';
                        b.style.background = 'rgba(255,255,255,0.06)';
                        b.style.color = 'var(--muted-foreground)';
                    }
                });

                // 2. Top header badge
                const hBadge = document.getElementById('header-pending-badge');
                const hCount = document.getElementById('header-pending-count');
                if (hBadge && hCount) {
                    hCount.textContent = count;
                    hBadge.style.display = count > 0 ? 'inline-flex' : 'none';
                }

                // 3. Metric tile
                const mPending = document.getElementById('metric-val-pending');
                if (mPending) {
                    mPending.textContent = count;
                    mPending.style.color = count > 0 ? 'var(--warning)' : 'var(--muted-foreground)';
                }

                // 4. Tab button text
                const atabText = document.getElementById('atab-text-orders');
                if (atabText) {
                    atabText.textContent = `Đơn Hàng & Cổng Thanh Toán ${count > 0 ? '(' + count + ')' : ''}`;
                }

                // 5. Browser window tab title
                if (count > 0) {
                    document.title = `(${count}) ⏳ Chờ Duyệt - 2TAMNE Admin`;
                } else {
                    document.title = originalDocumentTitle;
                }
            }

            // Live Table Synchronizer for Manual Orders Table
            function syncPendingOrdersTable(pendingOrders, canApprove, canApproveToken, canReject) {
                const tbody = document.getElementById('pending-orders-tbody');
                const emptyState = document.getElementById('pending-orders-empty-state');
                const wrapper = document.getElementById('pending-orders-table-wrapper');
                const card = document.getElementById('card-pending-orders');

                if (!tbody) return;

                if (!pendingOrders || pendingOrders.length === 0) {
                    tbody.innerHTML = '';
                    if (wrapper) wrapper.style.display = 'none';
                    if (emptyState) emptyState.style.display = 'block';
                    if (card) card.style.borderColor = 'var(--border)';
                    return;
                }

                if (emptyState) emptyState.style.display = 'none';
                if (wrapper) wrapper.style.display = 'block';
                if (card) card.style.borderColor = 'rgba(234, 179, 8, 0.45)';

                const currentRows = tbody.querySelectorAll('.pending-order-row');
                const currentIds = new Set();
                currentRows.forEach(r => {
                    const id = r.id.replace('pending-order-row-', '');
                    currentIds.add(id);
                });

                const newIds = new Set(pendingOrders.map(o => o.id));

                // 1. Remove rows that are no longer pending
                currentRows.forEach(r => {
                    const id = r.id.replace('pending-order-row-', '');
                    if (!newIds.has(id)) {
                        r.style.transition = 'all 0.3s ease';
                        r.style.opacity = '0';
                        setTimeout(() => r.remove(), 300);
                    }
                });

                // 2. Prepend newly arrived orders
                let hasNewAddition = false;
                pendingOrders.forEach(ord => {
                    if (!currentIds.has(ord.id)) {
                        hasNewAddition = true;
                        const isTok = (ord.product === 'TOKEN_WALLET') || 
                                      (ord.package_name && (ord.package_name.toLowerCase().includes('token') || ord.package_name.toLowerCase().includes('unlimited')));
                        const canApprThis = isTok ? canApproveToken : canApprove;

                        const tr = document.createElement('tr');
                        tr.id = `pending-order-row-${ord.id}`;
                        tr.className = 'pending-order-row';
                        tr.style.backgroundColor = 'rgba(234, 179, 8, 0.25)';
                        tr.style.transition = 'background-color 3s ease';

                        tr.innerHTML = `
                            <td><code class="font-mono text-primary" style="font-weight:700">${escapeHtml(ord.id)}</code></td>
                            <td>
                                <b>${escapeHtml(ord.user || '')}</b>
                                <div class="text-subtle" style="font-size:11.5px">${escapeHtml(ord.fullname || '')}${ord.phone ? ' · ' + escapeHtml(ord.phone) : ''}</div>
                            </td>
                            <td>
                                <b>${escapeHtml(ord.package_name || '')}</b>
                                <div style="font-size:11px"><span class="badge">${escapeHtml(ord.product || 'SLIDESHOW')}</span></div>
                            </td>
                            <td><b style="color:var(--emerald)">${escapeHtml(ord.package_price || '')}</b></td>
                            <td>${escapeHtml(String(ord.duration_days || '30'))} ngày (${escapeHtml(ord.tier || 'VIP')})</td>
                            <td class="text-subtle" style="font-size:12px">${escapeHtml(ord.created_at || '')}</td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    ${canApprThis ? `
                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this)">
                                            <input type="hidden" name="action" value="approve_order">
                                            <input type="hidden" name="order_id" value="${escapeHtml(ord.id)}">
                                            <button type="submit" class="btn btn-emerald btn-xs">
                                                ${isTok ? '🪙 Duyệt Nạp Token' : '⚡ Duyệt & Cấp Key'}
                                            </button>
                                        </form>
                                    ` : ''}
                                    ${canReject ? `
                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this, true)">
                                            <input type="hidden" name="action" value="reject_order">
                                            <input type="hidden" name="order_id" value="${escapeHtml(ord.id)}">
                                            <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                        </form>
                                    ` : ''}
                                </div>
                            </td>
                        `;

                        tbody.insertBefore(tr, tbody.firstChild);
                        setTimeout(() => { tr.style.backgroundColor = ''; }, 3000);
                    }
                });

                if (hasNewAddition && card) {
                    card.open = true; // Automatically expand details card
                }
            }

            // Sync Pending Token Orders Card in Token Tab
            function syncPendingTokenOrdersTable(pendingTokenOrders, canApproveToken, canReject) {
                const card = document.getElementById('card-pending-token-orders');
                const tbody = document.getElementById('pending-token-orders-tbody');
                const title = document.getElementById('card-title-pending-tokens');
                const metricTok = document.getElementById('metric-val-pending-tokens');

                const count = (pendingTokenOrders && pendingTokenOrders.length) || 0;
                if (metricTok) {
                    metricTok.textContent = count;
                    metricTok.style.color = count > 0 ? 'var(--warning)' : 'var(--muted-foreground)';
                }

                if (!card || !tbody) return;

                if (count === 0) {
                    card.style.display = 'none';
                    tbody.innerHTML = '';
                    return;
                }

                card.style.display = 'block';
                if (title) {
                    title.textContent = `⏳ Đơn Nạp Token Chờ Duyệt (${count} đơn)`;
                }

                const currentRows = tbody.querySelectorAll('.pending-token-order-row');
                const currentIds = new Set();
                currentRows.forEach(r => {
                    const id = r.id.replace('pending-token-order-row-', '');
                    currentIds.add(id);
                });

                const newIds = new Set(pendingTokenOrders.map(o => o.id));

                currentRows.forEach(r => {
                    const id = r.id.replace('pending-token-order-row-', '');
                    if (!newIds.has(id)) {
                        r.remove();
                    }
                });

                pendingTokenOrders.forEach(ord => {
                    if (!currentIds.has(ord.id)) {
                        const tr = document.createElement('tr');
                        tr.id = `pending-token-order-row-${ord.id}`;
                        tr.className = 'pending-token-order-row';
                        tr.style.backgroundColor = 'rgba(234, 179, 8, 0.25)';
                        tr.style.transition = 'background-color 3s ease';

                        tr.innerHTML = `
                            <td><code class="font-mono text-primary" style="font-weight:700">${escapeHtml(ord.id)}</code></td>
                            <td>
                                <b>${escapeHtml(ord.user || '')}</b>
                                ${ord.phone ? `<br><span class="text-subtle" style="font-size:11px">📞 ${escapeHtml(ord.phone)}</span>` : ''}
                            </td>
                            <td><span class="badge" style="background:rgba(234, 179, 8, 0.15);color:#facc15;border:1px solid rgba(234, 179, 8, 0.35);font-weight:700">${escapeHtml(ord.package_name || '')}</span></td>
                            <td style="color:var(--emerald);font-weight:700">${escapeHtml(ord.package_price || '')} đ</td>
                            <td class="text-subtle" style="font-size:11.5px">${escapeHtml(ord.created_at || '')}</td>
                            <td>
                                <div style="display:flex;gap:6px">
                                    ${canApproveToken ? `
                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this)">
                                            <input type="hidden" name="action" value="approve_order">
                                            <input type="hidden" name="order_id" value="${escapeHtml(ord.id)}">
                                            <button type="submit" class="btn btn-emerald btn-xs">🪙 Duyệt Nạp Token</button>
                                        </form>
                                    ` : ''}
                                    ${canReject ? `
                                        <form method="POST" style="display:inline" onsubmit="handleAjaxOrderAction(event, this, true)">
                                            <input type="hidden" name="action" value="reject_order">
                                            <input type="hidden" name="order_id" value="${escapeHtml(ord.id)}">
                                            <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                        </form>
                                    ` : ''}
                                </div>
                            </td>
                        `;

                        tbody.insertBefore(tr, tbody.firstChild);
                        setTimeout(() => { tr.style.backgroundColor = ''; }, 3000);
                    }
                });
            }

            // Manual Refresh Handler for Button
            async function triggerManualOrderPoll(btn) {
                const origHtml = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '⏳ Đang kiểm tra...';
                }
                await pollDashboardUpdates(true);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml || '🔄 Làm mới';
                }
            }

            // Polling Function
            async function pollDashboardUpdates(isManual = false) {
                if (document.hidden && !isManual) return; // Smart pause when tab is inactive

                try {
                    const res = await fetch('license_admin.php?action=poll_dashboard_updates', { credentials: 'same-origin' });
                    const data = await res.json();
                    if (!data || data.status !== 'success') return;

                    const newCount = data.pending_count || 0;

                    // Sync table rows
                    syncPendingOrdersTable(data.pending_orders || [], !!data.can_approve, !!data.can_approve_token, !!data.can_reject);
                    syncPendingTokenOrdersTable(data.pending_token_orders || [], !!data.can_approve_token, !!data.can_reject);

                    // Update all badges and metrics
                    updatePendingUIElements(newCount);

                    // Check pending orders change for alert & sound
                    if (lastKnownPendingCount !== null && newCount > lastKnownPendingCount) {
                        const diff = newCount - lastKnownPendingCount;
                        playNotificationChime();
                        showToast(`🔔 Có <b>${diff} đơn hàng mới</b> vừa được tạo và đang chờ duyệt!`, 'warning', 7000);
                    } else if (isManual) {
                        showToast(`✅ Đã kiểm tra xong: ${newCount} đơn đang chờ duyệt.`, 'info', 2500);
                    }
                    lastKnownPendingCount = newCount;

                    // Check IPN logs change
                    if (lastKnownLogsCount !== null && data.logs_count !== lastKnownLogsCount) {
                        document.querySelectorAll('.sepay-logs-title').forEach(el => {
                            el.textContent = `📜 Nhật Ký IPN Webhook Nhận Được Gần Nhất (${data.logs_count} sự kiện)`;
                        });
                        document.querySelectorAll('.sepay-logs-container').forEach(c => {
                            if (data.sepay_logs && data.sepay_logs.length > 0) {
                                c.innerHTML = data.sepay_logs.map(line => `
                                    <div style="margin-bottom:3px;white-space:pre-wrap;border-bottom:1px dashed #1e293b;padding-bottom:2px">
                                        ${escapeHtml(line)}
                                    </div>
                                `).join('');
                            }
                        });
                    }
                    lastKnownLogsCount = data.logs_count;
                } catch (e) {
                    if (isManual) {
                        showToast('Lỗi kiểm tra đơn mới: ' + e.message, 'error');
                    }
                }
            }

            // Initialize polling after page loads (Every 8 seconds)
            setInterval(pollDashboardUpdates, 8000);

            // Immediately poll when tab gains focus
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    pollDashboardUpdates();
                }
            });

            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-backdrop')) {
                    e.target.classList.remove('active');
                }
            });

            window.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-backdrop.active').forEach(m => {
                        m.classList.remove('active');
                    });
                }
            });
        </script>
    <?php endif; ?>

</body>
</html>
