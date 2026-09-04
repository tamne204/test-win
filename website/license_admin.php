<?php
session_start();
require_once __DIR__ . '/storage/db.php';

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
    'orders.view'      => ['label' => 'Xem đơn hàng & doanh thu', 'group' => 'Đơn Hàng'],
    'orders.approve'   => ['label' => 'Duyệt đơn & cấp key tự động', 'group' => 'Đơn Hàng'],
    'orders.reject'    => ['label' => 'Từ chối / Hủy đơn hàng', 'group' => 'Đơn Hàng'],
    'keys.view'        => ['label' => 'Xem danh sách license keys', 'group' => 'License Keys'],
    'keys.create'      => ['label' => 'Tạo mới license key thủ công', 'group' => 'License Keys'],
    'keys.reset_hwid'  => ['label' => 'Reset HWID (đổi máy)', 'group' => 'License Keys'],
    'keys.ban'         => ['label' => 'Khóa / Mở khóa key', 'group' => 'License Keys'],
    'keys.assign'      => ['label' => 'Gán / Đổi user sở hữu key', 'group' => 'License Keys'],
    'keys.delete'      => ['label' => 'Xóa vĩnh viễn key', 'group' => 'License Keys'],
    'users.view'       => ['label' => 'Xem & tìm kiếm user', 'group' => 'Người Dùng'],
    'users.add_key'    => ['label' => 'Cấp key trực tiếp cho user', 'group' => 'Người Dùng'],
    'users.password'   => ['label' => 'Đặt lại mật khẩu user', 'group' => 'Người Dùng'],
    'users.edit'       => ['label' => 'Sửa thông tin user', 'group' => 'Người Dùng'],
    'users.delete'     => ['label' => 'Xóa tài khoản user', 'group' => 'Người Dùng'],
    'users.role'       => ['label' => 'Phân quyền quản trị (Super Admin)', 'group' => 'Người Dùng'],
    'tokens.manage'        => ['label' => 'Quản lý token (Xem ví & lịch sử)', 'group' => 'Ví & Token'],
    'tokens.approve_order' => ['label' => 'Duyệt đơn nạp token', 'group' => 'Ví & Token'],
    'tokens.adjust'        => ['label' => 'Điều chỉnh trực tiếp số dư token', 'group' => 'Ví & Token'],
    'features.manage'  => ['label' => 'Xem & xử lý góp ý tính năng', 'group' => 'Góp Ý & Báo Lỗi'],
    'bugs.manage'      => ['label' => 'Xem & xử lý báo cáo lỗi', 'group' => 'Góp Ý & Báo Lỗi'],
    'broadcast.manage' => ['label' => 'Cấu hình bản cập nhật & Phát thông báo', 'group' => 'Hệ Thống']
];

