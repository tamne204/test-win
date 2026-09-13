<?php
// website/sepay_checkout.php
// 2TOOLNE SePay Host Checkout Initializer & Form Redirector
// Resolves: SePay POST form requirement, protects merchant secrets, enables desktop browser GET flow

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/storage/db.php';
require_once __DIR__ . '/sepay_config.php';
require_once __DIR__ . '/lib/SepayClient.php';

$requestedOrderId = trim((string)($_GET['order_id'] ?? ($_POST['order_id'] ?? '')));

$ord_id       = '';
$pkg_name     = '';
$pkg_price    = '';
$amount       = 0;
$current_user = '';
$order_memo   = '';

if ($requestedOrderId !== '') {
    // -------------------------------------------------------------
    // Branch A: Server-Driven Order ID (Desktop App / Scoped Order)
    // No session required; order record is validated against DB
    // -------------------------------------------------------------
    $order = db_get_order($requestedOrderId);
    if (!$order) {
        ?>
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>2TOOLNE — Không tìm thấy đơn hàng</title>
            <meta name="theme-color" content="#FF7A00">
            <style>
                body { background: #0E0F11; color: #FFFFFF; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .card { background: #15171A; border: 1px solid #292C31; border-radius: 14px; padding: 32px 24px; max-width: 440px; width: 90%; text-align: center; box-shadow: 0 16px 36px rgba(0,0,0,0.5); }
                h3 { color: #F05252; margin: 12px 0 8px; font-size: 18px; }
                p { color: #8E929B; font-size: 13.5px; line-height: 1.5; margin-bottom: 20px; }
                .code-box { background: #1A1C20; border: 1px solid #292C31; padding: 8px 12px; border-radius: 6px; font-family: monospace; color: #FF7A00; font-size: 13px; margin-bottom: 16px; word-break: break-all; }
                .btn { display: inline-block; background: #FF7A00; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 13.5px; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 40px;">⚠️</div>
                <h3>Không Tìm Thấy Đơn Hàng</h3>
                <p>Mã phiên thanh toán không tồn tại hoặc đã hết hạn hiệu lực.</p>
                <div class="code-box"><?= htmlspecialchars($requestedOrderId) ?></div>
                <a href="/index.php" class="btn">Quay lại Trang Chủ</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $status = strtolower((string)($order['status'] ?? 'pending'));
    if ($status === 'approved' || $status === 'completed') {
        ?>
        <!DOCTYPE html>
        <html lang="vi">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>2TOOLNE — Đơn hàng đã thanh toán</title>
            <meta name="theme-color" content="#FF7A00">
            <style>
                body { background: #0E0F11; color: #FFFFFF; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .card { background: #15171A; border: 1px solid #292C31; border-radius: 14px; padding: 32px 24px; max-width: 440px; width: 90%; text-align: center; box-shadow: 0 16px 36px rgba(0,0,0,0.5); }
                h3 { color: #35C889; margin: 12px 0 8px; font-size: 18px; }
                p { color: #8E929B; font-size: 13.5px; line-height: 1.5; margin-bottom: 20px; }
                .code-box { background: #1A1C20; border: 1px solid #292C31; padding: 8px 12px; border-radius: 6px; font-family: monospace; color: #35C889; font-size: 13px; margin-bottom: 16px; }
                .btn { display: inline-block; background: #FF7A00; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 13.5px; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="card">
                <div style="font-size: 40px;">🎉</div>
                <h3>Đơn Hàng Đã Thanh Toán</h3>
                <p>Đơn hàng này đã được hệ thống xác nhận thanh toán thành công và kích hoạt dịch vụ.</p>
                <div class="code-box"><?= htmlspecialchars($requestedOrderId) ?></div>
                <a href="/index.php" class="btn">Quay lại Trang Chủ</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $ord_id       = (string)$order['id'];
    $pkg_name     = (string)($order['package_name'] ?: 'Gói Token / Dịch vụ 2TOOLNE');
    $pkg_price    = (string)($order['package_price'] ?: '0');
    $amount       = sepay_parse_amount($pkg_price);
    $current_user = (string)($order['username'] ?: 'customer');
    $order_memo   = (string)($order['memo'] ?? '');

} else {
    // -------------------------------------------------------------
    // Branch B: Legacy Web Portal Checkout (POST with Session Auth)
    // -------------------------------------------------------------
    $session_user = $_SESSION['user'] ?? '';
    $user_info    = $session_user ? db_get_user($session_user) : null;

    if (!$user_info) {
        header('Location: /index.php?login_required=1');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: /index.php');
        exit;
    }

    $current_user = $session_user;
    $pkg_name     = trim($_POST['package_name'] ?? 'Gói 1 Tháng');
    $pkg_price    = trim($_POST['package_price'] ?? '100.000đ');
    $pkg_days     = intval($_POST['duration_days'] ?? 30);
    $pkg_tier     = trim($_POST['tier'] ?? 'VIP');
    $pkg_prod     = trim($_POST['product'] ?? (
        stripos($pkg_name, 'Token') !== false 
            ? 'TOKEN_WALLET' 
            : (stripos($pkg_name, '2toolne') !== false ? '2TOOLNE' : (stripos($pkg_name, 'Extension') !== false ? 'LABS_EXTENSION' : 'SLIDESHOW'))
    ));

    $amount = sepay_parse_amount($pkg_price);
    if ($amount <= 0) {
        die("Số tiền thanh toán không hợp lệ: {$pkg_price}");
    }

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
        "Thanh toán SePay " . $current_user
    );
}

if ($amount <= 0) {
    die("Số tiền thanh toán không hợp lệ.");
}

// -------------------------------------------------------------
// SePay Merchant Configuration & Form Preparation
// -------------------------------------------------------------
$config      = sepay_get_config();
$merchant_id = trim((string)($config['merchant_id'] ?? ''));
$secret_key  = trim((string)($config['secret_key'] ?? ''));
$env         = trim((string)($config['env'] ?? 'production'));

if (empty($merchant_id) || empty($secret_key)) {
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>2TOOLNE — Cấu hình SePay chưa hoàn tất</title>
        <meta name="theme-color" content="#FF7A00">
        <style>
            body { background:#0E0F11; color:#FFFFFF; display:flex; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; margin:0; }
            .box { background:#15171A; border:1px solid #292C31; border-radius:14px; padding:28px; max-width:480px; width:90%; text-align:center; box-shadow: 0 16px 36px rgba(0,0,0,0.5); }
            .btn { display:inline-block; margin-top:16px; background:#FF7A00; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:600; font-size:13.5px; }
        </style>
    </head>
    <body>
        <div class="box">
            <div style="font-size:40px;margin-bottom:12px">⚙️</div>
            <h2 style="margin-top:0; font-size:18px;">Cổng SePay Đang Được Cấu Hình</h2>
            <p style="font-size:13.5px;color:#8E929B;line-height:1.6">
                Hệ thống thanh toán tự động qua SePay đang trong quá trình cập nhật thông tin Merchant. Vui lòng liên hệ Admin hoặc thanh toán qua chuyển khoản trực tiếp.
            </p>
            <p style="font-size:12px;color:#8E929B">Mã đơn hàng: <b style="color:#FF7A00"><?= htmlspecialchars($ord_id) ?></b></p>
            <a href="/index.php" class="btn">Quay lại Trang Chủ</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------
// Initialize Pure-PHP SePay Client & Cryptographic Signature
// -------------------------------------------------------------
$client      = new SepayClient($merchant_id, $secret_key, $env);
$checkoutUrl = $client->getCheckoutUrl();

$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site');

$orderDesc = "2TOOLNE {$ord_id} (@{$current_user})";
if (!empty($order_memo)) {
    $memoParsed = json_decode($order_memo, true);
    if (is_array($memoParsed) && !empty($memoParsed['package_id'])) {
        $orderDesc = "2TOOLNE Nạp Token {$pkg_name} (@{$current_user})";
    }
}

$checkoutData = [
    'merchant'             => $merchant_id,
    'currency'             => 'VND',
    'order_amount'         => $amount,
    'operation'            => 'PURCHASE',
    'order_description'    => $orderDesc,
    'order_invoice_number' => $ord_id,
    'customer_id'          => $current_user,
    'success_url'          => $siteUrl . '/index.php?payment_status=success&order_id=' . urlencode($ord_id),
    'error_url'            => $siteUrl . '/index.php?payment_status=error&order_id=' . urlencode($ord_id),
    'cancel_url'           => $siteUrl . '/index.php?payment_status=cancel&order_id=' . urlencode($ord_id),
];

$formFields = $client->prepareFormFields($checkoutData);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2TOOLNE — Đang kết nối Cổng thanh toán trực tuyến VietQR...</title>
    <meta name="theme-color" content="#FF7A00">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: #0E0F11;
            color: #FFFFFF;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .redirect-card {
            background: #15171A;
            border: 1px solid #292C31;
            border-radius: 16px;
            padding: 36px 30px;
            width: 90%;
            max-width: 440px;
            text-align: center;
            box-shadow: 0 20px 45px rgba(0,0,0,0.6);
        }
        .spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(255, 122, 0, 0.2);
            border-top-color: #FF7A00;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        h3 { margin: 0 0 8px; font-size: 17px; color: #FFFFFF; font-weight: 700; }
        p { margin: 0 0 16px; font-size: 13px; color: #8E929B; line-height: 1.5; }
        .details {
            background: #1A1C20;
            border: 1px solid #292C31;
            border-radius: 10px;
            padding: 14px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 20px;
        }
        .row { display: flex; justify-content: space-between; margin-bottom: 8px; align-items: center; }
        .row:last-child { margin-bottom: 0; }
        .btn-submit {
            background: #FF7A00;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.15s ease;
        }
        .btn-submit:hover {
            background: #FF8F1F;
        }
    </style>
</head>
<body>
    <div class="redirect-card">
        <img src="/assets/favicon.png" alt="2TOOLNE Logo" style="width:36px;height:36px;border-radius:8px;object-fit:contain;margin-bottom:12px">
        <div class="spinner"></div>
        <h3>Đang Kết Nối Thanh Toán VietQR Tự Động</h3>
        <p>Hệ thống đang thiết lập phiên thanh toán an toàn cho đơn hàng của bạn. Bạn sẽ được chuyển tới cổng SePay trong giây lát...</p>
        
        <div class="details">
            <div class="row"><span style="color:#8E929B">Mã đơn:</span> <b style="font-family:monospace;color:#FF7A00"><?= htmlspecialchars($ord_id) ?></b></div>
            <div class="row"><span style="color:#8E929B">Gói dịch vụ:</span> <b><?= htmlspecialchars($pkg_name) ?></b></div>
            <div class="row"><span style="color:#8E929B">Số tiền:</span> <b style="color:#FF7A00;font-size:15px"><?= number_format($amount) ?> VNĐ</b></div>
        </div>

        <form id="sepay-auto-form" action="<?= htmlspecialchars($checkoutUrl) ?>" method="POST">
            <?php foreach ($formFields as $key => $val): ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars((string)$val) ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn-submit">Bấm vào đây nếu trình duyệt không tự chuyển tiếp</button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var f = document.getElementById('sepay-auto-form');
                if (f) f.submit();
            }, 300);
        });
    </script>
</body>
</html>

