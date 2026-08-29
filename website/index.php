<?php
session_start();
require_once __DIR__ . '/storage/db.php';

// Check GET logout
if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    session_destroy();
    header("Location: index.php?flash_out=1");
    exit;
}

// ── AJAX ENDPOINT: Poll Pending Orders ──────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll_orders') {
    header('Content-Type: application/json');
    $u = $_SESSION['user'] ?? '';
    if (!$u) { echo json_encode(['pending_count' => 0]); exit; }
    $all_orders = db_get_orders();
    $cnt = 0;
    foreach ($all_orders as $ord) {
        if (($ord['user'] ?? '') === $u && ($ord['status'] ?? '') === 'pending') {
            $cnt++;
        }
    }
    echo json_encode(['pending_count' => $cnt]);
    exit;
}

// ── AJAX ENDPOINT: Mark Broadcast Notice Read ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_notice_read') {
    $nid = trim($_GET['id'] ?? '');
    if ($nid) {
        $cur_cfg = db_get_system_config();
        if (!empty($cur_cfg['broadcast_notice']) && ($cur_cfg['broadcast_notice']['id'] ?? '') === $nid) {
            $cur_cfg['broadcast_notice']['read_count'] = intval($cur_cfg['broadcast_notice']['read_count'] ?? 0) + 1;
            db_save_system_config($cur_cfg);
        }
        setcookie("read_notice_{$nid}", "1", time() + 86400 * 60, "/");
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// Load databases from MySQL
$users_db    = db_get_users();
$licenses_db = db_get_licenses();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();

$current_user = $_SESSION['user'] ?? null;
$user_info    = $current_user ? db_get_user($current_user) : null;
$client_ip    = $_SERVER['REMOTE_ADDR'] ?? '';

// ── PRG Flash Messages (Post-Redirect-Get) ──────────────────────────
$msg_success = '';
$msg_error   = '';
if (isset($_SESSION['flash_success'])) { $msg_success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $msg_error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']);   }

function flash_redirect($type, $msg, $tab = '') {
    $_SESSION["flash_{$type}"] = $msg;
    $url = 'index.php' . ($tab ? "?tab={$tab}" : '');
    header("Location: {$url}");
    exit;
}

// Check POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // 1. REGISTER
    if ($act === 'register') {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $p = $_POST['password'] ?? '';
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($u) || empty($p)) {
            $msg_error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!';
        } elseif (!preg_match('/^[a-z0-9_]{3,24}$/', $u)) {
            $msg_error = 'Tên đăng nhập từ 3-24 ký tự (chữ thường, số, dấu gạch dưới)!';
        } elseif (db_get_user($u)) {
            $msg_error = 'Tên đăng nhập này đã có người sử dụng!';
        } else {
            db_create_user($u, $fullname, $phone, password_hash($p, PASSWORD_DEFAULT), $client_ip);
            
            $got_trial = false;
            if (!db_is_ip_trial_claimed($client_ip, 'SLIDESHOW')) {
                $rnd = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
                $trial_key = "2TAMNE-TRIAL-{$rnd}";
                db_create_license($trial_key, 'SLIDESHOW', 'TRIAL', 3, $u, "Tặng tự động khi đăng ký (3 Ngày) - IP {$client_ip}");
                db_claim_ip_trial($client_ip, $u, 'SLIDESHOW');
                $got_trial = true;
            }

            $_SESSION['user'] = $u;
            header("Location: index.php?registered=" . ($got_trial ? '1' : '0'));
            exit;
        }
    }

    // 2. LOGIN
    elseif ($act === 'login') {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $p = $_POST['password'] ?? '';
        $user_row = db_get_user($u);

        if ($user_row && password_verify($p, $user_row['password_hash'])) {
            $_SESSION['user'] = $u;
            header("Location: index.php");
            exit;
        } else {
            $msg_error = 'Sai tên đăng nhập hoặc mật khẩu!';
        }
    }

    // 3. LOGOUT
    elseif ($act === 'logout') {
        unset($_SESSION['user']);
        header("Location: index.php");
        exit;
    }

    // 4. RESET HWID
    elseif ($act === 'reset_hwid' && $user_info) {
        $k = trim($_POST['key'] ?? '');
        $lic_item = db_get_license($k);
        if ($lic_item && ($lic_item['owner_user'] ?? '') === $current_user) {
            db_reset_license_hwid($k);
            flash_redirect('success', "✅ Đã Reset HWID cho Key <b>{$k}</b>! Bạn có thể kích hoạt trên máy tính mới.", 'keys');
        }
    }

    // 5. SUBMIT PAYMENT ORDER
    elseif ($act === 'submit_payment' && $user_info) {
        $pkg_name = trim($_POST['package_name'] ?? 'Gói 1 Tháng');
        $pkg_price = trim($_POST['package_price'] ?? '1.000.000đ');
        $pkg_days = intval($_POST['duration_days'] ?? 30);
        $pkg_tier = trim($_POST['tier'] ?? 'VIP');
        $pkg_prod = trim($_POST['product'] ?? (stripos($pkg_name, 'Extension') !== false ? 'LABS_EXTENSION' : 'SLIDESHOW'));

        $ord_id = uniqid('ORD_');
        db_create_order(
            $ord_id,
            $current_user,
            $user_info['fullname'] ?? $current_user,
            $user_info['phone'] ?? '',
            $pkg_prod,
            $pkg_name,
            $pkg_price,
            $pkg_days,
            $pkg_tier,
            "Mua tool " . $current_user
        );
        flash_redirect('success', '🎉 Đã ghi nhận thông tin thanh toán! Đơn hàng đang được Admin duyệt để cấp Key.', 'keys');
    }

    // 6. FEATURE REQUEST
    elseif ($act === 'add_feature' && $user_info) {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($title) || empty($desc)) {
            flash_redirect('error', '❌ Vui lòng nhập đầy đủ tiêu đề và nội dung mong muốn update!', 'features');
        } else {
            $cur_month = date('Y-m');
            $user_count = 0;
            foreach ($features_db as $f_item) {
                if (($f_item['user'] ?? '') === $current_user && strpos($f_item['created_at'] ?? '', $cur_month) === 0) {
                    $user_count++;
                }
            }

            if ($user_count >= 3) {
                flash_redirect('error', 'Bạn đã gửi tối đa 3 phiếu mong muốn update trong tháng này!', 'features');
            } else {
                db_create_feature(uniqid('ft_'), $current_user, $title, $desc);
                flash_redirect('success', '✅ Đã gửi phiếu mong muốn update! Admin sẽ xem xét bổ sung vào bản cập nhật tới.', 'features');
            }
        }
    }

    // 7. CHANGE PASSWORD
    elseif ($act === 'change_password' && $user_info) {
        $old_pw     = $_POST['old_password'] ?? '';
        $new_pw     = $_POST['new_password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (!password_verify($old_pw, $user_info['password_hash'])) {
            flash_redirect('error', '❌ Mật khẩu cũ không chính xác!', 'settings');
        } elseif (strlen($new_pw) < 6) {
            flash_redirect('error', '❌ Mật khẩu mới phải có ít nhất 6 ký tự!', 'settings');
        } elseif ($new_pw !== $confirm_pw) {
            flash_redirect('error', '❌ Xác nhận mật khẩu mới không khớp!', 'settings');
        } else {
            db_update_user_password($current_user, password_hash($new_pw, PASSWORD_DEFAULT));
            flash_redirect('success', '✅ Đã đổi mật khẩu thành công!', 'settings');
        }
    }

    // 8. BUG REPORT
    elseif ($act === 'add_bug' && $user_info) {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $err_code = trim($_POST['error_code'] ?? '');

        if (empty($title) || empty($desc)) {
            flash_redirect('error', '❌ Vui lòng nhập tiêu đề và chi tiết lỗi gặp phải!', 'bugs');
        } else {
            db_create_bug(uniqid('bug_'), $current_user, $title, $desc, $err_code);
            flash_redirect('success', '🐞 Đã gửi báo cáo lỗi! Đội ngũ kỹ thuật sẽ kiểm tra và khắc phục sớm nhất.', 'bugs');
        }
    }
}

// Refresh databases and pending orders for user
$users_db    = db_get_users();
$licenses_db = db_get_licenses();
$orders_db   = db_get_orders();
$features_db = db_get_features();
$bugs_db     = db_get_bugs();
$sys_config  = db_get_system_config();
$user_info   = $current_user ? db_get_user($current_user) : null;

$user_pending_orders = [];
if ($user_info) {
    foreach ($orders_db as $ord) {
        if (($ord['user'] ?? '') === $current_user && ($ord['status'] ?? '') === 'pending') {
            $user_pending_orders[] = $ord;
        }
    }
}

if (isset($_GET['flash_out'])) {
    $msg_success = '👋 Bạn đã đăng xuất thành công. Hẹn gặp lại!';
}

