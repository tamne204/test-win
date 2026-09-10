<?php
/**
 * 2TOOLNE SECURE AUTHENTICATED SOFTWARE DOWNLOAD CONTROLLER
 * Handles download authorization requests, evaluates entitlement (license / trial),
 * generates temporary short-lived signed tokens, and streams binary software artifacts securely.
 *
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../services/DownloadEntitlementService.php';

class DownloadController {

    /**
     * POST /api/v1/downloads/request
     * Validates session / token, verifies active entitlement, and generates a temporary signed download URL
     */
    public static function requestDownload(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Vui lòng đăng nhập hoặc đăng ký tài khoản để tải phần mềm.', 401, 'AUTH_REQUIRED');
            return;
        }

        $product     = trim((string)($body['product'] ?? ($_GET['product'] ?? 'AUTOEDIT')));
        $platform    = trim((string)($body['platform'] ?? ($_GET['platform'] ?? 'windows-x64')));
        $rawPackage  = strtolower(trim((string)($body['package_type'] ?? ($body['package_id'] ?? ($_GET['package_type'] ?? ($_GET['package_id'] ?? 'installer'))))));
        if (in_array($rawPackage, ['customer_test', 'customer_test_package', 'customer-test', 'customer_test_zip'], true)) {
            $packageType = 'customer_test';
        } elseif (in_array($rawPackage, ['portable', 'zip', 'standalone', 'direct', 'v201', 'v2.0.1'], true)) {
            $packageType = 'portable';
        } else {
            $packageType = 'installer';
        }

        // Check rate limit: max 15 download requests per minute
        if (!self::checkRateLimit((string)($user['id'] ?? 'guest'), 15, 60)) {
            Router::error('Bạn đã gửi quá nhiều yêu cầu tải phần mềm. Vui lòng chờ 1 phút.', 429, 'RATE_LIMIT_EXCEEDED');
            return;
        }

        // Evaluate entitlement
        $eval = DownloadEntitlementService::canUserDownload($user, $product, $platform, $packageType);
        if (!$eval['allowed']) {
            Router::json([
                'success'         => false,
                'error'           => $eval['error'] ?? 'ENTITLEMENT_REQUIRED',
                'message'         => $eval['message'] ?? 'Bạn cần kích hoạt bản dùng thử hoặc mua bản quyền để tải phần mềm.',
                'can_claim_trial' => $eval['can_claim_trial'] ?? false,
                'pricing_url'     => $eval['pricing_url'] ?? '/#pricing',
            ], $eval['status'] ?? 403);
            return;
        }

        // Resolve package info
        $pkg = DownloadEntitlementService::resolvePackageInfo($product, $platform, $packageType);
        if (!$pkg) {
            Router::error('Không tìm thấy gói phần mềm được yêu cầu.', 404, 'PACKAGE_NOT_FOUND');
            return;
        }

        // Create temporary signed token (10 min expiry, 256-bit CSPRNG)
        $tokenInfo = DownloadEntitlementService::createDownloadToken($user, $pkg['product'], $pkg['platform'], $pkg['filename'], $pkg['file_path'], $packageType, 600);

        // Construct absolute signed URL
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $isHttps ? 'https://' : 'https://';
        $host = $_SERVER['HTTP_HOST'] ?? 'www.2tamne.site';
        $downloadUrl = $scheme . $host . '/api/v1/downloads/file?token=' . urlencode($tokenInfo['token']);

        Router::json([
            'success'            => true,
            'download_url'       => $downloadUrl,
            'filename'           => $pkg['filename'],
            'filesize_bytes'     => $pkg['filesize'],
            'sha256'             => $pkg['sha256'],
            'expires_in_seconds' => $tokenInfo['expires_in_seconds'],
            'expires_at'         => date('c', $tokenInfo['expires_at']),
            'grant_type'         => $eval['grant_type'] ?? 'PAID',
            'reason'             => $eval['reason'] ?? '',
            'message'            => 'Xác thực thành công. Tệp phần mềm sẽ được tải về ngay.',
        ], 200);
    }

    /**
     * GET /api/v1/downloads/file?token=...
     * Validates session, verifies token user-binding, rechecks entitlement, and streams binary
     */
    public static function streamDownload(array $params, array $body): void {
        // 1. Session Authentication is MANDATORY (Bearer-only token access strictly forbidden)
        $currentUser = CloudAuthHelper::getCurrentUser();
        if (!$currentUser || empty($currentUser['id'])) {
            DownloadEntitlementService::logAuditEvent('anonymous', 'unknown', 'DOWNLOAD_DENIED', 'AUTH_REQUIRED', 0);
            Router::error('Vui lòng đăng nhập tài khoản để tải phần mềm. Liên kết tải xuống yêu cầu phiên đăng nhập hợp lệ của chủ tài khoản.', 401, 'AUTH_REQUIRED');
            return;
        }

        $token = trim((string)($_GET['token'] ?? ''));
        if (empty($token)) {
            Router::error('Mã token xác thực tải về không được để trống.', 400, 'TOKEN_REQUIRED');
            return;
        }

        // 2. Verify temporary token validity & cryptographic hash
        $res = DownloadEntitlementService::verifyAndConsumeToken($token);
        if (!$res['valid']) {
            DownloadEntitlementService::logAuditEvent((string)($currentUser['id'] ?? 'guest'), 'unknown', 'DOWNLOAD_DENIED', $res['error'] ?? 'TOKEN_INVALID', 0);
            Router::error(
                $res['message'] ?? 'Mã token tải về không hợp lệ.',
                $res['status'] ?? 403,
                $res['error'] ?? 'TOKEN_INVALID'
            );
            return;
        }

        $data = $res['data'];

        // 3. Strict User-Binding Enforcement (Token cannot be shared with another user or anonymous browser)
        $tokenUserId     = (string)($data['user_id'] ?? '');
        $tokenUsername   = strtolower(trim((string)($data['username'] ?? '')));
        $currentUserId   = (string)($currentUser['id'] ?? '');
        $currentUsername = strtolower(trim((string)($currentUser['username'] ?? '')));
        $isAdmin         = !empty($currentUser['is_admin']) || in_array($currentUser['role'] ?? '', ['admin', 'super_admin'], true);

        $isOwner = ($currentUserId === $tokenUserId) || ($currentUsername === $tokenUsername);
        if (!$isOwner && !$isAdmin) {
            DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'FORBIDDEN_USER_MISMATCH', 0);
            Router::error('Mã tải về này được cấp cho một tài khoản khác. Bạn không có quyền sử dụng liên kết này.', 403, 'FORBIDDEN_TOKEN_USER_MISMATCH');
            return;
        }

        // 4. Live Stream-Time Entitlement Re-check (Revocation/expiration during token lifetime immediately cuts access)
        $product     = (string)($data['product'] ?? 'AUTOEDIT');
        $platform    = (string)($data['platform'] ?? 'windows-x64');
        $packageType = (string)($data['package_type'] ?? 'installer');

        // Section 6: Release ID Tampering & Cross-Product Protection
        $expectedReleaseId = $product . '_' . $platform . '_' . $packageType;
        if (($data['release_id'] ?? '') !== $expectedReleaseId) {
            DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'RELEASE_ID_TAMPERED', 0);
            Router::error('Thông tin gói phần mềm đã bị can thiệp trái phép.', 403, 'RELEASE_ID_TAMPERED');
            return;
        }

        $eval = DownloadEntitlementService::canUserDownload($currentUser, $product, $platform, $packageType);
        if (!$eval['allowed']) {
            DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'ENTITLEMENT_LOST', 0);
            Router::error('Quyền tải phần mềm của bạn không còn hiệu lực hoặc giấy phép đã bị thu hồi/hết hạn.', 403, $eval['error'] ?? 'ENTITLEMENT_LOST');
            return;
        }

        // 5. Resolve physical file path (Prioritizing private storage outside docroot)
        $filePath = $data['file_path'];
        if (empty($filePath) || !file_exists($filePath)) {
            $filePath = DownloadEntitlementService::locatePhysicalFile($data['filename'], ($packageType === 'customer_test'));
        }

        if (empty($filePath) || !file_exists($filePath)) {
            Router::error('Tệp cài đặt không tìm thấy trên hệ thống lưu trữ máy chủ.', 404, 'FILE_NOT_FOUND');
            return;
        }

        // Section 7: CANONICAL REALPATH DEFENSE
        // Compute realpath of requested file and enforce that it resides strictly inside authorized private root
        $canonicalFile = realpath($filePath);
        if ($canonicalFile === false) {
            DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'CANONICAL_REALPATH_FAILED', 0);
            Router::error('Đường dẫn tệp không hợp lệ hoặc không thể xác minh thực thể.', 403, 'CANONICAL_PATH_VIOLATION');
            return;
        }

        $canonicalDataRoot = realpath(DownloadEntitlementService::PRIVATE_DATA_ROOT);
        $normCanonicalFile = str_replace('\\', '/', strtolower($canonicalFile));
        $normCanonicalDataRoot = ($canonicalDataRoot !== false) ? str_replace('\\', '/', strtolower($canonicalDataRoot)) : '';

        if (empty($normCanonicalDataRoot) || strpos($normCanonicalFile, $normCanonicalDataRoot . '/') !== 0) {
            DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'CANONICAL_PATH_OUT_OF_BOUNDS', 0);
            Router::error('Truy cập bị từ chối do vi phạm ranh giới lưu trữ bảo mật.', 403, 'FORBIDDEN_STORAGE_BOUNDARY');
            return;
        }

        // Customer test packages must strictly reside in customer_test directory
        if ($packageType === 'customer_test') {
            $custRoot = realpath(DownloadEntitlementService::PRIVATE_CUSTOMER_TEST_DIR);
            $normCustRoot = ($custRoot !== false) ? str_replace('\\', '/', strtolower($custRoot)) : '';
            if (empty($normCustRoot) || strpos($normCanonicalFile, $normCustRoot . '/') !== 0) {
                DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_DENIED', 'CUSTOMER_TEST_ROOT_VIOLATION', 0);
                Router::error('Gói thử nghiệm nằm ngoài phân vùng lưu trữ được phân quyền.', 403, 'FORBIDDEN_STORAGE_BOUNDARY');
                return;
            }
        }

        // ===================================================================
        // BINARY STREAM ISOLATION — MUST execute before ANY output or headers
        // ===================================================================
        // FIX #1: Disable all PHP output transforms that corrupt binary data.
        // On DirectAdmin shared hosting, zlib.output_compression = On is the
        // most common cause of NSIS installer CRC failure: PHP silently GZIP-
        // compresses the .exe body, producing a corrupted file on disk.
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        // Prevent session URL rewriting from injecting PHPSESSID into binary body
        @ini_set('session.use_trans_sid', '0');
        // Allow enough time to stream the full installer (600 MB / ~5 MB/s = 120s minimum)
        set_time_limit(600);

        // Clean ALL buffered output layers including any invisible zlib handler residue
        while (ob_get_level()) {
            @ob_end_clean();
        }

        // Remove any Content-Encoding header that a prior zlib/gzip handler may have set
        if (function_exists('header_remove')) {
            @header_remove('Content-Encoding');
        }

        $fileSize = filesize($canonicalFile);

        // Section 8: SAFE FILENAME & HEADER SANITIZATION
        // Strip CRLF, quotes, semicolons, and path traversal characters to prevent CRLF injection
        $rawFilename = (string)($data['filename'] ?? 'package.bin');
        $safeFilename = str_replace(["\r", "\n", '"', "'", ';', '/', '\\', "\0"], '', $rawFilename);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $safeFilename);
        if (empty($safeFilename)) {
            $safeFilename = '2toolne_download.bin';
        }

        // 6. Handle HTTP Range requests safely (Authorization applies to EVERY range chunk)
        $start  = 0;
        $end    = $fileSize - 1;
        $length = $fileSize;

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = intval($matches[1]);
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }

            // FIX #2: Clamp Range boundaries — prevent read-past-EOF and negative length
            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
            if ($start < 0) {
                $start = 0;
            }
            if ($start > $end) {
                // Range Not Satisfiable — client requested a range entirely beyond the file
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

        DownloadEntitlementService::logAuditEvent($currentUserId, (string)($data['release_id'] ?? ''), 'DOWNLOAD_STARTED', 'STREAMING', (int)$length);

        // Send protective security headers (Strict no-store, nosniff, attachment)
        // FIX #3: Content-Type must be application/octet-stream with NO charset.
        // The Router constructor sets Content-Type: application/json; charset=utf-8.
        // Override it cleanly here with an explicit charset-free octet-stream value.
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

        // Stream binary file in 1MB chunks
        $fp = @fopen($canonicalFile, 'rb');
        if (!$fp) {
            Router::error('Không thể mở tệp cài đặt để truyền tải.', 500, 'FILE_READ_ERROR');
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
     * POST /api/v1/downloads/claim-trial
     * Allows a registered user to claim their 3-day AutoEdit trial entitlement
     */
    public static function claimTrial(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Vui lòng đăng nhập hoặc đăng ký tài khoản để nhận dùng thử.', 401, 'AUTH_REQUIRED');
            return;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $res = DownloadEntitlementService::claimAutoEditTrial($user, $clientIp);

        if ($res['success']) {
            Router::json($res, 200);
        } else {
            Router::json($res, 400);
        }
    }

    /**
     * Simple file-based rate limiter
     */
    private static function checkRateLimit(string $identifier, int $maxRequests = 15, int $windowSeconds = 60): bool {
        $dir = dirname(__DIR__, 3) . '/storage/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $key = 'dl_rate_' . hash('sha256', $identifier . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $file = $dir . '/' . $key . '.json';

        $now = time();
        $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $dec = $raw ? json_decode($raw, true) : null;
            if ($dec && isset($dec['reset_at']) && $dec['reset_at'] > $now) {
                $data = $dec;
            }
        }

        if ($data['count'] >= $maxRequests) {
            return false;
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }
}
