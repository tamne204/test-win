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

    // 1. APPROVE ORDER -> AUTO GENERATE KEY (TRANSACTION-SAFE)
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
        $url = trim($sys_config['download_url'] ?? "/downloads/SlideshowBuilder_v{$ver}.zip");
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

// Refresh databases
$licenses_db = db_get_licenses();
$users_db    = db_get_users();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();

$pending_orders = array_filter($orders_db, fn($x) => ($x['status'] ?? '') === 'pending');
?>
<?php if (!$admin_logged_in): ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng Nhập Quản Trị — 2tamne.site</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0c0e;
            --bg-subtle: #111215;
            --bg-card: #141518;
            --bg-card-hover: #181a1e;
            --bg-card-elev: #1c1d22;
            --border: rgba(255, 255, 255, 0.07);
            --border-subtle: rgba(255, 255, 255, 0.04);
            --border-hover: rgba(255, 255, 255, 0.14);
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #34d399;
            --success-bg: rgba(52, 211, 153, 0.08);
            --warning: #fbbf24;
            --warning-bg: rgba(251, 191, 36, 0.08);
            --danger: #f87171;
            --danger-bg: rgba(248, 113, 113, 0.08);
            --text-main: #f0f0f2;
            --text-sub: #8b8d98;
            --text-muted: #565866;
            --btn-radius: 8px;
            --card-radius: 12px;
            --modal-radius: 14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg); color: var(--text-main);
            line-height: 1.5; padding: 20px 0;
            letter-spacing: -0.011em; -webkit-font-smoothing: antialiased;
        }
        .container { max-width: 1280px; margin: 0 auto; padding: 0 20px; }
        
        .header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
        }
        .header h1 { font-size: 18px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px; letter-spacing: -0.02em; }
        
        button, .btn {
            font-family: inherit; outline: none; border: none;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            cursor: pointer; transition: all 0.15s ease;
        }
        .btn { padding: 0 14px; height: 34px; border-radius: var(--btn-radius); font-weight: 600; font-size: 12.5px; text-decoration: none; }
        .btn-primary { background: #f0f0f2; color: #0b0c0e; border: 1px solid rgba(255,255,255,0.2); }
        .btn-primary:hover { background: #ffffff; }
        .btn-success { background: var(--success); color: #0b0c0e; font-weight: 600; }
        .btn-danger { background: var(--danger-bg); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger); }
        .btn-outline { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border); color: var(--text-main); }
        .btn-outline:hover { background: rgba(255, 255, 255, 0.07); border-color: var(--border-hover); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--card-radius); padding: 18px 20px;
            transition: all 0.15s ease;
        }
        .stat-card:hover { border-color: var(--border-hover); }
        .stat-val {
            font-size: 26px; font-weight: 700; color: var(--text-main);
            margin-top: 4px; letter-spacing: -0.02em; font-family: 'Inter', sans-serif;
        }
        .stat-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }

        .card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--card-radius); padding: 22px 24px; margin-bottom: 24px;
        }
        .card-title { font-size: 15px; font-weight: 600; color: var(--text-main); margin-bottom: 16px; letter-spacing: -0.01em; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .form-group { margin-bottom: 10px; }
        label { display: block; font-size: 11px; font-weight: 600; color: var(--text-sub); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.05em; }
        input, select {
            width: 100%; padding: 8px 12px; background: var(--bg-card-elev);
            border: 1px solid var(--border); border-radius: 6px; color: #fff;
            font-size: 12.5px; outline: none; font-family: inherit;
        }
        input:focus, select:focus { border-color: var(--border-hover); }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 12px; }
        th {
            text-align: left; padding: 10px 12px; background: var(--bg-subtle);
            color: var(--text-muted); font-size: 10.5px; text-transform: uppercase; font-weight: 600;
            letter-spacing: 0.05em; border-bottom: 1px solid var(--border);
        }
        td { padding: 12px; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }
        .badge { padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; font-family: 'JetBrains Mono', monospace; }

        .admin-tab-bar { display: flex; gap: 6px; border-bottom: 1px solid var(--border); margin-bottom: 22px; flex-wrap: wrap; }
        .admin-tab-btn {
            background: transparent; border: none; padding: 8px 14px; color: var(--text-sub);
            font-weight: 500; font-size: 13px; cursor: pointer; border-radius: var(--btn-radius);
            font-family: inherit; position: relative; transition: all 0.15s ease;
        }
        .admin-tab-btn:hover { color: var(--text-main); background: var(--bg-card-elev); }
        .admin-tab-btn.active { color: var(--text-main); background: var(--bg-card-elev); font-weight: 600; }
        .admin-tab-content { display: none; }
        .admin-tab-content.active { display: block; }
        .badge-count { position: absolute; top: -2px; right: -2px; background: var(--danger); color: #fff; border-radius: 10px; font-size: 10px; font-weight: 700; padding: 1px 5px; min-width: 16px; text-align: center; }
        .badge-active { background: var(--success-bg); border: 1px solid rgba(52, 211, 153, 0.2); color: var(--success); }
        .badge-banned { background: var(--danger-bg); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger); }
        .badge-trial { background: var(--warning-bg); border: 1px solid rgba(251, 191, 36, 0.2); color: var(--warning); }
        .badge-user { background: var(--bg-card-elev); border: 1px solid var(--border); color: #a1a1aa; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; font-family: 'JetBrains Mono', monospace; }

        .alert { padding: 12px 16px; border-radius: var(--btn-radius); font-size: 13px; font-weight: 500; margin-bottom: 20px; }
        .alert-success { background: var(--success-bg); border: 1px solid rgba(52, 211, 153, 0.2); color: var(--success); }
        
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            z-index: 9999; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-card {
            background: var(--bg-card); border: 1px solid var(--border-hover);
            border-radius: var(--modal-radius); max-width: 420px; width: 100%; padding: 24px;
            position: relative; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7);
        }
        .modal-close {
            position: absolute; top: 14px; right: 14px; background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border); width: 26px; height: 26px; border-radius: 6px;
            color: var(--text-sub); font-size: 15px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { color: #fff; background: rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>🔐 ADMIN CONTROL PANEL</h2>
        <?php if (!empty($msg_error)): ?><div class="err"><?= $msg_error ?></div><?php endif; ?>
        <?php if (!empty($msg_success)): ?><div style="color:#34d399;font-size:12px;margin-bottom:12px;text-align:center"><?= $msg_success ?></div><?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Nhập mật khẩu quản trị..." required autofocus>
            <button type="submit" name="admin_login">ĐĂNG NHẬP HỆ THỐNG</button>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const spotlightCards = document.querySelectorAll('.stat-card, .card, .liquid-glass, .login-card');
    spotlightCards.forEach(card => {
        card.addEventListener('mousemove', e => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
        });
    });
});
</script>