if (isset($_GET['registered'])) {
    if ($_GET['registered'] === '1') {
        $msg_success = '🎉 Chúc mừng bạn đã đăng ký thành công và được <b>TẶNG NGAY 1 KEY DÙNG THỬ 3 NGÀY</b>!';
    } else {
        $msg_success = 'Đăng ký tài khoản thành công! (Địa chỉ IP này đã từng nhận mã dùng thử trước đó).';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Slideshow Builder AI — 2tamne.site | Tự Động Hóa Video Khớp Nhạc & Phụ Đề</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #000000;
            --bg-card: rgba(28, 28, 30, 0.68);
            --bg-card-elev: rgba(44, 44, 46, 0.58);
            --border-subtle: rgba(255, 255, 255, 0.12);
            --border-hi: rgba(255, 255, 255, 0.22);
            --primary: #0a84ff;
            --primary-hover: #0077ed;
            --primary-active: #0062c4;
            --accent: #5e5ce6;
            --success: #30d158;
            --warning: #ffd60a;
            --danger: #ff453a;
            --text-main: #ffffff;
            --text-sub: rgba(235, 235, 245, 0.65);
            --text-muted: rgba(235, 235, 245, 0.45);
            --glass-blur: blur(28px) saturate(190%);
            --glass-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.15), 0 20px 40px rgba(0, 0, 0, 0.65);
            --btn-radius: 12px;
            --card-radius: 20px;
            --modal-radius: 24px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Montserrat", "Segoe UI", sans-serif;
            background: var(--bg);
            background-image: radial-gradient(circle at 50% 0%, rgba(10, 132, 255, 0.18) 0%, rgba(0, 0, 0, 0.95) 75%), radial-gradient(circle at 80% 20%, rgba(94, 92, 230, 0.12) 0%, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            line-height: 1.6;
            letter-spacing: -0.015em;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--primary); text-decoration: none; transition: all 0.2s ease; }
        a:hover { color: #409cff; }
        .container { max-width: 1240px; margin: 0 auto; padding: 0 24px; }
        
        
        html {
            scroll-behavior: smooth;
        }

        /* ── Floating Trial Badge Callout (Pointing to Đăng Ký) ── */
        .register-btn-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .trial-badge-callout {
            position: absolute;
            bottom: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #0a84ff, #5e5ce6);
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 9999px;
            white-space: nowrap;
            box-shadow: 0 4px 16px rgba(10, 132, 255, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.35);
            animation: floatBadge 2.2s ease-in-out infinite;
            pointer-events: none;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .trial-badge-callout .callout-arrow {
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid #5e5ce6;
        }
        @keyframes floatBadge {
            0%, 100% { transform: translate(-50%, 0); }
            50% { transform: translate(-50%, -6px); }
        }

        /* ── Navbar ───────────────────────────────────────── */
        nav {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 0; border-bottom: 1px solid var(--border-subtle);
            position: sticky; top: 0;
            background: rgba(13, 13, 15, 0.78);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            z-index: 1000;
        }
        .nav-brand { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em; }
        .nav-links { display: flex; align-items: center; gap: 20px; }
        .nav-link {
            font-size: 13.5px; font-weight: 600; color: var(--text-sub);
            padding: 8px 14px; border-radius: var(--btn-radius);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .nav-link:hover { color: #ffffff; background: rgba(255, 255, 255, 0.08); transform: scale(1.02); }
        
        /* ── Button Interactive 4-State Engine ────────────── */
        button, .btn, .btn-primary, .btn-outline, .btn-success, .btn-danger, .tab-btn {
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1),
                        background 0.2s ease,
                        border-color 0.2s ease,
                        box-shadow 0.2s ease,
                        opacity 0.2s ease !important;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            font-family: inherit; outline: none;
        }
        button:hover:not(:disabled), .btn:hover:not(:disabled), .btn-primary:hover:not(:disabled), .btn-outline:hover:not(:disabled), .btn-success:hover:not(:disabled) {
            transform: scale(1.02); filter: brightness(1.08);
        }
        button:active:not(:disabled), .btn:active:not(:disabled), .btn-primary:active:not(:disabled), .btn-outline:active:not(:disabled), .btn-success:active:not(:disabled) {
            transform: scale(0.98); filter: brightness(0.95);
        }
        button:disabled, .btn:disabled {
            opacity: 0.50 !important; cursor: not-allowed !important; transform: none !important;
        }

        .btn-primary {
            background: var(--primary);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: var(--btn-radius);
            color: #ffffff; font-weight: 700; font-size: 13px;
            padding: 10px 20px; cursor: pointer;
            box-shadow: 0 4px 16px rgba(10, 132, 255, 0.40);
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            box-shadow: 0 6px 22px rgba(10, 132, 255, 0.55);
        }
        .btn-outline {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border-subtle);
            border-radius: var(--btn-radius);
            color: #ffffff; font-weight: 600; font-size: 13px;
            padding: 9px 18px; cursor: pointer;
            backdrop-filter: blur(10px);
        }
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: var(--border-hi);
        }
        .btn-success {
            background: var(--success);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: var(--btn-radius);
            color: #ffffff; font-weight: 700; font-size: 13px;
            padding: 10px 20px; cursor: pointer;
            box-shadow: 0 4px 16px rgba(48, 209, 88, 0.35);
        }
        .btn-danger {
            background: var(--danger);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: var(--btn-radius);
            color: #ffffff; font-weight: 700; font-size: 13px;
            padding: 10px 20px; cursor: pointer;
            box-shadow: 0 4px 16px rgba(255, 69, 58, 0.35);
        }
        .btn-lg { padding: 14px 32px; font-size: 15px; border-radius: 14px; }

        
        /* ── Magic UI: Shimmer Button & Border Beam ───────── */
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .btn-primary, .shimmer-button {
            background: linear-gradient(110deg, #0a84ff 0%, #0a84ff 35%, #64d2ff 50%, #0a84ff 65%, #0a84ff 100%) !important;
            background-size: 200% 100% !important;
            animation: shimmer 3.5s infinite linear !important;
            border: 1px solid rgba(255, 255, 255, 0.20) !important;
            box-shadow: 0 4px 16px rgba(10, 132, 255, 0.40) !important;
        }
        .btn-primary:hover, .shimmer-button:hover {
            box-shadow: 0 6px 24px rgba(10, 132, 255, 0.60) !important;
        }

        /* ── Aceternity UI: Card Spotlight ────────────────── */
        .feature-card, .pricing-card, .stat-card, .card, .liquid-glass {
            position: relative;
            overflow: hidden;
        }
        .feature-card::before, .pricing-card::before, .stat-card::before, .card::before, .liquid-glass::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(10, 132, 255, 0.15), transparent 40%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            border-radius: inherit;
        }
        .feature-card:hover::before, .pricing-card:hover::before, .stat-card:hover::before, .card:hover::before, .liquid-glass:hover::before {
            opacity: 1;
        }

        /* ── Magic UI: Border Beam on VIP / Active Key ────── */
        .pricing-vip {
            position: relative;
            border: 1.5px solid rgba(10, 132, 255, 0.50) !important;
            box-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.25), 0 20px 50px rgba(10, 132, 255, 0.30) !important;
        }
        .pricing-vip::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, #64d2ff, #0a84ff, transparent);
            animation: borderBeamAnim 3s infinite linear;
        }
        @keyframes borderBeamAnim {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* ── Deep Ambient Depth Table Wrapper ─────────────── */
        .table-responsive {
            background: rgba(20, 20, 22, 0.65);
            backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border-subtle);
            border-radius: var(--card-radius);
            box-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.15), 0 20px 40px rgba(0, 0, 0, 0.65);
            overflow-x: auto;
            padding: 4px;
        }

        /* ── Hero Section ─────────────────────────────────── */
        .hero { padding: 90px 0 70px; text-align: center; }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(10, 132, 255, 0.15);
            border: 1px solid rgba(10, 132, 255, 0.35);
            color: var(--primary); padding: 6px 18px;
            border-radius: 9999px; font-size: 12px; font-weight: 700;
            margin-bottom: 24px; backdrop-filter: blur(12px);
        }
        .hero-title {
            font-size: 46px; font-weight: 900; line-height: 1.2;
            margin-bottom: 20px; letter-spacing: -0.03em;
            background: linear-gradient(135deg, #ffffff 40%, rgba(235, 235, 245, 0.7) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            font-size: 16.5px; color: var(--text-sub);
            max-width: 780px; margin: 0 auto 36px; font-weight: 400; line-height: 1.6;
        }
        .hero-cta { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; }

        /* ── Liquid Glass Cards ───────────────────────────── */
        .sec-title { font-size: 28px; font-weight: 800; text-align: center; margin-bottom: 12px; color: #ffffff; letter-spacing: -0.02em; }
        .sec-subtitle { font-size: 14.5px; color: var(--text-muted); text-align: center; margin-bottom: 44px; }
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 60px; }
        
        .feature-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border-subtle);
            border-radius: var(--card-radius);
            padding: 32px 28px;
            box-shadow: var(--glass-shadow);
            transition: all 0.25s ease;
        }
        .feature-card:hover {
            border-color: var(--border-hi);
            transform: translateY(-2px);
            box-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.25), 0 24px 48px rgba(0, 0, 0, 0.75);
        }
        .feat-icon { font-size: 34px; margin-bottom: 18px; }
        .feat-title { font-size: 18px; font-weight: 800; color: #ffffff; margin-bottom: 10px; letter-spacing: -0.01em; }
        .feat-desc { font-size: 13.5px; color: var(--text-sub); line-height: 1.6; }

        /* ── Pricing Cards ────────────────────────────────── */
        .pricing-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border-subtle);
            border-radius: var(--card-radius);
            padding: 36px 30px; position: relative;
            display: flex; flex-direction: column; justify-content: space-between;
            box-shadow: var(--glass-shadow);
            transition: all 0.25s ease;
        }
        .pricing-card:hover {
            border-color: var(--border-hi);
            transform: translateY(-2px);
        }
        .pricing-vip {
            border: 1.5px solid rgba(10, 132, 255, 0.60);
            background: rgba(28, 28, 30, 0.80);
            box-shadow: inset 0 1px 0 0 rgba(255, 255, 255, 0.25), 0 20px 50px rgba(10, 132, 255, 0.25);
        }
        .pricing-tag {
            position: absolute; top: -12px; right: 24px;
            background: var(--primary); color: #ffffff;
            font-size: 11px; font-weight: 800; padding: 4px 14px;
            border-radius: 9999px; box-shadow: 0 4px 12px rgba(10, 132, 255, 0.4);
        }
        .price-val { font-size: 38px; font-weight: 900; color: #ffffff; margin: 18px 0 8px; letter-spacing: -0.02em; }
        .price-period { font-size: 13px; color: var(--text-muted); }
        .price-features { list-style: none; margin: 26px 0; font-size: 13.5px; color: var(--text-sub); }
        .price-features li { margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }

        /* ── Alerts & Badges ──────────────────────────────── */
        .alert {
            padding: 14px 20px; border-radius: var(--btn-radius);
            font-size: 13.5px; font-weight: 600; margin-bottom: 24px;
            backdrop-filter: blur(15px);
        }
        .alert-success { background: rgba(48, 209, 88, 0.15); border: 1px solid rgba(48, 209, 88, 0.35); color: var(--success); }
        .alert-error { background: rgba(255, 69, 58, 0.15); border: 1px solid rgba(255, 69, 58, 0.35); color: var(--danger); }
        .alert-pending {
            background: rgba(255, 214, 10, 0.15); border: 1px solid rgba(255, 214, 10, 0.35); color: var(--warning);
            animation: pulseGlow 2s infinite;
        }
        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(255, 214, 10, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(255, 214, 10, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 214, 10, 0); }
        }

        .badge { padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .badge-active { background: rgba(48, 209, 88, 0.15); border: 1px solid rgba(48, 209, 88, 0.35); color: var(--success); }
        .badge-trial { background: rgba(255, 214, 10, 0.15); border: 1px solid rgba(255, 214, 10, 0.35); color: var(--warning); }
        .badge-banned { background: rgba(255, 69, 58, 0.15); border: 1px solid rgba(255, 69, 58, 0.35); color: var(--danger); }

        /* ── User Dashboard & Key Box ─────────────────────── */
        .dash-header {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--bg-card); backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border-subtle); border-radius: var(--card-radius);
            padding: 26px 32px; margin: 32px 0; box-shadow: var(--glass-shadow);
        }
        .key-box {
            background: rgba(0, 0, 0, 0.55);
            border: 1px solid rgba(10, 132, 255, 0.40);
            border-radius: 14px; padding: 18px 24px; margin: 16px 0;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }
        .key-text {
            font-family: -apple-system, BlinkMacSystemFont, "SF Mono", monospace;
            font-size: 17px; font-weight: 800; color: #64d2ff; letter-spacing: 1px;
        }

        /* ── Tabs & Navigation ────────────────────────────── */
        .tab-bar {
            display: flex; gap: 8px; border-bottom: 1px solid var(--border-subtle);
            margin-bottom: 26px; padding-bottom: 4px;
        }
        .tab-btn {
            background: transparent; border: none; padding: 10px 18px;
            color: var(--text-sub); font-weight: 700; font-size: 13.5px;
            cursor: pointer; border-radius: var(--btn-radius);
            transition: all 0.2s ease;
        }
        .tab-btn:hover { color: #ffffff; background: rgba(255, 255, 255, 0.08); }
        .tab-btn.active {
            color: #ffffff; background: var(--primary);
            box-shadow: 0 4px 14px rgba(10, 132, 255, 0.35);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ── Forms, Inputs & Tables ───────────────────────── */
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 12px; font-weight: 700; color: var(--text-sub); margin-bottom: 8px; }
        input[type="text"], input[type="password"], input[type="email"], textarea, select {
            width: 100%; padding: 12px 16px;
            background: rgba(44, 44, 46, 0.60);
            border: 1px solid var(--border-subtle);
            border-radius: var(--btn-radius);
            color: #ffffff; font-size: 13.5px; outline: none;
            transition: all 0.2s ease;
        }
        input:focus, textarea:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 132, 255, 0.25);
        }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; }
        th {
            text-align: left; padding: 12px 14px;
            background: rgba(28, 28, 30, 0.85); color: var(--text-muted);
            font-size: 11.5px; text-transform: uppercase; font-weight: 700;
            border-bottom: 1px solid var(--border-subtle);
        }
        td { padding: 14px; border-bottom: 1px solid var(--border-subtle); vertical-align: middle; }
        tr:hover td { background: rgba(255, 255, 255, 0.03); }

        /* ── Modals (Liquid Glass) ────────────────────────── */
        .modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            z-index: 9999; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-card {
            background: rgba(28, 28, 30, 0.88);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border-hi);
            border-radius: var(--modal-radius);
            max-width: 500px; width: 100%; padding: 32px; position: relative;
            box-shadow: var(--glass-shadow);
        }
        .modal-close {
            position: absolute; top: 18px; right: 18px;
            background: rgba(255, 255, 255, 0.08); border: 1px solid var(--border-subtle);
            width: 32px; height: 32px; border-radius: 50%;
            color: var(--text-sub); font-size: 18px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s ease;
        }
        .modal-close:hover { color: #fff; background: rgba(255, 255, 255, 0.16); transform: scale(1.05); }

        /* ── 📱 Responsive 100% Vertical Mobile ───────────── */
        @media (max-width: 768px) {
            * { max-width: 100% !important; box-sizing: border-box !important; }
            html, body { overflow-x: hidden !important; width: 100% !important; }
            .container { padding: 0 14px !important; width: 100% !important; max-width: 100% !important; }
            nav { padding: 12px 0 !important; }
            nav .container { flex-direction: column !important; gap: 10px !important; align-items: center !important; }
            .nav-brand { justify-content: center !important; font-size: 18px !important; }
            .nav-links { 
                display: flex !important; flex-wrap: wrap !important; 
                justify-content: center !important; gap: 6px !important; 
                width: 100% !important; overflow: visible !important;
            }
            .nav-link { 
                font-size: 11.5px !important; padding: 6px 10px !important; 
                background: rgba(255,255,255,0.06) !important; border-radius: 8px !important; 
                text-align: center !important;
            }
            .hero { padding: 40px 0 30px !important; }
            .hero-badge { font-size: 11px !important; padding: 4px 14px !important; }
            .hero-title { font-size: 26px !important; line-height: 1.3 !important; }
            .hero-desc { font-size: 13.5px !important; margin-bottom: 24px !important; }
            .hero-cta { flex-direction: column !important; width: 100% !important; gap: 10px !important; }
            .hero-cta .btn-primary, .hero-cta .btn-outline { width: 100% !important; justify-content: center !important; }
            .sec-title { font-size: 22px !important; }
            .sec-subtitle { font-size: 12.5px !important; margin-bottom: 24px !important; }
            .grid-3 { grid-template-columns: 1fr !important; gap: 16px !important; margin-bottom: 28px !important; }
            .pricing-card { padding: 24px 20px !important; }
            .price-val { font-size: 30px !important; }
            .dashboard-header { flex-direction: column !important; align-items: stretch !important; gap: 12px !important; }
            .dashboard-header > div:first-child { text-align: center !important; }
            .dashboard-header > div:last-child { display: flex !important; flex-direction: column !important; width: 100% !important; gap: 8px !important; }
            .dashboard-header .btn-primary, .dashboard-header .btn-outline { width: 100% !important; justify-content: center !important; }
            .tab-bar { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav>
        <div class="container" style="display:flex;justify-content:space-between;align-items:center;width:100%">
            <div class="nav-brand">
                <span>🎬</span>
                <span>2TAMNE.SITE</span>
            </div>
            <div class="nav-links">
                <a href="#products" class="nav-link" style="color:#38bdf8;font-weight:800">🎁 Sản Phẩm</a>
                <a href="#features" class="nav-link">Tính Năng</a>
                <a href="#pricing" class="nav-link">Bảng Giá</a>
                <a href="#download" class="nav-link">Tải Về</a>
                <?php if ($user_info): ?>
                    <a href="?logout=1" class="btn-outline" style="font-size:12px">Đăng Xuất (<?= htmlspecialchars($user_info['username']) ?>) 🚪</a>
                <?php else: ?>
                    <button class="btn-outline" onclick="openModal('modal-login')">Đăng Nhập</button>
                    <div class="register-btn-wrap">
                        <div class="trial-badge-callout">
                            🎁 Nhận Key 3 Ngày
                            <span class="callout-arrow"></span>
                        </div>
                        <button class="btn-primary shimmer-button" onclick="openModal('modal-register')">✨ Đăng Ký</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg_success): ?>
            <div class="alert alert-success" style="margin-top:20px"><?= $msg_success ?></div>
        <?php endif; ?>
        <?php if ($msg_error): ?>
            <div class="alert alert-error" style="margin-top:20px"><?= $msg_error ?></div>
        <?php endif; ?>

        <?php if ($user_info): ?>
            <!-- ===================================================== -->
            <!-- USER DASHBOARD (KHI ĐÃ ĐĂNG NHẬP) -->
            <!-- ===================================================== -->
            <div class="dash-header">
                <div>
                    <h2 style="font-size:22px;font-weight:900;color:#fff">Xin chào, <?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?> 👋</h2>
                    <p style="font-size:13px;color:#94a3b8">Quản lý License Key · Mua Gói VIP · Đóng góp ý kiến nâng cấp</p>
                </div>
                <div style="display:flex;gap:10px">
                    <button class="btn-primary" onclick="switchMainTab('tab-buy-key')">🛒 Mua / Thuê Key VIP</button>
                    <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn-outline">📥 Tải Bản Cài Đặt (v2.2.3.17)</a>
                </div>
            </div>

            <!-- PENDING ORDER NOTICE IF ANY -->
            <div id="pending-banners-wrap">
            <?php if (!empty($user_pending_orders)): ?>
                <?php foreach ($user_pending_orders as $p_ord): ?>
                    <div class="alert alert-pending" style="display:flex;justify-content:space-between;align-items:center;animation:pulseGlow 2s infinite">
                        <div>
                            ⏳ <b>ĐƠN HÀNG ĐANG CHỜ DUYỆT:</b> Bạn vừa tạo đơn mua <b><?= htmlspecialchars($p_ord['package_name']) ?> (<?= htmlspecialchars($p_ord['package_price']) ?>)</b>.
                            <br><span style="font-size:12px;color:#cbd5e1">Key đang được Admin duyệt, vui lòng chờ · Tự kiểm tra sau <b id="poll-sec">10</b>s...</span>
                        </div>
                        <a href="https://zalo.me/0326649304" target="_blank" class="btn-outline" style="background:#0b0f19;border-color:#f59e0b;color:#fcd34d;font-size:11.5px">💬 Nhắn Admin Duyệt Ngay</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <!-- MAIN DASHBOARD TABS -->
            <div class="tab-bar" style="margin-top:10px">
                <button class="tab-btn active" id="btn-tab-keys" onclick="switchMainTab('tab-my-keys')">🔑 Quản Lý License Key</button>
                <button class="tab-btn" id="btn-tab-buy" onclick="switchMainTab('tab-buy-key')">🛒 Mua / Thuê Gói VIP Mới</button>
                <button class="tab-btn" id="btn-tab-features" onclick="switchMainTab('tab-features-view')">🌟 Mong Muốn Update (3/tháng)</button>
                <button class="tab-btn" id="btn-tab-bugs" onclick="switchMainTab('tab-bugs-view')">🐞 Báo Lỗi Phần Mềm</button>
                <button class="tab-btn" id="btn-tab-settings" onclick="switchMainTab('tab-settings')">⚙️ Tài Khoản</button>
            </div>

            <!-- TAB 1: MY KEYS -->
            <div id="tab-my-keys" class="tab-content active">
                <div class="feature-card" style="margin-bottom:30px">
                    <h3 style="font-size:17px;font-weight:800;color:#f8fafc;margin-bottom:14px">🔑 DANH SÁCH LICENSE KEY CỦA BẠN</h3>
                    
                    <div id="in-tab-pending-notice">
                    <?php if (!empty($user_pending_orders)): ?>
                        <div style="background:rgba(245,158,11,0.1);border:1px dashed #f59e0b;padding:12px 18px;border-radius:10px;color:#fcd34d;font-size:12.5px;margin-bottom:14px">
                            ⏳ <b><?= count($user_pending_orders) ?> đơn hàng đang được Admin duyệt.</b> Key sẽ tự động xuất hiện tại đây khi được duyệt.
                        </div>
                    <?php endif; ?>
                    </div>

                    <?php 
                    $my_keys = $user_info['keys'] ?? [];
                    if (empty($my_keys)): 
                    ?>
                        <div style="background:#0b0f19;padding:24px;border-radius:10px;text-align:center;color:#64748b">
                            Bạn chưa sở hữu License Key nào. Hãy bấm sang tab <b>"🛒 Mua / Thuê Gói VIP Mới"</b> để chọn gói phù hợp!
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_keys as $k): 
                            $lic = $licenses_db[$k] ?? null;
                            if (!$lic) continue;
                            $status = $lic['status'] ?? 'active';
                            $tier = $lic['tier'] ?? 'VIP';
                            $hwid = $lic['hwid'] ?? '';
                        ?>
                            <div class="key-box">
                                <div>
                                    <?php
                                    $is_ext = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos($k, '2TAMNE-LABS-') === 0;
                                    ?>
                                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
                                        <span class="key-text"><?= htmlspecialchars($k) ?></span>
                                        <span class="badge <?= $tier === 'TRIAL' ? 'badge-trial' : 'badge-active' ?>"><?= $tier ?></span>
                                        <?php if ($is_ext): ?>
                                            <span class="badge" style="background:rgba(56,189,248,0.2);color:#38bdf8;border:1px solid #0284c7">🖼️ Extension Google Labs</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid #6366f1">🎬 Tool Video AI</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:12px;color:#94a3b8">
                                        Thời hạn: <b><?= $lic['expires_at'] ? (strpos($lic['expires_at'], '2099') !== false ? '👑 Vĩnh viễn (Lifetime)' : $lic['expires_at']) : ($lic['duration_days'] . ' ngày (bắt đầu tính khi kích hoạt)') ?></b>
                                        <?php if ($hwid): ?>
                                            · Thiết bị đã liên kết: <code style="color:#38bdf8"><?= substr($hwid, 0, 16) ?>...</code> (<?= htmlspecialchars($lic['device_name'] ?: 'Desktop') ?>)
                                        <?php else: ?>
                                            · <span style="color:#10b981">Chưa kích hoạt trên máy nào</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display:flex;gap:8px">
                                    <button class="btn-outline" style="padding:7px 14px;font-size:12px" onclick="copyText('<?= htmlspecialchars($k) ?>')">📋 Sao Chép Key</button>
                                    <?php if ($hwid): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Bạn có chắc chắn muốn Reset HWID để chuyển sang máy tính mới?')">
                                            <input type="hidden" name="action" value="reset_hwid">
                                            <input type="hidden" name="key" value="<?= htmlspecialchars($k) ?>">
                                            <button type="submit" class="btn-outline" style="padding:7px 14px;font-size:12px;border-color:#0284c7;color:#38bdf8">🔄 Đổi Máy (Reset HWID)</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: BUY KEY WITH QR PAYMENT -->
            <div id="tab-buy-key" class="tab-content">
                <div class="feature-card" style="margin-bottom:30px">
                    <h3 style="font-size:18px;font-weight:800;color:#fff;margin-bottom:8px">🛒 CHỌN GÓI BẢN QUYỀN VIP</h3>
                    <p style="font-size:13px;color:#94a3b8;margin-bottom:24px">Thanh toán qua chuyển khoản quét mã VietQR tự động — Hỗ trợ <b>Update Trọn Đời</b></p>
                    
                    <!-- SECTION: EXTENSION GOOGLE LABS 100K -->
                    <div style="background:linear-gradient(180deg,#131b33 0%,#0b0f19 100%);border:2px solid #0284c7;border-radius:16px;padding:24px;margin-bottom:30px;box-shadow:0 10px 30px rgba(56,189,248,0.15)">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                            <div>
                                <span style="background:rgba(56,189,248,0.2);color:#38bdf8;border:1px solid #0284c7;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:900">HOT DEAL 100K</span>
                                <h4 style="font-size:18px;font-weight:900;color:#fff;margin-top:6px">🖼️ EXTENSION GOOGLE LABS 2K/4K (VĨNH VIỄN)</h4>
                                <p style="font-size:12.5px;color:#94a3b8;margin-top:4px">Tự động tải hàng loạt ảnh 2K/4K từ Google Labs Flow, tự động đánh số 001→xxx, thu nhỏ thông minh.</p>
                            </div>
                            <div style="text-align:right">
                                <div style="font-size:28px;font-weight:900;color:#38bdf8">100.000đ</div>
                                <button type="button" class="btn-primary" style="margin-top:8px;padding:10px 20px;background:linear-gradient(135deg,#0284c7,#38bdf8)" onclick="openQrPayment('Extension Google Labs (Vĩnh Viễn)', '100.000đ', 36500, 'LIFETIME', 'LABS_EXTENSION')">
                                    ⚡ Mua Key Extension (100k)
                                </button>
                            </div>
                        </div>
                    </div>

                    <h4 style="font-size:15px;font-weight:800;color:#cbd5e1;margin-bottom:14px">🎬 GÓI BẢN QUYỀN TOOL TẠO VIDEO AI (SLIDESHOW BUILDER)</h4>
                    <div class="grid-3" style="margin-bottom:20px">
                        <!-- GÓI 1 THÁNG -->
                        <div class="pricing-card" style="background:#0b0f19">
                            <div>
                                <h4 style="font-size:17px;font-weight:800;color:#fff">GÓI 1 THÁNG</h4>
                                <div class="price-val" style="font-size:28px">1.000.000đ</div>
                                <div class="price-period">Thời hạn: 30 ngày</div>
                                <ul class="price-features">
                                    <li>✅ Mở khóa toàn bộ tính năng 4K</li>
                                    <li>✅ Xuất video không giới hạn</li>
                                    <li>✅ Update trọn đời trong kỳ thuê</li>
                                </ul>
                            </div>
                            <button type="button" class="btn-outline" onclick="openQrPayment('Gói 1 Tháng', '1.000.000đ', 30, 'VIP')">⚡ Mua Gói 1 Tháng</button>
                        </div>

                        <!-- GÓI 1 NĂM -->
                        <div class="pricing-card pricing-vip" style="background:linear-gradient(180deg,#131b33 0%,#0b0f19 100%)">
                            <span class="pricing-tag">TIẾT KIỆM 2 TRIỆU</span>
                            <div>
                                <h4 style="font-size:17px;font-weight:800;color:#fff">GÓI 1 NĂM (VIP)</h4>
                                <div class="price-val" style="font-size:28px">10.000.000đ</div>
                                <div class="price-period">Thời hạn: 365 ngày</div>
                                <ul class="price-features">
                                    <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                    <li>✅ Hỗ trợ ưu tiên 1-1 từ Admin</li>
                                    <li>✅ Cập nhật tính năng mới liên tục</li>
                                </ul>
                            </div>
                            <button type="button" class="btn-primary" onclick="openQrPayment('Gói 1 Năm (VIP)', '10.000.000đ', 365, 'VIP')">⚡ Mua Gói 1 Năm</button>
                        </div>

                        <!-- GÓI VĨNH VIỄN -->
                        <div class="pricing-card" style="background:#0b0f19">
                            <div>
                                <h4 style="font-size:17px;font-weight:800;color:#fff">GÓI VĨNH VIỄN</h4>
                                <div class="price-val" style="font-size:28px">15.000.000đ</div>
                                <div class="price-period">Sở hữu trọn đời (Lifetime)</div>
                                <ul class="price-features">
                                    <li>👑 <b>Miễn phí 100% mọi bản Update tương lai</b></li>
                                    <li>👑 Hỗ trợ kỹ thuật trọn đời</li>
                                    <li>👑 Ưu tiên phát triển tính năng riêng</li>
                                </ul>
                            </div>
                            <button type="button" class="btn-outline" onclick="openQrPayment('Gói Vĩnh Viễn (Lifetime)', '15.000.000đ', 36500, 'LIFETIME')">👑 Mua Gói Vĩnh Viễn</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 5: ACCOUNT SETTINGS -->
            <div id="tab-settings" class="tab-content">
                <div class="feature-card" style="max-width:520px">
                    <h3 style="font-size:17px;font-weight:800;color:#fff;margin-bottom:20px">⚙️ CÀI ĐẶT TÀI KHOẢN</h3>

                    <div style="background:#0b0f19;padding:20px;border-radius:12px;border:1px solid #1e293b;margin-bottom:16px">
                        <h4 style="font-size:14px;font-weight:800;color:#cbd5e1;margin-bottom:14px">👤 Thông Tin Tài Khoản</h4>
                        <div style="font-size:13px;color:#94a3b8;line-height:2">
                            Tên đăng nhập: <b style="color:#fff"><?= htmlspecialchars($user_info['username']) ?></b><br>
                            Họ tên: <b style="color:#fff"><?= htmlspecialchars($user_info['fullname'] ?: '(Chưa cập nhật)') ?></b><br>
                            SĐT / Zalo: <b style="color:#fff"><?= htmlspecialchars($user_info['phone'] ?: '(Chưa cập nhật)') ?></b><br>
                            Ngày đăng ký: <b style="color:#fff"><?= $user_info['created_at'] ?? '?' ?></b>
                        </div>
                    </div>

                    <div style="background:#0b0f19;padding:20px;border-radius:12px;border:1px solid #1e293b">
                        <h4 style="font-size:14px;font-weight:800;color:#cbd5e1;margin-bottom:14px">🔐 Đổi Mật Khẩu</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-group">
                                <label>MẬT KHẨU HIỆN TẠI:</label>
                                <input type="password" name="old_password" required>
                            </div>
                            <div class="form-group">
                                <label>MẬT KHẨU MỚI (tối thiểu 6 ký tự):</label>
                                <input type="password" name="new_password" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label>XÁC NHẬN MẬT KHẨU MỚI:</label>
                                <input type="password" name="confirm_password" required>
                            </div>
                            <button type="submit" class="btn-primary" style="background:linear-gradient(135deg,#10b981,#059669)">🔐 Đổi Mật Khẩu</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- TAB 3: FEATURE REQUEST -->
            <div id="tab-features-view" class="tab-content">
                <div class="feature-card">
                    <h3 style="font-size:17px;font-weight:800;color:#fff;margin-bottom:6px">🌟 PHIẾU MONG MUỐN UPDATE</h3>
                    <?php
                    $cur_month_feat = date('Y-m');
                    $feat_used = 0;
                    foreach ($features_db as $fi_c) {
                        if (($fi_c['user'] ?? '') === $current_user && strpos($fi_c['created_at'] ?? '', $cur_month_feat) === 0) $feat_used++;
                    }
                    $feat_left = max(0, 3 - $feat_used);
                    // Check if user has any active non-trial key
                    $has_active_key = false;
                    foreach (($user_info['keys'] ?? []) as $uk) {
                        if (isset($licenses_db[$uk]) && ($licenses_db[$uk]['status'] ?? '') === 'active' && ($licenses_db[$uk]['tier'] ?? '') !== 'TRIAL') {
                            $has_active_key = true; break;
                        }
                    }
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
                        <p style="font-size:12.5px;color:#94a3b8">Mỗi tháng tài khoản của bạn được tạo <b>tối đa 3 phiếu</b> để yêu cầu thêm tính năng mới.</p>
                        <span style="background:<?= $feat_left > 0 ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' ?>;border:1px solid <?= $feat_left > 0 ? '#10b981' : '#ef4444' ?>;color:<?= $feat_left > 0 ? '#34d399' : '#f87171' ?>;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:800;white-space:nowrap">
                            <?= $feat_left > 0 ? "✅ Còn {$feat_left}/3 phiếu tháng này" : "❌ Đã dùng hết 3/3 phiếu tháng này" ?>
                        </span>
                    </div>
                    <?php if (!$has_active_key): ?>
                    <div style="background:rgba(99,102,241,0.1);border:1px solid #6366f1;padding:14px 18px;border-radius:10px;color:#a5b4fc;font-size:13px;margin-bottom:16px">
                        🔒 Tính năng này chỉ dành cho thành viên có <b>Key VIP / Lifetime</b>. Hãy mua gói để mở khóa!
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($has_active_key && $feat_left > 0): ?>
                    <form method="POST" style="background:#0b0f19;padding:20px;border-radius:12px;margin-bottom:24px;border:1px solid #1e293b">
                        <input type="hidden" name="action" value="add_feature">
                        <div class="form-group">
                            <label>TIÊU ĐỀ TÍNH NĂNG MONG MUỐN:</label>
                            <input type="text" name="title" placeholder="Ví dụ: Thêm hiệu ứng 3D Ken Burns lượn sóng" required>
                        </div>
                        <div class="form-group">
                            <label>MÔ TẢ CHI TIẾT CÁCH TÍNH NĂNG HOẠT ĐỘNG:</label>
                            <textarea name="description" rows="3" placeholder="Mô tả kỹ tính năng bạn cần để đội ngũ kỹ thuật phát triển ở bản cập nhật tới..." required></textarea>
                        </div>
                        <button type="submit" class="btn-primary">🚀 Gửi Phiếu Mong Muốn Update</button>
                    </form>
                    <?php endif; ?>

                    <h4 style="font-size:14px;color:#cbd5e1;margin-bottom:10px">📋 Lịch sử phiếu bạn đã gửi:</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Tiêu đề</th>
                                <th>Nội dung</th>
                                <th>Ngày gửi</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $my_feats = array_filter($features_db, fn($x) => ($x['user'] ?? '') === $current_user);
                            if (empty($my_feats)): 
                            ?>
                                <tr><td colspan="4" style="text-align:center;color:#64748b">Bạn chưa gửi phiếu mong muốn update nào.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($my_feats) as $f): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($f['title']) ?></b></td>
                                        <td style="color:#94a3b8"><?= nl2br(htmlspecialchars($f['description'])) ?></td>
                                        <td style="font-size:11px;color:#64748b"><?= $f['created_at'] ?></td>
                                        <td><span class="badge badge-active"><?= htmlspecialchars($f['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 4: BUG REPORT -->
            <div id="tab-bugs-view" class="tab-content">
                <div class="feature-card">
                    <h3 style="font-size:17px;font-weight:800;color:#fff;margin-bottom:6px">🐞 BÁO LỖI PHẦN MỀM (VÔ HẠN)</h3>
                    <p style="font-size:12.5px;color:#94a3b8;margin-bottom:20px">Gửi báo cáo lỗi kỹ thuật để Admin hỗ trợ khắc phục nhanh nhất.</p>

                    <form method="POST" style="background:#0b0f19;padding:20px;border-radius:12px;margin-bottom:24px;border:1px solid #1e293b">
                        <input type="hidden" name="action" value="add_bug">
                        <div class="form-group">
                            <label>TIÊU ĐỀ LỖI GẶP PHẢI:</label>
                            <input type="text" name="title" placeholder="Ví dụ: Render video 4K bị thoát đột ngột" required>
                        </div>
                        <div class="form-group">
                            <label>MÃ LỖI (NẾU CÓ TRÊN BẢNG CHẨN ĐOÁN):</label>
                            <input type="text" name="error_code" placeholder="Ví dụ: ERR_FFMPEG_PROCESSING_CRASH">
                        </div>
                        <div class="form-group">
                            <label>CHI TIẾT CÁC BƯỚC DẪN ĐẾN LỖI:</label>
                            <textarea name="description" rows="3" placeholder="Mô tả các thao tác bạn vừa thực hiện trước khi gặp lỗi..." required></textarea>
                        </div>
                        <button type="submit" class="btn-primary" style="background:linear-gradient(135deg,#ef4444,#f59e0b)">🐞 Gửi Báo Cáo Lỗi</button>
                    </form>

                    <h4 style="font-size:14px;color:#cbd5e1;margin-bottom:10px">📋 Lịch sử báo lỗi của bạn:</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Tiêu đề lỗi</th>
                                <th>Chi tiết</th>
                                <th>Mã lỗi</th>
                                <th>Ngày gửi</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $my_bugs = array_filter($bugs_db, fn($x) => ($x['user'] ?? '') === $current_user);
                            if (empty($my_bugs)): 
                            ?>
                                <tr><td colspan="5" style="text-align:center;color:#64748b">Chưa có báo cáo lỗi nào.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($my_bugs) as $b): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($b['title']) ?></b></td>
                                        <td style="color:#94a3b8"><?= nl2br(htmlspecialchars($b['description'])) ?></td>
                                        <td><code><?= htmlspecialchars($b['error_code'] ?: '-') ?></code></td>
                                        <td style="font-size:11px;color:#64748b"><?= $b['created_at'] ?></td>
                                        <td><span class="badge badge-trial"><?= htmlspecialchars($b['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- ===================================================== -->
            <!-- ===================================================== -->
            <!-- LANDING PAGE PUBLIC (CHƯA ĐĂNG NHẬP) -->
            <!-- ===================================================== -->
            <section class="hero">
                <div class="hero-badge">🚀 HỆ SINH THÁI PHẦN MỀM TỰ ĐỘNG HÓA 2TAMNE.SITE</div>
                <h1 class="hero-title">Công Cụ Sáng Tạo Video & Đồ Họa AI<br><span style="color:#38bdf8">Tự Động Hóa Triệu View</span></h1>
                <p class="hero-desc">
                    Nền tảng cung cấp các phần mềm & extension tự động hóa chuyên nghiệp: Tạo video khớp nhạc 4K siêu mượt và Tải ảnh hàng loạt 2K/4K Google Labs Flow.
                </p>
                <div class="hero-cta">
                    <a href="#products" class="btn-primary btn-lg">🎁 Khám Phá Các Sản Phẩm</a>
                    <button class="btn-outline btn-lg" onclick="openModal('modal-register')">🎁 Đăng Ký Nhận Dùng Thử</button>
                </div>
            </section>

            <!-- ═══ KHU VỰC TAB SẢN PHẨM MỞ RỘNG (PRODUCTS SHOWCASE) ═══ -->
            <section id="products" style="padding:40px 0 60px">
                <div style="text-align:center;margin-bottom:30px">
                    <span style="background:rgba(99,102,241,0.15);border:1px solid #6366f1;color:#a5b4fc;padding:6px 18px;border-radius:20px;font-size:12px;font-weight:800;display:inline-block;margin-bottom:12px">💎 DANH MỤC PHẦN MỀM</span>
                    <h2 class="sec-title" style="font-size:32px">LỰA CHỌN SẢN PHẨM BẠN CẦN</h2>
                    <p class="sec-subtitle">Chuyển đổi giữa các tab để xem chi tiết tính năng, hướng dẫn cài đặt và bảng giá từng công cụ</p>
                </div>

                <!-- PRODUCT TABS BAR (EASILY EXTENSIBLE) -->
                <div style="display:flex;justify-content:center;gap:12px;margin-bottom:36px;flex-wrap:wrap">
                    <button class="btn-primary prod-tab-btn active" id="ptab-btn-video" onclick="switchProductTab('ptab-video', 'ptab-btn-video')" style="padding:12px 24px;font-size:14px;font-weight:900;border-radius:30px;box-shadow:0 8px 25px rgba(99,102,241,0.3)">
                        🎬 Tool Tạo Video AI (Slideshow Builder)
                    </button>
                    <button class="btn-outline prod-tab-btn" id="ptab-btn-extension" onclick="switchProductTab('ptab-extension', 'ptab-btn-extension')" style="padding:12px 24px;font-size:14px;font-weight:900;border-radius:30px;border-color:#0284c7;color:#38bdf8">
                        🖼️ Extension Google Labs Flow (Tải Ảnh 2K/4K)
                    </button>
                </div>

                <!-- ── PRODUCT 1: SLIDESHOW BUILDER AI ── -->
                <div id="ptab-video" class="prod-tab-content" style="display:block">
                    <!-- Features -->
                    <div id="features" class="grid-3" style="margin-bottom:40px;scroll-margin-top:90px">
                        <div class="feature-card">
                            <div class="feat-icon">🎥</div>
                            <div class="feat-title">Cú Máy Ken Burns 4K</div>
                            <div class="feat-desc">Áp dụng công thức Sine Easing & Subpixel nội suy Bicubic, chuyển động mượt mà tuyệt đối ở cả 1080p, 2K và 4K.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feat-icon">⚡</div>
                            <div class="feat-title">Lồng Tiếng AI 0% RAM</div>
                            <div class="feat-desc">Tích hợp Edge-TTS Microsoft Cloud và VoxCPM thế hệ mới, sinh giọng đọc chuẩn phát thanh viên siêu tốc.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feat-icon">🔤</div>
                            <div class="feat-title">Phụ Đề Pill & Font Noonnu</div>
                            <div class="feat-desc">Tự động xuống dòng thông minh, căn chỉnh vị trí linh hoạt và tích hợp sẵn 5 font chữ Hàn Quốc siêu đẹp.</div>
                        </div>
                    </div>

                    <!-- Video Tool Download Box (Windows & macOS Dedicated) -->
                    <div style="background:#101626;border:1px solid #6366f1;border-radius:18px;padding:28px;margin-bottom:40px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                        <div>
                            <span class="badge badge-active" style="margin-bottom:8px;display:inline-block">BẢN CHÍNH THỨC v2.2.3.17</span>
                            <h3 style="font-size:20px;font-weight:900;color:#fff">Tải Bản Cài Đặt Slideshow Builder AI</h3>
                            <p style="font-size:13px;color:#94a3b8;margin-top:4px">Phiên bản tối ưu hóa riêng biệt cho Windows & macOS — Giải nén là chạy ngay lập tức.</p>
                        </div>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                            <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn-primary" style="padding:12px 20px;background:linear-gradient(135deg,#0284c7,#38bdf8)">🪟 Tải Cho Windows (.zip)</a>
                            <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn-primary" style="padding:12px 20px;background:linear-gradient(135deg,#6366f1,#a855f7)">🍎 Tải Cho macOS (.zip)</a>
                            <button class="btn-outline" style="padding:12px 18px" onclick="openModal('modal-register')">🎁 Dùng Thử 3 Ngày</button>
                        </div>
                    </div>

                    <!-- Pricing Video -->
                    <h3 id="pricing" style="text-align:center;font-size:24px;font-weight:800;color:#fff;margin-bottom:24px;scroll-margin-top:80px">💰 BẢNG GIÁ BẢN QUYỀN TOOL VIDEO</h3>
                    <div class="grid-3">
                        <div class="pricing-card">
                            <div>
                                <h3 style="font-size:18px;font-weight:800;color:#fff">GÓI 1 THÁNG</h3>
                                <p style="font-size:12px;color:#94a3b8">Dành cho cá nhân trải nghiệm dự án</p>
                                <div class="price-val">1.000.000đ</div>
                                <div class="price-period">Thời hạn: 30 ngày sử dụng</div>
                                <ul class="price-features">
                                    <li>✅ Mở khóa toàn bộ tính năng 4K</li>
                                    <li>✅ Xuất video không giới hạn</li>
                                    <li>✅ Hỗ trợ Update trong suốt thời gian thuê</li>
                                    <li>✅ Tặng 3 phiếu mong muốn update / tháng</li>
                                </ul>
                            </div>
                            <button class="btn-outline" style="width:100%" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>

                        <div class="pricing-card pricing-vip">
                            <span class="pricing-tag">TIẾT KIỆM 2 TRIỆU</span>
                            <div>
                                <h3 style="font-size:18px;font-weight:800;color:#fff">GÓI 1 NĂM (VIP)</h3>
                                <p style="font-size:12px;color:#94a3b8">Lựa chọn tối ưu cho nhà sáng tạo</p>
                                <div class="price-val">10.000.000đ</div>
                                <div class="price-period">Thời hạn: 365 ngày sử dụng</div>
                                <ul class="price-features">
                                    <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                    <li>✅ Hỗ trợ ưu tiên 1-1 từ Admin</li>
                                    <li>✅ Cập nhật tính năng mới liên tục</li>
                                    <li>✅ Reset đổi máy tính linh hoạt</li>
                                </ul>
                            </div>
                            <button class="btn-primary" style="width:100%" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>

                        <div class="pricing-card">
                            <div>
                                <h3 style="font-size:18px;font-weight:800;color:#fff">GÓI VĨNH VIỄN</h3>
                                <p style="font-size:12px;color:#94a3b8">Đầu tư 1 lần — Dùng trọn đời</p>
                                <div class="price-val">15.000.000đ</div>
                                <div class="price-period">Sở hữu trọn đời (Lifetime)</div>
                                <ul class="price-features">
                                    <li>👑 <b>Miễn phí 100% mọi bản Update tương lai</b></li>
                                    <li>👑 Quyền lợi VIP cao nhất</li>
                                    <li>👑 Hỗ trợ kỹ thuật trọn đời</li>
                                    <li>👑 Ưu tiên phát triển tính năng riêng</li>
                                </ul>
                            </div>
                            <button class="btn-outline" style="width:100%" onclick="openModal('modal-login')">👑 Đăng Nhập Để Mua</button>
                        </div>
                    </div>
                </div>

                <!-- ── PRODUCT 2: GOOGLE LABS EXTENSION ── -->
                <div id="ptab-extension" class="prod-tab-content" style="display:none">
                    <!-- Extension Features -->
                    <div class="grid-3" style="margin-bottom:40px">
                        <div class="feature-card">
                            <div style="font-size:32px;margin-bottom:16px">🎯</div>
                            <div style="font-size:18px;font-weight:800;color:#f8fafc;margin-bottom:10px">Tự Động Chọn 2K / 4K</div>
                            <div style="font-size:13.5px;color:#94a3b8">Tự động hover vào menu "Tải xuống", lựa chọn chuẩn 2K nét căng và bỏ qua các nút yêu cầu nâng cấp/bị khóa.</div>
                        </div>
                        <div class="feature-card">
                            <div style="font-size:32px;margin-bottom:16px">🔢</div>
                            <div style="font-size:18px;font-weight:800;color:#f8fafc;margin-bottom:10px">Đánh Số 001→xxx Chuẩn Xác</div>
                            <div style="font-size:13.5px;color:#94a3b8">Thuật toán Bottom-Up (dưới lên trên, phải qua trái) đảm bảo ảnh đầu tiên ở góc phải dưới cùng luôn là 001.png.</div>
                        </div>
                        <div class="feature-card">
                            <div style="font-size:32px;margin-bottom:16px">🗕</div>
                            <div style="font-size:18px;font-weight:800;color:#f8fafc;margin-bottom:10px">Thu Nhỏ Gọn & Kéo Thả</div>
                            <div style="font-size:13.5px;color:#94a3b8">Bảng điều khiển tự thu gọn khi chạy để tránh che khuất giao diện làm việc, hỗ trợ kéo thả tự do trên màn hình.</div>
                        </div>
                    </div>

                    <!-- Installation Guide -->
                    <div style="background:#101626;border:1px solid #0284c7;border-radius:18px;padding:32px;margin-bottom:40px">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px">
                            <div>
                                <h3 style="font-size:20px;font-weight:900;color:#fff">📖 HƯỚNG DẪN CÀI ĐẶT EXTENSION (EDGE & CHROME)</h3>
                                <p style="font-size:13px;color:#94a3b8">Cài đặt trực tiếp chỉ trong 30 giây mà không cần qua Chrome Web Store</p>
                            </div>
                            <a href="/downloads/2tamne_Labs_Extension_v1.5.0.2.zip?v=1.5.0.2" class="btn-primary" style="padding:12px 22px;font-size:13.5px;background:linear-gradient(135deg,#0284c7,#38bdf8)">📥 Tải File Extension (.zip)</a>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
                            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:12px;padding:18px">
                                <div style="font-size:24px;font-weight:900;color:#38bdf8;margin-bottom:8px">1️⃣</div>
                                <h4 style="font-size:14px;font-weight:800;color:#fff;margin-bottom:6px">Tải & Giải Nén</h4>
                                <p style="font-size:12.5px;color:#94a3b8">Tải file <code>2tamne_Labs_Extension_v1.0.zip</code> và giải nén ra thư mục trên máy tính.</p>
                            </div>
                            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:12px;padding:18px">
                                <div style="font-size:24px;font-weight:900;color:#38bdf8;margin-bottom:8px">2️⃣</div>
                                <h4 style="font-size:14px;font-weight:800;color:#fff;margin-bottom:6px">Mở Quản Lý Tiện Ích</h4>
                                <p style="font-size:12.5px;color:#94a3b8">Truy cập <code>chrome://extensions</code> (Chrome) hoặc <code>edge://extensions</code> (Edge).</p>
                            </div>
                            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:12px;padding:18px">
                                <div style="font-size:24px;font-weight:900;color:#38bdf8;margin-bottom:8px">3️⃣</div>
                                <h4 style="font-size:14px;font-weight:800;color:#fff;margin-bottom:6px">Bật Developer Mode</h4>
                                <p style="font-size:12.5px;color:#94a3b8">Gạt công tắc <b>Chế độ dành cho nhà phát triển</b> (Developer mode) sang BẬT.</p>
                            </div>
                            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:12px;padding:18px">
                                <div style="font-size:24px;font-weight:900;color:#38bdf8;margin-bottom:8px">4️⃣</div>
                                <h4 style="font-size:14px;font-weight:800;color:#fff;margin-bottom:6px">Tải Tiện Ích Đã Giải Nén</h4>
                                <p style="font-size:12.5px;color:#94a3b8">Bấm nút <b>Load Unpacked (Tải tiện ích đã giải nén)</b> và chọn thư mục vừa giải nén!</p>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Extension -->
                    <div style="max-width:520px;margin:0 auto">
                        <div class="pricing-card" style="background:linear-gradient(180deg,#131b33 0%,#0b0f19 100%);border:2px solid #0284c7;box-shadow:0 10px 40px rgba(56,189,248,0.25)">
                            <span class="pricing-tag" style="background:linear-gradient(135deg,#0284c7,#38bdf8)">HOT DEAL 100K</span>
                            <div>
                                <h3 style="font-size:20px;font-weight:900;color:#fff">🖼️ EXTENSION GOOGLE LABS 2K/4K</h3>
                                <p style="font-size:12px;color:#94a3b8">Tiện ích tải ảnh hàng loạt tự động</p>
                                <div class="price-val" style="color:#38bdf8">100.000đ</div>
                                <div class="price-period">Sở hữu vĩnh viễn (Lifetime)</div>
                                <ul class="price-features">
                                    <li>👑 <b>Sở hữu trọn đời vĩnh viễn</b></li>
                                    <li>👑 Tự động tải ảnh 2K/4K đánh số 001→xxx</li>
                                    <li>👑 Khóa bản quyền 1 máy (Hỗ trợ đổi máy linh hoạt)</li>
                                    <li>👑 Miễn phí 100% mọi bản Update tương lai</li>
                                </ul>
                            </div>
                            <button class="btn-primary" style="width:100%;padding:14px;background:linear-gradient(135deg,#0284c7,#38bdf8);font-size:14px;font-weight:900" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua (100k)</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ KHU VỰC TẢI VỀ PHẦN MỀM (DOWNLOADS SECTION) ═══ -->
            <section id="download" style="padding:40px 0 60px;scroll-margin-top:80px">
                <div style="text-align:center;margin-bottom:32px">
                    <span style="background:rgba(10,132,255,0.15);border:1px solid #0a84ff;color:#64d2ff;padding:6px 18px;border-radius:20px;font-size:12px;font-weight:800;display:inline-block;margin-bottom:12px">📥 TẢI PHẦN MỀM & EXTENSION</span>
                    <h2 class="sec-title" style="font-size:32px">TẢI VỀ PHIÊN BẢN MỚI NHẤT (v2.2.3.17)</h2>
                    <p class="sec-subtitle">Tương thích hoàn hảo trên cả Windows 10/11 và macOS Apple Silicon / Intel</p>
                </div>

                <div class="grid-3" style="margin-bottom:30px">
                    <!-- Download Card 1: Windows -->
                    <div class="feature-card liquid-glass" style="display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:36px;margin-bottom:14px">🪟</div>
                            <div class="feat-title">Bản Cài Windows (64-bit)</div>
                            <p class="feat-desc" style="margin-bottom:16px">Tự động kích hoạt GPU NVIDIA NVENC, AMD AMF & Intel QSV. Khóa chuẩn xuất 60 FPS mượt mà.</p>
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:18px">
                                📌 Yêu cầu: Windows 10 / 11 (64-bit) · Dung lượng: ~6.28 MB
                            </div>
                        </div>
                        <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn-primary shimmer-button" style="width:100%;padding:12px;font-weight:800;justify-content:center">
                            📥 Tải Bản Windows (.zip)
                        </a>
                    </div>

                    <!-- Download Card 2: macOS -->
                    <div class="feature-card liquid-glass" style="display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:36px;margin-bottom:14px">🍎</div>
                            <div class="feat-title">Bản Cài macOS (M1/M2/M3/M4 & Intel)</div>
                            <p class="feat-desc" style="margin-bottom:16px">Tối ưu hóa GPU Apple VideoToolbox Metal, siêu tiết kiệm pin và render 60 FPS tốc độ cao.</p>
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:18px">
                                📌 Yêu cầu: macOS 12 Monterey trở lên · Dung lượng: ~6.28 MB
                            </div>
                        </div>
                        <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn-primary shimmer-button" style="width:100%;padding:12px;font-weight:800;justify-content:center">
                            📥 Tải Bản macOS (.zip)
                        </a>
                    </div>

                    <!-- Download Card 3: Extension Google Labs -->
                    <div class="feature-card liquid-glass" style="display:flex;flex-direction:column;justify-content:space-between">
                        <div>
                            <div style="font-size:36px;margin-bottom:14px">🧩</div>
                            <div class="feat-title">Extension Google Labs Flow</div>
                            <p class="feat-desc" style="margin-bottom:16px">Tiện ích tự động tải ảnh 2K/4K hàng loạt, đánh số 001→xxx cho Chrome và Microsoft Edge.</p>
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:18px">
                                📌 Trình duyệt: Google Chrome / Edge · Cài đặt 30s
                            </div>
                        </div>
                        <a href="/downloads/2tamne_Labs_Extension_v1.5.0.2.zip?v=1.5.0.2" class="btn-outline" style="width:100%;padding:12px;font-weight:800;justify-content:center">
                            📥 Tải Extension (.zip)
                        </a>
                    </div>
                </div>
            </section>

        <?php endif; ?>
    </div>

    <!-- MODAL VIETQR PAYMENT WITH COUNTDOWN TIMER -->
    <div id="modal-qr-pay" class="modal">
        <div class="modal-card" style="max-width:440px;text-align:center">
            <button class="modal-close" onclick="closeModal('modal-qr-pay')">&times;</button>
            <h3 style="font-size:17px;font-weight:800;color:#fff;margin-bottom:4px">QUÉT MÃ VIETQR THANH TOÁN</h3>
            <p style="font-size:12px;color:#94a3b8;margin-bottom:12px">Gói chọn mua: <b id="qr-pkg-title" style="color:#38bdf8">...</b></p>
            
            <!-- QR IMAGE DISPLAY -->
            <div style="background:#fff;padding:10px;border-radius:12px;display:inline-block;margin-bottom:12px;box-shadow:0 10px 30px rgba(0,0,0,0.5)">
                <img src="/assets/vietqr_tamne.png" alt="Mã VietQR Thanh Toán" style="width:240px;height:auto;border-radius:6px;display:block">
            </div>

            <!-- TRANSFER INFO -->
            <div style="background:#0b0f19;border:1px solid #1e293b;border-radius:10px;padding:12px;text-align:left;font-size:12px;margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="color:#64748b">Ngân hàng:</span>
                    <b>VietinBank (PGD Thủ Đô)</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="color:#64748b">Chủ tài khoản:</span>
                    <b>HOANG LUONG TAM</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="color:#64748b">Số tài khoản:</span>
                    <b style="color:#38bdf8">101876965948</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="color:#64748b">Số tiền:</span>
                    <b id="qr-pkg-price" style="color:#34d399;font-size:14px">...</b>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span style="color:#64748b">Nội dung CK:</span>
                    <b id="qr-memo-text" style="color:#fcd34d">...</b>
                </div>
            </div>

            <!-- 2-MINUTE COUNTDOWN TIMER -->
            <div style="background:rgba(99,102,241,0.15);border:1px solid #6366f1;border-radius:8px;padding:8px;margin-bottom:14px;font-size:13px;font-weight:800;color:#a5b4fc">
                ⏱️ Thời gian giữ đơn thanh toán: <span id="countdown-timer" style="color:#f43f5e;font-size:15px">02:00</span>
            </div>

            <!-- SUBMIT BUTTON -->
            <form method="POST">
                <input type="hidden" name="action" value="submit_payment">
                <input type="hidden" name="package_name" id="form-pkg-name">
                <input type="hidden" name="package_price" id="form-pkg-price">
                <input type="hidden" name="duration_days" id="form-pkg-days">
                <input type="hidden" name="tier" id="form-pkg-tier">
                <input type="hidden" name="product" id="form-pkg-product" value="SLIDESHOW">
                <button type="submit" class="btn-primary" style="width:100%;padding:12px;font-size:14px">✅ Tôi Đã Thanh Toán</button>
            </form>
        </div>
    </div>

    <!-- MODAL LOGIN -->
    <div id="modal-login" class="modal">
        <div class="modal-card">
            <button class="modal-close" onclick="closeModal('modal-login')">&times;</button>
            <h3 style="font-size:18px;font-weight:800;margin-bottom:16px;color:#fff">🔐 ĐĂNG NHẬP TÀI KHOẢN</h3>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>TÊN ĐĂNG NHẬP:</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label>MẬT KHẨU:</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn-primary" style="width:100%">Đăng Nhập Ngay</button>
            </form>
        </div>
    </div>

    <!-- MODAL REGISTER -->
    <div id="modal-register" class="modal">
        <div class="modal-card">
            <button class="modal-close" onclick="closeModal('modal-register')">&times;</button>
            <h3 style="font-size:18px;font-weight:800;margin-bottom:6px;color:#fff">🎁 ĐĂNG KÝ NHẬN KEY 3 NGÀY</h3>
            <p style="font-size:12px;color:#94a3b8;margin-bottom:16px">Tự động cấp 1 License Key 3 ngày miễn phí cho IP của bạn</p>
            <form method="POST">
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label>TÊN ĐĂNG NHẬP (Chữ thường, số):</label>
                    <input type="text" name="username" placeholder="Ví dụ: hoangtam2026" required>
                </div>
                <div class="form-group">
                    <label>HỌ VÀ TÊN:</label>
                    <input type="text" name="fullname" placeholder="Ví dụ: Hoàng Tâm" required>
                </div>
                <div class="form-group">
                    <label>SỐ ĐIỆN THOẠI / ZALO (Để nhận hỗ trợ):</label>
                    <input type="text" name="phone" placeholder="Ví dụ: 09xx xxx xxx" required>
                </div>
                <div class="form-group">
                    <label>MẬT KHẨU:</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn-primary" style="width:100%">🚀 Tạo Tài Khoản & Nhận Key Ngay</button>
            </form>
        </div>
    </div>

    <footer style="border-top:1px solid #1e293b;padding:30px 0;text-align:center;font-size:12px;color:#64748b;margin-top:60px">
        <p>© 2026 <b>2TAMNE.SITE</b> — Phần mềm Slideshow Builder AI. Mọi quyền được bảo lưu.</p>
        <p style="margin-top:6px">Hotline / Zalo hỗ trợ: <a href="https://zalo.me/0326649304" target="_blank" style="color:#38bdf8;font-weight:700">0326649304 (Admin)</a></p>
    </footer>

    <script>
        let timerInterval = null;

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
            if (id === 'modal-qr-pay' && timerInterval) {
                clearInterval(timerInterval);
            }
        }
        function copyText(txt) {
            navigator.clipboard.writeText(txt).then(() => {
                alert('📋 Đã sao chép vào bộ nhớ đệm: ' + txt);
            });
        }
        const TAB_BTN_MAP = {
            'tab-my-keys': 'btn-tab-keys',
            'tab-buy-key': 'btn-tab-buy',
            'tab-features-view': 'btn-tab-features',
            'tab-bugs-view': 'btn-tab-bugs',
            'tab-settings': 'btn-tab-settings'
        };
        
        // Switch Homepage Product Tabs
        function switchProductTab(tabId, btnId) {
            document.querySelectorAll('.prod-tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.prod-tab-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline');
            });
            const targetEl = document.getElementById(tabId);
            const targetBtn = document.getElementById(btnId);
            if (targetEl) targetEl.style.display = 'block';
            if (targetBtn) {
                targetBtn.classList.add('active');
                targetBtn.classList.add('btn-primary');
                targetBtn.classList.remove('btn-outline');
            }
        }

        function switchMainTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            if (TAB_BTN_MAP[tabId]) document.getElementById(TAB_BTN_MAP[tabId]).classList.add('active');
        }

        function openQrPayment(pkgName, pkgPrice, days, tier, product = 'SLIDESHOW') {
            const elTitle = document.getElementById('qr-pkg-title');
            const elPrice = document.getElementById('qr-pkg-price');
            const elMemo = document.getElementById('qr-memo-text');
            const elFormName = document.getElementById('form-pkg-name');
            const elFormPrice = document.getElementById('form-pkg-price');
            const elFormDays = document.getElementById('form-pkg-days');
            const elFormTier = document.getElementById('form-pkg-tier');
            const elFormProd = document.getElementById('form-pkg-product');

            if (elTitle) elTitle.textContent = pkgName;
            if (elPrice) elPrice.textContent = pkgPrice;
            if (elMemo) elMemo.textContent = 'Mua tool ' + '<?= $current_user ?>';
            
            if (elFormName) elFormName.value = pkgName;
            if (elFormPrice) elFormPrice.value = pkgPrice;
            if (elFormDays) elFormDays.value = days;
            if (elFormTier) elFormTier.value = tier;
            if (elFormProd) elFormProd.value = product;

            openModal('modal-qr-pay');
            startCountdown(120); // 2 minutes
        }

        function startCountdown(durationSec) {
            if (timerInterval) clearInterval(timerInterval);
            let left = durationSec;
            const timerEl = document.getElementById('countdown-timer');

            function updateDisplay() {
                let m = Math.floor(left / 60);
                let s = left % 60;
                timerEl.textContent = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }
            updateDisplay();

            timerInterval = setInterval(() => {
                left--;
                if (left < 0) {
                    clearInterval(timerInterval);
                    timerEl.textContent = 'Hết giờ (vui lòng làm mới)';
                } else {
                    updateDisplay();
                }
            }, 1000);
        }

        // Auto-open tab from URL ?tab= parameter (PRG)
        (function() {
            const urlTab = new URLSearchParams(location.search).get('tab');
            const tabMap = {
                'keys':     'tab-my-keys',
                'buy':      'tab-buy-key',
                'features': 'tab-features-view',
                'bugs':     'tab-bugs-view',
                'settings': 'tab-settings'
            };
            if (urlTab && tabMap[urlTab]) {
                window.addEventListener('DOMContentLoaded', () => switchMainTab(tabMap[urlTab]));
            }
        })();

        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
                if (timerInterval) clearInterval(timerInterval);
            }
        }

        // ── AUTO-POLLING: tự kiểm tra đơn hàng mỗi 10s ──────────────
        <?php if (!empty($user_pending_orders)): ?>
        (function() {
            let pollSec = 10;
            const secEl = document.getElementById('poll-sec');
            
            // Countdown display every second
            const cdInterval = setInterval(function() {
                pollSec--;
                if (secEl) secEl.textContent = pollSec;
                if (pollSec <= 0) pollSec = 10;
            }, 1000);

            // Actual poll every 10 seconds
            setInterval(function() {
                fetch('index.php?ajax=poll_orders')
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    if (data.pending_count === 0) {
                        // All orders approved/rejected — reload to show updated dashboard
                        location.reload();
                    }
                })
                .catch(function(){/* ignore network errors silently */});
            }, 10000);
        })();
        <?php endif; ?>
    </script>

