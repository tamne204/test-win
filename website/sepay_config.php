<?php
// website/sepay_config.php
// Centralized SePay Payment Gateway Configuration

if (!defined('SEPAY_CONFIG_FILE')) {
    define('SEPAY_CONFIG_FILE', __DIR__ . '/storage/sepay_settings.json');
}

/**
 * Lấy cấu hình SePay hiện tại
 * @return array
 */
function sepay_get_config() {
    $defaults = [
        'merchant_id' => '',          // Mã đơn vị merchant (my.sepay.vn)
        'secret_key'  => '',          // Khóa bảo mật merchant (my.sepay.vn)
        'env'         => 'production', // 'production' hoặc 'sandbox'
        'enabled'     => true,
    ];

    if (file_exists(SEPAY_CONFIG_FILE)) {
        $content = @file_get_contents(SEPAY_CONFIG_FILE);
        if ($content) {
            $saved = json_decode($content, true);
            if (is_array($saved)) {
                $defaults = array_merge($defaults, $saved);
            }
        }
    }

    return $defaults;
}

/**
 * Cập nhật và lưu cấu hình SePay
 * @param array $data
 * @return array
 */
function sepay_save_config(array $data) {
    $current = sepay_get_config();
    $updated = array_merge($current, $data);

    $dir = dirname(SEPAY_CONFIG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    file_put_contents(
        SEPAY_CONFIG_FILE,
        json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    return $updated;
}

/**
 * Lấy URL cổng thanh toán SePay theo môi trường
 * @param string|null $env
 * @return string
 */
function sepay_get_checkout_url(?string $env = null): string {
    if (!$env) {
        $cfg = sepay_get_config();
        $env = $cfg['env'] ?? 'production';
    }
    return ($env === 'sandbox')
        ? 'https://pay-sandbox.sepay.vn/v1/checkout/init'
        : 'https://pay.sepay.vn/v1/checkout/init';
}

/**
 * Chuẩn hóa số tiền thành số nguyên (VND)
 * @param mixed $price
 * @return int
 */
function sepay_parse_amount($price): int {
    if (is_numeric($price)) {
        return (int)$price;
    }
    $clean = preg_replace('/[^\d]/', '', (string)$price);
    return (int)$clean;
}
