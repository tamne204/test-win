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

// ── ADMIN AJAX ENDPOINT: Live pending count ─────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pending_count') {
    header('Content-Type: application/json');
    if (!$is_admin) { echo json_encode(['count' => 0]); exit; }
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
    unset($_SESSION['admin_last_active']);
    session_destroy();
    header("Location: license_admin.php");
    exit;
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $p = $_POST['password'] ?? '';
    if ($p === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_last_active'] = time();
        header("Location: license_admin.php");
        exit;
    } else {
        $msg_error = 'Sai mật khẩu quản trị!';
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

    // 1. APPROVE ORDER -> AUTO GENERATE KEY
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
            
            $is_2toolne = ($ord_item['product'] ?? '') === '2TOOLNE' || stripos($ord_item['package_name'] ?? '', '2toolne') !== false;
            $is_ext_order = ($ord_item['product'] ?? '') === 'LABS_EXTENSION' || ($ord_item['tier'] ?? '') === 'LABS_EXTENSION' || stripos($ord_item['package_name'] ?? '', 'Extension') !== false || strpos($ord_item['package_price'] ?? '', '100.000') !== false;
            
            if ($is_2toolne) {
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
        $ord_id = trim($_POST['order_id'] ?? '');
        db_reject_order($ord_id);
        adm_redirect('success', "Đã hủy đơn <b>{$ord_id}</b>!", 'orders');
    }

    // 3. CREATE KEY MANUALLY
    elseif ($act === 'create_key') {
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
        $key = trim($_POST['key'] ?? '');
        $new_owner = trim($_POST['new_owner'] ?? '');
        db_assign_license_user($key, $new_owner);
        adm_redirect('success', "✅ Đã gán Key <b>{$key}</b> cho <b>" . ($new_owner ?: '(Không gán)') . "</b>!", 'keys');
    }

    // 5. RESET HWID
    elseif ($act === 'reset_hwid') {
        $key = trim($_POST['key'] ?? '');
        db_reset_license_hwid($key);
        adm_redirect('success', "🔄 Đã Reset HWID cho Key <b>{$key}</b>!", 'keys');
    }

    // 6. TOGGLE BAN/UNBAN KEY
    elseif ($act === 'toggle_ban') {
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
        $key = trim($_POST['key'] ?? '');
        db_delete_license($key);
        adm_redirect('success', "🗑️ Đã xóa Key <b>{$key}</b>!", 'keys');
    }

    // 8. UPDATE FEATURE REQUEST STATUS
    elseif ($act === 'update_feature_status') {
        $fid = trim($_POST['feature_id'] ?? '');
        $new_status = trim($_POST['status'] ?? 'Đang xem xét');
        db_update_feature_status($fid, $new_status);
        adm_redirect('success', "✅ Cập nhật phiếu <b>{$fid}</b> → <b>{$new_status}</b>!", 'features');
    }

    // 9. UPDATE BUG REPORT STATUS
    elseif ($act === 'update_bug_status') {
        $bid = trim($_POST['bug_id'] ?? '');
        $new_status = trim($_POST['status'] ?? 'Đã tiếp nhận');
        db_update_bug_status($bid, $new_status);
        adm_redirect('success', "✅ Cập nhật báo cáo <b>{$bid}</b> → <b>{$new_status}</b>!", 'bugs');
    }

    // 10. DELETE FEATURE REQUEST
    elseif ($act === 'delete_feature') {
        $fid = trim($_POST['feature_id'] ?? '');
        db_delete_feature($fid);
        adm_redirect('success', "🗑️ Đã xóa phiếu!", 'features');
    }

    // 11. DELETE BUG REPORT
    elseif ($act === 'delete_bug') {
        $bid = trim($_POST['bug_id'] ?? '');
        db_delete_bug($bid);
        adm_redirect('success', "🗑️ Đã xóa báo cáo lỗi!", 'bugs');
    }

    // 12. ADMIN: CHANGE USER PASSWORD
    elseif ($act === 'admin_change_user_pw') {
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
        $target_u = trim($_POST['target_user'] ?? '');
        if (!empty($target_u)) {
            db_delete_user($target_u);
            adm_redirect('success', "🗑️ Đã xóa tài khoản <b>{$target_u}</b>!", 'users');
        }
    }

    // 14. SAVE VERSION CONFIG
    elseif ($act === 'save_version_config') {
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
    </style>
</head>
<body>

    <?php if (!$admin_logged_in): ?>
        <!-- ═══ ADMIN LOGIN SCREEN ═══ -->
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
            <div class="card" style="max-width:380px;width:100%">
                <div class="card-header" style="justify-content:center;text-align:center;flex-direction:column;gap:6px">
                    <div style="width:32px;height:32px;border-radius:8px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff">2</div>
                    <div class="card-title" style="font-size:16px">2tamne.site Admin Portal</div>
                    <div class="text-subtle" style="font-size:12px">Xác thực quyền quản trị hệ thống</div>
                </div>
                <div class="card-body">
                    <?php if ($msg_error): ?>
                        <div class="alert alert-danger"><?= $msg_error ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="admin_login" value="1">
                        <div class="form-group">
                            <label class="form-label">MẬT KHẨU QUẢN TRỊ:</label>
                            <input type="password" name="password" class="form-input" placeholder="Nhập mật khẩu admin..." required autofocus>
                        </div>
                        <button type="submit" class="btn btn-emerald" style="width:100%;height:38px">Đăng Nhập Vào Quản Trị</button>
                    </form>
                </div>
                <div class="card-footer" style="text-align:center;font-size:12px;color:var(--muted-foreground)">
                    Phiên đăng nhập có thời hạn 4 giờ
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- ═══ ADMIN DASHBOARD ═══ -->
        <header class="admin-header">
            <div class="container" style="display:flex;justify-content:space-between;align-items:center;width:100%">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:24px;height:24px;border-radius:6px;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:12px">2</div>
                    <b style="font-size:14px;color:var(--foreground)">2tamne Admin</b>
                    <span class="badge badge-active" style="font-size:10px">Live</span>
                </div>

                <div style="display:flex;align-items:center;gap:12px">
                    <?php if (count($pending_orders) > 0): ?>
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
                <div class="metric-tile">
                    <div class="metric-title">Tổng Người Dùng</div>
                    <div class="metric-val"><?= count($users_db) ?></div>
                </div>
                <div class="metric-tile">
                    <div class="metric-title">Đơn Chờ Duyệt</div>
                    <div class="metric-val" style="color:<?= count($pending_orders) > 0 ? 'var(--warning)' : 'var(--muted-foreground)' ?>">
                        <?= count($pending_orders) ?>
                    </div>
                </div>
            </div>

            <!-- ADMIN TABS -->
            <nav class="admin-tab-nav">
                <button class="admin-tab-btn active" id="atab-btn-orders" onclick="switchAdminTab('atab-orders', 'atab-btn-orders')">
                    <span>🛒</span> Đơn Hàng (<?= count($pending_orders) ?>)
                </button>
                <button class="admin-tab-btn" id="atab-btn-keys" onclick="switchAdminTab('atab-keys', 'atab-btn-keys')">
                    <span>🔑</span> Quản Lý License (<?= count($licenses_db) ?>)
                </button>
                <button class="admin-tab-btn" id="atab-btn-users" onclick="switchAdminTab('atab-users', 'atab-btn-users')">
                    <span>👥</span> Người Dùng (<?= count($users_db) ?>)
                </button>
                <button class="admin-tab-btn" id="atab-btn-features" onclick="switchAdminTab('atab-features', 'atab-btn-features')">
                    <span>💡</span> Góp Ý (<?= count($features_db) ?>)
                </button>
                <button class="admin-tab-btn" id="atab-btn-bugs" onclick="switchAdminTab('atab-bugs', 'atab-btn-bugs')">
                    <span>🐞</span> Báo Lỗi (<?= count($bugs_db) ?>)
                </button>
                <button class="admin-tab-btn" id="atab-btn-version" onclick="switchAdminTab('atab-version', 'atab-btn-version')">
                    <span>🚀</span> Phiên Bản & Thông Báo
                </button>
            </nav>

            <!-- ═══ TAB 1: ORDERS ═══ -->
            <div id="atab-orders" class="admin-tab-content active">
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
                                                    <?php if ($st === 'pending'): ?>
                                                        <div style="display:flex;gap:6px">
                                                            <form method="POST" style="display:inline">
                                                                <input type="hidden" name="action" value="approve_order">
                                                                <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                                <button type="submit" class="btn btn-emerald btn-xs">⚡ Duyệt & Cấp Key</button>
                                                            </form>
                                                            <form method="POST" style="display:inline" onsubmit="return confirm('Hủy đơn này?')">
                                                                <input type="hidden" name="action" value="reject_order">
                                                                <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                                <button type="submit" class="btn btn-danger btn-xs">Hủy</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-subtle" style="font-size:11.5px">Đã xử lý</span>
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
            <div id="atab-keys" class="admin-tab-content">
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
                                                    <button class="btn btn-outline btn-xs" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '<?= htmlspecialchars($owner) ?>')">Đổi</button>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">(Chưa gán)</span>
                                                    <button class="btn btn-outline btn-xs" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '')">➕ Gán</button>
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
                                                    <form method="POST" style="display:inline" onsubmit="return confirm('Reset HWID cho key này?')">
                                                        <input type="hidden" name="action" value="reset_hwid">
                                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                        <button type="submit" class="btn btn-outline btn-xs" title="Reset HWID">🔄</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-subtle" style="font-size:11px">Chưa kích hoạt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted" style="font-size:11.5px"><?= htmlspecialchars($lic['note'] ?? '') ?></td>
                                            <td>
                                                <div style="display:flex;gap:4px">
                                                    <form method="POST" style="display:inline">
                                                        <input type="hidden" name="action" value="toggle_ban">
                                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                        <button type="submit" class="btn btn-outline btn-xs">
                                                            <?= $status === 'banned' ? '🔓 Mở' : '🔒 Khóa' ?>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display:inline" onsubmit="return confirm('Xác nhận XÓA vĩnh viễn key này?')">
                                                        <input type="hidden" name="action" value="delete_key">
                                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                                        <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                                                    </form>
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
            <div id="atab-users" class="admin-tab-content">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">👥 Danh Sách Tài Khoản Người Dùng (<?= count($users_db) ?>)</div>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tài Khoản</th>
                                        <th>Họ & Tên</th>
                                        <th>SĐT / Zalo</th>
                                        <th>Số Key Sở Hữu</th>
                                        <th>IP Đăng Ký</th>
                                        <th>Ngày Tham Gia</th>
                                        <th>Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users_db as $u_name => $u_data): ?>
                                        <tr>
                                            <td><b class="text-primary"><?= htmlspecialchars($u_name) ?></b></td>
                                            <td><?= htmlspecialchars($u_data['fullname'] ?: '(Không có)') ?></td>
                                            <td><?= htmlspecialchars($u_data['phone'] ?: '(Không có)') ?></td>
                                            <td>
                                                <span class="badge badge-active"><?= count($u_data['keys'] ?? []) ?> keys</span>
                                            </td>
                                            <td><code><?= htmlspecialchars($u_data['registered_ip'] ?? 'N/A') ?></code></td>
                                            <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($u_data['created_at']) ?></td>
                                            <td>
                                                <div style="display:flex;gap:4px">
                                                    <button class="btn btn-outline btn-xs" onclick="openAdminPwModal('<?= htmlspecialchars($u_name) ?>')">🔐 Đổi MK</button>
                                                    <form method="POST" style="display:inline" onsubmit="return confirm('Xóa vĩnh viễn user <?= htmlspecialchars($u_name) ?>?')">
                                                        <input type="hidden" name="action" value="admin_delete_user">
                                                        <input type="hidden" name="target_user" value="<?= htmlspecialchars($u_name) ?>">
                                                        <button type="submit" class="btn btn-danger btn-xs">🗑️ Xóa</button>
                                                    </form>
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
            <div id="atab-features" class="admin-tab-content">
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
            <div id="atab-bugs" class="admin-tab-content">
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
            <div id="atab-version" class="admin-tab-content">
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

        <!-- TOAST -->
        <div id="toast">📋 Đã sao chép!</div>

        <script>
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