<?php
$active_broadcast = $sys_config['broadcast_notice'] ?? null;
$has_active_broadcast = $active_broadcast && !empty($active_broadcast['active']) && !empty($active_broadcast['title']);
?>
<?php if ($has_active_broadcast): ?>
<!-- MANDATORY GLOBAL BROADCAST MODAL -->
<div id="modal-global-broadcast" class="modal" style="background:rgba(7,10,19,0.92);backdrop-filter:blur(14px);z-index:999999;display:none;position:fixed;inset:0;align-items:center;justify-content:center;padding:20px">
    <div class="modal-card" style="max-width:560px;width:100%;border:2px solid #6366f1;box-shadow:0 30px 80px rgba(99,102,241,0.4);padding:32px;text-align:center;position:relative;background:#101626;border-radius:18px">
        <?php
        $b_type = $active_broadcast['type'] ?? 'info';
        $badge_title = '📢 THÔNG BÁO TỪ ADMIN';
        $badge_style = 'background:rgba(99,102,241,0.2);border:1px solid #6366f1;color:#a5b4fc';
        if ($b_type === 'update') {
            $badge_title = '🚀 BẢN CẬP NHẬT MỚI';
            $badge_style = 'background:rgba(16,185,129,0.2);border:1px solid #10b981;color:#34d399';
        } elseif ($b_type === 'alert') {
            $badge_title = '⚠️ THÔNG BÁO KHẨN';
            $badge_style = 'background:rgba(239,68,68,0.2);border:1px solid #ef4444;color:#f87171';
        } elseif ($b_type === 'maintenance') {
            $badge_title = '🛠️ BẢO TRÌ HỆ THỐNG';
            $badge_style = 'background:rgba(245,158,11,0.2);border:1px solid #f59e0b;color:#fcd34d';
        } elseif ($b_type === 'promo') {
            $badge_title = '🎁 ƯU ĐÃI ĐẶC BIỆT';
            $badge_style = 'background:rgba(236,72,153,0.2);border:1px solid #ec4899;color:#f472b6';
        }
        ?>
        <div style="margin-bottom:14px">
            <span style="display:inline-block;padding:5px 16px;border-radius:20px;font-size:12px;font-weight:900;<?= $badge_style ?>">
                <?= $badge_title ?>
            </span>
        </div>
        
        <h2 style="font-size:20px;font-weight:900;color:#fff;margin-bottom:16px;line-height:1.35">
            <?= htmlspecialchars($active_broadcast['title']) ?>
        </h2>

        <div style="background:#070a13;border:1px solid #1e293b;border-radius:12px;padding:16px 20px;font-size:13.5px;color:#cbd5e1;line-height:1.75;margin-bottom:20px;text-align:left;white-space:pre-wrap;max-height:280px;overflow-y:auto">
            <?= htmlspecialchars($active_broadcast['content']) ?>
        </div>

        <?php if (!empty($active_broadcast['button_text'])): ?>
            <a href="<?= htmlspecialchars($active_broadcast['button_url'] ?: '#') ?>" target="_blank" class="btn-outline" style="display:block;width:100%;padding:12px;margin-bottom:12px;border-color:#6366f1;color:#818cf8;font-weight:800;font-size:13.5px;text-align:center">
                <?= htmlspecialchars($active_broadcast['button_text']) ?>
            </a>
        <?php endif; ?>

        <button type="button" class="btn-primary" onclick="confirmNoticeRead('<?= htmlspecialchars($active_broadcast['id']) ?>')" style="width:100%;padding:14px;font-size:14px;font-weight:900;justify-content:center">
            ✅ Tôi Đã Đọc & Hiểu (Xác Nhận)
        </button>
    </div>
