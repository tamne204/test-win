<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/storage/db.php';

define('PAYLOAD_SECRET_SALT', '2TAMNE_SECURE_SALT_v1_9988776655');

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$key  = trim($input['license_key'] ?? '');
$hwid = trim($input['hwid'] ?? '');
$ts   = intval($input['timestamp'] ?? 0);

if (empty($key) || empty($hwid)) {
    json_out(['ok' => false, 'error_code' => 'MISSING_PARAMS', 'message' => 'Thiếu thông tin xác thực bản quyền.'], 400);
}

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM `licenses` WHERE `license_key` = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $lic = $stmt->fetch();

    // 1. Check Key existence
    if (!$lic) {
        json_out(['ok' => false, 'error_code' => 'KEY_NOT_FOUND', 'message' => 'Mã bản quyền đã bị xóa hoặc không tồn tại trên hệ thống!'], 404);
    }

    // 2. Check Ban Status
    if (($lic['status'] ?? '') === 'banned') {
        json_out(['ok' => false, 'error_code' => 'KEY_BANNED', 'message' => 'Mã bản quyền này đã bị khóa do vi phạm điều khoản!'], 403);
    }

    // 3. Check Activation & HWID
    if (empty($lic['hwid'])) {
        json_out(['ok' => false, 'error_code' => 'KEY_NOT_ACTIVATED', 'message' => 'Key này chưa được kích hoạt trên thiết bị.'], 400);
    }

    if ($lic['hwid'] !== $hwid) {
        json_out(['ok' => false, 'error_code' => 'HWID_MISMATCH', 'message' => 'Mã phần cứng thiết bị không khớp với bản quyền đã đăng ký!'], 403);
    }

    // 4. Check Expiry
    $now = time();
    $duration_days = intval($lic['duration_days'] ?? 30);
    $is_lifetime = ($duration_days <= 0 || $duration_days >= 9999);

    if (!$is_lifetime) {
        $expire_ts = strtotime($lic['expires_at']);
        if ($now > $expire_ts) {
            json_out(['ok' => false, 'error_code' => 'KEY_EXPIRED', 'message' => 'Mã bản quyền của bạn đã hết hạn sử dụng!'], 403);
        }
        $days_left = max(0, ceil(($expire_ts - $now) / 86400));
    } else {
        $days_left = 9999;
    }

    // 5. CORE EXECUTION PAYLOAD (Dynamic Client-Side Engine)
    // This engine contains the exact 2K selector, Bottom-Up 001 indexing, and batch downloader routines.
    $core_js_engine = <<<'JSEngine'
