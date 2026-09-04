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

$current_user   = $_SESSION['user'] ?? null;
$user_info      = $current_user ? db_get_user($current_user) : null;
$user_wallet    = $current_user ? db_get_user_wallet($current_user) : null;
$user_tokens_tx = $current_user ? db_get_user_token_transactions($current_user, 30) : [];
$token_packages = db_get_token_packages();
$client_ip      = $_SERVER['REMOTE_ADDR'] ?? '';

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
        $pkg_prod = trim($_POST['product'] ?? (stripos($pkg_name, 'Token') !== false ? 'TOKEN_WALLET' : (stripos($pkg_name, '2toolne') !== false ? '2TOOLNE' : (stripos($pkg_name, 'Extension') !== false ? 'LABS_EXTENSION' : 'SLIDESHOW'))));

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
        $target_tab = ($pkg_prod === 'TOKEN_WALLET') ? 'wallet' : 'keys';
        $success_msg = ($pkg_prod === 'TOKEN_WALLET') 
            ? '🎉 Đã ghi nhận thông tin thanh toán nạp Token! Admin sẽ duyệt và cộng Token vào ví ngay khi nhận được thanh toán.' 
            : '🎉 Đã ghi nhận thông tin thanh toán! Đơn hàng đang được Admin duyệt để cấp Key.';
        flash_redirect('success', $success_msg, $target_tab);
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
$user_info      = $current_user ? db_get_user($current_user) : null;
$user_wallet    = $current_user ? db_get_user_wallet($current_user) : null;
$user_tokens_tx = $current_user ? db_get_user_token_transactions($current_user, 30) : [];

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
        $msg_success = '🎉 Chúc mừng bạn đã đăng ký thành công! Bạn được <b>TẶNG NGAY 1 KEY DÙNG THỬ 3 NGÀY</b> + <b>50 TOKENS MIỄN PHÍ</b> vào ví!';
    } else {
        $msg_success = '🎉 Đăng ký tài khoản thành công! Bạn đã được <b>TẶNG NGAY 50 TOKENS MIỄN PHÍ</b> vào ví!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>2tamne.site — Commercial AI Software Suite & Production Tools</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="globals.css">
    <style>
        /* ── Specific View Layout Adjustments ──────────────────────────────── */
        .site-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(9, 9, 11, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            height: 60px;
            display: flex;
            align-items: center;
        }
        .nav-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            color: var(--foreground);
            letter-spacing: -0.02em;
        }
        .nav-brand-logo {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: linear-gradient(135deg, #10b981, #0284c7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #fff;
            font-size: 13px;
        }
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .nav-item {
            font-size: 13px;
            font-weight: 500;
            color: var(--muted-foreground);
            transition: color 0.15s ease;
        }
        .nav-item:hover {
            color: var(--foreground);
        }

        /* ── Hero Section ─────────────────────────────────────────────────── */
        .hero-section {
            padding: 72px 0 48px;
            text-align: center;
        }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-1);
            border: 1px solid var(--border);
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            color: var(--muted-foreground);
            margin-bottom: 20px;
        }
        .hero-title {
            font-size: 44px;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
            color: var(--foreground);
        }
        .hero-title span {
            background: linear-gradient(135deg, #fafafa 30%, #a1a1aa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            font-size: 15px;
            color: var(--muted-foreground);
            max-width: 640px;
            margin: 0 auto 32px;
            line-height: 1.6;
        }
        .hero-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* ── Product Switcher Tabs ────────────────────────────────────────── */
        .prod-tab-bar {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 40px 0 32px;
            flex-wrap: wrap;
        }

        /* ── Feature Grid ─────────────────────────────────────────────────── */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }
        .feature-card {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all 0.2s ease;
        }
        .feature-card:hover {
            border-color: var(--border-hover);
            background: var(--surface-hover);
        }
        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background: var(--surface-2);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-bottom: 14px;
            color: var(--foreground);
        }
        .feature-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 6px;
        }
        .feature-desc {
            font-size: 13px;
            color: var(--muted-foreground);
            line-height: 1.5;
        }

        /* ── Pricing Matrix ───────────────────────────────────────────────── */
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 48px;
        }
        .pricing-card {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }
        .pricing-card.featured {
            border-color: var(--emerald);
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.15);
        }
        .pricing-card-badge {
            position: absolute;
            top: 14px;
            right: 14px;
        }
        .price-val {
            font-size: 28px;
            font-weight: 700;
            color: var(--foreground);
            margin: 12px 0 4px;
            letter-spacing: -0.02em;
        }
        .price-sub {
            font-size: 12px;
            color: var(--muted-foreground);
            margin-bottom: 20px;
        }
        .price-checklist {
            list-style: none;
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--foreground);
        }
        .price-checklist li {
            padding: 6px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Dashboard Layout ─────────────────────────────────────────────── */
        .dash-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 24px;
            margin-top: 24px;
            min-height: calc(100vh - 180px);
        }
        .dash-sidebar {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 16px;
            height: fit-content;
        }
        .dash-user-card {
            padding-bottom: 14px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }
        .dash-user-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--foreground);
        }
        .dash-user-sub {
            font-size: 12px;
            color: var(--muted-foreground);
            font-family: var(--font-mono);
        }
        .dash-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dash-nav-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            color: var(--muted-foreground);
            background: transparent;
            border: none;
            text-align: left;
            cursor: pointer;
            width: 100%;
            transition: all 0.15s ease;
        }
        .dash-nav-btn:hover {
            color: var(--foreground);
            background: var(--surface-2);
        }
        .dash-nav-btn.active {
            color: #ffffff;
            background: var(--surface-3);
            font-weight: 600;
        }
        .dash-main {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ── Toast Notification ───────────────────────────────────────────── */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--surface-1);
            color: var(--foreground);
            border: 1px solid var(--border-strong);
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            box-shadow: var(--shadow-lg);
            display: none;
            align-items: center;
            gap: 8px;
            z-index: 10000;
            animation: modalFadeIn 0.2s ease;
        }

        @media (max-width: 900px) {
            .dash-layout {
                grid-template-columns: 1fr;
            }
            .hero-title {
                font-size: 32px;
            }
        }
        @media (max-width: 768px) {
            .smart-download-container {
                width: 100%;
            }
            .smart-download-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <!-- ═══ HEADER / NAVBAR ═══ -->
    <header class="site-nav">
        <div class="container nav-inner">
            <a href="index.php" class="nav-brand">
                <div class="nav-brand-logo">2</div>
                <span>2tamne.site</span>
                <span class="badge" style="font-size:10px;padding:1px 6px">v1.0-rc</span>
            </a>

            <nav class="nav-menu">
                <?php if ($user_info): ?>
                    <a href="#tab-buy-key" class="nav-item" onclick="switchMainTab('tab-buy-key')">Sản Phẩm</a>
                    <a href="#tab-downloads" class="nav-item" onclick="switchMainTab('tab-downloads')">Tải Về</a>
                    <a href="#tab-wallet-view" class="nav-item" onclick="switchMainTab('tab-wallet-view')">Ví Token</a>
                    <a href="#tab-buy-key" class="nav-item" onclick="switchMainTab('tab-buy-key')">Bảng Giá</a>
                <?php else: ?>
                    <a href="#products" class="nav-item">Sản Phẩm</a>
                    <a href="#downloads" class="nav-item">Tải Về</a>
                    <a href="#pricing" class="nav-item">Bảng Giá</a>
                <?php endif; ?>
                <a href="https://zalo.me/0326649304" target="_blank" class="nav-item" style="color:var(--emerald)">Hỗ Trợ Zalo</a>
            </nav>

            <div style="display:flex;align-items:center;gap:10px">
                <?php if ($user_info): ?>
                    <a href="#tab-wallet-view" onclick="switchMainTab('tab-wallet-view')" class="badge" style="background:rgba(234, 179, 8, 0.15);border:1px solid rgba(234, 179, 8, 0.45);color:#facc15;font-weight:700;font-size:12px;padding:4px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border-radius:20px;cursor:pointer" title="Số dư token: Bấm để nạp thêm hoặc xem lịch sử">
                        🪙 <span id="nav-token-balance"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?></span> Tokens
                    </a>
                    <?php if (in_array($user_info['role'] ?? '', ['admin', 'super_admin', 'sales', 'tech_support', 'content_manager', 'custom'])): ?>
                        <a href="license_admin.php" class="btn btn-outline btn-xs" style="border-color:var(--primary);color:var(--primary)" title="Trang Quản Trị Hệ Thống">
                            👑 Quản Trị
                        </a>
                    <?php endif; ?>
                    <a href="?logout=1" class="btn btn-outline btn-sm">
                        <span>Đăng Xuất (<?= htmlspecialchars($user_info['username']) ?>)</span>
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline btn-sm" onclick="openModal('modal-login')">Đăng Nhập</button>
                    <button class="btn btn-primary btn-sm" onclick="openModal('modal-register')">🎁 Nhận Key + 50 Token</button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- FLASH ALERTS -->
        <?php if ($msg_success): ?>
            <div class="alert alert-success" style="margin-top:20px"><?= $msg_success ?></div>
        <?php endif; ?>
        <?php if ($msg_error): ?>
            <div class="alert alert-danger" style="margin-top:20px"><?= $msg_error ?></div>
        <?php endif; ?>

        <?php if ($user_info): ?>
            <!-- ═══════════════════════════════════════════════════════════════
                 LOGGED-IN USER ACCOUNT PORTAL / DASHBOARD
                 ═══════════════════════════════════════════════════════════════ -->
            
            <!-- PENDING ORDERS POLLING BANNER -->
            <?php if (!empty($user_pending_orders)): ?>
                <?php foreach ($user_pending_orders as $p_ord): ?>
                    <div class="alert alert-warning" style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                        <div>
                            ⏳ <b>Đơn hàng đang chờ duyệt:</b> Đơn mua <b><?= htmlspecialchars($p_ord['package_name']) ?> (<?= htmlspecialchars($p_ord['package_price']) ?>)</b>.
                            Key sẽ tự động xuất hiện tại mục "Bản Quyền". Tự động kiểm tra sau <b id="poll-sec">10</b>s...
                        </div>
                        <a href="https://zalo.me/0326649304" target="_blank" class="btn btn-outline btn-xs" style="border-color:var(--warning);color:var(--warning)">
                            💬 Nhắn Admin Duyệt Gấp
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="dash-layout">
                <!-- SIDEBAR -->
                <aside class="dash-sidebar">
                    <div class="dash-user-card">
                        <div class="dash-user-name"><?= htmlspecialchars($user_info['fullname'] ?: $user_info['username']) ?></div>
                        <div class="dash-user-sub">@<?= htmlspecialchars($user_info['username']) ?></div>
                    </div>
                    <nav class="dash-nav">
                        <button class="dash-nav-btn active" id="btn-tab-keys" onclick="switchMainTab('tab-my-keys')">
                            <span>🔑</span> Bản Quyền Của Tôi
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-wallet" onclick="switchMainTab('tab-wallet-view')">
                            <span>🪙</span> Ví Token (<?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?>)
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-downloads" onclick="switchMainTab('tab-downloads')">
                            <span>📥</span> Tải Phần Mềm
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-buy" onclick="switchMainTab('tab-buy-key')">
                            <span>🛒</span> Mua Gói Bản Quyền
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-features" onclick="switchMainTab('tab-features-view')">
                            <span>💡</span> Góp Ý Tính Năng
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-bugs" onclick="switchMainTab('tab-bugs-view')">
                            <span>🐞</span> Báo Lỗi Phần Mềm
                        </button>
                        <button class="dash-nav-btn" id="btn-tab-settings" onclick="switchMainTab('tab-settings')">
                            <span>⚙️</span> Cài Đặt Tài Khoản
                        </button>
                    </nav>
                </aside>

                <!-- MAIN WORKSPACE -->
                <div class="dash-main">

                    <!-- TAB 1: MY KEYS -->
                    <div id="tab-my-keys" class="tab-pane active">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🔑 Danh Sách License Key Đã Sở Hữu</div>
                                <button class="btn btn-emerald btn-sm" onclick="switchMainTab('tab-buy-key')">+ Mua Thêm Key</button>
                            </div>
                            <div class="card-body" style="padding:0">
                                <?php 
                                $my_keys = $user_info['keys'] ?? [];
                                if (empty($my_keys)): 
                                ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🔑</div>
                                        <div class="empty-state-title">Chưa Có License Key Nào</div>
                                        <div class="empty-state-desc">Bạn chưa kích hoạt license nào trên tài khoản. Hãy bấm sang tab "Mua Gói Bản Quyền" để chọn gói phù hợp.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>License Key</th>
                                                    <th>Sản Phẩm</th>
                                                    <th>Gói</th>
                                                    <th>Thời Hạn</th>
                                                    <th>Trạng Thái</th>
                                                    <th>Thiết Bị (HWID)</th>
                                                    <th>Thao Tác</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($my_keys as $k): 
                                                    $lic = $licenses_db[$k] ?? null;
                                                    if (!$lic) continue;
                                                    $status = $lic['status'] ?? 'active';
                                                    $tier = $lic['tier'] ?? 'VIP';
                                                    $hwid = $lic['hwid'] ?? '';
                                                    $is_2toolne = ($lic['product'] ?? '') === '2TOOLNE' || strpos($k, '2TOOLNE-') === 0;
                                                    $is_ext = ($lic['product'] ?? '') === 'LABS_EXTENSION' || strpos($k, '2TAMNE-LABS-') === 0;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:6px">
                                                                <code class="font-mono" style="color:var(--info);font-size:12.5px;font-weight:600"><?= htmlspecialchars($k) ?></code>
                                                                <button class="btn btn-outline btn-xs" onclick="copyText('<?= htmlspecialchars($k) ?>')" title="Sao chép">📋</button>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($is_2toolne): ?>
                                                                <span class="badge badge-active">🚀 2toolne Studio</span>
                                                            <?php elseif ($is_ext): ?>
                                                                <span class="badge badge-info">🖼️ Labs Extension</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-purple">🎬 Slideshow AI</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?= $tier === 'TRIAL' ? 'badge-trial' : 'badge-active' ?>"><?= htmlspecialchars($tier) ?></span>
                                                        </td>
                                                        <td style="font-size:12px;color:var(--muted-foreground)">
                                                            <?= $lic['expires_at'] ? (strpos($lic['expires_at'], '2099') !== false ? '👑 Vĩnh viễn' : htmlspecialchars($lic['expires_at'])) : ($lic['duration_days'] . ' ngày') ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-danger' ?>">
                                                                <?= strtoupper($status) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($hwid): ?>
                                                                <div style="font-size:11.5px">
                                                                    <code style="color:var(--info)"><?= substr($hwid, 0, 10) ?>...</code>
                                                                    <div style="color:var(--muted-subtle)"><?= htmlspecialchars($lic['device_name'] ?: 'Desktop') ?></div>
                                                                </div>
                                                            <?php else: ?>
                                                                <span style="color:var(--emerald);font-size:12px">Chưa kích hoạt</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                                                <?php if ($is_2toolne): ?>
                                                                    <a href="/downloads/2toolne_macOS_latest.zip" class="btn btn-emerald btn-xs" title="Tải 2toolne cho macOS">🍎 Mac</a>
                                                                    <a href="/downloads/2toolne_Windows_latest.zip" class="btn btn-accent btn-xs" title="Tải 2toolne cho Windows">🪟 Win</a>
                                                                <?php elseif ($is_ext): ?>
                                                                    <a href="/downloads/2tamne_Labs_Extension_latest.zip" class="btn btn-outline btn-xs" title="Tải Labs Extension">🧩 Extension</a>
                                                                <?php else: ?>
                                                                    <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn btn-emerald btn-xs" title="Tải Slideshow AI cho macOS">🍎 Mac</a>
                                                                    <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn btn-accent btn-xs" title="Tải Slideshow AI cho Windows">🪟 Win</a>
                                                                <?php endif; ?>
                                                                <?php if ($hwid): ?>
                                                                    <button type="button" class="btn btn-outline btn-xs" style="color:var(--info);border-color:var(--info)" onclick="openResetHwidModal('<?= htmlspecialchars($k) ?>')">
                                                                        🔄 Đổi Máy
                                                                    </button>
                                                                <?php else: ?>
                                                                    <span class="text-subtle" style="font-size:11px">Sẵn sàng</span>
                                                                <?php endif; ?>
                                                            </div>
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

                    <!-- TAB 1.2: WALLET & TOKENS (VÍ & NẠP TOKEN UPSCALE) -->
                    <div id="tab-wallet-view" class="tab-pane" style="display:none">
                        <!-- QUICK DOWNLOAD BANNER FOR UPSCALE APP -->
                        <div class="card" style="margin-bottom:24px;border-color:rgba(250, 204, 21, 0.4);background:linear-gradient(135deg, rgba(250, 204, 21, 0.1) 0%, var(--surface-1) 100%)">
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div style="flex:1;min-width:280px">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                                        <span class="badge" style="background:#facc15;color:#000;font-weight:800">CÀI ĐẶT ỨNG DỤNG</span>
                                        <span class="badge badge-info">v1.0.0 Stable</span>
                                    </div>
                                    <h4 style="font-size:17px;margin:0 0 4px">Tải Ứng Dụng 2toolne Upscale 4K Về Máy Tính</h4>
                                    <p class="text-muted" style="margin:0;font-size:13px">Sử dụng số dư <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> Tokens của bạn để phóng to ảnh 2K & 4K siêu nét bằng AI on-device trên Windows và macOS.</p>
                                </div>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                                    <div style="display:flex;gap:6px">
                                        <a href="/downloads/2toolne_Upscale_Windows_latest.zip" class="btn btn-accent btn-sm" title="Tải Portable .zip cho Windows">🪟 Windows (.zip)</a>
                                        <a href="/downloads/2toolne_Upscale_Setup_latest.exe" class="btn btn-outline btn-sm" title="Tải Cài đặt .exe cho Windows">⚙️ .exe</a>
                                    </div>
                                    <div style="display:flex;gap:6px">
                                        <a href="/downloads/2toolne_Upscale_macOS_latest.zip" class="btn btn-emerald btn-sm" title="Tải Universal .zip cho Mac">🍏 Mac (.zip)</a>
                                        <a href="/downloads/2toolne_Upscale_latest.dmg" class="btn btn-outline btn-sm" title="Tải Gói .dmg cho Mac">📦 .dmg</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STATS ROW -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px">
                            <div class="card" style="padding:18px;border-color:rgba(234, 179, 8, 0.35);background:linear-gradient(135deg, rgba(234, 179, 8, 0.08) 0%, var(--surface-1) 100%)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">🪙 Số Dư Khả Dụng</div>
                                <div style="font-size:28px;font-weight:800;color:#facc15">
                                    <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> <span style="font-size:14px;font-weight:600;color:var(--foreground)">Tokens</span>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Dùng để Upscale ảnh 2K & 4K</div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">⏳ Đang Tạm Giữ (Render)</div>
                                <div style="font-size:28px;font-weight:800;color:var(--info)">
                                    <?= number_format($user_wallet['reserved'] ?? 0, 0, ',', '.') ?> <span style="font-size:14px;font-weight:600;color:var(--foreground)">Tokens</span>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Đang chờ tác vụ hoàn tất</div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">🛡️ Chế Độ Trừ Phí</div>
                                <div style="font-size:24px;font-weight:800;color:<?= ($user_wallet['credit_mode'] ?? '') === 'UNLIMITED' ? 'var(--emerald)' : 'var(--primary)' ?>">
                                    <?= htmlspecialchars($user_wallet['credit_mode'] ?? 'METERED') ?>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px"><?= ($user_wallet['credit_mode'] ?? '') === 'UNLIMITED' ? 'Không giới hạn số lượt' : 'Trừ theo ảnh hoàn thành' ?></div>
                            </div>
                            <div class="card" style="padding:18px;border-color:var(--border)">
                                <div style="font-size:12px;color:var(--muted-foreground);font-weight:600;text-transform:uppercase;margin-bottom:6px">👑 Hạng Tài Khoản</div>
                                <div style="font-size:24px;font-weight:800;color:var(--foreground)">
                                    <?= strtoupper(htmlspecialchars($user_wallet['plan'] ?? 'FREE')) ?>
                                </div>
                                <div style="font-size:11.5px;color:var(--muted-subtle);margin-top:4px">Gói dịch vụ kích hoạt</div>
                            </div>
                        </div>

                        <!-- RULE EXPLANATION -->
                        <div class="card" style="margin-bottom:24px;border-color:rgba(59, 130, 246, 0.3);background:linear-gradient(180deg, rgba(59, 130, 246, 0.06) 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span style="font-size:20px">💡</span>
                                    <h4 style="font-size:15px;margin:0">Quy Tắc Tiêu Thụ Token & Bảo Đảm Hoàn Tiền</h4>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;font-size:13px;color:var(--muted-foreground);margin-top:10px">
                                    <div>🖼️ <b>Ảnh Độ Phân Giải 2K:</b> Tiêu thụ <b>1 Token</b> / ảnh xuất thành công.</div>
                                    <div>🌟 <b>Ảnh Độ Phân Giải 4K Ultra HD:</b> Tiêu thụ <b>2 Tokens</b> / ảnh xuất thành công.</div>
                                    <div>🔒 <b>Chu trình 3 bước an toàn:</b> Khóa tạm (Reserve) ➔ Render xong (Commit) ➔ Hoàn trả 100% nếu lỗi máy/mạng (Release).</div>
                                </div>
                            </div>
                        </div>

                        <!-- TOKEN PACKAGES -->
                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🛒 Mua Gói Nạp Token Upscale 4K</div>
                                <span class="badge badge-active">Quét VietQR Tự Động</span>
                            </div>
                            <div class="card-body">
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <!-- PACKAGE 1: STARTER -->
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Starter (1.000 Token)</h4>
                                            <div class="price-val" style="color:#facc15">100.000đ</div>
                                            <div class="price-sub">Chi phí: 100đ / token</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>1.000 Tokens</b> vào ví ngay lập tức</li>
                                                <li>🖼️ Tương đương 1.000 ảnh 2K hoặc 500 ảnh 4K</li>
                                                <li>⚡ Phù hợp người dùng trải nghiệm cơ bản</li>
                                                <li>✅ Token không bao giờ hết hạn</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#facc15;color:#facc15" onclick="openQrPayment('Gói 1.000 Token (Starter)', '100.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 100.000đ</button>
                                    </div>

                                    <!-- PACKAGE 2: PRO CREATOR -->
                                    <div class="pricing-card featured" style="border-color:#facc15">
                                        <span class="badge pricing-card-badge" style="background:#facc15;color:#000;font-weight:800">TẶNG 500 TOKENS</span>
                                        <div>
                                            <h4>Gói Pro Creator (3.000 Token)</h4>
                                            <div class="price-val" style="color:#facc15">250.000đ</div>
                                            <div class="price-sub">Chi phí: ~83đ / token</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>3.000 Tokens</b> (Tặng kèm 500 Tokens)</li>
                                                <li>🖼️ Tương đương 3.000 ảnh 2K hoặc 1.500 ảnh 4K</li>
                                                <li>⚡ Dành cho Content Creator làm kênh thường xuyên</li>
                                                <li>✅ Token không có hạn sử dụng</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-emerald" onclick="openQrPayment('Gói 3.000 Token (Pro Creator)', '250.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 250.000đ</button>
                                    </div>

                                    <!-- PACKAGE 3: STUDIO PACK -->
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Studio (10.000 Token)</h4>
                                            <div class="price-val" style="color:#facc15">700.000đ</div>
                                            <div class="price-sub">Chi phí: 70đ / token (Tiết kiệm 30%)</div>
                                            <ul class="price-checklist">
                                                <li>🪙 <b>10.000 Tokens</b> (Tặng kèm 3.000 Tokens)</li>
                                                <li>🖼️ Tương đương 10.000 ảnh 2K hoặc 5.000 ảnh 4K</li>
                                                <li>⚡ Dành cho Studio & Team sản xuất lớn</li>
                                                <li>👑 Ưu tiên băng thông GPU cao cấp nhất</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:#facc15;color:#facc15" onclick="openQrPayment('Gói 10.000 Token (Studio)', '700.000đ', 36500, 'TOKEN', 'TOKEN_WALLET')">⚡ Nạp 700.000đ</button>
                                    </div>

                                    <!-- PACKAGE 4: UNLIMITED STUDIO -->
                                    <div class="pricing-card" style="border-color:var(--purple-500)">
                                        <div>
                                            <span class="badge badge-purple" style="margin-bottom:8px">KHÔNG GIỚI HẠN</span>
                                            <h4>Unlimited Studio (30 Ngày)</h4>
                                            <div class="price-val" style="color:var(--purple-400)">1.800.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>👑 <b>Không giới hạn số lượng ảnh 2K / 4K</b></li>
                                                <li>🚀 Render liên tục 24/7 không trừ token</li>
                                                <li>⚡ Tối ưu cho xưởng sản xuất video công nghiệp</li>
                                                <li>✅ Hỗ trợ kỹ thuật ưu tiên 1-1 từ Admin</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-primary" onclick="openQrPayment('Gói Unlimited Studio (30 Ngày)', '1.800.000đ', 30, 'UNLIMITED', 'TOKEN_WALLET')">👑 Mua Gói Unlimited</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TOKEN TRANSACTIONS HISTORY -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📜 Lịch Sử Biến Động Số Dư Token</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <?php if (empty($user_tokens_tx)): ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">🪙</div>
                                        <div class="empty-state-title">Chưa Có Biến Động Token Nào</div>
                                        <div class="empty-state-desc">Tài khoản chưa phát sinh giao dịch nạp hoặc tiêu thụ token Upscale 4K.</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Mã GD</th>
                                                    <th>Thời Gian</th>
                                                    <th>Loại GD</th>
                                                    <th>Biến Động</th>
                                                    <th>Số Dư Cuối</th>
                                                    <th>Lý Do / Chi Tiết</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($user_tokens_tx as $tx): 
                                                    $d = (int)($tx['delta'] ?? 0);
                                                    $t_type = $tx['type'] ?? 'N/A';
                                                    $delta_str = ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.');
                                                    $delta_color = $d > 0 ? 'var(--emerald)' : ($d < 0 ? 'var(--danger)' : 'var(--muted-foreground)');
                                                ?>
                                                    <tr>
                                                        <td><code style="font-size:11.5px">#<?= htmlspecialchars($tx['id']) ?></code></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($tx['created_at']) ?></td>
                                                        <td>
                                                            <?php if ($t_type === 'COMMIT'): ?>
                                                                <span class="badge badge-purple">COMMIT (Render)</span>
                                                            <?php elseif ($t_type === 'RESERVE'): ?>
                                                                <span class="badge badge-warning">RESERVE (Khóa)</span>
                                                            <?php elseif ($t_type === 'RELEASE'): ?>
                                                                <span class="badge badge-info">RELEASE (Hoàn)</span>
                                                            <?php elseif ($t_type === 'PROMOTION'): ?>
                                                                <span class="badge badge-active">🎁 TẶNG ĐĂNG KÝ</span>
                                                            <?php else: ?>
                                                                <span class="badge"><?= htmlspecialchars($t_type) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="font-weight:700;color:<?= $delta_color ?>"><?= $delta_str ?></td>
                                                        <td><b><?= number_format($tx['balance_after'] ?? 0, 0, ',', '.') ?></b></td>
                                                        <td style="font-size:12.5px;color:var(--muted-foreground)">
                                                            <?= htmlspecialchars($tx['reason'] ?: ($tx['metadata_json'] ?: '-')) ?>
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

                    <!-- TAB 1.5: DOWNLOADS (TRUNG TÂM TẢI PHẦN MỀM) -->
                    <div id="tab-downloads" class="tab-pane" style="display:none">
                        <!-- 1. SMART AUTO-DETECT OS: 2TOOLNE UPSCALE 4K -->
                        <div class="card" style="margin-bottom:24px;border-color:#facc15;background:linear-gradient(180deg, #221a05 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                                    <div style="flex:1;min-width:280px">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                            <span class="badge" style="background:#facc15;color:#000;font-weight:800">✨ AI SIÊU PHÂN GIẢI 2K & 4K</span>
                                            <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                        </div>
                                        <h3 style="font-size:22px;margin:0 0 6px">2toolne Upscale 4K — AI Super-Resolution App (v1.0.0 Stable)</h3>
                                        <p class="text-muted" style="margin:0;font-size:13.5px">Ứng dụng phóng to và phục chế ảnh 2K/4K siêu nét bằng AI on-device (Vulkan, DirectML, Apple Silicon Neural Engine). Tiêu thụ Token theo lượt ảnh hoàn tất (1 Token = 2K, 2 Tokens = 4K).</p>
                                    </div>
                                    <!-- PROMINENT SMART 1-CLICK BUTTON -->
                                    <div class="smart-download-container" data-app-name="2toolne Upscale 4K" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                        <a href="/downloads/2toolne_Upscale_Windows_latest.zip" 
                                           data-os-win="/downloads/2toolne_Upscale_Windows_latest.zip" 
                                           data-os-mac="/downloads/2toolne_Upscale_macOS_latest.zip" 
                                           class="smart-download-btn btn btn-accent btn-lg" 
                                           style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(250, 204, 21, 0.25)">
                                            <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.zip)</span>
                                        </a>
                                        <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Bản chính thức v1.0.0 • Tự động tương thích thiết bị</div>
                                    </div>
                                </div>

                                <!-- ALL DOWNLOAD OPTIONS (PRIMARY & SECONDARY) -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                                    <!-- WINDOWS SUITE -->
                                    <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🪟</span> Bản Dành Cho Windows (10/11 64-bit):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tăng tốc phần cứng DirectML, Vulkan, NVIDIA RTX Tensor.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <a href="/downloads/2toolne_Upscale_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">📥 Tải .zip (Portable 64-bit)</a>
                                            <a href="/downloads/2toolne_Upscale_Setup_latest.exe" class="btn btn-outline btn-sm" style="flex:1;text-align:center">⚙️ Bộ cài .exe (Setup)</a>
                                        </div>
                                    </div>

                                    <!-- MACOS SUITE -->
                                    <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🍏</span> Bản Dành Cho macOS (Monterey 12+):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal Binary: Apple Silicon (M1/M2/M3/M4 Metal) & Intel Core.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <a href="/downloads/2toolne_Upscale_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍏 Tải .zip (Universal Mac)</a>
                                            <a href="/downloads/2toolne_Upscale_latest.dmg" class="btn btn-outline btn-sm" style="flex:1;text-align:center">📦 Gói cài .dmg</a>
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top:14px;padding:8px 12px;background:rgba(250, 204, 21, 0.08);border-radius:var(--radius-sm);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                                    <span style="font-size:12.5px;color:var(--foreground)">🪙 Số dư Token hiện tại: <b style="color:#facc15"><?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> Tokens</b></span>
                                    <a href="#tab-wallet-view" class="btn btn-outline btn-xs" onclick="switchMainTab('tab-wallet-view')">Nạp Thêm Token ➔</a>
                                </div>
                            </div>
                        </div>

                        <!-- 2. SMART AUTO-DETECT OS: 2TOOLNE STUDIO -->
                        <div class="card" style="margin-bottom:24px;border-color:var(--emerald);background:linear-gradient(180deg, #09261e 0%, var(--surface-1) 100%)">
                            <div class="card-body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                                    <div style="flex:1;min-width:280px">
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                            <span class="badge badge-active">🚀 SẢN PHẨM MỚI 2026</span>
                                            <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                        </div>
                                        <h3 style="font-size:22px;margin:0 0 6px">2toolne Studio — AI YouTube Production Suite (v1.0.0 RC)</h3>
                                        <p class="text-muted" style="margin:0;font-size:13.5px">Hệ thống sản xuất phim tài liệu tự động 200–250 shots, Edge TTS, Căn chỉnh lời chính xác, Upscale 4K & Multi-Track DAW.</p>
                                    </div>
                                    <!-- PROMINENT SMART 1-CLICK BUTTON -->
                                    <div class="smart-download-container" data-app-name="2toolne Studio" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                        <a href="/downloads/2toolne_Windows_latest.zip" 
                                           data-os-win="/downloads/2toolne_Windows_latest.zip" 
                                           data-os-mac="/downloads/2toolne_macOS_latest.zip" 
                                           class="smart-download-btn btn btn-emerald btn-lg" 
                                           style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(16, 185, 129, 0.3)">
                                            <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.zip)</span>
                                        </a>
                                        <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Bản chính thức v1.0.0 RC • Tự động tương thích thiết bị</div>
                                    </div>
                                </div>

                                <!-- ALL DOWNLOAD OPTIONS (PRIMARY & SECONDARY) -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                                    <!-- WINDOWS SUITE -->
                                    <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🪟</span> Bản Dành Cho Windows (10/11 64-bit):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tối ưu NVIDIA RTX / CUDA, Intel & AMD đa nhân.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <a href="/downloads/2toolne_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">📥 Tải .zip (Portable 64-bit)</a>
                                            <a href="/downloads/2toolne_Setup_latest.exe" class="btn btn-outline btn-sm" style="flex:1;text-align:center">⚙️ Bộ cài .exe (Setup)</a>
                                        </div>
                                    </div>

                                    <!-- MACOS SUITE -->
                                    <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                        <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                            <span>🍏</span> Bản Dành Cho macOS (Monterey 12+):
                                        </div>
                                        <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal Binary: Apple Silicon (M1/M2/M3/M4) & Intel Core.</p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                                            <a href="/downloads/2toolne_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍏 Tải .zip (Universal Mac)</a>
                                            <a href="/downloads/2toolne_latest.dmg" class="btn btn-outline btn-sm" style="flex:1;text-align:center">📦 Gói cài .dmg</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
                            <!-- SLIDESHOW BUILDER AI -->
                            <div class="card">
                                <div class="card-header">
                                    <div class="card-title">🎬 Slideshow Builder AI (v2.3.0 Stable)</div>
                                    <span class="badge badge-purple">GPU Renderer G</span>
                                </div>
                                <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:calc(100% - 55px)">
                                    <div>
                                        <p class="text-muted" style="font-size:13px;margin-bottom:14px">
                                            Phần mềm tạo video chuyển cảnh Ken Burns 4K mượt mà 60 FPS, phụ đề tự động Pill/Noonnu, lồng tiếng phát thanh viên đa giọng đọc.
                                        </p>
                                        <div class="text-subtle" style="font-size:12px;margin-bottom:16px">
                                            ✅ Tích hợp GPU Hardware Acceleration (Apple VideoToolbox / NVIDIA NVENC / QuickSync)
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                                        <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍎 Tải macOS (.zip)</a>
                                        <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">🪟 Tải Windows (.zip)</a>
                                    </div>
                                </div>
                            </div>

                            <!-- LABS EXTENSION -->
                            <div class="card">
                                <div class="card-header">
                                    <div class="card-title">🖼️ Google Labs Flow Extension (v1.5.0)</div>
                                    <span class="badge badge-info">Chrome & Edge</span>
                                </div>
                                <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:calc(100% - 55px)">
                                    <div>
                                        <p class="text-muted" style="font-size:13px;margin-bottom:14px">
                                            Tiện ích tự động tải hàng loạt ảnh 2K/4K từ Google Labs, tự động sắp xếp và đánh số thứ tự chuẩn xác 001→xxx.
                                        </p>
                                        <div class="text-subtle" style="font-size:12px;margin-bottom:16px">
                                            ⚡ Cài đặt nhanh trong 30 giây qua Developer Mode trên Chrome / Brave / Edge / Cốc Cốc.
                                        </div>
                                    </div>
                                    <div>
                                        <a href="/downloads/2tamne_Labs_Extension_latest.zip" class="btn btn-outline btn-sm" style="width:100%;text-align:center">📥 Tải Extension (.zip)</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SYSTEM REQUIREMENTS & GUIDE -->
                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📖 Hướng Dẫn Kích Hoạt & Cài Đặt Nhanh</div>
                            </div>
                            <div class="card-body" style="font-size:13px;color:var(--muted-foreground);line-height:1.7">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                                    <div>
                                        <h4 style="color:var(--foreground);font-size:14px;margin-bottom:8px">🪟 Dành cho Windows:</h4>
                                        <ol style="padding-left:18px;margin:0">
                                            <li>Tải file <code>.zip</code> tương ứng về máy và giải nén (Extract All).</li>
                                            <li>Khởi chạy file <code>start_windows.bat</code> (hoặc <code>run.bat</code>).</li>
                                            <li>Tại giao diện phần mềm, dán <b>License Key</b> từ mục <b>"Bản Quyền Của Tôi"</b> để kích hoạt.</li>
                                        </ol>
                                    </div>
                                    <div>
                                        <h4 style="color:var(--foreground);font-size:14px;margin-bottom:8px">🍎 Dành cho macOS:</h4>
                                        <ol style="padding-left:18px;margin:0">
                                            <li>Tải file <code>.zip</code> về máy, giải nén và mở thư mục phần mềm.</li>
                                            <li>Nhấp đúp vào <code>start_mac.command</code> để khởi động ứng dụng.</li>
                                            <li>Nhập <b>License Key</b> để mở khóa toàn bộ tính năng.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: BUY VIP KEY -->
                    <div id="tab-buy-key" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🚀 2TOOLNE — AI YouTube Production Studio (Khuyên Dùng)</div>
                                <span class="badge badge-active">NEW v1.0.0</span>
                            </div>
                            <div class="card-body">
                                <p class="text-muted" style="margin-bottom:20px">Hệ thống sản xuất video tài liệu tự động 200–250 shots, Edge TTS, Cắt ghép Opencut Multi-Track & Radar phân tích tăng trưởng.</p>
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <div class="pricing-card">
                                        <div>
                                            <h4>2toolne (1 Tháng)</h4>
                                            <div class="price-val" style="color:var(--emerald)">1.200.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>✅ 225-Shot Parallel Flow DAG</li>
                                                <li>✅ Edge TTS + Căn Chỉnh Khớp Lời</li>
                                                <li>✅ DAW Audio Ducking DSP</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:var(--emerald);color:var(--emerald)" onclick="openQrPayment('2toolne (1 Tháng)', '1.200.000đ', 30, 'VIP', '2TOOLNE')">⚡ Mua Gói 1 Tháng</button>
                                    </div>

                                    <div class="pricing-card featured">
                                        <span class="badge badge-active pricing-card-badge">TIẾT KIỆM 2 TRIỆU</span>
                                        <div>
                                            <h4>2toolne (1 Năm VIP)</h4>
                                            <div class="price-val" style="color:var(--emerald)">12.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 365 ngày sử dụng</div>
                                            <ul class="price-checklist">
                                                <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                                <li>✅ Hỗ trợ ưu tiên 1-1 từ Admin</li>
                                                <li>✅ YouTube Uploader & Growth Radar</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-emerald" onclick="openQrPayment('2toolne (1 Năm VIP)', '12.000.000đ', 365, 'VIP', '2TOOLNE')">⚡ Mua Gói 1 Năm (VIP)</button>
                                    </div>

                                    <div class="pricing-card">
                                        <div>
                                            <h4>2toolne (Vĩnh Viễn)</h4>
                                            <div class="price-val" style="color:var(--emerald)">18.000.000đ</div>
                                            <div class="price-sub">Sở hữu trọn đời (Lifetime)</div>
                                            <ul class="price-checklist">
                                                <li>👑 <b>Cập nhật tính năng trọn đời</b></li>
                                                <li>👑 Quyền lợi VIP cao cấp nhất</li>
                                                <li>👑 Hỗ trợ kỹ thuật 24/7 trực tiếp</li>
                                            </ul>
                                        </div>
                                        <button class="btn btn-outline" style="border-color:var(--emerald);color:var(--emerald)" onclick="openQrPayment('2toolne (Vĩnh Viễn)', '18.000.000đ', 36500, 'LIFETIME', '2TOOLNE')">👑 Mua Gói Vĩnh Viễn</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-bottom:24px">
                            <div class="card-header">
                                <div class="card-title">🎬 Tool Video AI (Slideshow Builder)</div>
                            </div>
                            <div class="card-body">
                                <div class="pricing-grid" style="margin-bottom:0">
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói 1 Tháng</h4>
                                            <div class="price-val">1.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 30 ngày</div>
                                        </div>
                                        <button class="btn btn-outline" onclick="openQrPayment('Slideshow Builder (1 Tháng)', '1.000.000đ', 30, 'VIP', 'SLIDESHOW')">⚡ Mua Gói 1 Tháng</button>
                                    </div>
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói 1 Năm (VIP)</h4>
                                            <div class="price-val">10.000.000đ</div>
                                            <div class="price-sub">Thời hạn: 365 ngày</div>
                                        </div>
                                        <button class="btn btn-primary" onclick="openQrPayment('Slideshow Builder (1 Năm)', '10.000.000đ', 365, 'VIP', 'SLIDESHOW')">⚡ Mua Gói 1 Năm</button>
                                    </div>
                                    <div class="pricing-card">
                                        <div>
                                            <h4>Gói Vĩnh Viễn</h4>
                                            <div class="price-val">15.000.000đ</div>
                                            <div class="price-sub">Sở hữu trọn đời</div>
                                        </div>
                                        <button class="btn btn-outline" onclick="openQrPayment('Slideshow Builder (Vĩnh Viễn)', '15.000.000đ', 36500, 'LIFETIME', 'SLIDESHOW')">👑 Mua Gói Vĩnh Viễn</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🖼️ Extension Google Labs (Tải Ảnh 2K/4K)</div>
                                <span class="badge badge-info">HOT DEAL 100K</span>
                            </div>
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div>
                                    <p class="text-muted">Tự động tải hàng loạt ảnh 2K/4K từ Google Labs Flow, tự động đánh số 001→xxx.</p>
                                    <div class="price-val" style="color:var(--info);margin:8px 0 0">100.000đ <span style="font-size:13px;color:var(--muted-foreground)">/ Sở hữu vĩnh viễn</span></div>
                                </div>
                                <button class="btn btn-accent" onclick="openQrPayment('Extension Google Labs (Vĩnh Viễn)', '100.000đ', 36500, 'LIFETIME', 'LABS_EXTENSION')">
                                    ⚡ Mua Key Extension (100k)
                                </button>
                            </div>
                        </div>

                        <!-- TOKEN PACKAGES SHORTCUT -->
                        <div class="card" style="margin-top:24px;border-color:rgba(234, 179, 8, 0.35);background:linear-gradient(135deg, rgba(234, 179, 8, 0.08) 0%, var(--surface-1) 100%)">
                            <div class="card-header">
                                <div class="card-title">🪙 Nạp Token Upscale Ảnh 2K / 4K</div>
                                <span class="badge badge-active">Mới 2026</span>
                            </div>
                            <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                                <div>
                                    <p class="text-muted">Nạp thêm token cho hệ thống Upscale 4K của 2toolne. 1.000 Token chỉ 100k, 3.000 Token chỉ 250k, hoặc Gói Không Giới Hạn 30 ngày.</p>
                                    <div style="font-size:12.5px;color:#facc15;font-weight:600;margin-top:4px">Số dư hiện tại: <?= number_format($user_wallet['balance'] ?? 0, 0, ',', '.') ?> Tokens</div>
                                </div>
                                <button class="btn btn-emerald" onclick="switchMainTab('tab-wallet-view')">
                                    🪙 Xem & Mua Gói Token
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: FEATURE REQUESTS -->
                    <div id="tab-features-view" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">💡 Góp Ý / Mong Muốn Update Tính Năng</div>
                                <span class="badge">Tối đa 3 phiếu / tháng</span>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_feature">
                                    <div class="form-group">
                                        <label class="form-label">TIÊU ĐỀ TÍNH NĂNG:</label>
                                        <input type="text" name="title" class="form-input" placeholder="Ví dụ: Tích hợp thêm bộ lọc giọng nữ tiếng Anh..." required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÔ TẢ CHI TIẾT MONG MUỐN:</label>
                                        <textarea name="description" class="form-textarea" rows="3" placeholder="Mô tả cụ thể cách tính năng hoạt động..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Gửi Góp Ý Cho Admin</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📋 Lịch Sử Góp Ý Của Bạn</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Tiêu Đề</th>
                                                <th>Nội Dung</th>
                                                <th>Trạng Thái</th>
                                                <th>Ngày Gửi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $my_features = array_filter($features_db, fn($x) => ($x['user'] ?? '') === $current_user);
                                            if (empty($my_features)): ?>
                                                <tr><td colspan="4" class="empty-state">Bạn chưa gửi phiếu góp ý nào.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($my_features as $f): ?>
                                                    <tr>
                                                        <td><b><?= htmlspecialchars($f['title']) ?></b></td>
                                                        <td class="text-muted"><?= htmlspecialchars($f['description']) ?></td>
                                                        <td><span class="badge badge-info"><?= htmlspecialchars($f['status'] ?? 'Đang xem xét') ?></span></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($f['created_at']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: BUG REPORTS -->
                    <div id="tab-bugs-view" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">🐞 Báo Cáo Lỗi Phần Mềm</div>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_bug">
                                    <div class="form-group">
                                        <label class="form-label">TIÊU ĐỀ LỖI:</label>
                                        <input type="text" name="title" class="form-input" placeholder="Ví dụ: Lỗi không xuất được video ở độ phân giải 4K..." required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÃ LỖI / ERROR CODE (NẾU CÓ):</label>
                                        <input type="text" name="error_code" class="form-input" placeholder="Ví dụ: ERR_FFMPEG_ENCODER_TIMEOUT">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MÔ TẢ CHI TIẾT CÁC BƯỚC BỊ LỖI:</label>
                                        <textarea name="description" class="form-textarea" rows="3" placeholder="Mô tả cụ thể cấu hình máy, thao tác trước khi xảy ra lỗi..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-danger">Gửi Báo Cáo Lỗi Kỹ Thuật</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">📋 Lịch Sử Báo Lỗi</div>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Tiêu Đề</th>
                                                <th>Mã Lỗi</th>
                                                <th>Trạng Thái</th>
                                                <th>Ngày Gửi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $my_bugs = array_filter($bugs_db, fn($x) => ($x['user'] ?? '') === $current_user);
                                            if (empty($my_bugs)): ?>
                                                <tr><td colspan="4" class="empty-state">Không có báo cáo lỗi nào từ bạn.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($my_bugs as $b): ?>
                                                    <tr>
                                                        <td><b><?= htmlspecialchars($b['title']) ?></b></td>
                                                        <td><code><?= htmlspecialchars($b['error_code'] ?: 'N/A') ?></code></td>
                                                        <td><span class="badge badge-warning"><?= htmlspecialchars($b['status'] ?? 'Đã tiếp nhận') ?></span></td>
                                                        <td class="text-subtle" style="font-size:12px"><?= htmlspecialchars($b['created_at']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 5: SETTINGS -->
                    <div id="tab-settings" class="tab-pane" style="display:none">
                        <div class="card" style="margin-bottom:20px">
                            <div class="card-header">
                                <div class="card-title">👤 Thông Tin Tài Khoản</div>
                            </div>
                            <div class="card-body">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:13px">
                                    <div>Tên đăng nhập: <b class="text-primary"><?= htmlspecialchars($user_info['username']) ?></b></div>
                                    <div>Họ và tên: <b><?= htmlspecialchars($user_info['fullname'] ?: '(Chưa cập nhật)') ?></b></div>
                                    <div>SĐT / Zalo: <b><?= htmlspecialchars($user_info['phone'] ?: '(Chưa cập nhật)') ?></b></div>
                                    <div>Ngày tham gia: <span class="text-muted"><?= $user_info['created_at'] ?? '?' ?></span></div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="card-title">🔐 Đổi Mật Khẩu</div>
                            </div>
                            <div class="card-body">
                                <form method="POST" style="max-width:400px">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label">MẬT KHẨU HIỆN TẠI:</label>
                                        <input type="password" name="old_password" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">MẬT KHẨU MỚI (từ 6 ký tự):</label>
                                        <input type="password" name="new_password" class="form-input" required minlength="6">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">XÁC NHẬN MẬT KHẨU MỚI:</label>
                                        <input type="password" name="confirm_password" class="form-input" required minlength="6">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Lưu Mật Khẩu Mới</button>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php else: ?>
            <!-- ═══════════════════════════════════════════════════════════════
                 PUBLIC LANDING PAGE (KHI CHƯA ĐĂNG NHẬP)
                 ═══════════════════════════════════════════════════════════════ -->
            <section class="hero-section">
                <div class="hero-eyebrow">
                    <span>✨</span> Commercial Video AI & Developer Tools Suite
                </div>
                <h1 class="hero-title">
                    <span>Tự Động Hóa Sản Xuất Video</span><br>
                    Chuẩn Mực & Hiệu Suất Cao
                </h1>
                <p class="hero-desc">
                    Hệ sinh thái phần mềm đồ họa, xử lý video đa luồng và tiện ích AI dành cho nhà sáng tạo nội dung, xưởng sản xuất video tài liệu và kỹ sư công nghệ.
                </p>
                <div class="hero-actions">
                    <button class="btn btn-emerald btn-lg" onclick="openModal('modal-register')">🚀 Nhận Key Dùng Thử 3 Ngày</button>
                    <a href="#products" class="btn btn-outline btn-lg">Khám Phá Sản Phẩm</a>
                </div>
            </section>

            <!-- PRODUCTS SECTION -->
            <section id="products" style="padding:32px 0 64px">
                <div style="text-align:center;margin-bottom:24px">
                    <span class="badge badge-info" style="margin-bottom:8px">DANH MỤC CÔNG CỤ</span>
                    <h2 style="font-size:28px">Lựa Chọn Phần Mềm Phù Hợp</h2>
                </div>

                <div class="prod-tab-bar">
                    <button class="btn btn-emerald prod-tab-btn active" id="ptab-btn-2toolne" onclick="switchProductTab('ptab-2toolne', 'ptab-btn-2toolne')">
                        🚀 2toolne (AI YouTube Studio)
                    </button>
                    <button class="btn btn-outline prod-tab-btn" id="ptab-btn-token" onclick="switchProductTab('ptab-token', 'ptab-btn-token')">
                        ✨ 2toolne Upscale 4K (AI)
                    </button>
                    <button class="btn btn-outline prod-tab-btn" id="ptab-btn-video" onclick="switchProductTab('ptab-video', 'ptab-btn-video')">
                        🎬 Slideshow Builder AI
                    </button>
                    <button class="btn btn-outline prod-tab-btn" id="ptab-btn-extension" onclick="switchProductTab('ptab-extension', 'ptab-btn-extension')">
                        🖼️ Google Labs Flow Extension
                    </button>
                </div>

                <!-- PRODUCT 0: 2TOOLNE STUDIO -->
                <div id="ptab-2toolne" class="prod-tab-content">
                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">🎬</div>
                            <div class="feature-title">200–250 Shots Auto Flow</div>
                            <div class="feature-desc">Tự động điều phối kịch bản phim tài liệu dài thông qua DAG pipeline song song, loại bỏ hoàn toàn tắc nghẽn.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🎙️</div>
                            <div class="feature-title">Edge TTS & Forced Alignment</div>
                            <div class="feature-desc">Sinh giọng đọc phát thanh viên chất lượng cao và căn chỉnh phụ đề khớp từng mili-giây (Integer ms Ground Truth).</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🎛️</div>
                            <div class="feature-title">DAW Scrubber & Ducking DSP</div>
                            <div class="feature-desc">Timeline 4 rãnh mượt mà, thuật toán tự động giảm âm lượng BGM (-18dB) khi có giọng đọc và xuất phụ đề Karaoke.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🔒</div>
                            <div class="feature-title">Character Bank & Lock Guard</div>
                            <div class="feature-desc">Ngân hàng nhân vật đồng nhất (char1, char2), bảo vệ prompt tự động trong chế độ safe_auto chống trôi hình ảnh.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">📺</div>
                            <div class="feature-title">YouTube Review & Resumable Upload</div>
                            <div class="feature-desc">Cổng kiểm duyệt trước khi đăng (mặc định Private-First), hỗ trợ tải lên từng phần Resumable an toàn.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">📊</div>
                            <div class="feature-title">Growth Radar & Outlier Detector</div>
                            <div class="feature-desc">Phân tích chỉ số tăng trưởng kênh YouTube, phát hiện chủ đề bùng nổ của đối thủ (&gt;2.5x) và gợi ý tối ưu.</div>
                        </div>
                    </div>

                    <!-- DOWNLOAD BOX -->
                    <div class="card" style="margin-bottom:36px;border-color:var(--emerald);background:linear-gradient(180deg, #09261e 0%, var(--surface-1) 100%)">
                        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                            <div>
                                <span class="badge badge-active" style="margin-bottom:8px">BẢN CHÍNH THỨC v1.0.0 (RELEASE CANDIDATE)</span>
                                <h3 style="font-size:18px">Tải Bản Cài Đặt 2toolne Studio</h3>
                                <p class="text-muted" style="margin-top:4px">Kiến trúc Electron 44 + React 19 + Python Media Worker — Độc lập và bảo mật.</p>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="/downloads/2toolne_macOS_latest.zip" class="btn btn-emerald">🍎 Tải Cho macOS (.app)</a>
                                <a href="/downloads/2toolne_Windows_latest.zip" class="btn btn-accent">🪟 Tải Cho Windows (.zip)</a>
                                <button class="btn btn-outline" onclick="openModal('modal-register')">🎁 Dùng Thử 3 Ngày</button>
                            </div>
                        </div>
                    </div>

                    <!-- PRICING -->
                    <div class="pricing-grid">
                        <div class="pricing-card">
                            <div>
                                <h4>Gói 1 Tháng</h4>
                                <div class="price-val" style="color:var(--emerald)">1.200.000đ</div>
                                <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                <ul class="price-checklist">
                                    <li>✅ 225-Shot Parallel Flow DAG</li>
                                    <li>✅ Edge TTS + Căn Chỉnh Khớp Lời</li>
                                    <li>✅ DAW Audio Ducking & Phụ Đề Karaoke</li>
                                    <li>✅ Hỗ trợ Update trong suốt kỳ thuê</li>
                                </ul>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>
                        <div class="pricing-card featured">
                            <span class="badge badge-active pricing-card-badge">KHUYÊN DÙNG VIP</span>
                            <div>
                                <h4>Gói 1 Năm (VIP)</h4>
                                <div class="price-val" style="color:var(--emerald)">12.000.000đ</div>
                                <div class="price-sub">Thời hạn: 365 ngày sử dụng</div>
                                <ul class="price-checklist">
                                    <li>✅ Toàn bộ quyền lợi gói tháng</li>
                                    <li>✅ Hỗ trợ ưu tiên 1-1 từ Admin</li>
                                    <li>✅ YouTube Uploader & Growth Radar</li>
                                    <li>✅ Đổi máy tính HWID linh hoạt</li>
                                </ul>
                            </div>
                            <button class="btn btn-emerald" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>
                        <div class="pricing-card">
                            <div>
                                <h4>Gói Vĩnh Viễn</h4>
                                <div class="price-val" style="color:var(--emerald)">18.000.000đ</div>
                                <div class="price-sub">Sở hữu trọn đời (Lifetime)</div>
                                <ul class="price-checklist">
                                    <li>👑 <b>Cập nhật tính năng trọn đời</b></li>
                                    <li>👑 Quyền lợi VIP cao cấp nhất</li>
                                    <li>👑 Hỗ trợ kỹ thuật 24/7 từ Admin</li>
                                    <li>👑 Ưu tiên phát triển tính năng theo yêu cầu</li>
                                </ul>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">👑 Đăng Nhập Để Mua</button>
                        </div>
                    </div>
                </div>

                <!-- PRODUCT 1: SLIDESHOW BUILDER -->
                <div id="ptab-video" class="prod-tab-content" style="display:none">
                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">🎥</div>
                            <div class="feature-title">Cú Máy Ken Burns 4K</div>
                            <div class="feature-desc">Sine Easing & Subpixel nội suy Bicubic, chuyển động mượt mà ở cả 1080p, 2K và 4K.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">⚡</div>
                            <div class="feature-title">Lồng Tiếng AI 0% RAM</div>
                            <div class="feature-desc">Tích hợp Edge-TTS Microsoft Cloud và VoxCPM thế hệ mới, sinh giọng đọc phát thanh viên siêu tốc.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🔤</div>
                            <div class="feature-title">Phụ Đề Pill & Font Noonnu</div>
                            <div class="feature-desc">Tự động xuống dòng thông minh, căn chỉnh vị trí linh hoạt và tích hợp sẵn 5 font chữ cao cấp.</div>
                        </div>
                    </div>

                    <!-- DOWNLOAD BOX FOR SLIDESHOW BUILDER -->
                    <div class="card" style="margin-bottom:36px;border-color:var(--purple-500);background:linear-gradient(180deg, rgba(147, 51, 234, 0.1) 0%, var(--surface-1) 100%)">
                        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                            <div>
                                <span class="badge badge-purple" style="margin-bottom:8px">BẢN CHÍNH THỨC v2.3.0 (GPU RENDERER G)</span>
                                <h3 style="font-size:18px">Tải Bộ Cài Đặt Slideshow Builder AI</h3>
                                <p class="text-muted" style="margin-top:4px">Động cơ tăng tốc phần cứng GPU NVIDIA / VideoToolbox / QuickSync — Render 4K mượt mà.</p>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn btn-emerald">🍎 Tải Cho macOS (.zip)</a>
                                <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn btn-accent">🪟 Tải Cho Windows (.zip)</a>
                                <button class="btn btn-outline" onclick="openModal('modal-register')">🎁 Dùng Thử 3 Ngày</button>
                            </div>
                        </div>
                    </div>

                    <div class="pricing-grid">
                        <div class="pricing-card">
                            <div>
                                <h4>Gói 1 Tháng</h4>
                                <div class="price-val">1.000.000đ</div>
                                <div class="price-sub">Thời hạn: 30 ngày</div>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>
                        <div class="pricing-card featured">
                            <span class="badge badge-info pricing-card-badge">TIẾT KIỆM 2 TRIỆU</span>
                            <div>
                                <h4>Gói 1 Năm (VIP)</h4>
                                <div class="price-val">10.000.000đ</div>
                                <div class="price-sub">Thời hạn: 365 ngày</div>
                            </div>
                            <button class="btn btn-primary" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>
                        <div class="pricing-card">
                            <div>
                                <h4>Gói Vĩnh Viễn</h4>
                                <div class="price-val">15.000.000đ</div>
                                <div class="price-sub">Sở hữu trọn đời</div>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">👑 Đăng Nhập Để Mua</button>
                        </div>
                    </div>
                </div>

                <!-- PRODUCT 2: LABS EXTENSION -->
                <div id="ptab-extension" class="prod-tab-content" style="display:none">
                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">🎯</div>
                            <div class="feature-title">Tự Động Chọn Chuẩn 2K / 4K</div>
                            <div class="feature-desc">Tự động nhận diện nút tải xuống, lựa chọn độ phân giải nét cao nhất trên Google Labs.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🔢</div>
                            <div class="feature-title">Đánh Số 001→xxx Chuẩn Xác</div>
                            <div class="feature-desc">Thuật toán Bottom-Up đảm bảo thứ tự ảnh đầu tiên đến cuối cùng luôn liên tục và chính xác.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🗕</div>
                            <div class="feature-title">Thu Nhỏ Gọn & Kéo Thả</div>
                            <div class="feature-desc">Bảng điều khiển tự thu gọn khi chạy để tránh che khuất giao diện, kéo thả tự do trên màn hình.</div>
                        </div>
                    </div>

                    <!-- DOWNLOAD BOX FOR LABS EXTENSION -->
                    <div class="card" style="margin-bottom:36px;border-color:var(--info);background:linear-gradient(180deg, rgba(59, 130, 246, 0.1) 0%, var(--surface-1) 100%)">
                        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                            <div>
                                <span class="badge badge-info" style="margin-bottom:8px">EXTENSION CHROME & EDGE v1.5.0</span>
                                <h3 style="font-size:18px">Tải Tiện Ích Google Labs Flow Auto-Downloader</h3>
                                <p class="text-muted" style="margin-top:4px">Tải hàng loạt ảnh 2K/4K chỉ với 1 click, tự động đánh số 001→xxx.</p>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="/downloads/2tamne_Labs_Extension_latest.zip" class="btn btn-outline">📥 Tải Extension (.zip)</a>
                                <button class="btn btn-accent" onclick="openModal('modal-login')">⚡ Mua Key (100k)</button>
                            </div>
                        </div>
                    </div>

                    <div style="max-width:480px;margin:0 auto">
                        <div class="pricing-card featured" style="border-color:var(--info)">
                            <span class="badge badge-info pricing-card-badge">HOT DEAL 100K</span>
                            <div>
                                <h4>Extension Google Labs 2K/4K</h4>
                                <div class="price-val" style="color:var(--info)">100.000đ</div>
                                <div class="price-sub">Sở hữu vĩnh viễn (Lifetime)</div>
                                <ul class="price-checklist">
                                    <li>👑 <b>Sở hữu trọn đời vĩnh viễn</b></li>
                                    <li>👑 Tự động tải ảnh 2K/4K đánh số 001→xxx</li>
                                    <li>👑 Khóa bản quyền 1 máy (Hỗ trợ đổi máy linh hoạt)</li>
                                    <li>👑 Miễn phí mọi bản Update tương lai</li>
                                </ul>
                            </div>
                            <button class="btn btn-accent" style="width:100%" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua (100k)</button>
                        </div>
                    </div>
                </div>

                <!-- PRODUCT 3: TOKEN UPSCALE 4K -->
                <div id="ptab-token" class="prod-tab-content" style="display:none">
                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">✨</div>
                            <div class="feature-title">Upscale 2K / 4K Siêu Nét</div>
                            <div class="feature-desc">Mô hình AI siêu phân giải chuyên sâu cho ảnh nhân vật, tranh phong cảnh và tư liệu tài liệu lịch sử.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🔒</div>
                            <div class="feature-title">Chu Trình 3 Bước An Toàn</div>
                            <div class="feature-desc">Khóa tạm ➔ Xử lý xong mới trừ tiền ➔ Tự động hoàn trả 100% token nếu phần mềm gặp sự cố mạng hay tắt máy.</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🎁</div>
                            <div class="feature-title">Tặng 50 Token Miễn Phí</div>
                            <div class="feature-desc">Đăng ký tài khoản nhận ngay 50 token trải nghiệm tạo ảnh 2K/4K không tốn một đồng chi phí nào.</div>
                        </div>
                    </div>

                    <!-- DOWNLOAD BOX FOR UPSCALE APP -->
                    <div class="card" style="margin-bottom:32px;border-color:#facc15;background:linear-gradient(180deg, rgba(250, 204, 21, 0.1) 0%, var(--surface-1) 100%)">
                        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
                            <div>
                                <span class="badge" style="background:#facc15;color:#000;font-weight:800;margin-bottom:8px">BẢN CHÍNH THỨC v1.0.0 (ON-DEVICE AI ACCELERATION)</span>
                                <h3 style="font-size:18px;margin:0 0 4px">Tải Bộ Cài Đặt 2toolne Upscale 4K</h3>
                                <p class="text-muted" style="margin:0;font-size:13px">Tăng tốc phần cứng qua Vulkan / DirectML / Apple Silicon Metal — Chạy độc lập trên máy tính.</p>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="/downloads/2toolne_Upscale_macOS_latest.zip" class="btn btn-emerald">🍏 Tải Cho macOS (.zip / .dmg)</a>
                                <a href="/downloads/2toolne_Upscale_Windows_latest.zip" class="btn btn-accent">🪟 Tải Cho Windows (.zip / .exe)</a>
                                <button class="btn btn-outline" onclick="openModal('modal-register')">🎁 Nhận 50 Token Miễn Phí</button>
                            </div>
                        </div>
                    </div>

                    <div class="pricing-grid">
                        <!-- STARTER -->
                        <div class="pricing-card">
                            <div>
                                <h4>Gói Starter (1.000 Token)</h4>
                                <div class="price-val" style="color:#facc15">100.000đ</div>
                                <div class="price-sub">100đ / token • Không hạn dùng</div>
                                <ul class="price-checklist">
                                    <li>🪙 1.000 Tokens Upscale 2K / 4K</li>
                                    <li>🖼️ Tương đương 1.000 ảnh 2K hoặc 500 ảnh 4K</li>
                                    <li>⚡ Token vĩnh viễn không hết hạn</li>
                                </ul>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>

                        <!-- PRO -->
                        <div class="pricing-card featured" style="border-color:#facc15">
                            <span class="badge pricing-card-badge" style="background:#facc15;color:#000;font-weight:800">TẶNG 500 TOKENS</span>
                            <div>
                                <h4>Gói Pro (3.000 Token)</h4>
                                <div class="price-val" style="color:#facc15">250.000đ</div>
                                <div class="price-sub">~83đ / token • Tặng thêm 500 token</div>
                                <ul class="price-checklist">
                                    <li>🪙 3.000 Tokens (Đã gồm 500 token thưởng)</li>
                                    <li>🖼️ Tương đương 3.000 ảnh 2K hoặc 1.500 ảnh 4K</li>
                                    <li>⚡ Tối ưu cho nhà sáng tạo nội dung YouTube</li>
                                </ul>
                            </div>
                            <button class="btn btn-emerald" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>

                        <!-- STUDIO -->
                        <div class="pricing-card">
                            <div>
                                <h4>Gói Studio (10.000 Token)</h4>
                                <div class="price-val" style="color:#facc15">700.000đ</div>
                                <div class="price-sub">70đ / token • Tiết kiệm 30%</div>
                                <ul class="price-checklist">
                                    <li>🪙 10.000 Tokens (Tặng 3.000 token)</li>
                                    <li>🖼️ Tương đương 10.000 ảnh 2K hoặc 5.000 ảnh 4K</li>
                                    <li>👑 Ưu tiên tốc độ xử lý trên hàng đợi GPU</li>
                                </ul>
                            </div>
                            <button class="btn btn-outline" onclick="openModal('modal-login')">⚡ Đăng Nhập Để Mua</button>
                        </div>

                        <!-- UNLIMITED -->
                        <div class="pricing-card" style="border-color:var(--purple-500)">
                            <div>
                                <span class="badge badge-purple" style="margin-bottom:8px">KHÔNG GIỚI HẠN</span>
                                <h4>Unlimited Studio (30 Ngày)</h4>
                                <div class="price-val" style="color:var(--purple-400)">1.800.000đ</div>
                                <div class="price-sub">Thời hạn: 30 ngày sử dụng</div>
                                <ul class="price-checklist">
                                    <li>👑 Không giới hạn ảnh 2K / 4K</li>
                                    <li>🚀 Render liên tục không trừ token</li>
                                    <li>⚡ Thích hợp cho studio quy mô lớn</li>
                                </ul>
                            </div>
                            <button class="btn btn-primary" onclick="openModal('modal-login')">👑 Đăng Nhập Để Mua</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- DOWNLOADS SECTION -->
            <section id="downloads" style="padding:48px 0 64px;border-top:1px solid var(--border)">
                <div style="text-align:center;margin-bottom:32px">
                    <span class="badge badge-primary" style="margin-bottom:8px">DOWNLOADS</span>
                    <h2 style="font-size:28px">Tải Phần Mềm & Tiện Ích</h2>
                    <p class="text-muted" style="margin-top:4px">Tương thích hoàn hảo trên cả Windows 10/11 và macOS Apple Silicon / Intel</p>
                </div>

                <!-- 1. SMART AUTO-DETECT 2TOOLNE UPSCALE 4K -->
                <div class="card" style="margin-bottom:24px;border-color:#facc15;background:linear-gradient(180deg, #221a05 0%, var(--surface-1) 100%)">
                    <div class="card-body">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                            <div style="flex:1;min-width:280px">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span class="badge" style="background:#facc15;color:#000;font-weight:800">✨ AI SIÊU PHÂN GIẢI 2K & 4K</span>
                                    <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                </div>
                                <h3 style="font-size:22px;margin:0 0 6px">2toolne Upscale 4K (AI Super-Resolution App)</h3>
                                <p class="text-muted" style="margin:0;font-size:13.5px">Ứng dụng phóng to và tái tạo chi tiết hình ảnh 2K & 4K bằng mô hình AI on-device (Vulkan, DirectML, Apple Silicon). Chạy cục bộ siêu tốc, bảo mật tuyệt đối.</p>
                            </div>
                            <!-- PROMINENT SMART 1-CLICK BUTTON -->
                            <div class="smart-download-container" data-app-name="2toolne Upscale 4K" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                <a href="/downloads/2toolne_Upscale_Windows_latest.zip" 
                                   data-os-win="/downloads/2toolne_Upscale_Windows_latest.zip" 
                                   data-os-mac="/downloads/2toolne_Upscale_macOS_latest.zip" 
                                   class="smart-download-btn btn btn-accent btn-lg" 
                                   style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(250, 204, 21, 0.25)">
                                    <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.zip)</span>
                                </a>
                                <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Tự động nhận diện cấu hình • Bản chuẩn v1.0.0 Stable</div>
                            </div>
                        </div>

                        <!-- ALL DOWNLOAD OPTIONS -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                            <!-- WINDOWS -->
                            <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                    <span>🪟</span> Dành Cho Windows (10/11 64-bit):
                                </div>
                                <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tối ưu DirectML, Vulkan, NVIDIA CUDA / Tensor Cores.</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a href="/downloads/2toolne_Upscale_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">📥 Tải .zip (Portable 64-bit)</a>
                                    <a href="/downloads/2toolne_Upscale_Setup_latest.exe" class="btn btn-outline btn-sm" style="flex:1;text-align:center">⚙️ Bản cài đặt .exe</a>
                                </div>
                            </div>

                            <!-- MACOS -->
                            <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                    <span>🍏</span> Dành Cho macOS (Monterey 12+):
                                </div>
                                <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal: Apple Silicon (M1/M2/M3/M4 Metal) & Intel.</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a href="/downloads/2toolne_Upscale_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍏 Tải .zip (Universal Mac)</a>
                                    <a href="/downloads/2toolne_Upscale_latest.dmg" class="btn btn-outline btn-sm" style="flex:1;text-align:center">📦 Gói cài .dmg</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. SMART AUTO-DETECT 2TOOLNE STUDIO -->
                <div class="card" style="margin-bottom:24px;border-color:var(--emerald);background:linear-gradient(180deg, #09261e 0%, var(--surface-1) 100%)">
                    <div class="card-body">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px">
                            <div style="flex:1;min-width:280px">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span class="badge badge-active">🚀 BẢN CHÍNH THỨC 2026</span>
                                    <span class="os-detect-badge badge badge-info" style="font-weight:600">🔍 Đang nhận diện hệ điều hành...</span>
                                </div>
                                <h3 style="font-size:22px;margin:0 0 6px">2toolne Studio (AI YouTube Production Suite)</h3>
                                <p class="text-muted" style="margin:0;font-size:13.5px">Hệ thống sản xuất phim tài liệu tự động 200–250 shots, Edge TTS, Căn chỉnh lời chính xác, Upscale 4K & Multi-Track DAW.</p>
                            </div>
                            <!-- PROMINENT SMART 1-CLICK BUTTON -->
                            <div class="smart-download-container" data-app-name="2toolne Studio" style="display:flex;flex-direction:column;gap:8px;min-width:260px">
                                <a href="/downloads/2toolne_Windows_latest.zip" 
                                   data-os-win="/downloads/2toolne_Windows_latest.zip" 
                                   data-os-mac="/downloads/2toolne_macOS_latest.zip" 
                                   class="smart-download-btn btn btn-emerald btn-lg" 
                                   style="font-weight:700;text-align:center;box-shadow:0 0 20px rgba(16, 185, 129, 0.3)">
                                    <span class="smart-download-icon">🪟</span> <span class="smart-download-text">Tải Cho Windows (.zip)</span>
                                </a>
                                <div style="font-size:11px;color:var(--muted-foreground);text-align:center">Tự động nhận diện cấu hình • Bản chuẩn v1.0.0 RC</div>
                            </div>
                        </div>

                        <!-- ALL DOWNLOAD OPTIONS -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px">
                            <!-- WINDOWS -->
                            <div class="os-option-box os-box-windows" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                    <span>🪟</span> Dành Cho Windows (10/11 64-bit):
                                </div>
                                <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Tối ưu NVIDIA CUDA, RTX Tensor, Intel & AMD.</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a href="/downloads/2toolne_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">📥 Tải .zip (Portable 64-bit)</a>
                                    <a href="/downloads/2toolne_Setup_latest.exe" class="btn btn-outline btn-sm" style="flex:1;text-align:center">⚙️ Bản cài đặt .exe</a>
                                </div>
                            </div>

                            <!-- MACOS -->
                            <div class="os-option-box os-box-macos" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-sm);padding:12px">
                                <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:var(--foreground);font-size:13px;margin-bottom:6px">
                                    <span>🍏</span> Dành Cho macOS (Monterey 12+):
                                </div>
                                <p style="font-size:12px;color:var(--muted-foreground);margin-bottom:10px">Universal: Apple Silicon (M1/M2/M3/M4) & Intel.</p>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a href="/downloads/2toolne_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍏 Tải .zip (Universal Mac)</a>
                                    <a href="/downloads/2toolne_latest.dmg" class="btn btn-outline btn-sm" style="flex:1;text-align:center">📦 Gói cài .dmg</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. SLIDESHOW BUILDER & EXTENSION GRID -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                    <div class="card">
                        <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:100%">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span style="font-size:22px">🎬</span>
                                    <div class="card-title" style="margin:0">Slideshow Builder AI (v2.3.0 Stable)</div>
                                </div>
                                <p class="text-muted" style="font-size:13px;margin:8px 0 16px">Động cơ Renderer G GPU Subpixel nội suy mượt mà 60 FPS, phụ đề tự động Pill/Noonnu, lồng tiếng đa giọng đọc.</p>
                                <div class="text-subtle" style="font-size:12px;margin-bottom:16px">Tương thích: Windows 10/11 & macOS Monterey+</div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <a href="/downloads/SlideshowBuilder_macOS_latest.zip" class="btn btn-emerald btn-sm" style="flex:1;text-align:center">🍎 Tải macOS (.zip)</a>
                                <a href="/downloads/SlideshowBuilder_Windows_latest.zip" class="btn btn-accent btn-sm" style="flex:1;text-align:center">🪟 Tải Windows (.zip)</a>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body" style="display:flex;flex-direction:column;justify-content:space-between;height:100%">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <span style="font-size:22px">🧩</span>
                                    <div class="card-title" style="margin:0">Google Labs Flow Extension (v1.5.0)</div>
                                </div>
                                <p class="text-muted" style="font-size:13px;margin:8px 0 16px">Tiện ích tự động tải ảnh 2K/4K hàng loạt, tự động đánh số thứ tự chuẩn xác 001→xxx cho Chrome / Edge.</p>
                                <div class="text-subtle" style="font-size:12px;margin-bottom:16px">Cài đặt trực tiếp qua Developer Mode trong 30s</div>
                            </div>
                            <a href="/downloads/2tamne_Labs_Extension_latest.zip" class="btn btn-outline" style="width:100%;text-align:center">📥 Tải Extension (.zip)</a>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <!-- ═══ FOOTER ═══ -->
    <footer style="border-top:1px solid var(--border);padding:32px 0;text-align:center;font-size:13px;color:var(--muted-foreground);margin-top:64px">
        <div class="container">
            <p>© 2026 <b>2tamne.site</b> — Commercial AI Software Suite. Mọi quyền được bảo lưu.</p>
            <p style="margin-top:6px">Hotline & Zalo Admin Hỗ Trợ: <a href="https://zalo.me/0326649304" target="_blank" style="color:var(--emerald);font-weight:600">0326649304</a></p>
        </div>
    </footer>

    <!-- ═══ MODALS & DIALOGS ═══ -->

    <!-- MODAL: VIETQR PAYMENT WITH COUNTDOWN TIMER -->
    <div id="modal-qr-pay" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:440px;text-align:center">
            <div class="modal-header">
                <div class="modal-title">Quét Mã VietQR Thanh Toán</div>
                <button class="modal-close" onclick="closeModal('modal-qr-pay')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--muted-foreground);margin-bottom:12px">
                    Gói chọn mua: <b id="qr-pkg-title" style="color:var(--emerald)">...</b>
                </p>
                
                <!-- QR IMAGE -->
                <div style="background:#fff;padding:8px;border-radius:var(--radius-sm);display:inline-block;margin-bottom:14px;box-shadow:var(--shadow-md)">
                    <img src="/assets/vietqr_tamne.png" alt="VietQR" style="width:230px;height:auto;border-radius:4px;display:block">
                </div>

                <!-- BANK DETAILS -->
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;text-align:left;font-size:12.5px;margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span class="text-muted">Ngân hàng:</span>
                        <b>VietinBank (PGD Thủ Đô)</b>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span class="text-muted">Chủ tài khoản:</span>
                        <b>HOANG LUONG TAM</b>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span class="text-muted">Số tài khoản:</span>
                        <b class="font-mono" style="color:var(--info)">101876965948</b>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span class="text-muted">Số tiền:</span>
                        <b id="qr-pkg-price" style="color:var(--emerald);font-size:14px">...</b>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span class="text-muted">Nội dung CK:</span>
                        <div style="display:flex;align-items:center;gap:4px">
                            <b id="qr-memo-text" class="font-mono" style="color:var(--warning)">...</b>
                            <button type="button" class="btn btn-outline btn-xs" onclick="copyText(document.getElementById('qr-memo-text').textContent)">📋</button>
                        </div>
                    </div>
                </div>

                <!-- 2-MINUTE COUNTDOWN -->
                <div style="background:var(--surface-2);border:1px solid var(--border-strong);border-radius:var(--radius-sm);padding:8px;font-size:12.5px;font-weight:600;color:var(--foreground);margin-bottom:14px">
                    ⏱️ Thời gian giữ đơn: <span id="countdown-timer" style="color:var(--danger);font-size:14px">02:00</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="submit_payment">
                    <input type="hidden" name="package_name" id="form-pkg-name">
                    <input type="hidden" name="package_price" id="form-pkg-price">
                    <input type="hidden" name="duration_days" id="form-pkg-days">
                    <input type="hidden" name="tier" id="form-pkg-tier">
                    <input type="hidden" name="product" id="form-pkg-product" value="SLIDESHOW">
                    <button type="submit" class="btn btn-emerald" style="width:100%;height:40px;font-size:14px">
                        ✅ Tôi Đã Chuyển Khoản Thành Công
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: LOGIN -->
    <div id="modal-login" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:400px">
            <div class="modal-header">
                <div class="modal-title">🔐 Đăng Nhập Tài Khoản</div>
                <button class="modal-close" onclick="closeModal('modal-login')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label class="form-label">TÊN ĐĂNG NHẬP:</label>
                        <input type="text" name="username" class="form-input" required autofocus>
                    </div>
                    <div class="form-group">
                        <label class="form-label">MẬT KHẨU:</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;height:38px">Đăng Nhập Ngay</button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: REGISTER -->
    <div id="modal-register" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">🎁 Đăng Ký Nhận Key 3 Ngày</div>
                <button class="modal-close" onclick="closeModal('modal-register')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:12.5px;color:var(--muted-foreground);margin-bottom:14px">
                    Tự động cấp 1 License Key trải nghiệm 3 ngày miễn phí cho địa chỉ IP của bạn.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="form-group">
                        <label class="form-label">TÊN ĐĂNG NHẬP (chữ thường, số):</label>
                        <input type="text" name="username" class="form-input" placeholder="Ví dụ: hoangtam2026" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">HỌ VÀ TÊN:</label>
                        <input type="text" name="fullname" class="form-input" placeholder="Ví dụ: Hoàng Tâm" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SỐ ĐIỆN THOẠI / ZALO:</label>
                        <input type="text" name="phone" class="form-input" placeholder="Ví dụ: 09xx xxx xxx" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">MẬT KHẨU:</label>
                        <input type="password" name="password" class="form-input" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-emerald" style="width:100%;height:40px">
                        🚀 Tạo Tài Khoản & Nhận Key Ngay
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: CONFIRM HWID RESET (DESTRUCTIVE ACTION CONFIRM DIALOG) -->
    <div id="modal-reset-hwid-confirm" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">🔄 Xác Nhận Đổi Máy (Reset HWID)</div>
                <button class="modal-close" onclick="closeModal('modal-reset-hwid-confirm')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--foreground);line-height:1.6;margin-bottom:14px">
                    Bạn có chắc chắn muốn <b>Reset liên kết phần cứng (HWID)</b> cho mã bản quyền này?
                </p>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px;font-size:12.5px;color:var(--muted-foreground);margin-bottom:16px">
                    Key: <b id="hwid-reset-key-label" class="font-mono text-primary">...</b><br>
                    Sau khi reset, bạn có thể nhập key này để kích hoạt trên máy tính mới.
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_hwid">
                    <input type="hidden" name="key" id="hwid-reset-key-input">
                    <div style="display:flex;justify-content:flex-end;gap:10px">
                        <button type="button" class="btn btn-outline" onclick="closeModal('modal-reset-hwid-confirm')">Hủy Bỏ</button>
                        <button type="submit" class="btn btn-accent">Xác Nhận Reset HWID</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- GLOBAL BROADCAST NOTICE MODAL -->
    <?php
    $active_broadcast = $sys_config['broadcast_notice'] ?? null;
    $has_active_broadcast = $active_broadcast && !empty($active_broadcast['active']) && !empty($active_broadcast['title']);
    if ($has_active_broadcast):
    ?>
    <div id="modal-global-broadcast" class="modal-backdrop">
        <div class="modal-dialog" style="max-width:520px;text-align:center">
            <div class="modal-header" style="justify-content:center">
                <span class="badge badge-info">📢 THÔNG BÁO TỪ HỆ THỐNG</span>
            </div>
            <div class="modal-body">
                <h3 style="font-size:18px;margin-bottom:12px"><?= htmlspecialchars($active_broadcast['title']) ?></h3>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;font-size:13px;color:var(--muted-foreground);line-height:1.6;text-align:left;white-space:pre-wrap;max-height:260px;overflow-y:auto;margin-bottom:16px">
                    <?= htmlspecialchars($active_broadcast['content']) ?>
                </div>
                <?php if (!empty($active_broadcast['button_text'])): ?>
                    <a href="<?= htmlspecialchars($active_broadcast['button_url'] ?: '#') ?>" target="_blank" class="btn btn-outline" style="width:100%;margin-bottom:10px">
                        <?= htmlspecialchars($active_broadcast['button_text']) ?>
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" style="width:100%" onclick="confirmNoticeRead('<?= htmlspecialchars($active_broadcast['id']) ?>')">
                    ✅ Tôi Đã Hiểu (Đóng Thông Báo)
                </button>
            </div>
        </div>
    </div>
    <script>
    (function() {
        const noticeId = '<?= htmlspecialchars($active_broadcast['id']) ?>';
        const storageKey = 'read_notice_' + noticeId;
        const isReadLocal = localStorage.getItem(storageKey);
        const isReadCookie = document.cookie.split('; ').some(row => row.startsWith(storageKey + '='));
        if (!isReadLocal && !isReadCookie) {
            window.addEventListener('DOMContentLoaded', () => {
                const m = document.getElementById('modal-global-broadcast');
                if (m) m.classList.add('active');
            });
        }
    })();
    function confirmNoticeRead(noticeId) {
        localStorage.setItem('read_notice_' + noticeId, '1');
        document.cookie = 'read_notice_' + noticeId + '=1; path=/; max-age=' + (86400 * 60);
        const m = document.getElementById('modal-global-broadcast');
        if (m) m.classList.remove('active');
        fetch('index.php?ajax=mark_notice_read&id=' + encodeURIComponent(noticeId)).catch(() => {});
    }
    </script>
    <?php endif; ?>

    <!-- TOAST NOTIFICATION -->
    <div id="toast">📋 Đã sao chép vào bộ nhớ đệm!</div>

    <!-- SCRIPTS -->
    <script>
        let timerInterval = null;

        function openModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        }
        function closeModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.remove('active');
            if (id === 'modal-qr-pay' && timerInterval) clearInterval(timerInterval);
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
        function openResetHwidModal(key) {
            document.getElementById('hwid-reset-key-label').textContent = key;
            document.getElementById('hwid-reset-key-input').value = key;
            openModal('modal-reset-hwid-confirm');
        }

        const TAB_BTN_MAP = {
            'tab-my-keys': 'btn-tab-keys',
            'tab-wallet-view': 'btn-tab-wallet',
            'tab-downloads': 'btn-tab-downloads',
            'tab-buy-key': 'btn-tab-buy',
            'tab-features-view': 'btn-tab-features',
            'tab-bugs-view': 'btn-tab-bugs',
            'tab-settings': 'btn-tab-settings'
        };

        function switchProductTab(tabId, btnId) {
            document.querySelectorAll('.prod-tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.prod-tab-btn').forEach(btn => {
                btn.classList.remove('active', 'btn-emerald', 'btn-primary');
                btn.classList.add('btn-outline');
            });
            const targetEl = document.getElementById(tabId);
            const targetBtn = document.getElementById(btnId);
            if (targetEl) targetEl.style.display = 'block';
            if (targetBtn) {
                targetBtn.classList.add('active', 'btn-emerald');
                targetBtn.classList.remove('btn-outline');
            }
        }

        function switchMainTab(tabId) {
            document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.dash-nav-btn').forEach(el => el.classList.remove('active'));
            const target = document.getElementById(tabId);
            if (target) target.style.display = 'block';
            if (TAB_BTN_MAP[tabId]) {
                const b = document.getElementById(TAB_BTN_MAP[tabId]);
                if (b) b.classList.add('active');
            }
        }

        function openQrPayment(pkgName, pkgPrice, days, tier, product = '2TOOLNE') {
            <?php if (!$user_info): ?>
                openModal('modal-login');
                return;
            <?php endif; ?>
            document.getElementById('qr-pkg-title').textContent = pkgName;
            document.getElementById('qr-pkg-price').textContent = pkgPrice;
            document.getElementById('qr-memo-text').textContent = 'Mua tool ' + '<?= $current_user ?>';
            
            document.getElementById('form-pkg-name').value = pkgName;
            document.getElementById('form-pkg-price').value = pkgPrice;
            document.getElementById('form-pkg-days').value = days;
            document.getElementById('form-pkg-tier').value = tier;
            document.getElementById('form-pkg-product').value = product;

            openModal('modal-qr-pay');
            startCountdown(120);
        }

        function startCountdown(durationSec) {
            if (timerInterval) clearInterval(timerInterval);
            let left = durationSec;
            const timerEl = document.getElementById('countdown-timer');

            function updateDisplay() {
                let m = Math.floor(left / 60);
                let s = left % 60;
                if (timerEl) timerEl.textContent = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }
            updateDisplay();

            timerInterval = setInterval(() => {
                left--;
                if (left < 0) {
                    clearInterval(timerInterval);
                    if (timerEl) timerEl.textContent = 'Hết hạn';
                } else {
                    updateDisplay();
                }
            }, 1000);
        }

        function initSmartOSDetection() {
            const ua = (navigator.userAgent || '').toLowerCase();
            const platform = (navigator.platform || '').toLowerCase();
            const isMac = platform.includes('mac') || ua.includes('macintosh') || ua.includes('mac os');

            const badges = document.querySelectorAll('.os-detect-badge');
            const containers = document.querySelectorAll('.smart-download-container');
            const winBoxes = document.querySelectorAll('.os-box-windows');
            const macBoxes = document.querySelectorAll('.os-box-macos');

            if (isMac) {
                badges.forEach(b => {
                    b.innerHTML = '🍏 Thiết bị của bạn: <b>macOS (Apple Silicon & Intel)</b>';
                    b.className = 'os-detect-badge badge badge-active';
                });
                containers.forEach(c => {
                    const btn = c.querySelector('.smart-download-btn');
                    const icon = c.querySelector('.smart-download-icon');
                    const text = c.querySelector('.smart-download-text');
                    if (btn) {
                        const macUrl = btn.getAttribute('data-os-mac') || '/downloads/2toolne_macOS_latest.zip';
                        btn.href = macUrl;
                        btn.className = 'smart-download-btn btn btn-emerald btn-lg';
                    }
                    if (icon) icon.textContent = '🍏';
                    if (text) text.textContent = 'Tải Cho macOS (.zip Universal)';
                });
                macBoxes.forEach(box => {
                    box.style.borderColor = 'var(--emerald)';
                    box.style.background = 'rgba(16, 185, 129, 0.08)';
                });
            } else {
                badges.forEach(b => {
                    b.innerHTML = '🪟 Thiết bị của bạn: <b>Windows 10/11 64-bit</b>';
                    b.className = 'os-detect-badge badge badge-info';
                });
                containers.forEach(c => {
                    const btn = c.querySelector('.smart-download-btn');
                    const icon = c.querySelector('.smart-download-icon');
                    const text = c.querySelector('.smart-download-text');
                    if (btn) {
                        const winUrl = btn.getAttribute('data-os-win') || '/downloads/2toolne_Windows_latest.zip';
                        btn.href = winUrl;
                        btn.className = 'smart-download-btn btn btn-accent btn-lg';
                    }
                    if (icon) icon.textContent = '🪟';
                    if (text) text.textContent = 'Tải Cho Windows (.zip 64-bit)';
                });
                winBoxes.forEach(box => {
                    box.style.borderColor = 'var(--accent)';
                    box.style.background = 'rgba(99, 102, 241, 0.08)';
                });
            }
        }

        window.addEventListener('DOMContentLoaded', initSmartOSDetection);

        // Auto-open tab from URL ?tab= parameter or #hash (PRG & Hash Router)
        (function() {
            const urlTab = new URLSearchParams(location.search).get('tab');
            const rawHash = (location.hash || '').replace('#', '');
            const tabMap = {
                'keys':            'tab-my-keys',
                'my-keys':         'tab-my-keys',
                'wallet':          'tab-wallet-view',
                'tokens':          'tab-wallet-view',
                'token':           'tab-wallet-view',
                'tab-wallet-view': 'tab-wallet-view',
                'downloads':       'tab-downloads',
                'download':        'tab-downloads',
                'tab-downloads':   'tab-downloads',
                'buy':             'tab-buy-key',
                'buy-key':         'tab-buy-key',
                'pricing':         'tab-buy-key',
                'products':        'tab-buy-key',
                'features':        'tab-features-view',
                'bugs':            'tab-bugs-view',
                'settings':        'tab-settings'
            };
            const target = urlTab || rawHash;
            if (target && tabMap[target]) {
                window.addEventListener('DOMContentLoaded', () => {
                    const el = document.getElementById(tabMap[target]);
                    if (el) switchMainTab(tabMap[target]);
                });
            }
            window.addEventListener('hashchange', () => {
                const h = (location.hash || '').replace('#', '');
                if (h && tabMap[h]) {
                    const el = document.getElementById(tabMap[h]);
                    if (el) switchMainTab(tabMap[h]);
                }
            });
        })();

        // Click outside to close modal
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                e.target.classList.remove('active');
                if (timerInterval) clearInterval(timerInterval);
            }
        });

        // Auto-polling for pending orders
        <?php if (!empty($user_pending_orders)): ?>
        (function() {
            let pollSec = 10;
            const secEl = document.getElementById('poll-sec');
            setInterval(function() {
                pollSec--;
                if (secEl) secEl.textContent = pollSec;
                if (pollSec <= 0) pollSec = 10;
            }, 1000);

            setInterval(function() {
                fetch('index.php?ajax=poll_orders')
                .then(r => r.json())
                .then(data => {
                    if (data.pending_count === 0) location.reload();
                })
                .catch(() => {});
            }, 10000);
        })();
        <?php endif; ?>
    </script>
</body>
</html>