$ROLE_PRESETS = [
    'super_admin' => [
        'title' => '👑 Super Admin (Toàn quyền)',
        'perms' => array_keys($ALL_PERMISSIONS)
    ],
    'sales' => [
        'title' => '🛒 Nhân Viên Bán Hàng / CSKH',
        'perms' => ['orders.view', 'orders.approve', 'orders.reject', 'keys.view', 'keys.create', 'keys.assign', 'users.view', 'users.add_key', 'tokens.manage', 'tokens.approve_order']
    ],
    'tech_support' => [
        'title' => '🛠️ Kỹ Thuật Viên (Hỗ trợ kỹ thuật)',
        'perms' => ['keys.view', 'keys.reset_hwid', 'keys.ban', 'users.view', 'bugs.manage', 'features.manage', 'tokens.manage']
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

function adm_can($perm) {
    if (empty($_SESSION['admin_logged'])) return false;
    $role = $_SESSION['admin_role'] ?? 'super_admin';
    if ($role === 'super_admin' || $role === 'admin') return true;
    $perms = $_SESSION['admin_permissions'] ?? [];
    return in_array($perm, $perms);
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

function adm_redirect($type, $msg, $tab = 'orders') {
    $_SESSION["adm_flash_{$type}"] = $msg;
    header("Location: license_admin.php?tab={$tab}");
    exit;
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
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();

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
            
            $is_token_order = ($ord_item['product'] ?? '') === 'TOKEN_WALLET' || stripos($ord_item['package_name'] ?? '', 'Token') !== false || stripos($ord_item['package_name'] ?? '', 'Unlimited') !== false;
            if ($is_token_order) {
                if (!adm_can('tokens.approve_order')) {
                    adm_redirect('error', '❌ Bạn không có quyền duyệt đơn nạp token!', 'orders');
                }
            } else {
                if (!adm_can('orders.approve')) {
                    adm_redirect('error', '❌ Bạn không có quyền duyệt đơn hàng cấp key!', 'orders');
                }
            }
            $is_2toolne = ($ord_item['product'] ?? '') === '2TOOLNE' || stripos($ord_item['package_name'] ?? '', '2toolne') !== false;
            $is_ext_order = ($ord_item['product'] ?? '') === 'LABS_EXTENSION' || ($ord_item['tier'] ?? '') === 'LABS_EXTENSION' || stripos($ord_item['package_name'] ?? '', 'Extension') !== false || strpos($ord_item['package_price'] ?? '', '100.000') !== false;
            
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
                                ->execute([$txId, $uid, $curBal, $ord_id, "Kích hoạt gói {$pkg_n} qua đơn {$ord_id}", $admin_name]);
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
                    db_adjust_user_wallet($u, $tokens_to_add, "Duyệt đơn {$ord_id}: {$pkg_n} (+{$tokens_to_add} Tokens)", $admin_name);
                    $assigned_label = "+" . number_format($tokens_to_add) . " TOKENS";
                }
                db_approve_order($ord_id, $assigned_label);
                adm_redirect('success', "🎉 Đã duyệt đơn nạp token <b>{$ord_id}</b> và kích hoạt <b>{$assigned_label}</b> cho <b>{$u}</b>!", 'orders');
            } elseif ($is_2toolne) {
                $prod_tag = '2TOOLNE';
                $tier_tag = $tier;
                $days_val = $days;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TOOLNE-{$tier}-{$rnd}";
            } elseif ($is_ext_order) {
                $prod_tag = 'LABS_EXTENSION';
                $tier_tag = 'LIFETIME';
                $days_val = 36500;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TAMNE-LABS-{$rnd}";
            } else {
                $prod_tag = 'SLIDESHOW';
                $tier_tag = $tier;
                $days_val = $days;
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $new_key = "2TAMNE-{$tier}-{$rnd}";
            }

            db_create_license($new_key, $prod_tag, $tier_tag, $days_val, $u, "Đơn hàng {$ord_id}: {$ord_item['package_name']} ({$ord_item['package_price']})");
            db_approve_order($ord_id, $new_key);

            adm_redirect('success', "🎉 Đã duyệt đơn <b>{$ord_id}</b> và cấp Key <b>{$new_key}</b> cho <b>{$u}</b>!", 'orders');
        }
    }

    // 2. REJECT ORDER
    elseif ($act === 'reject_order') {
        if (!adm_can('orders.reject')) adm_redirect('error', '❌ Bạn không có quyền hủy đơn hàng!', 'orders');
        $ord_id = trim($_POST['order_id'] ?? '');
        db_reject_order($ord_id);
        adm_redirect('success', "Đã hủy đơn <b>{$ord_id}</b>!", 'orders');
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
        if ($product === '2TOOLNE') {
            $new_key = "2TOOLNE-{$tier}-{$rnd}";
        } elseif ($product === 'LABS_EXTENSION') {
            $new_key = "2TAMNE-LABS-{$rnd}";
        } else {
            $new_key = "2TAMNE-{$tier}-{$rnd}";
        }

        db_create_license($new_key, $product, $tier, $duration, $owner_user, $note);
        adm_redirect('success', "✅ Đã tạo Key <b>{$new_key}</b>" . (!empty($owner_user) ? " → Gán cho <b>{$owner_user}</b>" : ""), 'keys');
    }

    // 4. ASSIGN USER TO KEY
    elseif ($act === 'assign_user') {
        if (!adm_can('keys.assign')) adm_redirect('error', '❌ Bạn không có quyền gán user cho key!', 'keys');
        $key = trim($_POST['key'] ?? '');
        $new_owner = trim($_POST['new_owner'] ?? '');
        db_assign_license_user($key, $new_owner);
        adm_redirect('success', "✅ Đã gán Key <b>{$key}</b> cho <b>" . ($new_owner ?: '(Không gán)') . "</b>!", 'keys');
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
            adm_redirect('success', "🗑️ Đã xóa tài khoản <b>{$target_u}</b>!", 'users');
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
            adm_redirect('success', "🛡️ Đã cập nhật phân quyền thành công cho <b>{$target_u}</b>!", 'users');
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
            adm_redirect('success', "✅ Đã cập nhật thông tin cho <b>{$target_u}</b>!", 'users');
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
            if ($product === '2TOOLNE') {
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
            adm_redirect('success', "🎉 Đã cấp Key <b>{$new_key}</b> cho <b>{$target_u}</b>!", 'users');
        }
    }

    // 13.4 ADMIN: RESET HWID FROM USER VIEW
    elseif ($act === 'admin_user_reset_hwid') {
        if (!adm_can('keys.reset_hwid')) adm_redirect('error', '❌ Bạn không có quyền reset HWID!', 'users');
        $key = trim($_POST['key'] ?? '');
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($key)) {
            db_reset_license_hwid($key);
            adm_redirect('success', "🔄 Đã Reset HWID cho Key <b>{$key}</b> của <b>{$target_u}</b>!", 'users');
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
            adm_redirect('success', "🔒 Đã đổi trạng thái Key <b>{$key}</b> → <b>" . strtoupper($new_st) . "</b>!", 'users');
        }
    }

    // 13.6 ADMIN: DELETE KEY FROM USER VIEW
    elseif ($act === 'admin_user_delete_key') {
        if (!adm_can('keys.delete')) adm_redirect('error', '❌ Bạn không có quyền xóa key!', 'users');
        $key = trim($_POST['key'] ?? '');
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($key)) {
            db_delete_license($key);
            adm_redirect('success', "🗑️ Đã xóa Key <b>{$key}</b> khỏi tài khoản <b>{$target_u}</b>!", 'users');
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
            adm_redirect('success', "✅ Đã điều chỉnh Token cho <b>{$target_u}</b>! Số dư mới: <b>" . number_format($res['new_balance']) . " Tokens</b> (" . ($delta > 0 ? "+{$delta}" : $delta) . ")", 'users');
        } else {
            adm_redirect('error', "❌ Lỗi điều chỉnh token: " . ($res['error'] ?? 'Không rõ lỗi'), 'users');
        }
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
    <link rel="stylesheet" href="globals.css">
    <style>
        .admin-header {
            background: var(--surface-1);
            border-bottom: 1px solid var(--border);
            height: 56px;
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

        $can_orders   = adm_can('orders.view');
        $can_keys     = adm_can('keys.view');
        $can_users    = adm_can('users.view');
        $can_features = adm_can('features.manage');
        $can_bugs     = adm_can('bugs.manage');
        $can_version  = adm_can('broadcast.manage');

        $tab_perm_map = [
            'orders'   => 'orders.view',
            'keys'     => 'keys.view',
            'users'    => 'users.view',
            'features' => 'features.manage',
            'bugs'     => 'bugs.manage',
            'version'  => 'broadcast.manage'
        ];
        $active_tab = 'orders';
        $req_tab = $_GET['tab'] ?? '';
        if ($req_tab && isset($tab_perm_map[$req_tab]) && adm_can($tab_perm_map[$req_tab])) {
            $active_tab = $req_tab;
        } elseif (!$can_orders) {
            if ($can_keys) $active_tab = 'keys';
            elseif ($can_users) $active_tab = 'users';
            elseif ($can_features) $active_tab = 'features';
            elseif ($can_bugs) $active_tab = 'bugs';
            elseif ($can_version) $active_tab = 'version';
        }
    ?>
        <!-- ═══ ADMIN DASHBOARD ═══ -->
        <header class="admin-header">
            <div class="container" style="display:flex;justify-content:space-between;align-items:center;width:100%">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:24px;height:24px;border-radius:6px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:12px">2</div>
                    <b style="font-size:14px;color:var(--foreground)">2tamne Admin</b>
                    
                    <div style="display:flex;align-items:center;gap:6px;padding-left:8px;border-left:1px solid var(--border)">
                        <span style="font-size:12.5px;color:var(--foreground);font-weight:600">👤 <?= htmlspecialchars($cur_fullname) ?></span>
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
                    <?php if ($can_orders && count($pending_orders) > 0): ?>
                        <span class="badge badge-warning" style="animation:pulseGlow 2s infinite">
                            ⏳ <?= count($pending_orders) ?> đơn chờ duyệt
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
                <?php if ($can_users): ?>
                    <div class="metric-tile">
                        <div class="metric-title">Tổng Người Dùng</div>
                        <div class="metric-val"><?= count($users_db) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($can_orders): ?>
                    <div class="metric-tile">
                        <div class="metric-title">Đơn Chờ Duyệt</div>
                        <div class="metric-val" style="color:<?= count($pending_orders) > 0 ? 'var(--warning)' : 'var(--muted-foreground)' ?>">
                            <?= count($pending_orders) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ADMIN TABS -->
            <nav class="admin-tab-nav">
                <?php if ($can_orders): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'orders' ? 'active' : '' ?>" id="atab-btn-orders" onclick="switchAdminTab('atab-orders', 'atab-btn-orders')">
                        <span>🛒</span> Đơn Hàng (<?= count($pending_orders) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_keys): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'keys' ? 'active' : '' ?>" id="atab-btn-keys" onclick="switchAdminTab('atab-keys', 'atab-btn-keys')">
                        <span>🔑</span> Quản Lý License (<?= count($licenses_db) ?>)
                    </button>
                <?php endif; ?>
                <?php if ($can_users): ?>
                    <button class="admin-tab-btn <?= $active_tab === 'users' ? 'active' : '' ?>" id="atab-btn-users" onclick="switchAdminTab('atab-users', 'atab-btn-users')">
                        <span>👥</span> Người Dùng (<?= count($users_db) ?>)
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
            </nav>

            <!-- ═══ TAB 1: ORDERS ═══ -->
            <div id="atab-orders" class="admin-tab-content <?= $active_tab === 'orders' ? 'active' : '' ?>">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🛒 Danh Sách Đơn Mua Key Cần Duyệt</div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <?php if (empty($orders_db)): ?>
                            <div class="empty-state">Chưa có đơn hàng nào phát sinh.</div>
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
                                            <th>Thao Tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders_db as $ord): 
                                            $st = $ord['status'] ?? 'pending';
                                        ?>
                                            <tr>
                                                <td><code class="font-mono text-primary"><?= htmlspecialchars($ord['id']) ?></code></td>
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
                                                    <span class="badge <?= $st === 'approved' ? 'badge-active' : ($st === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                                                        <?= strtoupper($st) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($st === 'pending'): 
                                                        $is_tok_ord = ($ord['product'] ?? '') === 'TOKEN_WALLET' || stripos($ord['package_name'] ?? '', 'Token') !== false || stripos($ord['package_name'] ?? '', 'Unlimited') !== false;
                                                        $can_approve = $is_tok_ord ? adm_can('tokens.approve_order') : adm_can('orders.approve');
                                                    ?>
                                                        <div style="display:flex;gap:6px">
                                                            <?php if ($can_approve): ?>
                                                                <form method="POST" style="display:inline">
                                                                    <input type="hidden" name="action" value="approve_order">
                                                                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                                    <button type="submit" class="btn btn-emerald btn-xs">
                                                                        <?= $is_tok_ord ? '🪙 Duyệt Nạp Token' : '⚡ Duyệt & Cấp Key' ?>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if (adm_can('orders.reject')): ?>
                                                                <form method="POST" style="display:inline" onsubmit="return confirm('Hủy đơn này?')">
                                                                    <input type="hidden" name="action" value="reject_order">
                                                                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                                    <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if (!$can_approve && !adm_can('orders.reject')): ?>
                                                                <span class="text-subtle" style="font-size:11.5px">Chờ duyệt</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php if ($st === 'approved'): ?>
                                                            <?php if (!empty($ord['issued_key'])): ?>
                                                                <code class="font-mono" style="font-size:11px;color:var(--emerald)"><?= htmlspecialchars($ord['issued_key']) ?></code>
                                                            <?php else: ?>
                                                                <span class="badge badge-active" style="font-size:10px">ĐÃ DUYỆT</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($st === 'rejected'): ?>
                                                            <span class="badge badge-danger" style="font-size:10px">ĐÃ HỦY</span>
                                                        <?php else: ?>
                                                            <span class="text-subtle" style="font-size:11.5px">Đã xử lý</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
                            <form method="POST">
                                <input type="hidden" name="action" value="create_key">
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label class="form-label">SẢN PHẨM:</label>
                                        <select name="product" class="form-select">
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
                        <div class="card-title">🔑 Danh Sách License Key Toàn Hệ Thống (<?= count($licenses_db) ?>)</div>
                        <input type="text" id="key-search-input" class="form-input" placeholder="🔍 Tìm kiếm key, user, ghi chú..." style="max-width:260px;height:32px" onkeyup="filterKeyTable()">
                    </div>
                    <div class="card-body" style="padding:0">
                        <!-- SUBTAB PRODUCT FILTER -->
                        <div style="display:flex;gap:6px;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;background:var(--surface-2)">
                            <button type="button" class="btn btn-primary btn-xs subtab-prod-btn active" onclick="filterKeysByProduct('ALL', this)">
                                ✨ Tất Cả (<?= count($licenses_db) ?>)
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
                                <tbody>
                                    <?php foreach (array_reverse($licenses_db, true) as $k => $lic): 
                                        $status = $lic['status'] ?? 'active';
                                        $tier = $lic['tier'] ?? 'VIP';
                                        $hwid = $lic['hwid'] ?? '';
                                        $owner = $lic['owner_user'] ?? '';
                                        $is_2toolne = ($lic['product'] ?? '') === '2TOOLNE' || strpos($k, '2TOOLNE-') === 0;
                                        $is_ext = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos($k, '2TAMNE-LABS-') === 0;
                                        $prod_row_tag = $is_2toolne ? '2TOOLNE' : ($is_ext ? 'LABS_EXTENSION' : 'SLIDESHOW');
                                    ?>
                                        <tr class="lic-row" data-product="<?= $prod_row_tag ?>" data-search="<?= strtolower(htmlspecialchars($k . ' ' . $owner . ' ' . ($lic['note'] ?? ''))) ?>">
                                            <td>
                                                <div style="display:flex;align-items:center;gap:6px">
                                                    <code class="font-mono text-primary" style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($k) ?></code>
                                                    <button class="btn btn-outline btn-xs" onclick="copyText('<?= htmlspecialchars($k) ?>')" title="Sao chép">📋</button>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($is_2toolne): ?>
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
                                            <td>
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
                                            <td>
                                                <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-danger' ?>">
                                                    <?= strtoupper($status) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($hwid): ?>
                                                    <code style="color:var(--info);font-size:11px"><?= substr($hwid, 0, 10) ?>...</code>
                                                    <?php if (adm_can('keys.reset_hwid')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="return confirm('Reset HWID cho key này?')">
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
                                                        <form method="POST" style="display:inline">
                                                            <input type="hidden" name="action" value="toggle_ban">
                                                            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                            <button type="submit" class="btn btn-outline btn-xs">
                                                                <?= $status === 'banned' ? '🔓 Mở' : '🔒 Khóa' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (adm_can('keys.delete')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="return confirm('Xác nhận XÓA vĩnh viễn key này?')">
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

            <!-- ═══ TAB 3: USERS ═══ -->
            <div id="atab-users" class="admin-tab-content <?= $active_tab === 'users' ? 'active' : '' ?>">
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
                                        <tr class="user-table-row" data-search="<?= htmlspecialchars($search_blob) ?>">
                                            <td>
                                                <a href="javascript:void(0)" class="user-link" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>')" title="Bấm để quản lý chi tiết user này">
                                                    👤 <b><?= htmlspecialchars($u_name) ?></b>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars($u_data['fullname'] ?: '(Chưa có)') ?></td>
                                            <td><?= htmlspecialchars($u_data['phone'] ?: '(Chưa có)') ?></td>
                                            <td>
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
                                                <span class="badge badge-active" style="cursor:pointer" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>', 'usertab-keys')" title="Bấm để xem danh sách key">
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
                                            <td><code><?= htmlspecialchars($u_data['registered_ip'] ?? 'N/A') ?></code></td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($u_data['created_at']) ?></td>
                                            <td>
                                                <div style="display:flex;gap:4px">
                                                    <button class="btn btn-emerald btn-xs" onclick="openUserManageModal('<?= htmlspecialchars($u_name) ?>')" title="Quản lý chi tiết">⚙️ Quản Lý</button>
                                                    <?php if (adm_can('users.password')): ?>
                                                        <button class="btn btn-outline btn-xs" onclick="openAdminPwModal('<?= htmlspecialchars($u_name) ?>')">🔐 Đổi MK</button>
                                                    <?php endif; ?>
                                                    <?php if (adm_can('users.delete')): ?>
                                                        <form method="POST" style="display:inline" onsubmit="return confirm('Xóa vĩnh viễn user <?= htmlspecialchars($u_name) ?>?')">
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
            </div>

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
                                        <tr>
                                            <td><code><?= htmlspecialchars($f['id']) ?></code></td>
                                            <td><b><?= htmlspecialchars($f['user']) ?></b></td>
                                            <td><b><?= htmlspecialchars($f['title']) ?></b></td>
                                            <td class="text-muted" style="max-width:320px"><?= htmlspecialchars($f['description']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline">
                                                    <input type="hidden" name="action" value="update_feature_status">
                                                    <input type="hidden" name="feature_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                    <select name="status" class="form-select" style="font-size:11.5px;padding:4px 8px" onchange="this.form.submit()">
                                                        <option value="Đang xem xét" <?= ($f['status']??'') === 'Đang xem xét' ? 'selected' : '' ?>>Đang xem xét</option>
                                                        <option value="Đang phát triển" <?= ($f['status']??'') === 'Đang phát triển' ? 'selected' : '' ?>>Đang phát triển</option>
                                                        <option value="Đã hoàn thành" <?= ($f['status']??'') === 'Đã hoàn thành' ? 'selected' : '' ?>>Đã hoàn thành</option>
                                                        <option value="Từ chối" <?= ($f['status']??'') === 'Từ chối' ? 'selected' : '' ?>>Từ chối</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($f['created_at']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Xóa phiếu này?')">
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
                                        <tr>
                                            <td><code><?= htmlspecialchars($b['id']) ?></code></td>
                                            <td><b><?= htmlspecialchars($b['user']) ?></b></td>
                                            <td><b><?= htmlspecialchars($b['title']) ?></b></td>
                                            <td><code style="color:var(--danger)"><?= htmlspecialchars($b['error_code'] ?: 'N/A') ?></code></td>
                                            <td class="text-muted" style="max-width:320px"><?= htmlspecialchars($b['description']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline">
                                                    <input type="hidden" name="action" value="update_bug_status">
                                                    <input type="hidden" name="bug_id" value="<?= htmlspecialchars($b['id']) ?>">
                                                    <select name="status" class="form-select" style="font-size:11.5px;padding:4px 8px" onchange="this.form.submit()">
                                                        <option value="Đã tiếp nhận" <?= ($b['status']??'') === 'Đã tiếp nhận' ? 'selected' : '' ?>>Đã tiếp nhận</option>
                                                        <option value="Đang xử lý" <?= ($b['status']??'') === 'Đang xử lý' ? 'selected' : '' ?>>Đang xử lý</option>
                                                        <option value="Đã sửa xong" <?= ($b['status']??'') === 'Đã sửa xong' ? 'selected' : '' ?>>Đã sửa xong</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($b['created_at']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Xóa báo cáo này?')">
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
                        <form method="POST" style="margin-bottom:16px">
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

                        <form method="POST" onsubmit="return confirm('Phát thông báo cập nhật mới cho TOÀN BỘ người dùng truy cập web?')">
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
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_broadcast_notice">
                                <button type="submit" class="btn btn-outline btn-xs">
                                    <?= !empty($sys_config['broadcast_notice']['active']) ? '🔴 Tắt Thông Báo' : '🟢 Bật Thông Báo' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST">
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
        </main>

        <!-- ═══ ADMIN MODALS ═══ -->

        <?php if (adm_can('keys.assign')): ?>
            <!-- MODAL ASSIGN KEY TO USER -->
            <div id="modal-admin-assign" class="modal-backdrop">
                <div class="modal-dialog" style="max-width:420px">
                    <div class="modal-header">
                        <div class="modal-title">👤 Gán / Đổi User Cho Key</div>
                        <button class="modal-close" onclick="closeModal('modal-admin-assign')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
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
                        <form method="POST">
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
                                    <form method="POST">
                                        <input type="hidden" name="action" value="admin_add_user_key">
                                        <input type="hidden" name="target_user" id="m-addkey-user">
                                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:10px;margin-bottom:10px">
                                            <div class="form-group" style="margin:0">
                                                <label class="form-label" style="font-size:11px">SẢN PHẨM:</label>
                                                <select name="product" class="form-select" style="font-size:12px;padding:6px 10px">
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
                                    <form method="POST">
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
                                        <form method="POST">
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
                                                        <div style="font-size:11.5px;font-weight:700;color:var(--primary);margin-bottom:6px">🛒 Đơn Hàng</div>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="orders.view" class="m-perm-cb" onchange="onPermCheckboxChange()"> Xem đơn hàng
                                                        </label>
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;margin-bottom:4px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="orders.approve" class="m-perm-cb" onchange="onPermCheckboxChange()"> Duyệt đơn & cấp key
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
                                                        <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
                                                            <input type="checkbox" name="permissions[]" value="users.role" class="m-perm-cb" onchange="onPermCheckboxChange()"> Phân quyền quản trị
                                                        </label>
                                                    </div>

                                                    <!-- NHÓM: VÍ & TOKEN -->
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

                                                    <!-- NHÓM 4: HỖ TRỢ & HỆ THỐNG -->
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
                                        <form method="POST">
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
                                        <form method="POST">
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
                                        <form method="POST" id="m-delete-user-form" onsubmit="return confirm('Bạn có CHẮC CHẮN muốn xóa vĩnh viễn tài khoản này?')">
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
        foreach ($users_db as $u_name => $u_data) {
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
                'username'           => $u_name,
                'fullname'           => $u_data['fullname'] ?? '',
                'phone'              => $u_data['phone'] ?? '',
                'role'               => $u_data['role'] ?? 'user',
                'permissions'        => $u_data['permissions'] ?? [],
                'registered_ip'      => $u_data['registered_ip'] ?? '',
                'created_at'         => $u_data['created_at'] ?? '',
                'keys'               => $u_keys,
                'orders'             => $u_orders,
                'token_balance'      => (int)($u_wallet['balance'] ?? 0),
                'token_reserved'     => (int)($u_wallet['reserved_balance'] ?? ($u_wallet['reserved'] ?? 0)),
                'credit_mode'        => $u_wallet['credit_mode'] ?? 'METERED',
                'plan'               => $u_wallet['plan'] ?? 'free',
                'token_transactions' => $u_txs
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
                'keys_reset_hwid'      => adm_can('keys.reset_hwid'),
                'keys_ban'             => adm_can('keys.ban'),
                'keys_delete'          => adm_can('keys.delete'),
                'users_delete'         => adm_can('users.delete'),
                'users_password'       => adm_can('users.password'),
                'tokens_manage'        => adm_can('tokens.manage'),
                'tokens_adjust'        => adm_can('tokens.adjust'),
                'tokens_approve_order' => adm_can('tokens.approve_order')
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

            function openUserManageModal(username, initialTab = 'usertab-keys') {
                const u = (typeof USERS_DATA !== 'undefined') ? USERS_DATA[username] : null;
                if (!u) {
                    alert('Không tìm thấy thông tin của user: ' + username);
                    return;
                }
                if (initialTab === 'usertab-wallet' && !CURRENT_ADMIN_PERMS.tokens_manage) {
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
                            keyActionBtns += `<form method="POST" style="display:inline" onsubmit="return confirm('Reset liên kết HWID cho key ${k.key}?')">
                                <input type="hidden" name="action" value="admin_user_reset_hwid">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-outline btn-xs" title="Reset HWID">🔄 Đổi Máy</button>
                            </form>`;
                        }
                        if (CURRENT_ADMIN_PERMS.keys_ban) {
                            keyActionBtns += `<form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="admin_user_toggle_ban">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-${isBanned ? 'emerald' : 'outline'} btn-xs" title="${isBanned ? 'Mở Khóa Key' : 'Khóa Key'}">${isBanned ? '🔓 Mở' : '🔒 Khóa'}</button>
                            </form>`;
                        }
                        if (CURRENT_ADMIN_PERMS.keys_delete) {
                            keyActionBtns += `<form method="POST" style="display:inline" onsubmit="return confirm('Xác nhận xóa vĩnh viễn key ${k.key}?')">
                                <input type="hidden" name="action" value="admin_user_delete_key">
                                <input type="hidden" name="key" value="${k.key}">
                                <input type="hidden" name="target_user" value="${u.username}">
                                <button type="submit" class="btn btn-danger btn-xs" title="Xóa Key">🗑️</button>
                            </form>`;
                        }
                        if (!keyActionBtns) keyActionBtns = '<span class="text-subtle" style="font-size:11px">-</span>';

                        return `<tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:4px">
                                    <code class="font-mono" style="color:var(--info);font-size:11.5px;font-weight:600">${k.key}</code>
                                    <button type="button" class="btn btn-outline btn-xs" onclick="copyText('${k.key}')" title="Sao chép">📋</button>
                                </div>
                            </td>
                            <td><span class="badge">${k.product}</span></td>
                            <td><span class="badge badge-purple">${k.tier}</span></td>
                            <td style="font-size:11.5px;color:var(--muted-foreground)">${k.expires_at ? k.expires_at : (k.duration_days + ' ngày')}</td>
                            <td>${hwidLabel}</td>
                            <td>${statusBadge}</td>
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
                            let tTypeBadge = `<span class="badge badge-outline">${escapeHtml(t.type || 'N/A')}</span>`;
                            if (t.type === 'COMMIT') tTypeBadge = '<span class="badge badge-purple">COMMIT</span>';
                            else if (t.type === 'RESERVE') tTypeBadge = '<span class="badge badge-warning">RESERVE</span>';
                            else if (t.type === 'RELEASE' || t.type === 'REFUND') tTypeBadge = '<span class="badge badge-info">' + escapeHtml(t.type) + '</span>';
                            else if (t.type === 'ADMIN_ADJUST' || t.type === 'ADMIN_ADJUSTMENT' || t.type === 'PROMOTION' || t.type === 'UPGRADE') tTypeBadge = '<span class="badge badge-emerald">' + escapeHtml(t.type) + '</span>';

                            return `<tr>
                                <td><code style="font-size:11px">#${escapeHtml(t.id)}</code></td>
                                <td>${tTypeBadge}</td>
                                <td class="${deltaClass}" style="font-weight:700">${deltaStr}</td>
                                <td><b>${afterBal}</b></td>
                                <td style="max-width:240px;word-break:break-word;font-size:12px">${escapeHtml(t.description || t.reason || t.metadata_json || '-')}</td>
                                <td class="text-subtle" style="font-size:11px">${escapeHtml(t.created_at || '-')}</td>
                            </tr>`;
                        }).join('');
                    }
                }

                switchUserModalTab(initialTab || 'usertab-keys');
                openModal('modal-manage-user');
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

            // Auto-open tab from URL ?tab=
            (function() {
                const urlTab = new URLSearchParams(location.search).get('tab');
                const tabMap = {
                    'orders':   ['atab-orders', 'atab-btn-orders'],
                    'keys':     ['atab-keys', 'atab-btn-keys'],
                    'users':    ['atab-users', 'atab-btn-users'],
                    'features': ['atab-features', 'atab-btn-features'],
                    'bugs':     ['atab-bugs', 'atab-btn-bugs'],
                    'version':  ['atab-version', 'atab-btn-version']
                };
                if (urlTab && tabMap[urlTab]) {
                    window.addEventListener('DOMContentLoaded', () => switchAdminTab(tabMap[urlTab][0], tabMap[urlTab][1]));
                }
            })();

            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-backdrop')) {
                    e.target.classList.remove('active');
                }
            });
        </script>
    <?php endif; ?>

</body>
</html>
