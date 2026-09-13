<?php
/**
 * 2TOOLNE SOFTWARE DOWNLOAD GATE — ENTITLEMENT & TOKEN SERVICE
 * Authoritative backend service that evaluates user eligibility to download software packages,
 * enforces license/trial checks, and manages short-lived signed tokens.
 *
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../../../storage/db.php';

class DownloadEntitlementService {

    private const TOKEN_EXPIRY_SECONDS = 600; // 10 minutes

    // Centralized Single Source of Truth for Private Storage Paths
    public const PRIVATE_DATA_ROOT          = 'C:/2TOOLNE-Private';
    public const PRIVATE_PACKAGE_DIR        = 'C:/2TOOLNE-Private/packages';
    public const PRIVATE_CUSTOMER_TEST_DIR  = 'C:/2TOOLNE-Private/customer_test';
    public const PRIVATE_TOKEN_DIR          = 'C:/2TOOLNE-Private/runtime/download_tokens';
    public const PRIVATE_LOG_DIR            = 'C:/2TOOLNE-Private/logs';

    /**
     * Directory where temporary download tokens are stored outside web document root
     */
    public static function getTokenStorageDir(): string {
        $privateDir = self::PRIVATE_TOKEN_DIR;
        if (!is_dir($privateDir)) {
            @mkdir($privateDir, 0777, true);
        }
        if (is_dir($privateDir)) {
            return $privateDir;
        }
        $dir = dirname(__DIR__, 3) . '/storage/download_tokens';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }
        }
        return $dir;
    }

    /**
     * Directory where binary software packages are stored outside web document root
     */
    public static function getPrivatePackageDir(bool $isCustomerTest = false): string {
        $dir = $isCustomerTest ? self::PRIVATE_CUSTOMER_TEST_DIR : self::PRIVATE_PACKAGE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir)) {
            return $dir;
        }
        return dirname(__DIR__, 3) . '/storage/packages';
    }

    /**
     * Evaluates if a given user is entitled to download the specified product package.
     *
     * @param array|null $user Authenticated user payload from CloudAuthHelper
     * @param string $product Product name ('AUTOEDIT', 'UPSCALE', 'SLIDESHOW', 'EXTENSION')
     * @param string $platform Target OS platform ('windows-x64', 'macos', etc.)
     * @param string $packageType 'installer' or 'customer_test'
     * @return array [allowed: bool, status: int, error?: string, message: string, reason?: string, ...]
     */
    public static function canUserDownload(?array $user, string $product = 'AUTOEDIT', string $platform = 'windows-x64', string $packageType = 'installer'): array {
        // Section 5: Strict validation against path traversal and injection attacks
        $checkPayload = $product . ' ' . $platform . ' ' . $packageType;
        if (preg_match('/(\.\.|\/|\\\\|\0|%00|%2e|%2f|%5c|\x00)/i', $checkPayload)
            || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\..*)?$/i', trim($product))
            || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\..*)?$/i', trim($platform))
            || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\..*)?$/i', trim($packageType))) {
            return [
                'allowed' => false,
                'status'  => 400,
                'error'   => 'INVALID_TRAVERSAL_PARAMETER',
                'message' => 'Tham số yêu cầu không hợp lệ hoặc chứa ký tự độc hại bị cấm.',
            ];
        }

        // 1. Unauthenticated users are completely denied
        if (!$user || empty($user['username'])) {
            return [
                'allowed' => false,
                'status'  => 401,
                'error'   => 'AUTH_REQUIRED',
                'message' => 'Vui lòng đăng nhập hoặc đăng ký tài khoản để tải phần mềm.',
            ];
        }

        $isAdmin = !empty($user['is_admin']) || in_array($user['role'] ?? '', ['admin', 'super_admin'], true);

        // 2. Customer test packages are restricted strictly to admins / internal testers
        if ($packageType === 'customer_test') {
            if (!$isAdmin) {
                return [
                    'allowed' => false,
                    'status'  => 403,
                    'error'   => 'FORBIDDEN_INTERNAL_ONLY',
                    'message' => 'Gói thử nghiệm khách hàng (Customer Test) chỉ dành riêng cho Quản trị viên và Tester được ủy quyền.',
                ];
            }
            return [
                'allowed'    => true,
                'status'     => 200,
                'reason'     => 'ADMIN_INTERNAL_GRANT',
                'grant_type' => 'ADMIN',
                'message'    => 'Ủy quyền quản trị viên: Cho phép tải gói Customer Test.',
            ];
        }

        // 3. Admin grant: Admins have unrestricted download access
        if ($isAdmin) {
            return [
                'allowed'    => true,
                'status'     => 200,
                'reason'     => 'ADMIN_GRANT',
                'grant_type' => 'ADMIN',
                'message'    => 'Ủy quyền quản trị viên: Cho phép tải phần mềm.',
            ];
        }

        // 4. Regular registered user: Evaluate entitlement against database licenses
        $normProd = strtoupper(trim($product));
        $username = strtolower(trim((string)$user['username']));
        $email    = strtolower(trim((string)($user['email'] ?? '')));

        // Free extension: registered users are entitled to the Labs Extension
        if (in_array($normProd, ['EXTENSION', 'LABS_EXTENSION', '2TAMNE_LABS_EXTENSION'])) {
            return [
                'allowed'    => true,
                'status'     => 200,
                'reason'     => 'REGISTERED_USER_FREE_TIER',
                'grant_type' => 'FREE',
                'message'    => 'Tiện ích mở rộng Google Labs được cấp phép miễn phí cho tài khoản đã đăng ký.',
            ];
        }

        $db = Database::getConnection();

        // Query all licenses belonging to this user
        $stmt = $db->prepare('
            SELECT * FROM licenses 
            WHERE LOWER(owner_username) = ? OR LOWER(owner_username) = ?
            ORDER BY id DESC
        ');
        $stmt->execute([$username, $email]);
        $userLicenses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Check product specific logic
        if (in_array($normProd, ['AUTOEDIT', '2TOOLNE.CAPCUT.V2', 'CAPCUT_V2', 'CAPCUT', '2TOOLNE_AUTOEDIT', 'CAPCUT_AUTOEDIT'])) {
            return self::evaluateAutoEditEntitlement($user, $userLicenses);
        }

        if (in_array($normProd, ['UPSCALE', '2TOOLNE.UPSCALE', '2TOOLNE_UPSCALE'])) {
            return self::evaluateUpscaleEntitlement($user, $userLicenses);
        }

        if (in_array($normProd, ['SLIDESHOW', 'SLIDESHOW_AI', 'SLIDESHOWBUILDER'])) {
            return self::evaluateSlideshowEntitlement($user, $userLicenses);
        }

        // Unknown product
        return [
            'allowed' => false,
            'status'  => 400,
            'error'   => 'INVALID_PRODUCT',
            'message' => 'Sản phẩm phần mềm yêu cầu không hợp lệ.',
        ];
    }

    /**
     * Evaluates license/trial for 2TOOLNE AutoEdit
     */
    private static function evaluateAutoEditEntitlement(array $user, array $licenses): array {
        $now = time();
        $hasActivePaid = false;
        $hasActiveTrial = false;
        $hasExpiredTrial = false;
        $hasExpiredPaid = false;
        $hasRevoked = false;
        $activeLic = null;

        foreach ($licenses as $lic) {
            $p = strtoupper(trim((string)($lic['product'] ?? '')));
            // Match CapCut / AutoEdit products
            $isMatch = in_array($p, ['2TOOLNE.CAPCUT.V2', 'CAPCUT_V2', 'AUTOEDIT', '2TOOLNE_AUTOEDIT', 'CAPCUT'])
                    || stripos($p, 'capcut') !== false
                    || stripos($p, 'autoedit') !== false;

            if (!$isMatch) continue;

            $status = strtolower(trim((string)($lic['status'] ?? 'active')));
            $tier   = strtoupper(trim((string)($lic['tier'] ?? 'VIP')));
            $isTrial = ($tier === 'TRIAL' || intval($lic['duration_days'] ?? 0) <= 3);

            if ($status === 'banned' || $status === 'revoked') {
                $hasRevoked = true;
                continue;
            }

            if ($status === 'active') {
                $expiresAt = $lic['expires_at'] ? strtotime($lic['expires_at']) : 0;
                $isUnexpired = empty($lic['expires_at']) || ($expiresAt > $now);

                if ($isUnexpired) {
                    if ($isTrial) {
                        $hasActiveTrial = true;
                        if (!$activeLic) $activeLic = $lic;
                    } else {
                        $hasActivePaid = true;
                        $activeLic = $lic;
                        break; // Paid license takes highest priority
                    }
                } else {
                    if ($isTrial) {
                        $hasExpiredTrial = true;
                    } else {
                        $hasExpiredPaid = true;
                    }
                }
            }
        }

        if ($hasActivePaid && $activeLic) {
            return [
                'allowed'     => true,
                'status'      => 200,
                'reason'      => 'ACTIVE_PAID_LICENSE',
                'grant_type'  => 'PAID',
                'license_key' => $activeLic['license_key'],
                'tier'        => $activeLic['tier'] ?? 'VIP',
                'expires_at'  => $activeLic['expires_at'],
                'message'     => 'Bản quyền AutoEdit chính thức đang hoạt động.',
            ];
        }

        if ($hasActiveTrial && $activeLic) {
            return [
                'allowed'     => true,
                'status'      => 200,
                'reason'      => 'ACTIVE_TRIAL',
                'grant_type'  => 'TRIAL',
                'license_key' => $activeLic['license_key'],
                'tier'        => 'TRIAL',
                'expires_at'  => $activeLic['expires_at'],
                'message'     => 'Bản dùng thử AutoEdit đang hoạt động.',
            ];
        }

        if ($hasRevoked) {
            return [
                'allowed' => false,
                'status'  => 403,
                'error'   => 'REVOKED_LICENSE',
                'message' => 'Bản quyền AutoEdit của tài khoản đã bị tạm khóa hoặc thu hồi.',
            ];
        }

        if ($hasExpiredTrial) {
            return [
                'allowed' => false,
                'status'  => 403,
                'error'   => 'EXPIRED_TRIAL',
                'message' => 'Bản dùng thử 3 ngày của bạn đã hết hạn. Vui lòng nâng cấp gói bản quyền để tiếp tục tải và sử dụng.',
                'pricing_url' => '/#pricing',
            ];
        }

        if ($hasExpiredPaid) {
            return [
                'allowed' => false,
                'status'  => 403,
                'error'   => 'EXPIRED_LICENSE',
                'message' => 'Gói bản quyền AutoEdit của bạn đã hết hạn. Vui lòng gia hạn để tiếp tục tải bản cập nhật.',
                'pricing_url' => '/#pricing',
            ];
        }

        // No license found at all
        return [
            'allowed'         => false,
            'status'          => 403,
            'error'           => 'ENTITLEMENT_REQUIRED',
            'message'         => 'Bạn cần kích hoạt bản dùng thử 3 ngày hoặc sở hữu bản quyền AutoEdit để tải phần mềm.',
            'can_claim_trial' => true,
            'pricing_url'     => '/#pricing',
        ];
    }

    /**
     * Evaluates Upscale entitlement (requires active Upscale license)
     */
    private static function evaluateUpscaleEntitlement(array $user, array $licenses): array {
        // Check if user has an active Upscale license
        $now = time();
        foreach ($licenses as $lic) {
            $p = strtoupper(trim((string)($lic['product'] ?? '')));
            if (in_array($p, ['UPSCALE', '2TOOLNE.UPSCALE', '2TOOLNE_UPSCALE'])) {
                if (($lic['status'] ?? '') === 'active' && (empty($lic['expires_at']) || strtotime($lic['expires_at']) > $now)) {
                    return [
                        'allowed'    => true,
                        'status'     => 200,
                        'reason'     => 'ACTIVE_UPSCALE_LICENSE',
                        'grant_type' => 'PAID',
                        'message'    => 'Cho phép tải: Bản quyền Upscale hợp lệ.',
                    ];
                }
            }
        }

        return [
            'allowed'     => false,
            'status'      => 403,
            'error'       => 'ENTITLEMENT_REQUIRED',
            'message'     => 'Bạn cần sở hữu bản quyền 2TOOLNE Upscale để tải ứng dụng.',
            'pricing_url' => '/#pricing',
        ];
    }

    /**
     * Evaluates SlideshowBuilder entitlement
     */
    private static function evaluateSlideshowEntitlement(array $user, array $licenses): array {
        $now = time();
        foreach ($licenses as $lic) {
            $p = strtoupper(trim((string)($lic['product'] ?? '')));
            if (in_array($p, ['SLIDESHOW', 'SLIDESHOW_AI', 'SLIDESHOWBUILDER'])) {
                if (($lic['status'] ?? '') === 'active' && (empty($lic['expires_at']) || strtotime($lic['expires_at']) > $now)) {
                    return [
                        'allowed'    => true,
                        'status'     => 200,
                        'reason'     => 'ACTIVE_SLIDESHOW_LICENSE',
                        'grant_type' => $lic['tier'] === 'TRIAL' ? 'TRIAL' : 'PAID',
                        'message'    => 'Cho phép tải: Bản quyền Slideshow AI hợp lệ.',
                    ];
                }
            }
        }

        return [
            'allowed'     => false,
            'status'      => 403,
            'error'       => 'ENTITLEMENT_REQUIRED',
            'message'     => 'Bạn cần kích hoạt bản dùng thử hoặc mua bản quyền Slideshow AI để tải phần mềm.',
            'pricing_url' => '/#pricing',
        ];
    }

    /**
     * Resolves the authoritative local package file path and metadata
     */
    public static function resolvePackageInfo(string $product, string $platform = 'windows-x64', string $packageType = 'installer'): ?array {
        $normProd = strtoupper(trim($product));
        $normPlat = strtolower(trim($platform));
        $isMac = in_array($normPlat, ['mac', 'macos', 'darwin', 'osx'], true);

        $filename = '';
        $sha256 = '';
        $sizeBytes = 0;

        if (in_array($normProd, ['AUTOEDIT', '2TOOLNE.CAPCUT.V2', 'CAPCUT_V2', 'CAPCUT', '2TOOLNE_AUTOEDIT'])) {
            if ($packageType === 'customer_test') {
                $filename  = '2TOOLNE_Windows_Customer_Test_2.0.0-hardened.zip';
                $sha256    = '812076c622b6a1eeebcac44a74d76ffedcd3bfa711d394a7a571ddfce86c19ac';
                $sizeBytes = 971974552;
            } elseif ($packageType === 'portable' || $packageType === 'zip' || $packageType === 'direct' || $packageType === 'v211' || $packageType === 'v210' || $packageType === 'v206' || $packageType === 'v205' || $packageType === 'v204' || $packageType === 'v201') {
                $filename  = '2toolne-autoedit-2.1.1-win-x64.zip';
                $sha256    = '8a9b4bb917a2859fbda77df898cec94f306a152942283da57b46db151ca03a9a';
                $sizeBytes = 290079506;
            } elseif ($isMac) {
                $filename  = '2TOOLNE-AutoEdit-2.1.1.dmg';
                $sha256    = '4205a7f7542eaa32b0d8d63f2594ed1d6febea9278749d7e1b664cc30f10281f';
                $sizeBytes = 279390331;
            } else {
                $filename  = '2TOOLNE-AutoEdit-Setup-2.1.1.exe';
                $sha256    = '81745a1b52668da41aa136ac7a14f1e0bfd285ca1a97c46874e89c3e1fe632b0';
                $sizeBytes = 220366254;
            }
        } elseif (in_array($normProd, ['UPSCALE', '2TOOLNE.UPSCALE', '2TOOLNE_UPSCALE'])) {
            if ($isMac) {
                $filename = '2toolne_Upscale_latest.dmg';
            } else {
                $filename = '2toolne_Upscale_Setup_latest.exe';
            }
        } elseif (in_array($normProd, ['SLIDESHOW', 'SLIDESHOW_AI', 'SLIDESHOWBUILDER'])) {
            if ($isMac) {
                $filename = 'SlideshowBuilder_macOS_v2.3.9.zip';
            } else {
                $filename = 'SlideshowBuilder_Windows_v2.3.9.zip';
            }
        } elseif (in_array($normProd, ['EXTENSION', 'LABS_EXTENSION', '2TAMNE_LABS_EXTENSION'])) {
            $filename = '2tamne_Labs_Extension_latest.zip';
        }

        if (empty($filename)) {
            return null;
        }

        $filePath = self::locatePhysicalFile($filename);

        return [
            'product'      => $normProd,
            'platform'     => $isMac ? 'macos' : 'windows-x64',
            'package_type' => $packageType,
            'filename'     => $filename,
            'file_path'    => $filePath,
            'exists'       => ($filePath !== null && file_exists($filePath)),
            'sha256'       => $sha256,
            'filesize'     => ($filePath && file_exists($filePath)) ? filesize($filePath) : $sizeBytes,
        ];
    }

    /**
     * Searches protected storage locations outside web document root for a binary artifact
     */
    public static function locatePhysicalFile(string $filename, bool $isCustomerTest = false): ?string {
        $root = dirname(__DIR__, 3);
        $candidates = [];

        if ($isCustomerTest || stripos($filename, 'customer') !== false) {
            $candidates[] = self::PRIVATE_CUSTOMER_TEST_DIR . '/' . $filename;
        }
        $candidates[] = self::PRIVATE_PACKAGE_DIR . '/' . $filename;

        // Local development & fallback paths
        $candidates[] = $root . '/storage/packages/' . $filename;
        $candidates[] = $root . '/storage/downloads/' . $filename;
        $candidates[] = $root . '/downloads/' . $filename;
        $candidates[] = 'C:/wwwroot/2tamne.site/storage/packages/' . $filename;
        $candidates[] = 'C:/wwwroot/2tamne.site/downloads/' . $filename;

        // Also check alias filenames
        $aliases = [];
        if ($filename === '2TOOLNE-AutoEdit-Setup-2.1.1.exe' || $filename === '2TOOLNE-AutoEdit-Setup-2.1.0.exe') {
            $aliases[] = '2TOOLNE-AutoEdit-Setup-latest.exe';
        } elseif ($filename === '2toolne-autoedit-2.1.1-win-x64.zip' || $filename === '2toolne-autoedit-2.1.0-win-x64.zip') {
            $aliases[] = '2toolne-autoedit-latest-win-x64.zip';
        } elseif ($filename === '2TOOLNE-AutoEdit-2.1.1.dmg' || $filename === '2TOOLNE-AutoEdit-2.1.0.dmg') {
            $aliases[] = '2TOOLNE AutoEdit-2.1.1.dmg';
            $aliases[] = '2TOOLNE AutoEdit-2.1.1-arm64.dmg';
            $aliases[] = '2TOOLNE-AutoEdit-2.1.1-arm64.dmg';
            $aliases[] = '2TOOLNE AutoEdit-2.1.0.dmg';
            $aliases[] = '2TOOLNE AutoEdit-2.1.0-arm64.dmg';
            $aliases[] = '2TOOLNE-AutoEdit-2.1.0-arm64.dmg';
            $aliases[] = '2TOOLNE-AutoEdit-latest.dmg';
            $aliases[] = '2toolne_AutoEdit_macOS_latest.dmg';
        } elseif ($filename === '2TOOLNE_Windows_Customer_Test_2.0.0-hardened.zip') {
            $aliases[] = '2TOOLNE-AutoEdit-Customer-latest.zip';
        }

        foreach ($aliases as $alias) {
            if ($isCustomerTest || stripos($alias, 'customer') !== false) {
                $candidates[] = self::PRIVATE_CUSTOMER_TEST_DIR . '/' . $alias;
            }
            $candidates[] = self::PRIVATE_PACKAGE_DIR . '/' . $alias;
            $candidates[] = $root . '/storage/packages/' . $alias;
            $candidates[] = 'C:/wwwroot/2tamne.site/storage/packages/' . $alias;
        }

        foreach ($candidates as $c) {
            if (file_exists($c) && is_file($c)) {
                return $c;
            }
        }

        // Return preferred storage location outside webroot
        return ($isCustomerTest ? self::PRIVATE_CUSTOMER_TEST_DIR : self::PRIVATE_PACKAGE_DIR) . '/' . $filename;
    }

    /**
     * Issues a short-lived cryptographically secure download token (256-bit entropy via CSPRNG)
     */
    public static function createDownloadToken(array $user, string $product, string $platform, string $filename, ?string $filePath, $packageTypeOrExpiry = 'installer', int $expirySeconds = self::TOKEN_EXPIRY_SECONDS): array {
        if (is_int($packageTypeOrExpiry)) {
            $expirySeconds = $packageTypeOrExpiry;
            $packageType = 'installer';
        } else {
            $packageType = (string)$packageTypeOrExpiry;
        }

        // 32 bytes = 256 bits of CSPRNG entropy
        $rawToken  = bin2hex(random_bytes(32)); 
        $tokenHash = hash('sha256', $rawToken);
        $tokenDir  = self::getTokenStorageDir();
        $tokenFile = $tokenDir . '/' . $tokenHash . '.json';

        $now = time();
        $releaseId = $product . '_' . $platform . '_' . $packageType;
        $payload = [
            'token_hash'     => $tokenHash,
            'user_id'        => (string)($user['id'] ?? ''),
            'username'       => (string)($user['username'] ?? ''),
            'email'          => (string)($user['email'] ?? ''),
            'role'           => (string)($user['role'] ?? 'user'),
            'product'        => $product,
            'platform'       => $platform,
            'package_type'   => $packageType,
            'release_id'     => $releaseId,
            'filename'       => $filename,
            'file_path'      => $filePath,
            'created_at'     => $now,
            'expires_at'     => $now + $expirySeconds,
            'client_ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
            'download_count' => 0,
            'chunks_served'  => 0,
            'bytes_served'   => 0,
        ];

        // Store hashed token on disk outside document root (Never plaintext!)
        file_put_contents($tokenFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        self::logAuditEvent((string)($user['id'] ?? 'guest'), $releaseId, 'DOWNLOAD_AUTHORIZED', 'SUCCESS', 0);

        // Opportunistic cleanup of expired tokens (5% probability)
        if (random_int(1, 100) <= 5) {
            self::purgeExpiredTokens();
        }

        return [
            'token'              => $rawToken,
            'expires_at'         => $payload['expires_at'],
            'expires_in_seconds' => $expirySeconds,
            'filename'           => $filename,
            'release_id'         => $releaseId,
        ];
    }

    /**
     * Verifies and consumes a download token, supporting byte ranges safely
     */
    public static function verifyAndConsumeToken(string $rawToken, int $streamedBytes = 0): array {
        $rawToken = trim($rawToken);
        if (empty($rawToken) || !preg_match('/^[a-f0-9]{48,64}$/i', $rawToken)) {
            return [
                'valid'   => false,
                'status'  => 403,
                'error'   => 'TOKEN_INVALID',
                'message' => 'Mã token tải về không hợp lệ hoặc sai định dạng.',
            ];
        }

        $tokenHash = hash('sha256', $rawToken);
        $tokenDir  = self::getTokenStorageDir();
        $tokenFile = $tokenDir . '/' . $tokenHash . '.json';

        if (!file_exists($tokenFile)) {
            return [
                'valid'   => false,
                'status'  => 403,
                'error'   => 'TOKEN_NOT_FOUND',
                'message' => 'Mã token tải về không tồn tại hoặc đã hết hạn.',
            ];
        }

        $content = @file_get_contents($tokenFile);
        $data = $content ? json_decode($content, true) : null;

        if (!is_array($data) || empty($data['expires_at'])) {
            @unlink($tokenFile);
            return [
                'valid'   => false,
                'status'  => 403,
                'error'   => 'TOKEN_CORRUPT',
                'message' => 'Mã token tải về bị lỗi cấu trúc dữ liệu.',
            ];
        }

        // Check expiration
        if (time() > intval($data['expires_at'])) {
            @unlink($tokenFile);
            self::logAuditEvent((string)($data['user_id'] ?? ''), (string)($data['release_id'] ?? ''), 'DOWNLOAD_EXPIRED', 'EXPIRED', 0);
            return [
                'valid'   => false,
                'status'  => 410,
                'error'   => 'TOKEN_EXPIRED',
                'message' => 'Liên kết tải về tạm thời đã hết hạn. Vui lòng tạo yêu cầu tải mới từ trang cá nhân.',
            ];
        }

        // Increment stats for range / resume requests without breaking normal multi-range downloads
        $data['download_count'] = intval($data['download_count'] ?? 0) + 1;
        $data['chunks_served']  = intval($data['chunks_served'] ?? 0) + 1;
        $data['bytes_served']   = intval($data['bytes_served'] ?? 0) + $streamedBytes;
        $data['last_stream_at'] = time();
        $data['last_stream_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        @file_put_contents($tokenFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return [
            'valid' => true,
            'data'  => $data,
        ];
    }

    /**
     * Audit logger for safe download security events
     */
    public static function logAuditEvent(string $userId, string $releaseId, string $event, string $result, int $bytes = 0): void {
        $logDir = self::PRIVATE_LOG_DIR;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        if (!is_dir($logDir)) {
            $logDir = dirname(__DIR__, 3) . '/storage';
        }
        $logFile = $logDir . '/download_audit.jsonl';

        $entry = [
            'timestamp'    => date('c'),
            'user_id'      => preg_replace('/[^a-zA-Z0-9_-]/', '', $userId),
            'release_id'   => preg_replace('/[^a-zA-Z0-9_\.-]/', '', $releaseId),
            'event'        => $event,
            'result'       => $result,
            'bytes_served' => $bytes,
            'client_ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Purges expired token files from storage
     */
    public static function purgeExpiredTokens(): int {
        $dir = self::getTokenStorageDir();
        $files = glob($dir . '/*.json');
        if (!$files) return 0;

        $purged = 0;
        $now = time();
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $dec = $raw ? json_decode($raw, true) : null;
            if (!$dec || !isset($dec['expires_at']) || ($dec['expires_at'] + 3600) < $now) {
                @unlink($file);
                $purged++;
            }
        }
        return $purged;
    }

    /**
     * Grants a 3-day AutoEdit trial to an eligible registered user
     */
    public static function claimAutoEditTrial(array $user, string $clientIp): array {
        $username = trim((string)$user['username']);
        $product  = '2toolne.capcut.v2';

        if (empty($username)) {
            return ['success' => false, 'message' => 'Người dùng không hợp lệ.'];
        }

        // Check if IP or user already claimed trial for AutoEdit
        if (function_exists('db_is_ip_trial_claimed') && db_is_ip_trial_claimed($clientIp, $product)) {
            return [
                'success' => false,
                'error'   => 'TRIAL_ALREADY_CLAIMED',
                'message' => 'Địa chỉ IP này đã nhận bản dùng thử AutoEdit trước đây.',
            ];
        }

        $db = Database::getConnection();
        // Also check if user already has a trial in licenses table
        $stmt = $db->prepare('
            SELECT id FROM licenses 
            WHERE LOWER(owner_username) = LOWER(?) AND (product = ? OR product = "AUTOEDIT")
            LIMIT 1
        ');
        $stmt->execute([$username, $product]);
        if ($stmt->fetch()) {
            return [
                'success' => false,
                'error'   => 'USER_TRIAL_EXISTS',
                'message' => 'Tài khoản của bạn đã được cấp bản quyền hoặc bản dùng thử AutoEdit.',
            ];
        }

        $rnd = strtoupper(bin2hex(random_bytes(3)));
        $trialKey = "2TOOLNE-AUTOEDIT-TRIAL-{$rnd}";

        if (function_exists('db_create_license')) {
            db_create_license($trialKey, $product, 'TRIAL', 3, $username, "Kích hoạt tự động qua Download Gate (3 Ngày) - IP {$clientIp}");
        }
        if (function_exists('db_claim_ip_trial')) {
            db_claim_ip_trial($clientIp, $username, $product);
        }

        return [
            'success'     => true,
            'license_key' => $trialKey,
            'duration'    => 3,
            'message'     => 'Kích hoạt thành công bản dùng thử AutoEdit 3 ngày!',
        ];
    }
}
