<?php
/**
 * 2TOOLNE AUTOEDIT & UPSCALE — AUTO-UPDATE CONTROLLER
 * Secure update discovery, cryptographic entitlement authorization, and binary streaming engine.
 * Supports multi-channel updates (windows-canary, stable).
 * Enforces private durable storage (C:/2TOOLNE-Private/packages) outside webroot.
 * Separates public update discovery from authoritative download token issuance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class UpdateController {
    private const UPDATE_SECRET = '2TOOLNE_SECURE_UPDATE_SALT_2026';
    private const CANARY_CHANNEL = 'windows-canary';
    private const STABLE_CHANNEL = 'stable';
    private const CANARY_VERSION = '2.0.1';
    private const TOKEN_TTL_SECONDS = 900; // Strictly 15 minutes

    // Durable private storage outside Apache webroot
    private const PRIVATE_PACKAGES_DIR = 'C:/2TOOLNE-Private/packages';

    // 2.0.1 Authoritative Package Metadata (Single Source of Truth)
    private const RELEASE_201_PACKAGE = '2toolne-autoedit-2.0.1-win-x64.zip';
    private const RELEASE_201_SIZE_BYTES = 949148943;
    private const RELEASE_201_SIZE_MB = 905.18;
    private const RELEASE_201_SHA256 = 'fcdcb6af72d542e6f58bd2b0ca00b82d73c86fdb8cbf4f4f04e6b01b99a8161e';
    private const RELEASE_201_NOTES = '2TOOLNE AutoEdit v2.0.1: Cập nhật tương thích chính xác CapCut v9.3.0.3970, tự động phân giải thư mục com.lveditor.draft, bảo vệ đường dẫn an toàn và tối ưu giao diện.';

    // Trusted Release Artifact Registry (Strict product, platform, architecture isolation)
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
     * Resolves and verifies an authoritative principal from license, device, or session credentials.
     * Returns principal array if valid active entitlement exists for requested application, null otherwise.
     */
    public static function resolveAuthorizedPrincipal(
        string $appName,
        ?string $licenseId = null,
        ?string $licenseKey = null,
        ?string $deviceId = null,
        ?string $bearerToken = null
    ): ?array {
        // Collect credentials from headers or request parameters if not passed explicitly
        if (empty($licenseId)) {
            $licenseId = trim((string)($_SERVER['HTTP_X_LICENSE_ID'] ?? $_REQUEST['license_id'] ?? ''));
        }
        if (empty($licenseKey)) {
            $licenseKey = trim((string)($_SERVER['HTTP_X_LICENSE_KEY'] ?? $_REQUEST['license_key'] ?? ''));
        }
        if (empty($deviceId)) {
            $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? $_REQUEST['device_id'] ?? ''));
        }
        if (empty($bearerToken)) {
            $authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
            if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
                $bearerToken = $m[1];
            } elseif (!empty($_REQUEST['auth_token'])) {
                $bearerToken = trim((string)$_REQUEST['auth_token']);
            }
        }

        // Fallback to parsed JSON body if parameters empty
        if (empty($licenseId) && empty($licenseKey) && empty($deviceId) && empty($bearerToken)) {
            $rawInput = @file_get_contents('php://input');
            if ($rawInput) {
                $json = json_decode($rawInput, true);
                if (is_array($json)) {
                    $licenseId = trim((string)($json['license_id'] ?? ''));
                    $licenseKey = trim((string)($json['license_key'] ?? ''));
                    $deviceId = trim((string)($json['device_id'] ?? ''));
                    $bearerToken = trim((string)($json['auth_token'] ?? ''));
                }
            }
        }

        // Anonymous callers have no credentials
        if (empty($licenseId) && empty($licenseKey) && empty($deviceId) && empty($bearerToken)) {
            return null;
        }

        try {
            $db = Database::getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        $now = time();
        $allowedProducts = ($appName === 'autoedit')
            ? ['2toolne.capcut.v2', 'CAPCUT_V2', 'ALL_ACCESS', '2TOOLNE']
            : ['UPSCALE', 'ALL_ACCESS', '2TOOLNE'];

        // 1. Authorize via License ID
        if (!empty($licenseId)) {
            $stmt = $db->prepare('SELECT * FROM `licenses` WHERE `license_id` = ? OR `id` = ? LIMIT 1');
            $stmt->execute([$licenseId, $licenseId]);
            $lic = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lic && $lic['status'] === 'active' && in_array($lic['product'], $allowedProducts, true)) {
                if (empty($lic['expires_at']) || strtotime($lic['expires_at']) > $now) {
                    $userId = $lic['owner_username'] ?: 'anon_user';
                    $fpHash = !empty($deviceId) ? hash('sha256', $deviceId) : ($lic['hwid'] ? hash('sha256', $lic['hwid']) : 'no_dev');
                    return [
                        'type' => 'license',
                        'principal_id' => "lic:{$lic['license_id']}:usr:{$userId}:dev:" . substr($fpHash, 0, 16),
                        'user_id' => $userId,
                        'license_id' => $lic['license_id'],
                        'tier' => $lic['tier'] ?? 'PRO',
                    ];
                }
            }
        }

        // 2. Authorize via License Key
        if (!empty($licenseKey)) {
            $cleanKey = strtoupper(trim($licenseKey));
            $lookupPepper = function_exists('db_get_capcut_lookup_pepper') ? db_get_capcut_lookup_pepper() : '2TOOLNE_PEPPER_2026';
            $lookupHash = hash_hmac('sha256', $cleanKey, $lookupPepper);
            $stmt = $db->prepare('SELECT * FROM `licenses` WHERE `key_lookup_hash` = ? OR `license_key` = ? LIMIT 1');
            $stmt->execute([$lookupHash, $cleanKey]);
            $lic = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lic && $lic['status'] === 'active' && in_array($lic['product'], $allowedProducts, true)) {
                if (empty($lic['expires_at']) || strtotime($lic['expires_at']) > $now) {
                    $userId = $lic['owner_username'] ?: 'anon_user';
                    $fpHash = !empty($deviceId) ? hash('sha256', $deviceId) : ($lic['hwid'] ? hash('sha256', $lic['hwid']) : 'no_dev');
                    return [
                        'type' => 'license_key',
                        'principal_id' => "lic:{$lic['license_id']}:usr:{$userId}:dev:" . substr($fpHash, 0, 16),
                        'user_id' => $userId,
                        'license_id' => $lic['license_id'],
                        'tier' => $lic['tier'] ?? 'PRO',
                    ];
                }
            }
        }

        // 3. Authorize via Registered Active Device
        if (!empty($deviceId)) {
            $fpHash = hash('sha256', $deviceId);
            $devStmt = $db->prepare('
                SELECT d.*, l.license_id, l.product, l.status as lic_status, l.expires_at, l.tier
                FROM `devices` d
                JOIN `licenses` l ON (l.owner_username = d.user_id OR l.hwid = d.device_fingerprint OR l.hwid = ?)
                WHERE (d.device_fingerprint_hash = ? OR d.device_fingerprint = ?) AND d.status = "ACTIVE"
                ORDER BY l.id DESC LIMIT 1
            ');
            $devStmt->execute([$deviceId, $fpHash, $deviceId]);
            $devRow = $devStmt->fetch(PDO::FETCH_ASSOC);
            if ($devRow && $devRow['lic_status'] === 'active' && in_array($devRow['product'], $allowedProducts, true)) {
                if (empty($devRow['expires_at']) || strtotime($devRow['expires_at']) > $now) {
                    return [
                        'type' => 'device',
                        'principal_id' => "dev:" . substr($fpHash, 0, 16) . ":usr:{$devRow['user_id']}:lic:{$devRow['license_id']}",
                        'user_id' => $devRow['user_id'],
                        'license_id' => $devRow['license_id'],
                        'tier' => $devRow['tier'] ?? 'PRO',
                    ];
                }
            }
        }

        // 4. Authorize via Bearer Session Token
        if (!empty($bearerToken)) {
            $sessStmt = $db->prepare('SELECT user_id FROM `app_auth_sessions` WHERE token = ? AND expires_at > NOW() LIMIT 1');
            $sessStmt->execute([$bearerToken]);
            $sess = $sessStmt->fetch(PDO::FETCH_ASSOC);
            if ($sess && !empty($sess['user_id'])) {
                $userId = $sess['user_id'];
                $licStmt = $db->prepare('SELECT * FROM `licenses` WHERE owner_username = ? AND status = "active" ORDER BY id DESC LIMIT 1');
                $licStmt->execute([$userId]);
                $lic = $licStmt->fetch(PDO::FETCH_ASSOC);
                if ($lic && in_array($lic['product'], $allowedProducts, true)) {
                    if (empty($lic['expires_at']) || strtotime($lic['expires_at']) > $now) {
                        return [
                            'type' => 'session',
                            'principal_id' => "usr:{$userId}:lic:{$lic['license_id']}",
                            'user_id' => $userId,
                            'license_id' => $lic['license_id'],
                            'tier' => $lic['tier'] ?? 'PRO',
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Creates a cryptographically signed, bound, short-lived update download token.
     */
    public static function createDownloadToken(
        string $channel,
        string $appName,
        string $platform,
        string $arch,
        string $version,
        string $sha256,
        string $principalId
    ): array {
        $issuedAt = time();
        $expires = $issuedAt + self::TOKEN_TTL_SECONDS;
        $nonce = bin2hex(random_bytes(8));

        // Token elements: channel|appName|platform|arch|version|sha256|principalId|issuedAt|expires|nonce
        $tokenPayload = "{$channel}|{$appName}|{$platform}|{$arch}|{$version}|{$sha256}|{$principalId}|{$issuedAt}|{$expires}|{$nonce}";
        $sig = hash_hmac('sha256', $tokenPayload, self::UPDATE_SECRET);
        $token = base64_encode("{$tokenPayload}|{$sig}");
        $downloadUrl = "https://www.2tamne.site/api/v1/update/download?token=" . urlencode($token);

        return [
            'token' => $token,
            'download_url' => $downloadUrl,
            'issued_at' => $issuedAt,
            'expires' => $expires,
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'nonce' => $nonce,
            'principal' => $principalId,
        ];
    }

    /**
     * GET /api/v1/update/check
     * Public Update Discovery endpoint.
     * Returns non-sensitive release metadata to any caller.
     * ONLY issues a usable download URL if an authoritative active entitlement credential is provided.
     * An attacker spoofing desktop headers without real credentials will NEVER receive a download token.
     */
    public static function check(array $params, array $body): void {
        $appName = strtolower(trim((string)($_GET['app'] ?? 'autoedit')));
        $clientVersion = trim((string)($_GET['version'] ?? '2.0.0'));
        $platform = strtolower(trim((string)($_GET['platform'] ?? 'win32')));
        $arch = strtolower(trim((string)($_GET['arch'] ?? 'x64')));
        $channel = strtolower(trim((string)($_GET['channel'] ?? self::STABLE_CHANNEL)));

        if (!in_array($appName, ['autoedit', 'upscale'], true)) {
            Router::error('Ứng dụng không xác định.', 400, 'UNKNOWN_APPLICATION');
            return;
        }

        $isWin = in_array($platform, ['win32', 'windows'], true);
        $normPlatform = $isWin ? 'win32' : 'darwin';

        // Resolve Target Release Metadata
        if ($appName === 'autoedit') {
            if ($isWin) {
                $latestVersion = self::CANARY_VERSION;
                $filename = self::RELEASE_201_PACKAGE;
                $sizeBytes = self::RELEASE_201_SIZE_BYTES;
                $fileSizeMb = self::RELEASE_201_SIZE_MB;
                $sha256 = self::RELEASE_201_SHA256;
                $releaseNotes = self::RELEASE_201_NOTES;
                $isMandatory = false;
                $publishedAt = '2026-09-11 03:00:00';
            } else {
                $latestVersion = '2.0.0';
                $filename = "2toolne-autoedit-{$latestVersion}-mac-{$arch}.zip";
                $sha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
                $sizeBytes = 152043520;
                $fileSizeMb = 145.0;
                $releaseNotes = "2TOOLNE AutoEdit macOS v{$latestVersion}";
                $isMandatory = false;
                $publishedAt = '2026-09-08 00:00:00';
            }
        } else {
            $latestVersion = '1.0.2';
            $releaseNotes = "2TOOLNE AI Upscale v{$latestVersion}";
            $isMandatory = false;
            $filename = $isWin ? "2toolne_Upscale_Setup_latest.exe" : "2toolne_Upscale_latest.dmg";
            $fileSizeMb = $isWin ? 150.0 : 130.0;
            $sizeBytes = intval($fileSizeMb * 1024 * 1024);
            $sha256 = '';
            $publishedAt = '2026-09-05 00:00:00';
        }

        $hasUpdate = version_compare($latestVersion, $clientVersion, '>');

        // Separate Discovery from Authorization:
        // Evaluate caller's authoritative principal
        $principal = self::resolveAuthorizedPrincipal(
            $appName,
            $body['license_id'] ?? null,
            $body['license_key'] ?? null,
            $body['device_id'] ?? null,
            $body['auth_token'] ?? null
        );
        $authorized = ($principal !== null);
        $downloadUrl = null;
        $tokenInfo = null;

        if ($hasUpdate && $authorized) {
            // Authorized client: issue cryptographically signed, bound download token
            $tokenInfo = self::createDownloadToken(
                $channel,
                $appName,
                $normPlatform,
                $arch,
                $latestVersion,
                $sha256,
                $principal['principal_id']
            );
            $downloadUrl = $tokenInfo['download_url'];
        }

        Router::json([
            'success' => true,
            'has_update' => $hasUpdate,
            'current_version' => $clientVersion,
            'latest_version' => $latestVersion,
            'channel' => $channel,
            'release_notes' => $releaseNotes,
            'mandatory' => $isMandatory,
            'file_size_mb' => $fileSizeMb,
            'filename' => $filename,
            'sha256' => $sha256,
            'download_url' => $downloadUrl,
            'auth_required' => !$authorized,
            'authorized' => $authorized,
            'message' => $authorized
                ? 'Đã xác thực bản quyền thành công. Đường dẫn tải bản cập nhật an toàn đã được kích hoạt.'
                : 'Bản cập nhật v' . $latestVersion . ' đã sẵn sàng. Yêu cầu xác thực bản quyền hợp lệ để tạo liên kết tải về an toàn.',
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
     * POST /api/v1/update/authorize
     * Explicit Update Download Authorization endpoint.
     * Requires active license / device / session entitlement.
     * Issues 15-minute cryptographically signed download token.
     */
    public static function authorize(array $params, array $body): void {
        $appName = strtolower(trim((string)($body['app'] ?? $_GET['app'] ?? 'autoedit')));
        $version = trim((string)($body['version'] ?? $_GET['version'] ?? self::CANARY_VERSION));
        $platform = strtolower(trim((string)($body['platform'] ?? $_GET['platform'] ?? 'win32')));
        $arch = strtolower(trim((string)($body['arch'] ?? $_GET['arch'] ?? 'x64')));
        $channel = strtolower(trim((string)($body['channel'] ?? $_GET['channel'] ?? self::STABLE_CHANNEL)));

        $isWin = in_array($platform, ['win32', 'windows'], true);
        $normPlatform = $isWin ? 'win32' : 'darwin';

        // 1. Authorize Principal
        $principal = self::resolveAuthorizedPrincipal(
            $appName,
            $body['license_id'] ?? null,
            $body['license_key'] ?? null,
            $body['device_id'] ?? null,
            $body['auth_token'] ?? null
        );
        if (!$principal) {
            Router::error(
                'Yêu cầu bản quyền hợp lệ: Không tìm thấy bản quyền hoạt động hoặc thiết bị chưa được kích hoạt cho ứng dụng này.',
                401,
                'AUTH_REQUIRED'
            );
            return;
        }

        // 2. Validate Target Release in Trusted Catalog
        $releaseInfo = self::TRUSTED_PACKAGES[$appName][$version][$normPlatform][$arch] ?? null;
        if (!$releaseInfo) {
            Router::error('Bản cập nhật yêu cầu không tồn tại trong danh mục phát hành tin cậy.', 404, 'RELEASE_NOT_FOUND');
            return;
        }

        // 3. Issue Token
        $tokenInfo = self::createDownloadToken(
            $channel,
            $appName,
            $normPlatform,
            $arch,
            $version,
            $releaseInfo['sha256'],
            $principal['principal_id']
        );

        Router::json([
            'success' => true,
            'authorized' => true,
            'app' => $appName,
            'version' => $version,
            'platform' => $normPlatform,
            'arch' => $arch,
            'download_url' => $tokenInfo['download_url'],
            'token' => $tokenInfo['token'],
            'expires_in' => $tokenInfo['expires_in'],
            'principal' => $principal['principal_id'],
        ]);
    }

    /**
     * GET /api/v1/update/download
     * Authenticated, binary-clean update package streaming from private storage outside webroot.
     * Enforces HMAC validation, 15m expiration, trusted catalog lookup, realpath boundary isolation.
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
        if (count($parts) !== 11) {
            Router::error('Dữ liệu token cập nhật bị sai cấu trúc hoặc không đầy đủ ràng buộc.', 403, 'MALFORMED_TOKEN');
            return;
        }

        [$channel, $appName, $platform, $arch, $version, $expectedSha256, $principalId, $issuedAtStr, $expiresStr, $nonce, $sig] = $parts;
        $expires = intval($expiresStr);

        // 2. Cryptographic HMAC Verification
        $tokenPayload = "{$channel}|{$appName}|{$platform}|{$arch}|{$version}|{$expectedSha256}|{$principalId}|{$issuedAtStr}|{$expiresStr}|{$nonce}";
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

        // 4. Principal Presence Check
        if (empty($principalId)) {
            Router::error('Token cập nhật thiếu thông tin định danh chủ thể được cấp quyền.', 403, 'INVALID_PRINCIPAL');
            return;
        }

        // 5. Cross-Product & Cross-Platform Isolation Gate & Trusted Release Lookup
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
}
