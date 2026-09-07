<?php
// website/sepay_checkout.php
// SePay Checkout Initializer & Redirector

declare(strict_types=1);

session_start();

require_once __DIR__ . '/storage/db.php';
require_once __DIR__ . '/sepay_config.php';
require_once __DIR__ . '/lib/SepayClient.php';

$current_user = $_SESSION['user'] ?? '';
$user_info    = $current_user ? db_get_user($current_user) : null;

if (!$user_info) {
    header('Location: /index.php?login_required=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

$pkg_name  = trim($_POST['package_name'] ?? 'Gói 1 Tháng');
$pkg_price = trim($_POST['package_price'] ?? '100.000đ');
$pkg_days  = intval($_POST['duration_days'] ?? 30);
$pkg_tier  = trim($_POST['tier'] ?? 'VIP');
$pkg_prod  = trim($_POST['product'] ?? (
    stripos($pkg_name, 'Token') !== false 
        ? 'TOKEN_WALLET' 
        : (stripos($pkg_name, '2toolne') !== false ? '2TOOLNE' : (stripos($pkg_name, 'Extension') !== false ? 'LABS_EXTENSION' : 'SLIDESHOW'))
));

$amount = sepay_parse_amount($pkg_price);
if ($amount <= 0) {
    die("Số tiền thanh toán không hợp lệ: {$pkg_price}");
}

// 1. Tạo đơn hàng pending trong database
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

// 2. Lấy cấu hình SePay
$config = sepay_get_config();
$merchant_id = trim($config['merchant_id'] ?? '');
$secret_key  = trim($config['secret_key'] ?? '');
$env         = trim($config['env'] ?? 'production');

if (empty($merchant_id) || empty($secret_key)) {
    // Nếu admin chưa nhập Merchant ID / Secret Key
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>Cấu hình SePay chưa hoàn tất</title>
        <link rel="stylesheet" href="/globals.css">
        <style>
            body { background:#0f172a; color:#f8fafc; display:flex; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; margin:0; }
            .box { background:#1e293b; border:1px solid #334155; border-radius:12px; padding:28px; max-width:480px; text-align:center; }
            .btn { display:inline-block; margin-top:16px; background:#0ea5e9; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:600; }
        </style>
    </head>
    <body>
        <div class="box">
            <div style="font-size:40px;margin-bottom:12px">⚙️</div>
            <h2 style="margin-top:0">Cổng SePay Đang Được Cấu Hình</h2>
            <p style="font-size:14px;color:#94a3b8;line-height:1.6">
                Hệ thống thanh toán tự động qua SePay đang trong quá trình cập nhật thông tin Merchant. Vui lòng quay lại thanh toán qua phương thức chuyển khoản thông thường hoặc liên hệ Admin.
            </p>
            <p style="font-size:12px;color:#64748b">Mã đơn hàng đã ghi nhận: <b><?= htmlspecialchars($ord_id) ?></b></p>
            <a href="/index.php" class="btn">Quay lại Trang Chủ</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 3. Khởi tạo SePay Client & Ký dữ liệu
$client = new SepayClient($merchant_id, $secret_key, $env);
$checkoutUrl = $client->getCheckoutUrl();

$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site');

$checkoutData = [
    'merchant'             => $merchant_id,
    'currency'             => 'VND',
    'order_amount'         => $amount,
    'operation'            => 'PURCHASE',
    'order_description'    => "Don hang {$ord_id} - {$current_user}",
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
    <title>Đang kết nối Cổng thanh toán trực tuyến (VietQR)...</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: #090d16;
            color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .redirect-card {
            background: #131b2e;
            border: 1px solid #1e293b;
            border-radius: 16px;
            padding: 36px 30px;
            width: 90%;
            max-width: 440px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        }
        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(14, 165, 233, 0.2);
            border-top-color: #0ea5e9;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        h3 { margin: 0 0 8px; font-size: 18px; color: #fff; }
        p { margin: 0 0 16px; font-size: 13.5px; color: #94a3b8; line-height: 1.5; }
        .details {
            background: #0a0f1d;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 12px;
            font-size: 13px;
            text-align: left;
            margin-bottom: 20px;
        }
        .row { display: flex; justify-content: space-between; margin-bottom: 6px; }
        .row:last-child { margin-bottom: 0; }
        .btn-submit {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="redirect-card">
        <div class="spinner"></div>
        <h3>Đang Kết Nối Thanh Toán VietQR Tự Động</h3>
        <p>Hệ thống đang thiết lập cổng thanh toán VietQR an toàn cho đơn hàng của bạn. Quý khách vui lòng chờ trong giây lát...</p>
        
        <div class="details">
            <div class="row"><span style="color:#64748b">Mã đơn:</span> <b style="font-family:monospace;color:#38bdf8"><?= htmlspecialchars($ord_id) ?></b></div>
            <div class="row"><span style="color:#64748b">Gói dịch vụ:</span> <b><?= htmlspecialchars($pkg_name) ?></b></div>
            <div class="row"><span style="color:#64748b">Số tiền:</span> <b style="color:#10b981;font-size:14px"><?= number_format($amount) ?> VNĐ</b></div>
        </div>

        <form id="sepay-auto-form" action="<?= htmlspecialchars($checkoutUrl) ?>" method="POST">
            <?php foreach ($formFields as $key => $val): ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars((string)$val) ?>">
            <?php endforeach; ?>
            <noscript>
                <button type="submit" class="btn-submit">Bấm vào đây để tiếp tục thanh toán</button>
            </noscript>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                document.getElementById('sepay-auto-form').submit();
            }, 300);
        });
    </script>
</body>
</html>