</div>

<script>
(function() {
    const noticeId = '<?= htmlspecialchars($active_broadcast['id']) ?>';
    const storageKey = 'read_notice_' + noticeId;
    
    // Check if notice is already acknowledged in localStorage or Cookie
    const isReadLocal = localStorage.getItem(storageKey);
    const isReadCookie = document.cookie.split('; ').some(row => row.startsWith(storageKey + '='));
    
    if (!isReadLocal && !isReadCookie) {
        window.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('modal-global-broadcast');
            if (modal) {
                modal.style.display = 'flex';
            }
        });
    }
})();

function confirmNoticeRead(noticeId) {
    const storageKey = 'read_notice_' + noticeId;
    localStorage.setItem(storageKey, '1');
    document.cookie = storageKey + '=1; path=/; max-age=' + (86400 * 60);
    
    const modal = document.getElementById('modal-global-broadcast');
    if (modal) {
        modal.style.display = 'none';
    }

    // Send async ping to server to count read statistics
    fetch('index.php?ajax=mark_notice_read&id=' + encodeURIComponent(noticeId))
        .catch(() => {});
}
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {

// ── Smooth Scroll & Tab Switch for Navbar Links ──────────────
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        const targetId = this.getAttribute('href').substring(1);
        if (!targetId) return;

        // If user is logged in and clicks pricing -> switch to buy tab
        if (targetId === 'pricing' && typeof switchMainTab === 'function') {
            const buyTab = document.getElementById('tab-buy-key');
            if (buyTab) {
                e.preventDefault();
                switchMainTab('tab-buy-key');
                window.scrollTo({ top: buyTab.offsetTop - 80, behavior: 'smooth' });
                return;
            }
        }

        const targetEl = document.getElementById(targetId);
        if (targetEl) {
            e.preventDefault();
            const navHeight = document.querySelector('nav')?.offsetHeight || 70;
            const elementPosition = targetEl.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - navHeight - 10;
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    });
});

    const spotlightCards = document.querySelectorAll('.feature-card, .pricing-card, .stat-card, .card, .liquid-glass, .dash-header');
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