</body>
</html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hệ Thống Quản Trị Bản Quyền — 2tamne.site</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Montserrat', sans-serif; background: #070a13; color: #f8fafc; line-height: 1.5; padding: 24px 0; }
        .container { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #1e293b; }
        .header h1 { font-size: 22px; font-weight: 900; color: #818cf8; display: flex; align-items: center; gap: 8px; }
        .btn { padding: 8px 16px; border-radius: 8px; font-weight: 800; font-size: 12px; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-primary { background: linear-gradient(135deg, #6366f1, #38bdf8); color: #fff; }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #334155; color: #cbd5e1; }
        .btn-outline:hover { border-color: #6366f1; color: #818cf8; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #101626; border: 1px solid #1e293b; border-radius: 12px; padding: 18px 20px; }
        .stat-val { font-size: 28px; font-weight: 900; color: #38bdf8; margin-top: 4px; }
        .stat-label { font-size: 11.5px; font-weight: 700; color: #64748b; text-transform: uppercase; }

        .card { background: #101626; border: 1px solid #1e293b; border-radius: 14px; padding: 22px; margin-bottom: 24px; }
        .card-title { font-size: 16px; font-weight: 800; color: #f8fafc; margin-bottom: 16px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .form-group { margin-bottom: 10px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #94a3b8; margin-bottom: 5px; text-transform: uppercase; }
        input, select { width: 100%; padding: 9px 12px; background: #0b0f19; border: 1px solid #334155; border-radius: 6px; color: #fff; font-size: 12.5px; outline: none; }
        input:focus, select:focus { border-color: #6366f1; }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { text-align: left; padding: 12px 10px; background: #0b0f19; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #1e293b; }
        td { padding: 12px 10px; border-bottom: 1px solid #1e293b; vertical-align: middle; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge { padding: 3px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 800; }

        /* Admin Tab Nav */
        .admin-tab-bar { display: flex; gap: 6px; border-bottom: 2px solid #1e293b; margin-bottom: 24px; flex-wrap: wrap; }
        .admin-tab-btn { background: none; border: none; padding: 10px 18px; color: #64748b; font-weight: 800; font-size: 13px; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; font-family: inherit; position: relative; transition: color 0.2s; }
        .admin-tab-btn:hover { color: #94a3b8; }
        .admin-tab-btn.active { color: #38bdf8; border-color: #38bdf8; }
        .admin-tab-content { display: none; }
        .admin-tab-content.active { display: block; }
        .badge-count { position: absolute; top: 4px; right: 4px; background: #ef4444; color: #fff; border-radius: 10px; font-size: 10px; font-weight: 900; padding: 1px 5px; min-width: 16px; text-align: center; }
        .badge-active { background: rgba(16,185,129,0.15); color: #34d399; }
        .badge-banned { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-trial { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .badge-user { background: rgba(99,102,241,0.2); border: 1px solid rgba(99,102,241,0.4); color: #a5b4fc; padding: 2px 8px; border-radius: 6px; font-size: 11.5px; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
        .alert-success { background: rgba(16,185,129,0.15); border: 1px solid #10b981; color: #34d399; }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
        .modal-card { background: #101626; border: 1px solid #1e293b; border-radius: 14px; max-width: 400px; width: 100%; padding: 24px; position: relative; }
        .modal-close { position: absolute; top: 14px; right: 14px; background: none; border: none; color: #64748b; font-size: 20px; cursor: pointer; }

        /* ═════════════════════════════════════════════════════════ */
        /* 📱 ADMIN 100% VERTICAL MOBILE ENGINE (ZERO SCROLL)         */
        /* ═════════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            * { max-width: 100% !important; box-sizing: border-box !important; }
            html, body { overflow-x: hidden !important; width: 100% !important; }
            .container { padding: 0 10px !important; width: 100% !important; }
            
            .header { flex-direction: column !important; gap: 12px !important; align-items: stretch !important; text-align: center !important; }
            .header h1 { font-size: 15px !important; justify-content: center !important; }
            .header > div { justify-content: center !important; display: flex !important; gap: 6px !important; }

            /* Stats Grid: 2 columns */
            .stats-grid { grid-template-columns: 1fr 1fr !important; gap: 8px !important; margin-bottom: 16px !important; }
            .stat-card { padding: 10px 12px !important; }
            .stat-val { font-size: 18px !important; }
            .stat-label { font-size: 10px !important; }

            /* Admin Tab Bar: 2 Columns Grid */
            .admin-tab-bar {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 6px !important;
                width: 100% !important;
                overflow: visible !important;
                border-bottom: none !important;
                margin-bottom: 16px !important;
            }
            .admin-tab-btn {
                width: 100% !important;
                text-align: center !important;
                justify-content: center !important;
                padding: 10px 4px !important;
                font-size: 11px !important;
                background: #101626 !important;
                border: 1px solid #1e293b !important;
                border-radius: 8px !important;
                margin-bottom: 0 !important;
                border-bottom: 1px solid #1e293b !important;
                display: flex !important;
                align-items: center !important;
            }
            .admin-tab-btn.active {
                background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(56,189,248,0.25)) !important;
                border-color: #38bdf8 !important;
                color: #38bdf8 !important;
                font-weight: 800 !important;
            }

            /* Subtabs for products: Full width stack */
            .subtab-prod-btn {
                font-size: 11px !important;
                padding: 8px 10px !important;
                flex: 1 !important;
                text-align: center !important;
            }

            /* Forms */
            .form-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
            .card { padding: 14px !important; border-radius: 12px !important; margin-bottom: 16px !important; }

            /* Admin Tables Transformed to Mobile Vertical Cards */
            table { display: block !important; width: 100% !important; border: none !important; }
            thead { display: none !important; }
            tbody { display: flex !important; flex-direction: column !important; gap: 10px !important; width: 100% !important; }
            tr { 
                display: flex !important; 
                flex-direction: column !important; 
                background: #080c16 !important; 
                border: 1px solid #1e293b !important; 
                border-radius: 10px !important; 
                padding: 12px !important; 
                gap: 6px !important; 
                width: 100% !important; 
            }
            td { 
                display: flex !important; 
                justify-content: space-between !important; 
                align-items: center !important; 
                padding: 3px 0 !important; 
                border: none !important; 
                font-size: 11.5px !important; 
                width: 100% !important; 
                flex-wrap: wrap !important;
            }
            td > div { width: 100% !important; }

            /* Modal */
            .modal-card { width: 96% !important; max-height: 90vh !important; overflow-y: auto !important; padding: 16px !important; }
            input, select, textarea { font-size: 16px !important; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr !important; }
        }</style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>💎 TRÌNH QUẢN TRỊ LICENSE & DUYỆT ĐƠN HÀNG — 2TAMNE.SITE</h1>
            <div style="display:flex;gap:10px">
                <a href="index.php" target="_blank" class="btn btn-outline">🌐 Xem Trang Chủ</a>
                <a href="?logout=1" class="btn btn-danger">Đăng Xuất 🚪</a>
            </div>
        </div>

        <?php if ($msg_success): ?>
            <div class="alert alert-success"><?= $msg_success ?></div>
        <?php endif; ?>
        <?php if ($msg_error): ?>
            <div class="alert" style="background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#f87171;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:20px"><?= $msg_error ?></div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card" style="border-color:#f59e0b">
                <div class="stat-label" style="color:#f59e0b">Đơn Chờ Duyệt (Pending)</div>
                <div class="stat-val" style="color:#fcd34d"><?= count($pending_orders) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Tổng Số Key</div>
                <div class="stat-val"><?= count($licenses_db) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Khách Hàng Đã Đăng Ký</div>
                <div class="stat-val" style="color:#a5b4fc"><?= count($users_db) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Phiếu Mong Muốn Update</div>
                <div class="stat-val" style="color:#34d399"><?= count($features_db) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Báo Cáo Lỗi</div>
                <div class="stat-val" style="color:#f87171"><?= count($bugs_db) ?></div>
            </div>
        </div>

        <!-- ADMIN TAB NAV -->
        <div class="admin-tab-bar">
            <button class="admin-tab-btn active" id="atab-btn-orders" onclick="switchAdminTab('atab-orders','atab-btn-orders')">
                🛒 Đơn Hàng Chờ Duyệt
                <?php if (count($pending_orders) > 0): ?>
                <span class="badge-count"><?= count($pending_orders) ?></span>
                <?php endif; ?>
            </button>
            <button class="admin-tab-btn" id="atab-btn-keys" onclick="switchAdminTab('atab-keys','atab-btn-keys')">🔑 Quản Lý License Key</button>
            <button class="admin-tab-btn" id="atab-btn-features" onclick="switchAdminTab('atab-features','atab-btn-features')">
                🌟 Phiếu Update
                <?php if (count($features_db) > 0): ?><span class="badge-count" style="background:#6366f1"><?= count($features_db) ?></span><?php endif; ?>
            </button>
            <button class="admin-tab-btn" id="atab-btn-bugs" onclick="switchAdminTab('atab-bugs','atab-btn-bugs')">
                🐞 Báo Cáo Lỗi
                <?php if (count($bugs_db) > 0): ?><span class="badge-count" style="background:#f59e0b"><?= count($bugs_db) ?></span><?php endif; ?>
            </button>
            <button class="admin-tab-btn" id="atab-btn-users" onclick="switchAdminTab('atab-users','atab-btn-users')">
                👥 Quản Lý User
                <span class="badge-count" style="background:#6366f1"><?= count($users_db) ?></span>
            </button>
            <button class="admin-tab-btn" id="atab-btn-version" onclick="switchAdminTab('atab-version','atab-btn-version')">
                🚀 Phiên Bản & Thông Báo
                <?php if (!empty($sys_config['broadcast_notice']['active'])): ?>
                    <span class="badge-count" style="background:#10b981">Live</span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ═══ TAB 1: ĐƠN HÀNG CHỜ DUYỆT ═══ -->
        <div id="atab-orders" class="admin-tab-content active">

        <!-- 1. PENDING ORDERS (DUYỆT ĐƠN MUA QR) -->
        <div class="card" style="border:1px solid #f59e0b;background:linear-gradient(180deg,#161e33 0%,#101626 100%)">
            <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>🛒 ĐƠN HÀNG CHỜ DUYỆT THANH TOÁN VIETQR (<?= count($pending_orders) ?>)</span>
                <?php if (count($pending_orders) > 0): ?>
                    <span style="font-size:12px;color:#fcd34d;font-weight:600">🔔 Có khách hàng vừa bấm "Tôi đã thanh toán"!</span>
                <?php endif; ?>
            </div>
            
            <?php if (empty($pending_orders)): ?>
                <div style="background:#0b0f19;padding:18px;border-radius:10px;text-align:center;color:#64748b;font-size:13px">
                    Hiện tại không có đơn hàng nào đang chờ duyệt.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Mã Đơn</th>
                            <th>Khách Hàng (User)</th>
                            <th>Họ Tên / SĐT</th>
                            <th>Gói Mua</th>
                            <th>Số Tiền</th>
                            <th>Thời Gian</th>
                            <th>Nội Dung CK</th>
                            <th>Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($pending_orders) as $ord): ?>
                            <tr>
                                <td><b><?= htmlspecialchars($ord['id']) ?></b></td>
                                <td><span class="badge-user">👤 <?= htmlspecialchars($ord['user']) ?></span></td>
                                <td><?= htmlspecialchars($ord['fullname']) ?> (<?= htmlspecialchars($ord['phone']) ?>)</td>
                                <td><b style="color:#38bdf8"><?= htmlspecialchars($ord['package_name']) ?></b></td>
                                <td><b style="color:#34d399;font-size:13.5px"><?= htmlspecialchars($ord['package_price']) ?></b></td>
                                <td style="font-size:11.5px;color:#94a3b8"><?= $ord['created_at'] ?></td>
                                <td><code style="color:#fcd34d"><?= htmlspecialchars($ord['memo']) ?></code></td>
                                <td>
                                    <div style="display:flex;gap:6px">
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Xác nhận đã nhận tiền và TỰ ĐỘNG CẤP KEY cho khách này?')">
                                            <input type="hidden" name="action" value="approve_order">
                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                            <button type="submit" class="btn btn-success" style="padding:5px 10px;font-size:11.5px">⚡ Duyệt & Cấp Key</button>
                                        </form>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Từ chối / hủy đơn hàng này?')">
                                            <input type="hidden" name="action" value="reject_order">
                                            <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                            <button type="submit" class="btn btn-outline" style="padding:5px 10px;font-size:11.5px;color:#f87171">❌ Hủy</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        </div><!-- /atab-orders -->

        <!-- ═══ TAB 2: QUẢN LÝ LICENSE KEY ═══ -->
        <div id="atab-keys" class="admin-tab-content">

        <!-- 2. CREATE NEW KEY MANUALLY -->
        <div class="card">
            <div class="card-title">➕ TẠO LICENSE KEY THỦ CÔNG & GÁN USER</div>
            <form method="POST">
                <input type="hidden" name="action" value="create_key">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Sản Phẩm (Product):</label>
                        <select name="product">
                            <option value="2TOOLNE">🚀 2toolne — AI YouTube Production Studio</option>
                            <option value="SLIDESHOW">🎬 Tool Video AI (Slideshow Builder)</option>
                            <option value="LABS_EXTENSION">🖼️ Extension Google Labs (Tải Ảnh 2K/4K)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gói Bản Quyền (Tier):</label>
                        <select name="tier">
                            <option value="VIP">💎 VIP (1 Tháng / 1 Năm)</option>
                            <option value="LIFETIME">👑 LIFETIME (Vĩnh Viễn)</option>
                            <option value="TRIAL">🎁 TRIAL (Dùng Thử)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Thời Hạn Sử Dụng (Ngày):</label>
                        <select name="duration_days">
                            <option value="30">30 Ngày (1 Tháng)</option>
                            <option value="365">365 Ngày (1 Năm)</option>
                            <option value="36500">Vĩnh Viễn (Lifetime)</option>
                            <option value="3">3 Ngày (Dùng Thử)</option>
                            <option value="7">7 Ngày</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>👤 Gán Cho Tài Khoản User:</label>
                        <select name="owner_user">
                            <option value="">-- (Chưa gán / Cấp ngoài) --</option>
                            <?php foreach ($users_db as $u_id => $u_data): ?>
                                <option value="<?= htmlspecialchars($u_id) ?>">
                                    <?= htmlspecialchars($u_id) ?> (<?= htmlspecialchars($u_data['fullname'] ?: 'No name') ?> - <?= htmlspecialchars($u_data['phone'] ?: 'No phone') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Ghi Chú (Khách hàng, Zalo):</label>
                        <input type="text" name="note" placeholder="Ví dụ: Bán cho anh Nam qua Zalo">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:10px">⚡ TẠO VÀ GÁN KEY NGAY</button>
            </form>
        </div>

        <!-- 3. LICENSE LIST TABLE -->
        <div class="card">
            <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>🔑 DANH SÁCH TOÀN BỘ LICENSE KEY (<?= count($licenses_db) ?> keys)</span>
            </div>

            <!-- SUB-TABS: PRODUCT FILTER (EXTENSIBLE ARCHITECTURE) -->
            <?php
            $count_2toolne = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === '2TOOLNE' || strpos($x['license_key']??'', '2TOOLNE-') === 0));
            $count_video   = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === 'SLIDESHOW' || (strpos($x['license_key']??'', '2TAMNE-') === 0 && strpos($x['license_key']??'', '2TAMNE-LABS-') !== 0)));
            $count_ext     = count(array_filter($licenses_db, fn($x) => ($x['product']??'') === 'LABS_EXTENSION' || strpos($x['license_key']??'', '2TAMNE-LABS-') === 0));
            ?>
            <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;border-bottom:1px solid #1e293b;padding-bottom:12px">
                <button type="button" class="btn btn-primary subtab-prod-btn active" onclick="filterKeysByProduct('ALL', this)" style="padding:6px 14px;font-size:12px">
                    ✨ Tất Cả (<?= count($licenses_db) ?>)
                </button>
                <button type="button" class="btn btn-outline subtab-prod-btn" onclick="filterKeysByProduct('2TOOLNE', this)" style="padding:6px 14px;font-size:12px;border-color:#10b981;color:#34d399">
                    🚀 2toolne Studio (<?= $count_2toolne ?>)
                </button>
                <button type="button" class="btn btn-outline subtab-prod-btn" onclick="filterKeysByProduct('SLIDESHOW', this)" style="padding:6px 14px;font-size:12px;border-color:#6366f1;color:#a5b4fc">
                    🎬 Tool Video AI (<?= $count_video ?>)
                </button>
                <button type="button" class="btn btn-outline subtab-prod-btn" onclick="filterKeysByProduct('LABS_EXTENSION', this)" style="padding:6px 14px;font-size:12px;border-color:#0284c7;color:#38bdf8">
                    🖼️ Extension Google Labs (<?= $count_ext ?>)
                </button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Sản Phẩm</th>
                        <th>Gói</th>
                        <th>Thời Hạn</th>
                        <th>👤 Tài Khoản Sở Hữu</th>
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
                    ?>
                        <?php
                        $is_2toolne_key = ($lic['product'] ?? '') === '2TOOLNE' || strpos($k, '2TOOLNE-') === 0;
                        $is_ext_key = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos($k, '2TAMNE-LABS-') === 0;
                        $prod_row_tag = $is_2toolne_key ? '2TOOLNE' : ($is_ext_key ? 'LABS_EXTENSION' : 'SLIDESHOW');
                        ?>
                        <tr class="lic-row" data-product="<?= $prod_row_tag ?>">
                            <td><b style="font-family:monospace;color:#a5f3fc;font-size:13px"><?= htmlspecialchars($k) ?></b></td>
                            <td>
                                <?php if ($is_2toolne_key): ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;border:1px solid #10b981">🚀 2toolne Studio</span>
                                <?php elseif ($is_ext_key): ?>
                                    <span class="badge" style="background:rgba(56,189,248,0.2);color:#38bdf8;border:1px solid #0284c7">🖼️ Labs Extension</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid #6366f1">🎬 Video Tool</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $tier === 'TRIAL' ? 'badge-trial' : 'badge-active' ?>"><?= $tier ?></span></td>
                            <td>
                                <?php if ($lic['expires_at']): ?>
                                    <?= (strpos($lic['expires_at'], '2099') !== false) ? '👑 Vĩnh viễn' : htmlspecialchars($lic['expires_at']) ?>
                                <?php else: ?>
                                    <?= $lic['duration_days'] ?> ngày
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($owner): ?>
                                    <span class="badge-user">👤 <?= htmlspecialchars($owner) ?></span>
                                    <button class="btn btn-outline" style="padding:2px 6px;font-size:10px;margin-left:4px" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '<?= htmlspecialchars($owner) ?>')">Đổi</button>
                                <?php else: ?>
                                    <span style="color:#64748b;font-size:11px">(Chưa gán)</span>
                                    <button class="btn btn-outline" style="padding:2px 6px;font-size:10px;margin-left:4px;border-color:#6366f1;color:#818cf8" onclick="openAssignModal('<?= htmlspecialchars($k) ?>', '')">➕ Gán</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-banned' ?>">
                                    <?= strtoupper($status) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($hwid): ?>
                                    <code style="color:#38bdf8;font-size:11px"><?= substr($hwid, 0, 12) ?>...</code>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Reset HWID cho key này?')">
                                        <input type="hidden" name="action" value="reset_hwid">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                        <button type="submit" class="btn btn-outline" style="padding:2px 6px;font-size:10px;color:#38bdf8" title="Reset HWID">🔄</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#64748b;font-size:11px">Chưa kích hoạt</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#94a3b8;font-size:11.5px"><?= htmlspecialchars($lic['note'] ?? '') ?></td>
                            <td>
                                <div style="display:flex;gap:4px">
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="action" value="toggle_ban">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                        <button type="submit" class="btn btn-outline" style="padding:3px 7px;font-size:11px">
                                            <?= $status === 'banned' ? '🔓 Mở' : '🔒 Khóa' ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Bạn có chắc muốn XÓA VĨNH VIỄN key này?')">
                                        <input type="hidden" name="action" value="delete_key">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:3px 7px;font-size:11px">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>


        </div><!-- /atab-keys -->

        <!-- ═══ TAB 3: PHIẾU MONG MUỐN UPDATE ═══ -->
        <div id="atab-features" class="admin-tab-content">

        <!-- 4. FEATURE REQUESTS MANAGEMENT -->
        <div class="card">
            <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>🌟 PHIẾU MONG MUỐN UPDATE (<?= count($features_db) ?> phiếu)</span>
            </div>
            <?php if (empty($features_db)): ?>
                <div style="background:#0b0f19;padding:18px;border-radius:10px;text-align:center;color:#64748b;font-size:13px">Chưa có phiếu nào được gửi.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Người Gửi</th>
                        <th>Tiêu Đề</th>
                        <th>Nội Dung</th>
                        <th>Ngày Gửi</th>
                        <th>Trạng Thái</th>
                        <th>Cập Nhật</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($features_db) as $fi): ?>
                    <tr>
                        <td><span class="badge-user">👤 <?= htmlspecialchars($fi['user'] ?? '?') ?></span></td>
                        <td><b><?= htmlspecialchars($fi['title'] ?? '') ?></b></td>
                        <td style="color:#94a3b8;max-width:300px;font-size:11.5px"><?= nl2br(htmlspecialchars($fi['description'] ?? '')) ?></td>
                        <td style="font-size:11px;color:#64748b"><?= $fi['created_at'] ?? '' ?></td>
                        <td>
                            <?php
                            $fst = $fi['status'] ?? 'Đang xem xét';
                            $fst_cls = $fst === 'Đã thêm vào roadmap' ? 'badge-active' : ($fst === 'Không phù hợp' ? 'badge-banned' : 'badge-trial');
                            ?>
                            <span class="badge <?= $fst_cls ?>"><?= htmlspecialchars($fst) ?></span>
                        </td>
                        <td style="white-space:nowrap">
                            <div style="display:flex;gap:4px;align-items:center">
                            <form method="POST" style="display:flex;gap:4px">
                                <input type="hidden" name="action" value="update_feature_status">
                                <input type="hidden" name="feature_id" value="<?= htmlspecialchars($fi['id'] ?? '') ?>">
                                <select name="new_status" style="padding:4px 6px;font-size:11px;width:140px">
                                    <option value="Đang xem xét" <?= $fst==='Đang xem xét'?'selected':'' ?>>Đang xem xét</option>
                                    <option value="Đã thêm vào roadmap" <?= $fst==='Đã thêm vào roadmap'?'selected':'' ?>>Đã thêm vào roadmap</option>
                                    <option value="Đã phát triển xong" <?= $fst==='Đã phát triển xong'?'selected':'' ?>>Đã phát triển xong</option>
                                    <option value="Không phù hợp" <?= $fst==='Không phù hợp'?'selected':'' ?>>Không phù hợp</option>
                                </select>
                                <button type="submit" class="btn btn-success" style="padding:4px 8px;font-size:11px">💾</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Xóa phiếu này?')">
                                <input type="hidden" name="action" value="delete_feature">
                                <input type="hidden" name="feature_id" value="<?= htmlspecialchars($fi['id'] ?? '') ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" title="Xóa phiếu">🗑️</button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        </div><!-- /atab-features -->

        <!-- ═══ TAB 4: BÁO CÁO LỖI ═══ -->
        <div id="atab-bugs" class="admin-tab-content">

        <!-- 5. BUG REPORTS MANAGEMENT -->
        <div class="card">
            <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                <span>🐞 BÁO CÁO LỖI PHẦN MỀM (<?= count($bugs_db) ?> báo cáo)</span>
            </div>
            <?php if (empty($bugs_db)): ?>
                <div style="background:#0b0f19;padding:18px;border-radius:10px;text-align:center;color:#64748b;font-size:13px">Chưa có báo cáo lỗi nào.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Người Gửi</th>
                        <th>Tiêu Đề</th>
                        <th>Mã Lỗi</th>
                        <th>Chi Tiết</th>
                        <th>Ngày Gửi</th>
                        <th>Trạng Thái</th>
                        <th>Cập Nhật</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($bugs_db) as $bi): ?>
                    <tr>
                        <td><span class="badge-user">👤 <?= htmlspecialchars($bi['user'] ?? '?') ?></span></td>
                        <td><b><?= htmlspecialchars($bi['title'] ?? '') ?></b></td>
                        <td><code style="color:#f87171;font-size:11px"><?= htmlspecialchars($bi['error_code'] ?? '-') ?></code></td>
                        <td style="color:#94a3b8;max-width:260px;font-size:11.5px"><?= nl2br(htmlspecialchars($bi['description'] ?? '')) ?></td>
                        <td style="font-size:11px;color:#64748b"><?= $bi['created_at'] ?? '' ?></td>
                        <td>
                            <?php
                            $bst = $bi['status'] ?? 'Đã tiếp nhận';
                            $bst_cls = $bst === 'Đã sửa xong' ? 'badge-active' : ($bst === 'Không tái hiện được' ? 'badge-banned' : 'badge-trial');
                            ?>
                            <span class="badge <?= $bst_cls ?>"><?= htmlspecialchars($bst) ?></span>
                        </td>
                        <td style="white-space:nowrap">
                            <div style="display:flex;gap:4px;align-items:center">
                            <form method="POST" style="display:flex;gap:4px">
                                <input type="hidden" name="action" value="update_bug_status">
                                <input type="hidden" name="bug_id" value="<?= htmlspecialchars($bi['id'] ?? '') ?>">
                                <select name="new_status" style="padding:4px 6px;font-size:11px;width:140px">
                                    <option value="Đã tiếp nhận" <?= $bst==='Đã tiếp nhận'?'selected':'' ?>>Đã tiếp nhận</option>
                                    <option value="Đang kiểm tra" <?= $bst==='Đang kiểm tra'?'selected':'' ?>>Đang kiểm tra</option>
                                    <option value="Đã sửa xong" <?= $bst==='Đã sửa xong'?'selected':'' ?>>Đã sửa xong</option>
                                    <option value="Không tái hiện được" <?= $bst==='Không tái hiện được'?'selected':'' ?>>Không tái hiện được</option>
                                </select>
                                <button type="submit" class="btn btn-success" style="padding:4px 8px;font-size:11px">💾</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Xóa báo cáo lỗi này?')">
                                <input type="hidden" name="action" value="delete_bug">
                                <input type="hidden" name="bug_id" value="<?= htmlspecialchars($bi['id'] ?? '') ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px" title="Xóa báo cáo">🗑️</button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        </div><!-- /atab-bugs -->

        <!-- ═══ TAB 5: QUẢN LÝ USER ═══ -->
        <div id="atab-users" class="admin-tab-content">
        <div class="card">
            <div class="card-title">👥 DANH SÁCH TÀI KHOẢN NGƯỜI DÙNG (<?= count($users_db) ?> user)</div>
            <?php if (empty($users_db)): ?>
                <div style="background:#0b0f19;padding:18px;border-radius:10px;text-align:center;color:#64748b">Chưa có tài khoản nào.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tài Khoản</th>
                        <th>Họ Tên</th>
                        <th>SĐT / Zalo</th>
                        <th>IP Đăng Ký</th>
                        <th>Ngày Đăng Ký</th>
                        <th>Số Key</th>
                        <th>Đơn Hàng</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $row_num = 0;
                foreach ($users_db as $uid => $udata):
                    $row_num++;
                    $u_key_count = count($udata['keys'] ?? []);
                    $u_orders = array_values(array_filter($orders_db, fn($o) => ($o['user'] ?? '') === $uid));
                    $u_pending_cnt = count(array_filter($u_orders, fn($o) => ($o['status']??'') === 'pending'));
                ?>
                <tr>
                    <td style="color:#64748b;font-size:12px"><?= $row_num ?></td>
                    <td><span class="badge-user">👤 <?= htmlspecialchars($uid) ?></span></td>
                    <td style="font-weight:700"><?= htmlspecialchars($udata['fullname'] ?? '—') ?></td>
                    <td style="color:#38bdf8"><?= htmlspecialchars($udata['phone'] ?? '—') ?></td>
                    <td style="font-size:11px;color:#64748b"><code><?= htmlspecialchars($udata['registered_ip'] ?? '—') ?></code></td>
                    <td style="font-size:11px;color:#64748b"><?= $udata['created_at'] ?? '—' ?></td>
                    <td>
                        <span class="badge <?= $u_key_count > 0 ? 'badge-active' : 'badge-banned' ?>">
                            <?= $u_key_count ?> key
                        </span>
                    </td>
                    <td>
                        <?= count($u_orders) ?> đơn
                        <?php if ($u_pending_cnt > 0): ?>
                            <span class="badge" style="background:rgba(245,158,11,0.2);color:#fcd34d;margin-left:4px"><?= $u_pending_cnt ?> chờ</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-outline" style="padding:4px 10px;font-size:11px;border-color:#6366f1;color:#818cf8"
                            onclick="openUserDetailModal(<?= htmlspecialchars(json_encode([
                                'uid' => $uid,
                                'fullname' => $udata['fullname'] ?? '',
                                'phone' => $udata['phone'] ?? '',
                                'registered_ip' => $udata['registered_ip'] ?? '',
                                'created_at' => $udata['created_at'] ?? '',
                                'keys' => array_map(function($k) use ($licenses_db) {
                                    $l = $licenses_db[$k] ?? [];
                                    return [
                                        'key' => $k,
                                        'tier' => $l['tier'] ?? 'VIP',
                                        'product' => $l['product'] ?? (strpos($k, '2TAMNE-LABS-') === 0 ? 'LABS_EXTENSION' : 'SLIDESHOW'),
                                        'status' => $l['status'] ?? 'active',
                                        'duration_days' => $l['duration_days'] ?? 0,
                                        'expires_at' => $l['expires_at'] ?? '',
                                        'hwid' => $l['hwid'] ?? '',
                                        'device_name' => $l['device_name'] ?? ''
                                    ];
                                }, $udata['keys'] ?? []),
                                'orders' => $u_orders
                            ]), ENT_QUOTES) ?>)">
                            👁️ Chi Tiết
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        </div><!-- /atab-users -->

        <!-- ═══ TAB 6: PHIÊN BẢN & THÔNG BÁO TOÀN BỘ USER ═══ -->
        <div id="atab-version" class="admin-tab-content">
            
            <!-- 1. CẤU HÌNH PHIÊN BẢN & PHÁT UPDATE 1-CLICK -->
            <div class="card" style="border:1px solid #6366f1;background:linear-gradient(180deg,#131b33 0%,#101626 100%)">
                <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                    <span>🚀 CẤU HÌNH PHIÊN BẢN CHÍNH THỨC & NHẮC UPDATE 1-CLICK</span>
                    <span class="badge badge-active" style="font-size:12px">Bản hiện tại: v<?= htmlspecialchars($sys_config['app_version'] ?? '2.0.0') ?></span>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_version_config">
                    <div class="form-grid" style="grid-template-columns:1fr 2fr">
                        <div class="form-group">
                            <label>PHIÊN BẢN PHẦN MỀM MỚI NHẤT:</label>
                            <input type="text" name="app_version" value="<?= htmlspecialchars($sys_config['app_version'] ?? '2.0.0') ?>" placeholder="Ví dụ: 2.1.0" required>
                        </div>
                        <div class="form-group">
                            <label>ĐƯỜNG DẪN TẢI FILE CÀI ĐẶT (.ZIP):</label>
                            <input type="text" name="download_url" value="<?= htmlspecialchars($sys_config['download_url'] ?? '/downloads/SlideshowBuilder_v2.0.0.zip') ?>" placeholder="/downloads/SlideshowBuilder_v2.0.0.zip" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>NỘI DUNG GHI CHÚ BẢN PHÁT HÀNH (CHANGELOG / RELEASE NOTES):</label>
                        <textarea name="release_notes" rows="3" style="width:100%;padding:10px 12px;background:#0b0f19;border:1px solid #334155;border-radius:6px;color:#fff;font-size:12.5px;outline:none" placeholder="Ví dụ: - Nâng cấp Ken Burns 4K mượt mà hơn&#10;- Bổ sung 5 font chữ mới&#10;- Tối ưu 0% RAM lồng tiếng AI"><?= htmlspecialchars($sys_config['release_notes'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">💾 Lưu Cấu Hình Phiên Bản</button>
                    </div>
                </form>

                <hr style="border:none;border-top:1px solid #1e293b;margin:20px 0">

                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                    <div>
                        <h4 style="font-size:14px;font-weight:800;color:#fcd34d">🔔 NHẮC THÔNG BÁO CẬP NHẬT CHO TOÀN BỘ USER</h4>
                        <p style="font-size:12px;color:#94a3b8">Hệ thống sẽ tự động tạo một Popup thông báo cập nhật v<?= htmlspecialchars($sys_config['app_version'] ?? '2.0.0') ?> đè lên màn hình toàn bộ khách hàng kèm nút tải về.</p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Xác nhận phát thông báo nhắc cập nhật bản v<?= htmlspecialchars($sys_config['app_version'] ?? '2.0.0') ?> đến TOÀN BỘ khách hàng?')">
                        <input type="hidden" name="action" value="send_update_broadcast">
                        <button type="submit" class="btn btn-success" style="padding:10px 18px;font-size:13px">📢 PHÁT LỆNH UPDATE 1-CLICK</button>
                    </form>
                </div>
            </div>

            <!-- 2. SOẠN & QUẢN LÝ POPUP THÔNG BÁO TOÀN HỆ THỐNG -->
            <?php
            $notice = $sys_config['broadcast_notice'] ?? [];
            $is_notice_active = !empty($notice['active']);
            $notice_type = $notice['type'] ?? 'update';
            ?>
            <div class="card">
                <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                    <span>📢 SOẠN & PHÁT THÔNG BÁO TOÀN HỆ THỐNG (BROADCAST POPUP)</span>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="badge <?= $is_notice_active ? 'badge-active' : 'badge-banned' ?>">
                            <?= $is_notice_active ? '🟢 ĐANG PHÁT TOÀN DIỆN' : '⚫ ĐANG TẮT' ?>
                        </span>
                        <span class="badge badge-user">👁️ Đã có <?= intval($notice['read_count'] ?? 0) ?> user đã đọc</span>
                    </div>
                </div>

                <p style="font-size:12.5px;color:#94a3b8;margin-bottom:18px">
                    Khi bật, Popup sẽ <b>bung ra đè toàn màn hình của mọi user</b>. Khách hàng <b>bắt buộc phải nhấn "Tôi Đã Đọc & Hiểu"</b> mới có thể tắt được thông báo.
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="save_broadcast_notice">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>LOẠI THÔNG BÁO:</label>
                            <select name="notice_type">
                                <option value="update" <?= $notice_type === 'update' ? 'selected' : '' ?>>🚀 Bản Cập Nhật Mới (Update)</option>
                                <option value="alert" <?= $notice_type === 'alert' ? 'selected' : '' ?>>⚠️ Thông Báo Khẩn / Lưu Ý</option>
                                <option value="maintenance" <?= $notice_type === 'maintenance' ? 'selected' : '' ?>>🛠️ Bảo Trì Hệ Thống</option>
                                <option value="promo" <?= $notice_type === 'promo' ? 'selected' : '' ?>>🎁 Ưu Đãi / Khuyến Mãi</option>
                                <option value="info" <?= $notice_type === 'info' ? 'selected' : '' ?>>ℹ️ Tin Tức Chung</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>TIÊU ĐỀ THÔNG BÁO:</label>
                            <input type="text" name="notice_title" value="<?= htmlspecialchars($notice['title'] ?? '') ?>" placeholder="Ví dụ: 🎉 NÂNG CẤP HỆ THỐNG TẠO VIDEO 4K" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>NỘI DUNG THÔNG BÁO CHI TIẾT:</label>
                        <textarea name="notice_content" rows="4" style="width:100%;padding:10px 12px;background:#0b0f19;border:1px solid #334155;border-radius:6px;color:#fff;font-size:12.5px;outline:none" placeholder="Nhập nội dung thông báo gửi xuống toàn bộ user..." required><?= htmlspecialchars($notice['content'] ?? '') ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>CHỮ NÚT BẤM KÈM THEO (TÙY CHỌN):</label>
                            <input type="text" name="button_text" value="<?= htmlspecialchars($notice['button_text'] ?? '') ?>" placeholder="Ví dụ: Tải Ngay / Xem Chi Tiết">
                        </div>
                        <div class="form-group">
                            <label>ĐƯỜNG LINK KHI BẤM NÚT (TÙY CHỌN):</label>
                            <input type="text" name="button_url" value="<?= htmlspecialchars($notice['button_url'] ?? '') ?>" placeholder="Ví dụ: /downloads/... hoặc https://...">
                        </div>
                    </div>

                    <div style="margin:12px 0">
                        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="is_active" value="1" <?= $is_notice_active ? 'checked' : '' ?> style="width:auto">
                            <span style="font-size:13px;font-weight:700;color:#fff">KÍCH HOẠT HIỂN THỊ POPUP NGAY LẬP TỨC CHO MỌI USER</span>
                        </label>
                    </div>

                    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">📢 Phát / Cập Nhật Thông Báo</button>
                        <?php if (!empty($notice['title'])): ?>
                            <button type="button" class="btn btn-outline" onclick="openPreviewNoticeModal()">👁️ Xem Thử Popup</button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (!empty($notice['title'])): ?>
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid #1e293b;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                    <span style="font-size:12px;color:#64748b">Mã thông báo: <code><?= htmlspecialchars($notice['id'] ?? '') ?></code> · Ngày tạo: <?= $notice['created_at'] ?? '—' ?></span>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="toggle_broadcast_notice">
                        <button type="submit" class="btn <?= $is_notice_active ? 'btn-danger' : 'btn-success' ?>" style="font-size:11.5px">
                            <?= $is_notice_active ? '🛑 Tắt Thông Báo Này' : '🟢 Bật Lại Thông Báo Này' ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- 3. NHẬT KÝ TỔNG HỢP PHIẾU BÁO LỖI & PHIẾU FTUPDATE (AUDIT LOGS) -->
            <?php
            $audit_logs = [];
            foreach ($features_db as $fi) {
                $audit_logs[] = [
                    'type' => 'feature',
                    'id' => $fi['id'] ?? '',
                    'user' => $fi['user'] ?? '',
                    'title' => $fi['title'] ?? '',
                    'description' => $fi['description'] ?? '',
                    'extra' => '',
                    'created_at' => $fi['created_at'] ?? '',
                    'status' => $fi['status'] ?? 'Đang xem xét'
                ];
            }
            foreach ($bugs_db as $bi) {
                $audit_logs[] = [
                    'type' => 'bug',
                    'id' => $bi['id'] ?? '',
                    'user' => $bi['user'] ?? '',
                    'title' => $bi['title'] ?? '',
                    'description' => $bi['description'] ?? '',
                    'extra' => $bi['error_code'] ?? '',
                    'created_at' => $bi['created_at'] ?? '',
                    'status' => $bi['status'] ?? 'Đã tiếp nhận'
                ];
            }
            usort($audit_logs, function($a, $b) {
                return strcmp($b['created_at'], $a['created_at']);
            });
            ?>
            <div class="card">
                <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
                    <span>📋 NHẬT KÝ TỔNG HỢP PHIẾU BÁO LỖI & FTUPDATE CỦA USER (<?= count($audit_logs) ?> phiếu)</span>
                    <div style="display:flex;gap:8px">
                        <span class="badge" style="background:rgba(99,102,241,0.2);color:#a5b4fc">🌟 <?= count($features_db) ?> Feature</span>
                        <span class="badge" style="background:rgba(239,68,68,0.2);color:#f87171">🐞 <?= count($bugs_db) ?> Bug</span>
                    </div>
                </div>

                <?php if (empty($audit_logs)): ?>
                    <div style="background:#0b0f19;padding:18px;border-radius:10px;text-align:center;color:#64748b;font-size:13px">
                        Chưa có phiếu báo lỗi hoặc feature update nào từ khách hàng.
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Loại Phiếu</th>
                                <th>Khách Hàng</th>
                                <th>Tiêu Đề</th>
                                <th>Chi Tiết Nội Dung</th>
                                <th>Mã Lỗi</th>
                                <th>Thời Gian</th>
                                <th>Trạng Thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td>
                                        <?php if ($log['type'] === 'feature'): ?>
                                            <span class="badge" style="background:rgba(99,102,241,0.2);color:#818cf8">🌟 Feature Update</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:rgba(239,68,68,0.2);color:#f87171">🐞 Báo Lỗi</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-user">👤 <?= htmlspecialchars($log['user']) ?></span></td>
                                    <td><b><?= htmlspecialchars($log['title']) ?></b></td>
                                    <td style="color:#94a3b8;max-width:320px;font-size:11.5px"><?= nl2br(htmlspecialchars($log['description'])) ?></td>
                                    <td>
                                        <?php if ($log['extra']): ?>
                                            <code style="color:#f87171"><?= htmlspecialchars($log['extra']) ?></code>
                                        <?php else: ?>
                                            <span style="color:#64748b">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:11px;color:#64748b"><?= $log['created_at'] ?></td>
                                    <td>
                                        <span class="badge badge-trial"><?= htmlspecialchars($log['status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div><!-- /atab-version -->


    <!-- MODAL CHI TIẾT USER -->
    <div id="modal-user-detail" class="modal" style="align-items:flex-start;padding:30px 16px;overflow-y:auto">
        <div class="modal-card" style="max-width:700px;width:100%;max-height:90vh;overflow-y:auto">
            <button class="modal-close" onclick="closeUserDetailModal()">&times;</button>
            <h3 style="font-size:16px;font-weight:900;color:#818cf8;margin-bottom:16px">👤 CHI TIẾT TÀI KHOẢN: <span id="udm-uid" style="color:#fff"></span></h3>

            <!-- Basic info -->
            <div style="background:#0b0f19;padding:14px 18px;border-radius:10px;font-size:12.5px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div>Họ tên: <b id="udm-fullname" style="color:#fff"></b></div>
                <div>SĐT/Zalo: <b id="udm-phone" style="color:#38bdf8"></b></div>
                <div>IP đăng ký: <code id="udm-ip" style="color:#94a3b8"></code></div>
                <div>Ngày đăng ký: <span id="udm-date" style="color:#94a3b8"></span></div>
            </div>

            <!-- Keys section -->
            <h4 style="font-size:13px;font-weight:800;color:#cbd5e1;margin-bottom:8px">🔑 License Keys</h4>
            <div id="udm-keys" style="margin-bottom:14px"></div>

            <!-- Orders section -->
            <h4 style="font-size:13px;font-weight:800;color:#cbd5e1;margin-bottom:8px">🛒 Lịch Sử Giao Dịch</h4>
            <div id="udm-orders" style="margin-bottom:14px"></div>

            <!-- Change password -->
            <h4 style="font-size:13px;font-weight:800;color:#cbd5e1;margin-bottom:8px">🔐 Đặt Lại Mật Khẩu</h4>
            <form method="POST" style="background:#0b0f19;padding:14px 18px;border-radius:10px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="action" value="admin_change_user_pw">
                <input type="hidden" name="target_user" id="udm-pw-uid">
                <div style="flex:1;min-width:180px">
                    <label style="display:block;font-size:10px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase">Mật Khẩu Mới (≥6 ký tự):</label>
                    <input type="text" name="new_password" placeholder="Nhập mật khẩu mới..." required minlength="6" style="width:100%">
                </div>
                <button type="submit" class="btn btn-success" onclick="return confirm('Xác nhận đặt lại mật khẩu cho user này?')">🔐 Đặt Lại</button>
            </form>

            <!-- Delete user -->
            <form method="POST" style="margin-top:12px;text-align:right" onsubmit="return confirm('⚠️ XÓA VĨNH VIỄN tài khoản này? Hành động không thể hoàn tác!')">
                <input type="hidden" name="action" value="admin_delete_user">
                <input type="hidden" name="target_user" id="udm-del-uid">
                <button type="submit" class="btn btn-danger" style="font-size:12px">🗑️ Xóa Tài Khoản Vĩnh Viễn</button>
            </form>
        </div>
    </div>

    <!-- MODAL GÁN USER -->
    <div id="modal-assign" class="modal">
        <div class="modal-card">
            <button class="modal-close" onclick="closeAssignModal()">&times;</button>
            <h3 style="font-size:15px;font-weight:800;margin-bottom:14px;color:#fff">👤 GÁN KEY CHO TÀI KHOẢN</h3>
            <form method="POST">
                <input type="hidden" name="action" value="assign_user">
                <input type="hidden" name="key" id="assign-key-val">
                
                <div class="form-group">
                    <label>MÃ LICENSE KEY:</label>
                    <input type="text" id="assign-key-display" readonly style="color:#38bdf8;font-weight:800">
                </div>
                
                <div class="form-group">
                    <label>CHỌN TÀI KHOẢN USER:</label>
                    <select name="new_owner" id="assign-user-select">
                        <option value="">-- (Hủy gán / Cấp ngoài) --</option>
                        <?php foreach ($users_db as $u_id => $u_data): ?>
                            <option value="<?= htmlspecialchars($u_id) ?>">
                                <?= htmlspecialchars($u_id) ?> (<?= htmlspecialchars($u_data['fullname'] ?: 'No name') ?> - <?= htmlspecialchars($u_data['phone'] ?: 'No phone') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:10px">💾 LƯU GÁN TÀI KHOẢN</button>
            </form>
        </div>
    </div>

    <script>
        // Admin tab switching
        const ADMIN_TAB_MAP = {
            'atab-orders':   'atab-btn-orders',
            'atab-keys':     'atab-btn-keys',
            'atab-features': 'atab-btn-features',
            'atab-bugs':     'atab-btn-bugs',
            'atab-users':    'atab-btn-users',
            'atab-version':  'atab-btn-version'
        };
        
        // Filter License Keys By Product (Extensible Sub-tabs)
        function filterKeysByProduct(prodType, btnEl) {
            document.querySelectorAll('.subtab-prod-btn').forEach(b => {
                b.classList.remove('active');
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            btnEl.classList.add('active');
            btnEl.classList.add('btn-primary');
            btnEl.classList.remove('btn-outline');

            document.querySelectorAll('.lic-row').forEach(row => {
                if (prodType === 'ALL' || row.getAttribute('data-product') === prodType) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function switchAdminTab(tabId, btnId) {
            document.querySelectorAll('.admin-tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.admin-tab-btn').forEach(el => el.classList.remove('active'));
            if (document.getElementById(tabId)) document.getElementById(tabId).classList.add('active');
            if (document.getElementById(btnId)) document.getElementById(btnId).classList.add('active');
        }
        // Auto-open tab from URL ?tab= (PRG redirect)
        (function() {
            const tabFromUrl = new URLSearchParams(location.search).get('tab');
            const adminTabMap = {
                'orders':   ['atab-orders',   'atab-btn-orders'],
                'keys':     ['atab-keys',     'atab-btn-keys'],
                'features': ['atab-features', 'atab-btn-features'],
                'bugs':     ['atab-bugs',     'atab-btn-bugs'],
                'users':    ['atab-users',    'atab-btn-users'],
                'version':  ['atab-version',  'atab-btn-version'],
            };
            if (tabFromUrl && adminTabMap[tabFromUrl]) {
                window.addEventListener('DOMContentLoaded', () => {
                    switchAdminTab(...adminTabMap[tabFromUrl]);
                });
            }
        })();

        // Auto-refresh pending orders count every 30s
        setInterval(function() {
            fetch('license_admin.php?ajax=pending_count')
            .then(r => r.json())
            .then(data => {
                const badge = document.querySelector('#atab-btn-orders .badge-count');
                if (data.count > 0) {
                    if (badge) { badge.textContent = data.count; }
                    else {
                        const btn = document.getElementById('atab-btn-orders');
                        const sp = document.createElement('span');
                        sp.className = 'badge-count';
                        sp.textContent = data.count;
                        btn.appendChild(sp);
                    }
                } else if (badge) {
                    badge.remove();
                }
            }).catch(() => {});
        }, 30000);

        // User detail modal
        function openUserDetailModal(data) {
            document.getElementById('udm-uid').textContent      = data.uid;
            document.getElementById('udm-fullname').textContent = data.fullname || '—';
            document.getElementById('udm-phone').textContent    = data.phone || '—';
            document.getElementById('udm-ip').textContent       = data.registered_ip || '—';
            document.getElementById('udm-date').textContent     = data.created_at || '—';
            document.getElementById('udm-pw-uid').value         = data.uid;
            document.getElementById('udm-del-uid').value        = data.uid;

            // Keys
            const keysEl = document.getElementById('udm-keys');
            if (!data.keys || data.keys.length === 0) {
                keysEl.innerHTML = '<div style="color:#64748b;font-size:12px;padding:10px;background:#0b0f19;border-radius:8px">Chưa có key nào.</div>';
            } else {
                let khtml = '<div style="display:flex;flex-direction:column;gap:6px">';
                data.keys.forEach(k => {
                    const tierColor = k.tier === 'TRIAL' ? '#f59e0b' : k.tier === 'LIFETIME' ? '#a78bfa' : '#34d399';
                    const statusBg  = k.status === 'active' ? 'rgba(16,185,129,.12)' : 'rgba(239,68,68,.12)';
                    const exp = k.expires_at ? (k.expires_at.includes('2099') ? '👑 Vĩnh viễn' : k.expires_at) : (k.duration_days + ' ngày (chưa kích hoạt)');
                    const dev = k.hwid ? ('✅ ' + (k.device_name || 'Desktop')) : '⭕ Chưa kích hoạt';
                    const prodTag = k.product === 'LABS_EXTENSION' ? '<span style="background:rgba(56,189,248,0.2);color:#38bdf8;padding:2px 6px;border-radius:6px;font-size:10px;font-weight:800;margin-right:4px">🖼️ Labs Ext</span>' : '<span style="background:rgba(99,102,241,0.2);color:#a5b4fc;padding:2px 6px;border-radius:6px;font-size:10px;font-weight:800;margin-right:4px">🎬 Video Tool</span>';
                    khtml += `<div style="background:${statusBg};border:1px solid #1e293b;border-radius:8px;padding:10px 14px;font-size:12px">
                        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px">
                            <div>${prodTag}<span style="font-family:monospace;font-weight:800;color:#a5f3fc">${k.key}</span></div>
                            <span style="background:rgba(0,0,0,.3);color:${tierColor};padding:2px 8px;border-radius:8px;font-size:10px;font-weight:900">${k.tier}</span>
                        </div>
                        <div style="color:#94a3b8;margin-top:4px">Thời hạn: ${exp} · Thiết bị: ${dev}</div>
                    </div>`;
                });
                khtml += '</div>';
                keysEl.innerHTML = khtml;
            }

            // Orders
            const ordEl = document.getElementById('udm-orders');
            if (!data.orders || data.orders.length === 0) {
                ordEl.innerHTML = '<div style="color:#64748b;font-size:12px;padding:10px;background:#0b0f19;border-radius:8px">Chưa có giao dịch nào.</div>';
            } else {
                let ohtml = '<table style="width:100%;border-collapse:collapse;font-size:11.5px"><thead><tr style="background:#0b0f19"><th style="padding:8px;text-align:left;color:#64748b">Mã Đơn</th><th style="padding:8px;text-align:left;color:#64748b">Gói</th><th style="padding:8px;text-align:left;color:#64748b">Số Tiền</th><th style="padding:8px;text-align:left;color:#64748b">Ngày Tạo</th><th style="padding:8px;text-align:left;color:#64748b">Trạng Thái</th></tr></thead><tbody>';
                [...data.orders].reverse().forEach(o => {
                    const stMap = { pending: ['#fcd34d','rgba(245,158,11,.15)','⏳ Chờ duyệt'], approved: ['#34d399','rgba(16,185,129,.15)','✅ Đã duyệt'], rejected: ['#f87171','rgba(239,68,68,.15)','❌ Đã hủy'] };
                    const [sColor, sBg, sLabel] = stMap[o.status] || ['#94a3b8','rgba(0,0,0,.2)', o.status];
                    ohtml += `<tr style="border-bottom:1px solid #1e293b">
                        <td style="padding:8px"><code style="color:#94a3b8;font-size:10px">${o.id}</code></td>
                        <td style="padding:8px;font-weight:700;color:#38bdf8">${o.package_name}</td>
                        <td style="padding:8px;color:#34d399;font-weight:800">${o.package_price}</td>
                        <td style="padding:8px;color:#64748b;font-size:10px">${o.created_at}</td>
                        <td style="padding:8px"><span style="background:${sBg};color:${sColor};padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800">${sLabel}</span></td>
                    </tr>`;
                });
                ohtml += '</tbody></table>';
                ordEl.innerHTML = ohtml;
            }

            document.getElementById('modal-user-detail').style.display = 'flex';
        }
        function closeUserDetailModal() {
            document.getElementById('modal-user-detail').style.display = 'none';
        }

        function openAssignModal(key, curUser) {
            document.getElementById('assign-key-val').value = key;
            document.getElementById('assign-key-display').value = key;
            document.getElementById('assign-user-select').value = curUser;
            document.getElementById('modal-assign').style.display = 'flex';
        }
        function closeAssignModal() {
            document.getElementById('modal-assign').style.display = 'none';
        }
        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                closeAssignModal();
                closeUserDetailModal();
            }
        }
    </script>
    <!-- MODAL XEM THỬ POPUP THÔNG BÁO -->
    <div id="modal-preview-notice" class="modal" style="background:rgba(0,0,0,0.88);backdrop-filter:blur(8px)">
        <div class="modal-card" style="max-width:540px;width:95%;border:2px solid #6366f1;box-shadow:0 25px 60px rgba(99,102,241,0.35);padding:28px;text-align:center">
            <button class="modal-close" onclick="closePreviewNoticeModal()">&times;</button>
            <div style="margin-bottom:12px">
                <span class="badge badge-active" style="font-size:11.5px">👁️ XEM THỬ POPUP PHÍA USER</span>
            </div>
            <h3 style="font-size:18px;font-weight:900;color:#fff;margin-bottom:12px"><?= htmlspecialchars($notice['title'] ?? '') ?></h3>
            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:10px;padding:14px 18px;font-size:13px;color:#cbd5e1;line-height:1.6;margin-bottom:16px;text-align:left;white-space:pre-wrap"><?= htmlspecialchars($notice['content'] ?? '') ?></div>
            <?php if (!empty($notice['button_text'])): ?>
                <a href="<?= htmlspecialchars($notice['button_url'] ?? '#') ?>" target="_blank" class="btn btn-outline" style="width:100%;padding:10px;justify-content:center;margin-bottom:10px;border-color:#6366f1;color:#818cf8"><?= htmlspecialchars($notice['button_text']) ?></a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="closePreviewNoticeModal()" style="width:100%;padding:12px;font-size:13px;justify-content:center">
                ✅ Tôi Đã Đọc & Hiểu
            </button>
        </div>
    </div>

    <script>
        function openPreviewNoticeModal() {
            document.getElementById('modal-preview-notice').style.display = 'flex';
        }
        function closePreviewNoticeModal() {
            document.getElementById('modal-preview-notice').style.display = 'none';
        }
    </script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const spotlightCards = document.querySelectorAll('.stat-card, .card, .liquid-glass, .login-card');
    spotlightCards.forEach(card => {
        card.addEventListener('mousemove', e => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
        });
    });
});
</script>

</body>
</html>
