<?php
/**
 * 2TOOLNE AUTOEDIT & UPSCALE — AUTO-UPDATE CONTROLLER
 * Secure update check and authorized streaming engine for desktop clients
 * Supports multi-channel updates (windows-canary, stable)
 * Enforces private durable storage (C:/2TOOLNE-Private/packages) outside webroot
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class UpdateController {
    private const UPDATE_SECRET = '2TOOLNE_SECURE_UPDATE_SALT_2026';
    private const CANARY_CHANNEL = 'windows-canary';
    private const STABLE_CHANNEL = 'stable';
    private const CANARY_VERSION = '2.0.1';
    private const TOKEN_TTL_SECONDS = 900; // 15 minutes (strictly 10-15m)

    // Durable private storage outside Apache webroot
    private const PRIVATE_PACKAGES_DIR = 'C:/2TOOLNE-Private/packages';

    // 2.0.1 Authoritative Package Metadata (Single Source of Truth)
    private const RELEASE_201_PACKAGE = '2toolne-autoedit-2.0.1-win-x64.zip';
    private const RELEASE_201_SIZE_BYTES = 949148943;
    private const RELEASE_201_SIZE_MB = 905.18;
    private const RELEASE_201_SHA256 = 'fcdcb6af72d542e6f58bd2b0ca00b82d73c86fdb8cbf4f4f04e6b01b99a8161e';
    private const RELEASE_201_NOTES = '2TOOLNE AutoEdit v2.0.1: Cập nhật tương thích chính xác CapCut v9.3.0.3970, tự động phân giải thư mục com.lveditor.draft, bảo vệ đường dẫn an toàn và tối ưu giao diện.';

    // Trusted Release Artifact Registry (Prevents arbitrary file resolution or cross-product access)
    private const TRUSTED_PACKAGES = [
        'autoedit' => [
            '2.0.1' => [
                'win32' => [
                    'x64' => [
                        'filename' => self::RELEASE_201_PACKAGE,
                        'size_bytes' => self::RELEASE_201_SIZE_BYTES,
                        'size_mb' => self::RELEASE_201_SIZE_MB,
                        'sha256' => self::RELEASE_201_SHA256,
                    ]
                ]
            ],
            '2.0.0' => [
                'win32' => [
                    'x64' => [
                        'filename' => '2TOOLNE-AutoEdit-Setup-2.0.0.exe',
                        'size_bytes' => 523331420,
                        'size_mb' => 499.09,
                        'sha256' => '0d205a795fcf28732c3356687e92fe031a134ffb1183cf5e60ef61f6a0d9d2a6',
                    ]
                ]
            ]
        ]
    ];

    /**
     * GET /api/v1/update/check
     * Evaluates desktop client update availability and issues short-lived update download tokens.
     */
    public static function check(array $params, array $body): void {
        // 1. Anti-Scraping / Anonymous Browser Gate
        // Reject generic browser navigation requests (Sec-Fetch-Dest: document, text/html)
        $secFetchDest = strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
        $secFetchMode = strtolower($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

        if ($secFetchDest === 'document' || $secFetchMode === 'navigate' || (strpos($accept, 'text/html') !== false && strpos($accept, 'application/json') === false)) {
            Router::error('Truy cập bị từ chối. Endpoint cập nhật chỉ dành riêng cho ứng dụng desktop 2TOOLNE.', 403, 'DESKTOP_CLIENT_REQUIRED');
            return;
        }

        // 2. Strict Desktop Parameter Gate
        $appName = strtolower(trim((string)($_GET['app'] ?? '')));
        $clientVersion = trim((string)($_GET['version'] ?? ''));
        $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
        $arch = strtolower(trim((string)($_GET['arch'] ?? 'x64')));
        $channel = strtolower(trim((string)($_GET['channel'] ?? self::STABLE_CHANNEL)));

        // Default legacy client compatibility fallback
        if (empty($appName)) $appName = 'autoedit';
        if (empty($platform)) $platform = 'win32';
        if (empty($clientVersion)) $clientVersion = '2.0.0';

        if (!in_array($appName, ['autoedit', 'upscale'], true)) {
            Router::error('Ứng dụng không xác định.', 400, 'UNKNOWN_APPLICATION');
            return;
        }

        if (!in_array($platform, ['win32', 'windows', 'darwin', 'mac', 'macos'], true)) {
            Router::error('Nền tảng hệ điều hành không hỗ trợ.', 400, 'UNSUPPORTED_PLATFORM');
            return;
        }

        $isWin = in_array($platform, ['win32', 'windows'], true);
        $normPlatform = $isWin ? 'win32' : 'darwin';

        // 3. Resolve Target Release
        $config = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `config_key`, `config_value` FROM `system_config`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $config[$r['config_key']] = $r['config_value'];
            }
        } catch (\Throwable $e) {
            // DB fallback safe
        }

        if ($appName === 'autoedit') {
            if ($isWin) {
                // Windows platform: Both windows-canary and stable serve 2.0.1
                $latestVersion = self::CANARY_VERSION;
                $filename = self::RELEASE_201_PACKAGE;
                $sizeBytes = self::RELEASE_201_SIZE_BYTES;
                $fileSizeMb = self::RELEASE_201_SIZE_MB;
                $sha256 = self::RELEASE_201_SHA256;
                $releaseNotes = self::RELEASE_201_NOTES;
                $isMandatory = false;
                $publishedAt = '2026-09-11 03:00:00';
            } else {
                // macOS platform
                $latestVersion = $config['autoedit_app_version_mac'] ?? '2.0.0';
                $filename = "2toolne-autoedit-{$latestVersion}-mac-{$arch}.zip";
                $downloadUrl = "https://www.2tamne.site/downloads/releases/{$filename}";
                $sha256 = $config['autoedit_sha256_mac'] ?? '';
                $sizeBytes = intval(($config['autoedit_file_size_mac'] ?? 145.0) * 1024 * 1024);
                $fileSizeMb = floatval($config['autoedit_file_size_mac'] ?? 145.0);
                $releaseNotes = $config['autoedit_release_notes_mac'] ?? "2TOOLNE AutoEdit macOS v{$latestVersion}";
                $isMandatory = false;
                $publishedAt = $config['autoedit_published_at_mac'] ?? date('Y-m-d H:i:s');
            }
        } else {
            // Legacy Upscale app
            $latestVersion = $config['upscale_app_version'] ?? '1.0.2';
            $releaseNotes = $config['upscale_release_notes'] ?? "Phiên bản {$latestVersion}";
            $isMandatory = filter_var($config['upscale_update_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $filename = $isWin ? "2toolne_Upscale_Setup_latest.exe" : "2toolne_Upscale_latest.dmg";
            $fileSizeMb = $isWin ? 150.0 : 130.0;
            $sizeBytes = intval($fileSizeMb * 1024 * 1024);
            $sha256 = $isWin ? ($config['upscale_sha256_win'] ?? '') : ($config['upscale_sha256_mac'] ?? '');
            $publishedAt = $config['upscale_published_at'] ?? date('Y-m-d H:i:s');
        }

        $hasUpdate = version_compare($latestVersion, $clientVersion, '>');

        // 4. Token Generation (Only generated when update is available)
        $downloadUrl = null;
        if ($hasUpdate) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            // Extract /24 subnet for IPv4 or /64 prefix for IPv6 to prevent token sharing while tolerating mobile NAT hops
            $ipPrefix = self::extractIpSubnet($clientIp);

            $expires = time() + self::TOKEN_TTL_SECONDS;
            // Token bindings: product, version, platform, arch, release_hash, ip_subnet, expires
            $tokenPayload = "{$channel}|{$appName}|{$normPlatform}|{$arch}|{$latestVersion}|{$sha256}|{$ipPrefix}|{$expires}";
            $sig = hash_hmac('sha256', $tokenPayload, self::UPDATE_SECRET);
            $token = base64_encode("{$tokenPayload}|{$sig}");
            $downloadUrl = "https://www.2tamne.site/api/v1/update/download?token=" . urlencode($token);
        }

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
     * Authenticated, binary-clean update package streaming from private storage outside webroot.
     */
    public static function download(array $params, array $body): void {
        // 1. Mandatory Token Check (Anonymous calls strictly denied)
        $token = trim((string)($_GET['token'] ?? ''));
        if (empty($token)) {
            Router::error('Yêu cầu xác thực bản quyền: Mã token tải bản cập nhật không được để trống.', 401, 'AUTH_REQUIRED');
            return;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            Router::error('Định dạng token không hợp lệ.', 400, 'INVALID_TOKEN_FORMAT');
            return;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 9) {
            Router::error('Dữ liệu token cập nhật bị sai cấu trúc hoặc không đầy đủ ràng buộc.', 403, 'MALFORMED_TOKEN');
            return;
        }

        [$channel, $appName, $platform, $arch, $version, $expectedSha256, $boundIpPrefix, $expiresStr, $sig] = $parts;
        $expires = intval($expiresStr);

        // 2. Cryptographic HMAC Verification
        $tokenPayload = "{$channel}|{$appName}|{$platform}|{$arch}|{$version}|{$expectedSha256}|{$boundIpPrefix}|{$expiresStr}";
        $calculatedSig = hash_hmac('sha256', $tokenPayload, self::UPDATE_SECRET);
        if (!hash_equals($calculatedSig, $sig)) {
            Router::error('Chữ ký xác thực token cập nhật không hợp lệ.', 403, 'INVALID_SIGNATURE');
            return;
        }

        // 3. Expiration Gate (15 minutes TTL)
        if (time() > $expires) {
            Router::error('Token cập nhật đã hết hạn (TTL 15 phút). Vui lòng kiểm tra lại cập nhật từ ứng dụng.', 403, 'TOKEN_EXPIRED');
            return;
        }

        // 4. IP Subnet Binding Verification
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $currentIpPrefix = self::extractIpSubnet($clientIp);
        if ($boundIpPrefix !== $currentIpPrefix && $boundIpPrefix !== '127.0.0.0' && $currentIpPrefix !== '127.0.0.0') {
            Router::error('Token cập nhật không khớp với thiết bị gửi yêu cầu ban đầu.', 403, 'DEVICE_NETWORK_MISMATCH');
            return;
        }

        // 5. Cross-Product Isolation Gate & Trusted Release Lookup
        // Client input NEVER controls physical filename or path
        $releaseInfo = self::TRUSTED_PACKAGES[$appName][$version][$platform][$arch] ?? null;
        if (!$releaseInfo) {
            Router::error('Gói cập nhật không tồn tại trong danh mục phát hành tin cậy.', 404, 'RELEASE_NOT_FOUND');
            return;
        }

        if ($releaseInfo['sha256'] !== strtolower($expectedSha256)) {
            Router::error('Cảnh báo bảo mật: Mã băm định danh phát hành không khớp với cấu hình hệ thống.', 403, 'RELEASE_HASH_TAMPER_DETECTED');
            return;
        }

        $safeFilename = $releaseInfo['filename'];

        // 6. Path Traversal & Boundary Safety Gate
        // Strictly resolve from C:/2TOOLNE-Private/packages (outside Apache webroot)
        $privateDir = self::PRIVATE_PACKAGES_DIR;
        $targetFile = $privateDir . '/' . $safeFilename;

        $canonicalFile = realpath($targetFile);
        $canonicalRoot = realpath($privateDir);

        if (!$canonicalFile || !$canonicalRoot) {
            Router::error("Gói cập nhật {$safeFilename} tạm thời chưa sẵn sàng trên kho lưu trữ bảo mật.", 404, 'PACKAGE_NOT_FOUND');
            return;
        }

        $normFile = str_replace('\\', '/', strtolower($canonicalFile));
        $normRoot = str_replace('\\', '/', strtolower($canonicalRoot));

        if (strpos($normFile, $normRoot . '/') !== 0) {
            Router::error('Vi phạm ranh giới lưu trữ: Đường dẫn tệp nằm ngoài thư mục bảo mật.', 403, 'FORBIDDEN_STORAGE_BOUNDARY');
            return;
        }

        // Verify physical file size matches release metadata
        $fileSize = filesize($canonicalFile);
        if ($fileSize !== $releaseInfo['size_bytes']) {
            Router::error('Kích thước tệp trên máy chủ không khớp với chứng chỉ phát hành.', 500, 'ARTIFACT_INTEGRITY_MISMATCH');
            return;
        }

        // 7. Binary Stream Isolation
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
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        $fp = @fopen($canonicalFile, 'rb');
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

    /**
     * Helper to extract /24 IPv4 or /64 IPv6 prefix
     */
    private static function extractIpSubnet(string $ip): string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        return '127.0.0.0';
    }
}