(function(context) {
    return {
        version: "1.0.0-PRO",
        execute: async function(options, callbacks) {
            const { log, updateProgress, isCancelled } = callbacks;
            log("🚀 Khởi động Core Engine 2tamne (Bảo mật Cloud)...");

            // Helper sleep
            const sleep = ms => new Promise(r => setTimeout(r, ms));

            // Bottom-up collection algorithm
            function getDownloadButtons() {
                const buttons = Array.from(document.querySelectorAll('button'));
                return buttons.filter(btn => {
                    const aria = btn.getAttribute('aria-label') || '';
                    const text = btn.innerText || '';
                    const isDownload = aria.toLowerCase().includes('download') || 
                                     aria.toLowerCase().includes('tải') ||
                                     text.toLowerCase().includes('download') ||
                                     text.toLowerCase().includes('tải') ||
                                     btn.querySelector('svg[data-icon="download"]') ||
                                     btn.querySelector('i.material-icons:contains("download")');
                    return isDownload && btn.offsetParent !== null; // visible
                });
            }

            // Spatial Sort: Bottom-Up (dưới lên trên), Right-to-Left (phải qua trái)
            function sortBottomUp(elements) {
                return elements.sort((a, b) => {
                    const rectA = a.getBoundingClientRect();
                    const rectB = b.getBoundingClientRect();
                    // Y: descending (bottom first)
                    if (Math.abs(rectA.bottom - rectB.bottom) > 30) {
                        return rectB.bottom - rectA.bottom;
                    }
                    // X: descending (right first)
                    return rectB.right - rectA.right;
                });
            }

            // Resolution Selector: 2K / 4K Clicker
            async function selectResolution2K(downloadBtn) {
                // Hover or Click on download button to open menu
                downloadBtn.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
                downloadBtn.click();
                await sleep(400);

                // Find menu items
                const menuItems = Array.from(document.querySelectorAll('[role="menuitem"], [role="option"], .mat-menu-item, div[class*="menu"] button'));
                let targetOption = null;

                // Priority: 2K -> 4K -> High Res -> Original
                for (const item of menuItems) {
                    const text = (item.textContent || '').toUpperCase();
                    const isLocked = item.querySelector('[data-icon="lock"]') || 
                                     text.includes('UPGRADE') || 
                                     text.includes('NÂNG CẤP') || 
                                     item.classList.contains('disabled');
                    if (isLocked) continue;

                    if (text.includes('2K') || text.includes('2048') || text.includes('1440')) {
                        targetOption = item;
                        break;
                    }
                }

                if (!targetOption) {
                    // Fallback to highest available unlocked option
                    for (const item of menuItems) {
                        const text = (item.textContent || '').toUpperCase();
                        const isLocked = item.querySelector('[data-icon="lock"]') || text.includes('UPGRADE') || text.includes('NÂNG CẤP');
                        if (!isLocked && (text.includes('4K') || text.includes('ORIGINAL') || text.includes('GỐC') || text.includes('HD'))) {
                            targetOption = item;
                            break;
                        }
                    }
                }

                if (targetOption) {
                    targetOption.click();
                    return true;
                }
                return false;
            }

            const rawButtons = getDownloadButtons();
            if (rawButtons.length === 0) {
                log("⚠️ Không tìm thấy nút tải nào trên trang. Vui lòng mở đúng trang Labs Flow!");
                return { count: 0, ok: false };
            }

            const sortedButtons = sortBottomUp(rawButtons);
            const total = sortedButtons.length;
            log(`📊 Đã tìm thấy ${total} ảnh. Bắt đầu tải theo thứ tự 001→${String(total).padStart(3, '0')}...`);

            let processed = 0;
            for (let i = 0; i < total; i++) {
                if (isCancelled && isCancelled()) {
                    log("🛑 Đã dừng tiến trình tải theo yêu cầu.");
                    break;
                }

                const btn = sortedButtons[i];
                const indexNumber = String(i + 1).padStart(3, '0');
                log(`[${indexNumber}/${String(total).padStart(3, '0')}] Đang xử lý tải ảnh chuẩn 2K...`);

                try {
                    btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    await sleep(300);
                    await selectResolution2K(btn);
                    processed++;
                    if (updateProgress) updateProgress(processed, total);
                    await sleep(options.delay || 1200);
                } catch (err) {
                    log(`⚠️ Lỗi tại ảnh #${indexNumber}: ${err.message}`);
                }
            }

            log(`🎉 Hoàn tất tải ${processed}/${total} ảnh thành công!`);
            return { count: processed, total, ok: true };
        }
    };
})(window);
JSEngine;

    // 6. Sign and Obfuscate the payload
    $payload_base64 = base64_encode($core_js_engine);
    $signature = hash_hmac('sha256', $payload_base64 . $key . $hwid . $now, PAYLOAD_SECRET_SALT);

    json_out([
        'ok' => true,
        'status' => 'authorized',
        'tier' => $lic['tier'] ?? 'VIP',
        'product' => $lic['product'] ?? 'LABS_EXTENSION',
        'expires_at' => $lic['expires_at'],
        'days_left' => $days_left,
        'timestamp' => $now,
        'signature' => $signature,
        'payload' => $payload_base64
    ], 200);

} catch (Exception $e) {
    json_out(['ok' => false, 'error_code' => 'SERVER_ERROR', 'message' => 'Lỗi kết nối máy chủ.'], 500);
}
?>