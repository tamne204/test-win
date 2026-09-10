<?php
/**
 * 2TOOLNE AUTOEDIT & UPSCALE — AUTO-UPDATE CONTROLLER
 * Secure update check and authorized streaming engine for desktop clients
 * Supports multi-channel updates (windows-canary, stable)
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class UpdateController {
    private const UPDATE_SECRET = '2TOOLNE_SECURE_UPDATE_SALT_2026';
    private const CANARY_CHANNEL = 'windows-canary';
    private const CANARY_VERSION = '2.0.1';

    // 2.0.1 Authoritative Package Metadata
    private const RELEASE_201_PACKAGE = '2toolne-autoedit-2.0.1-win-x64.zip';
    private const RELEASE_201_SIZE_BYTES = 949148943;
    private const RELEASE_201_SIZE_MB = 905.18;
    private const RELEASE_201_SHA256 = 'fcdcb6af72d542e6f58bd2b0ca00b82d73c86fdb8cbf4f4f04e6b01b99a8161e';
    private const RELEASE_201_NOTES = '2TOOLNE AutoEdit v2.0.1: Cập nhật tương thích chính xác CapCut v9.3.0.3970, tự động phân giải thư mục com.lveditor.draft, bảo vệ đường dẫn an toàn và tối ưu giao diện.';

    /**
     * GET /api/v1/update/check
     */
    public static function check(array $params, array $body): void {
        $appName = strtolower(trim($_GET['app'] ?? 'autoedit'));
        $clientVersion = trim($_GET['version'] ?? '2.0.0');
        $platform = strtolower(trim($_GET['platform'] ?? (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'win32')));
        $arch = strtolower(trim($_GET['arch'] ?? 'x64'));
        $channel = strtolower(trim($_GET['channel'] ?? 'stable'));

        $isMac = in_array($platform, ['darwin', 'mac', 'macos', 'osx'], true);

        // Fetch system_config settings if available
        $config = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `config_key`, `config_value` FROM `system_config`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $config[$r['config_key']] = $r['config_value'];
            }
        } catch (\Throwable $e) {
            // DB optional fallback
        }

        if ($appName === 'autoedit') {
            if ($isMac) {
                $latestVersion = $config['autoedit_app_version_mac'] ?? '2.0.0';
                $filename = "2toolne-autoedit-{$latestVersion}-mac-{$arch}.zip";
                $downloadUrl = $config['autoedit_download_url_mac'] ?? "https://www.2tamne.site/downloads/releases/{$filename}";
                $sha256 = $config['autoedit_sha256_mac'] ?? '';
                $sizeBytes = intval(($config['autoedit_file_size_mac'] ?? 145.0) * 1024 * 1024);
                $fileSizeMb = floatval($config['autoedit_file_size_mac'] ?? 145.0);
                $releaseNotes = $config['autoedit_release_notes_mac'] ?? "2TOOLNE AutoEdit macOS v{$latestVersion}";
                $isMandatory = false;
                $publishedAt = $config['autoedit_published_at_mac'] ?? date('Y-m-d H:i:s');
            } else {
                // Windows platform
                if ($channel === self::CANARY_CHANNEL) {
                    $latestVersion = self::CANARY_VERSION;
                    $filename = self::RELEASE_201_PACKAGE;
                    $sizeBytes = self::RELEASE_201_SIZE_BYTES;
                    $fileSizeMb = self::RELEASE_201_SIZE_MB;
                    $sha256 = self::RELEASE_201_SHA256;
                    $releaseNotes = self::RELEASE_201_NOTES;
                    $isMandatory = false;
                    $publishedAt = '2026-09-11 03:00:00';
                } else {
                    // Stable channel — Promoted to 2.0.1 after canary gate verification
                    $latestVersion = self::CANARY_VERSION;
                    $filename = self::RELEASE_201_PACKAGE;
                    $sizeBytes = self::RELEASE_201_SIZE_BYTES;
                    $fileSizeMb = self::RELEASE_201_SIZE_MB;
                    $sha256 = self::RELEASE_201_SHA256;
                    $releaseNotes = self::RELEASE_201_NOTES;
                    $isMandatory = false;
                    $publishedAt = '2026-09-11 03:00:00';
                }

                // Generate short-lived authenticated desktop update download token (valid 2 hours)
                $expires = time() + 7200;
                $tokenPayload = "{$channel}|{$appName}|{$platform}|{$latestVersion}|{$expires}";
                $sig = hash_hmac('sha256', $tokenPayload, self::UPDATE_SECRET);
                $token = base64_encode("{$tokenPayload}|{$sig}");
                $downloadUrl = "https://www.2tamne.site/api/v1/update/download?token=" . urlencode($token);
            }
        } else {
            // Legacy Upscale app
            $latestVersion = $config['upscale_app_version'] ?? '1.0.2';
            $releaseNotes = $config['upscale_release_notes'] ?? "Phiên bản {$latestVersion}";
            $isMandatory = filter_var($config['upscale_update_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($isMac) {
                $filename = "2toolne_Upscale_latest.dmg";
                $downloadUrl = "https://www.2tamne.site/downloads/{$filename}?v={$latestVersion}";
                $sha256 = $config['upscale_sha256_mac'] ?? '';
                $fileSizeMb = 130.0;
                $sizeBytes = intval($fileSizeMb * 1024 * 1024);
            } else {
                $filename = "2toolne_Upscale_Setup_latest.exe";
                $downloadUrl = "https://www.2tamne.site/downloads/{$filename}?v={$latestVersion}";
                $sha256 = $config['upscale_sha256_win'] ?? '';
                $fileSizeMb = 150.0;
                $sizeBytes = intval($fileSizeMb * 1024 * 1024);
            }
            $publishedAt = $config['upscale_published_at'] ?? date('Y-m-d H:i:s');
        }

        $hasUpdate = version_compare($latestVersion, $clientVersion, '>');

        Router::json([
            'success' => true,
            'has_update' => $hasUpdate,
            'current_version' => $clientVersion,
            'latest_version' => $latestVersion,
            'channel' => $channel,
            'release_notes' => $releaseNotes,
            'download_url' => $downloadUrl,
            'mandatory' => $isMandatory,
            'file_size_mb' => $fileSizeMb,
            'filename' => $filename,
            'sha256' => $sha256,
            'package' => [
                'filename' => $filename,
                'url' => $downloadUrl,
                'sha256' => $sha256,
                'size_bytes' => $sizeBytes,
            ],
            'published_at' => $publishedAt,
        ]);
    }

    /**
     * GET /api/v1/update/download
     * Authenticated, binary-clean update package streaming
     */
    public static function download(array $params, array $body): void {
        $token = trim((string)($_GET['token'] ?? ''));
        if (empty($token)) {
            Router::error('Mã token tải bản cập nhật không được để trống.', 400, 'TOKEN_REQUIRED');
            return;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            Router::error('Định dạng token không hợp lệ.', 400, 'INVALID_TOKEN_FORMAT');
            return;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 6) {
            Router::error('Dữ liệu token cập nhật bị sai cấu trúc.', 403, 'MALFORMED_TOKEN');
            return;
        }

        [$channel, $appName, $platform, $version, $expiresStr, $sig] = $parts;
        $expires = intval($expiresStr);

        // Verify HMAC signature
        $tokenPayload = "{$channel}|{$appName}|{$platform}|{$version}|{$expiresStr}";
        $expectedSig = hash_hmac('sha256', $tokenPayload, self::UPDATE_SECRET);
        if (!hash_equals($expectedSig, $sig)) {
            Router::error('Chữ ký xác thực token cập nhật không hợp lệ.', 403, 'INVALID_SIGNATURE');
            return;
        }

        // Verify Expiration
        if (time() > $expires) {
            Router::error('Token cập nhật đã hết hạn. Vui lòng kiểm tra lại cập nhật từ ứng dụng.', 403, 'TOKEN_EXPIRED');
            return;
        }

        // Determine filename
        if ($version === '2.0.1') {
            $filename = self::RELEASE_201_PACKAGE;
        } else {
            $filename = "2TOOLNE-AutoEdit-{$version}-win64-f75b1b8.zip";
        }

        // Search storage package paths
        $baseDir = dirname(__DIR__, 3);
        $candidates = [
            $baseDir . "/storage/packages/{$filename}",
            $baseDir . "/downloads/releases/{$filename}",
            $baseDir . "/downloads/{$filename}",
            "C:/2TOOLNE-Private/packages/{$filename}",
        ];

        $targetFile = null;
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_readable($cand)) {
                $targetFile = realpath($cand);
                break;
            }
        }

        if (!$targetFile) {
            Router::error("Gói cập nhật {$filename} tạm thời chưa sẵn sàng trên máy chủ.", 404, 'PACKAGE_NOT_FOUND');
            return;
        }

        // Binary Stream Isolation
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('session.use_trans_sid', '0');
        set_time_limit(900);

        while (ob_get_level()) {
            @ob_end_clean();
        }

        if (function_exists('header_remove')) {
            @header_remove('Content-Encoding');
        }

        $fileSize = filesize($targetFile);
        $start = 0;
        $end = $fileSize - 1;
        $length = $fileSize;

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = intval($matches[1]);
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }
            if ($end >= $fileSize) $end = $fileSize - 1;
            if ($start < 0) $start = 0;
            if ($start > $end) {
                http_response_code(416);
                header("Content-Range: bytes */{$fileSize}");
                exit;
            }
            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
        } else {
            http_response_code(200);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        $fp = @fopen($targetFile, 'rb');
        if (!$fp) {
            Router::error('Không thể mở gói cập nhật để truyền tải.', 500, 'FILE_READ_ERROR');
            return;
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $bytesRemaining = $length;
        $bufferSize = 1048576; // 1MB buffer

        while (!feof($fp) && $bytesRemaining > 0 && connection_status() === CONNECTION_NORMAL) {
            $readSize = min($bufferSize, $bytesRemaining);
            $chunk = fread($fp, $readSize);
            if ($chunk === false) break;
            echo $chunk;
            flush();
            $bytesRemaining -= strlen($chunk);
        }

        fclose($fp);
        exit;
    }
}
