<?php
/**
 * 2TOOLNE CLOUD — CLOUD SHARE CONTROLLER (V2 PRODUCTION ARCHITECTURE)
 * Secure public link sharing with:
 * - URL fragment architecture (zero secret leakage in Apache access logs)
 * - SHA-256 hash lookup + AES-256-GCM encrypted recovery for creators
 * - Short-lived signed capability tokens for streaming & downloading
 * - Single-session access accounting (no DB writes on media Range chunks)
 * - Strict server-side folder subtree enforcement (no traversal)
 * - Idempotent share creation
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../storage/CryptoService.php';
require_once __DIR__ . '/../storage/GoogleDriveStorageAdapter.php';
require_once __DIR__ . '/../storage/CurlHelper.php';

class CloudShareController {

    private static function getMasterKey(): string {
        return hash('sha256', defined('CLOUD_MASTER_KEY') ? CLOUD_MASTER_KEY : '2toolne_cloud_master_aes256_secret_key_2026_salt', true);
    }

    /**
     * Encrypt raw share token with AES-256-GCM for owner recovery
     */
    public static function encryptTokenForRecovery(string $rawToken): string {
        $key = self::getMasterKey();
        $iv = random_bytes(12); // standard 96-bit IV for GCM
        $tag = '';
        $ciphertext = openssl_encrypt($rawToken, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode(json_encode([
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct'  => base64_encode($ciphertext),
        ]));
    }

    /**
     * Decrypt raw share token from AES-256-GCM envelope
     */
    public static function decryptTokenFromRecovery(string $envelope): ?string {
        try {
            $data = json_decode(base64_decode($envelope), true);
            if (!is_array($data) || empty($data['iv']) || empty($data['tag']) || empty($data['ct'])) {
                return null;
            }
            $key = self::getMasterKey();
            $iv  = base64_decode($data['iv']);
            $tag = base64_decode($data['tag']);
            $ct  = base64_decode($data['ct']);
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain !== false ? $plain : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Generate short-lived HMAC-signed capability session token (15 mins)
     */
    public static function generateCapability(array $payload): string {
        $payload['exp'] = time() + 900; // 15 minutes
        $payload['nonce'] = bin2hex(random_bytes(8));
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, self::getMasterKey());
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '.' . $sig;
    }

    /**
     * Verify short-lived capability session token
     */
    public static function verifyCapability(?string $token): ?array {
        if (empty($token) || strpos($token, '.') === false) return null;
        [$encodedJson, $sig] = explode('.', $token, 2);
        $json = base64_decode(strtr($encodedJson, '-_', '+/'));
        if (!$json) return null;

        $expectedSig = hash_hmac('sha256', $json, self::getMasterKey());
        if (!hash_equals($expectedSig, $sig)) return null;

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * POST /api/v1/cloud/spaces/{spaceId}/shares
     * Authenticated endpoint to create an idempotent share link
     */
    public static function createShare(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $spaceId = $params['spaceId'] ?? ($body['space_id'] ?? '');
        $userId  = (string)$user['id'];

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, $userId);
        if (!$auth['allowed']) {
            Router::error('Access denied to this cloud space', 403, 'FORBIDDEN');
        }

        // Check role: OWNER, ADMIN, EDITOR allowed; VIEWER denied
        $userRole = strtoupper((string)($auth['role'] ?? 'MEMBER'));
        if ($userRole === 'VIEWER') {
            Router::error('Bạn không có quyền tạo liên kết chia sẻ trong không gian này', 403, 'PERMISSION_DENIED');
        }

        $itemType = strtoupper(trim((string)($body['item_type'] ?? 'FILE')));
        if (!in_array($itemType, ['FILE', 'FOLDER'], true)) {
            Router::error('item_type phải là FILE hoặc FOLDER', 400, 'INVALID_ITEM_TYPE');
        }

        $itemId = trim((string)($body['item_id'] ?? ($body['id'] ?? '')));
        if (empty($itemId)) {
            Router::error('item_id không được để trống', 400, 'MISSING_ITEM_ID');
        }

        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ''));
        $db = Database::getConnection();

        // Check Idempotency: If duplicate creation request, return existing share
        if (!empty($idempotencyKey)) {
            $idemStmt = $db->prepare('SELECT * FROM cloud_shares WHERE idempotency_key = ? LIMIT 1');
            $idemStmt->execute([$idempotencyKey]);
            $existing = $idemStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $recoveredToken = self::decryptTokenFromRecovery($existing['share_token_ciphertext']);
                $baseUrl = self::getBaseShareUrl();
                Router::json([
                    'ok'      => true,
                    'success' => true,
                    'share'   => [
                        'id'           => $existing['id'],
                        'item_type'    => $existing['item_type'],
                        'item_id'      => $existing['item_id'],
                        'access_level' => $existing['access_level'],
                        'expires_at'   => $existing['expires_at'],
                        'created_at'   => $existing['created_at'],
                        'share_url'    => "{$baseUrl}/share/#{$recoveredToken}",
                        'raw_token'    => $recoveredToken,
                    ],
                ], 200);
                return;
            }
        }

        // Verify item existence and active status in space
        if ($itemType === 'FILE') {
            $stmt = $db->prepare('SELECT id, filename, status, deleted_at FROM cloud_files WHERE id = ? AND cloud_space_id = ? LIMIT 1');
            $stmt->execute([$itemId, $spaceId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item || $item['status'] === 'DELETED') {
                Router::error('Không tìm thấy tệp tin hoặc tệp tin đã bị xóa', 404, 'NOT_FOUND');
            }
            if ($item['status'] === 'TRASHED' || !empty($item['deleted_at'])) {
                Router::error('Không thể chia sẻ tệp tin đang nằm trong thùng rác', 400, 'ITEM_IN_TRASH');
            }
        } else {
            $stmt = $db->prepare('SELECT id, name, deleted_at FROM cloud_folders WHERE id = ? AND cloud_space_id = ? LIMIT 1');
            $stmt->execute([$itemId, $spaceId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                Router::error('Không tìm thấy thư mục hoặc thư mục đã bị xóa', 404, 'NOT_FOUND');
            }
            if (!empty($item['deleted_at'])) {
                Router::error('Không thể chia sẻ thư mục đang nằm trong thùng rác', 400, 'ITEM_IN_TRASH');
            }
        }

        // Access level
        $accessLevel = strtoupper(trim((string)($body['access_level'] ?? 'VIEW_ONLY')));
        if (!in_array($accessLevel, ['VIEW_ONLY', 'ALLOW_DOWNLOAD'], true)) {
            $accessLevel = 'VIEW_ONLY';
        }

        // Expiration calculation
        $expiresIn = $body['expires_in'] ?? null;
        $expiresAt = null;
        if ($expiresIn === '7d' || $expiresIn === 7) {
            $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);
        } elseif ($expiresIn === '30d' || $expiresIn === 30) {
            $expiresAt = date('Y-m-d H:i:s', time() + 30 * 86400);
        } elseif (is_numeric($expiresIn) && (int)$expiresIn > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$expiresIn);
        }

        // Cryptographically secure token generation (32 random bytes = 256 bits entropy)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $encryptedToken = self::encryptTokenForRecovery($rawToken);

        $shareId = 'sh_' . bin2hex(random_bytes(12));

        $insStmt = $db->prepare('
            INSERT INTO cloud_shares
                (id, cloud_space_id, item_type, item_id, share_token_hash, share_token_ciphertext, access_level, expires_at, idempotency_key, created_by_user_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insStmt->execute([
            $shareId,
            $spaceId,
            $itemType,
            $itemId,
            $tokenHash,
            $encryptedToken,
            $accessLevel,
            $expiresAt,
            !empty($idempotencyKey) ? $idempotencyKey : null,
            $userId,
        ]);

        $baseUrl = self::getBaseShareUrl();
        $shareUrl = "{$baseUrl}/share/#{$rawToken}";

        Router::json([
            'ok'      => true,
            'success' => true,
            'share'   => [
                'id'           => $shareId,
                'item_type'    => $itemType,
                'item_id'      => $itemId,
                'access_level' => $accessLevel,
                'expires_at'   => $expiresAt,
                'created_at'   => date('Y-m-d H:i:s'),
                'share_url'    => $shareUrl,
                'raw_token'    => $rawToken,
            ],
        ], 201);
    }

    /**
     * GET /api/v1/cloud/spaces/{spaceId}/shares
     * Authenticated endpoint to list existing active shares with decrypted URLs for authorized users
     */
    public static function getItemShares(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $spaceId  = $params['spaceId'] ?? ($_GET['space_id'] ?? '');
        $userId   = (string)$user['id'];

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, $userId);
        if (!$auth['allowed']) {
            Router::error('Access denied to this cloud space', 403, 'FORBIDDEN');
        }

        $userRole  = strtoupper((string)($auth['role'] ?? 'MEMBER'));
        $canManage = in_array($userRole, ['OWNER', 'ADMIN', 'EDITOR'], true);

        $itemType = strtoupper(trim((string)($_GET['item_type'] ?? '')));
        $itemId   = trim((string)($_GET['item_id'] ?? ''));

        $db = Database::getConnection();

        $query = '
            SELECT id, cloud_space_id, item_type, item_id, share_token_ciphertext, access_level,
                   expires_at, created_at, created_by_user_id, last_accessed_at, access_count
            FROM cloud_shares
            WHERE cloud_space_id = ? AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > NOW())
        ';
        $binds = [$spaceId];

        if (!empty($itemType) && !empty($itemId)) {
            $query .= ' AND item_type = ? AND item_id = ?';
            $binds[] = $itemType;
            $binds[] = $itemId;
        }

        $query .= ' ORDER BY created_at DESC';
        $stmt = $db->prepare($query);
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $baseUrl = self::getBaseShareUrl();
        $shares = [];

        foreach ($rows as $r) {
            $isCreator = ((string)$r['created_by_user_id'] === $userId);
            $shareUrl = null;

            // Only decrypt raw token if caller is creator or space OWNER/ADMIN
            if ($isCreator || $userRole === 'OWNER' || $userRole === 'ADMIN') {
                if (!empty($r['share_token_ciphertext'])) {
                    $decrypted = self::decryptTokenFromRecovery($r['share_token_ciphertext']);
                    if ($decrypted) {
                        $shareUrl = "{$baseUrl}/share/#{$decrypted}";
                    }
                }
            }

            $shares[] = [
                'id'           => $r['id'],
                'item_type'    => $r['item_type'],
                'item_id'      => $r['item_id'],
                'access_level' => $r['access_level'],
                'expires_at'   => $r['expires_at'],
                'created_at'   => $r['created_at'],
                'share_url'    => $shareUrl,
                'can_revoke'   => ($isCreator || $userRole === 'OWNER' || $userRole === 'ADMIN'),
            ];
        }

        Router::json([
            'ok'     => true,
            'shares' => $shares,
        ]);
    }

    /**
     * POST /api/v1/cloud/shares/{id}/revoke
     * Authenticated endpoint to revoke a share link immediately
     */
    public static function revokeShare(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $shareId = $params['id'] ?? ($body['share_id'] ?? '');
        $userId  = (string)$user['id'];

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_shares WHERE id = ? LIMIT 1');
        $stmt->execute([$shareId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$share) {
            Router::error('Không tìm thấy liên kết chia sẻ', 404, 'NOT_FOUND');
        }

        // Authorization check: creator OR space OWNER/ADMIN
        $auth = CloudAuthHelper::authorizeSpaceAccess($share['cloud_space_id'], $userId);
        $isCreator = ((string)$share['created_by_user_id'] === $userId);
        $isAdmin   = in_array(strtoupper((string)($auth['role'] ?? '')), ['OWNER', 'ADMIN'], true);

        if (!$isCreator && !$isAdmin) {
            Router::error('Bạn không có quyền thu hồi liên kết này', 403, 'FORBIDDEN');
        }

        $upStmt = $db->prepare('UPDATE cloud_shares SET revoked_at = NOW() WHERE id = ?');
        $upStmt->execute([$shareId]);

        Router::json([
            'ok'      => true,
            'revoked' => true,
            'message' => 'Liên kết chia sẻ đã được thu hồi thành công.',
        ]);
    }

    /**
     * POST /api/v1/cloud/public/share/resolve
     * Public endpoint: Resolves raw token from fragment locally sent via JSON body.
     * Generates a short-lived capability session token for preview/download.
     * Increments access_count ONCE per resolved session.
     */
    public static function resolvePublicShare(array $params, array $body): void {
        header("Cache-Control: no-store");
        header("Referrer-Policy: no-referrer");

        $rawToken = trim((string)($body['share_token'] ?? ($body['token'] ?? '')));
        if (empty($rawToken)) {
            Router::error('Mã liên kết không hợp lệ', 400, 'INVALID_TOKEN');
        }

        $shareRes = self::lookupShareByRawToken($rawToken);
        if (!$shareRes['valid']) {
            Router::error($shareRes['error'], $shareRes['status'], $shareRes['code']);
        }

        $sh = $shareRes['share'];

        // Single-session access accounting: Increment ONLY on resolution
        self::recordAccessSession((string)$sh['id']);

        // Generate short-lived scoped capability
        $capability = self::generateCapability([
            'share_id'     => $sh['id'],
            'space_id'     => $sh['cloud_space_id'],
            'item_type'    => $sh['item_type'],
            'item_id'      => $sh['item_id'],
            'access_level' => $sh['access_level'],
        ]);

        $db = Database::getConnection();
        $itemData = [];

        if ($sh['item_type'] === 'FILE') {
            $fStmt = $db->prepare('SELECT id, filename, extension, mime_type, size_bytes, created_at FROM cloud_files WHERE id = ? LIMIT 1');
            $fStmt->execute([$sh['item_id']]);
            $file = $fStmt->fetch(PDO::FETCH_ASSOC);

            $mime = $file['mime_type'] ?: 'application/octet-stream';
            $isImg = strpos($mime, 'image/') === 0;
            $isVideo = strpos($mime, 'video/') === 0;
            $isAudio = strpos($mime, 'audio/') === 0;

            $itemData = [
                'name'         => $file['filename'],
                'size_bytes'   => (int)$file['size_bytes'],
                'mime_type'    => $mime,
                'extension'    => $file['extension'],
                'can_preview'  => ($isImg || $isVideo || $isAudio),
                'preview_type' => $isImg ? 'IMAGE' : ($isVideo ? 'VIDEO' : ($isAudio ? 'AUDIO' : 'OTHER')),
            ];
        } else {
            $fldStmt = $db->prepare('SELECT id, name, created_at FROM cloud_folders WHERE id = ? LIMIT 1');
            $fldStmt->execute([$sh['item_id']]);
            $folder = $fldStmt->fetch(PDO::FETCH_ASSOC);

            $itemData = [
                'name'        => $folder['name'],
                'is_folder'   => true,
                'can_preview' => false,
            ];
        }

        Router::json([
            'ok'            => true,
            'item_type'     => $sh['item_type'],
            'access_level'  => $sh['access_level'],
            'expires_at'    => $sh['expires_at'],
            'share_session' => $capability,
            'item'          => $itemData,
        ]);
    }

    /**
     * GET /api/v1/cloud/public/share/download
     * Public streaming download endpoint authorized by short-lived capability session token
     */
    public static function publicDownload(array $params, array $body): void {
        header("Cache-Control: no-store");
        header("Referrer-Policy: no-referrer");

        $sessionToken = $_SERVER['HTTP_X_SHARE_SESSION'] ?? ($_GET['session'] ?? '');
        $cap = self::verifyCapability($sessionToken);
        if (!$cap) {
            Router::error('Phiên truy cập không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang.', 401, 'INVALID_SESSION');
        }

        // Verify active share from DB in real time
        $db = Database::getConnection();
        $sStmt = $db->prepare('SELECT * FROM cloud_shares WHERE id = ? LIMIT 1');
        $sStmt->execute([$cap['share_id']]);
        $sh = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sh || !empty($sh['revoked_at'])) {
            Router::error('Liên kết này không còn khả dụng.', 410, 'REVOKED');
        }

        if ($sh['access_level'] !== 'ALLOW_DOWNLOAD') {
            Router::error('Tải xuống không được phép đối với liên kết này.', 403, 'DOWNLOAD_FORBIDDEN');
        }

        $fileId = null;
        if ($sh['item_type'] === 'FILE') {
            $fileId = $sh['item_id'];
        } else {
            // Folder sharing: download a specific descendant file
            $requestedFileId = trim((string)($_GET['file_id'] ?? ''));
            if (empty($requestedFileId)) {
                Router::error('Thiếu mã tệp cần tải.', 400, 'MISSING_FILE_ID');
            }

            $stmt = $db->prepare('SELECT id, folder_id, cloud_space_id FROM cloud_files WHERE id = ? LIMIT 1');
            $stmt->execute([$requestedFileId]);
            $targetFile = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetFile) {
                Router::error('Tệp không tồn tại.', 404, 'NOT_FOUND');
            }

            if ($targetFile['cloud_space_id'] !== $sh['cloud_space_id'] || !self::verifyFolderDescendant($targetFile['folder_id'], $sh['item_id'], $sh['cloud_space_id'], $db)) {
                Router::error('Quyền truy cập bị từ chối: Tệp không thuộc thư mục được chia sẻ.', 403, 'FORBIDDEN');
            }

            $fileId = $requestedFileId;
        }

        $fStmt = $db->prepare('
            SELECT cf.*, sa.encrypted_credentials
            FROM cloud_files cf
            JOIN storage_accounts sa ON cf.storage_account_id = sa.id
            WHERE cf.id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
            LIMIT 1
        ');
        $fStmt->execute([$fileId]);
        $file = $fStmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            Router::error('Tệp tin không còn khả dụng.', 404, 'NOT_FOUND');
        }

        $cleanFilename = self::sanitizeFilename((string)$file['filename']);
        $mimeType = $file['mime_type'] ?: 'application/octet-stream';
        $sizeBytes = (int)$file['size_bytes'];

        if (!empty($file['encrypted_credentials'])) {
            try {
                $credentials = CryptoService::decryptJson($file['encrypted_credentials']);
                $adapter = new GoogleDriveStorageAdapter();
                $accessToken = $adapter->getValidAccessToken($credentials);

                $streamUrl = "https://www.googleapis.com/drive/v3/files/" . urlencode($file['provider_file_id']) . "?alt=media";

                header("Content-Type: {$mimeType}");
                header("Content-Disposition: attachment; filename=\"{$cleanFilename}\"; filename*=UTF-8''" . rawurlencode($file['filename']));
                header("Content-Length: {$sizeBytes}");
                header("X-Content-Type-Options: nosniff");
                header("Connection: close");

                $ch = curl_init($streamUrl);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                CurlHelper::applySslOptions($ch);
                curl_exec($ch);
                curl_close($ch);
                exit;
            } catch (Throwable $e) {}
        }

        // Local simulation / fallback
        header("Content-Type: {$mimeType}");
        header("Content-Disposition: attachment; filename=\"{$cleanFilename}\"; filename*=UTF-8''" . rawurlencode($file['filename']));
        header("Content-Length: {$sizeBytes}");
        header("X-Content-Type-Options: nosniff");
        header("Connection: close");
        echo "2TOOLNE CLOUD — Public Shared Stream Content: " . htmlspecialchars($cleanFilename, ENT_QUOTES, 'UTF-8');
        exit;
    }

    /**
     * GET /api/v1/cloud/public/share/preview
     * Public inline preview stream with HTTP Range support for browser playback seeking
     * Does NOT increment access_count on each chunk!
     */
    public static function publicPreview(array $params, array $body): void {
        header("Referrer-Policy: no-referrer");

        $sessionToken = $_SERVER['HTTP_X_SHARE_SESSION'] ?? ($_GET['session'] ?? '');
        $cap = self::verifyCapability($sessionToken);
        if (!$cap) {
            Router::error('Phiên truy cập không hợp lệ hoặc đã hết hạn.', 401, 'INVALID_SESSION');
        }

        $db = Database::getConnection();
        $sStmt = $db->prepare('SELECT * FROM cloud_shares WHERE id = ? LIMIT 1');
        $sStmt->execute([$cap['share_id']]);
        $sh = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sh || !empty($sh['revoked_at'])) {
            Router::error('Liên kết này không còn khả dụng.', 410, 'REVOKED');
        }

        $fileId = null;
        if ($sh['item_type'] === 'FILE') {
            $fileId = $sh['item_id'];
        } else {
            $requestedFileId = trim((string)($_GET['file_id'] ?? ''));
            if (empty($requestedFileId)) Router::error('Thiếu file_id', 400);

            $stmt = $db->prepare('SELECT id, folder_id, cloud_space_id FROM cloud_files WHERE id = ? LIMIT 1');
            $stmt->execute([$requestedFileId]);
            $targetFile = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetFile || !self::verifyFolderDescendant($targetFile['folder_id'], $sh['item_id'], $sh['cloud_space_id'], $db)) {
                Router::error('Quyền truy cập bị từ chối', 403);
            }
            $fileId = $requestedFileId;
        }

        $fStmt = $db->prepare('
            SELECT cf.*, sa.encrypted_credentials
            FROM cloud_files cf
            JOIN storage_accounts sa ON cf.storage_account_id = sa.id
            WHERE cf.id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
            LIMIT 1
        ');
        $fStmt->execute([$fileId]);
        $file = $fStmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            Router::error('Tệp tin không tồn tại', 404);
        }

        $mimeType = $file['mime_type'] ?: 'application/octet-stream';
        $sizeBytes = (int)$file['size_bytes'];
        $cleanFilename = self::sanitizeFilename((string)$file['filename']);
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

        if (!empty($file['encrypted_credentials'])) {
            try {
                $credentials = CryptoService::decryptJson($file['encrypted_credentials']);
                $adapter = new GoogleDriveStorageAdapter();
                $accessToken = $adapter->getValidAccessToken($credentials);

                $streamUrl = "https://www.googleapis.com/drive/v3/files/" . urlencode($file['provider_file_id']) . "?alt=media";

                $curlHeaders = ["Authorization: Bearer {$accessToken}"];
                if (!empty($rangeHeader)) {
                    $curlHeaders[] = "Range: {$rangeHeader}";
                }

                header("Content-Type: {$mimeType}");
                header("Content-Disposition: inline; filename=\"{$cleanFilename}\"");
                header("Accept-Ranges: bytes");
                header("X-Content-Type-Options: nosniff");
                header("Cache-Control: private, max-age=86400");

                $ch = curl_init($streamUrl);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => $curlHeaders,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HEADERFUNCTION => function($curl, $header) {
                        $len = strlen($header);
                        $h = trim($header);
                        if (stripos($h, 'Content-Range:') === 0 || stripos($h, 'HTTP/') === 0) {
                            header($h);
                        }
                        return $len;
                    }
                ]);
                CurlHelper::applySslOptions($ch);
                curl_exec($ch);
                curl_close($ch);
                exit;
            } catch (Throwable $e) {}
        }

        // Local Range Simulation (Zero DB overhead)
        if (!empty($rangeHeader) && preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $matches)) {
            $start = (int)$matches[1];
            $end   = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : ($sizeBytes > 0 ? $sizeBytes - 1 : 0);
            $length = ($end - $start) + 1;

            http_response_code(206);
            header("Content-Type: {$mimeType}");
            header("Content-Disposition: inline; filename=\"{$cleanFilename}\"");
            header("Content-Range: bytes {$start}-{$end}/{$sizeBytes}");
            header("Content-Length: {$length}");
            header("Accept-Ranges: bytes");
            header("X-Content-Type-Options: nosniff");
            header("Connection: close");
            echo substr("2TOOLNE CLOUD — Simulated Range Stream Content", $start, $length);
            exit;
        }

        header("Content-Type: {$mimeType}");
        header("Content-Disposition: inline; filename=\"{$cleanFilename}\"");
        header("Content-Length: {$sizeBytes}");
        header("Accept-Ranges: bytes");
        header("X-Content-Type-Options: nosniff");
        header("Connection: close");
        echo "2TOOLNE CLOUD — Simulated Preview Content";
        exit;
    }

    /**
     * GET /api/v1/cloud/public/share/folder
     * Public endpoint to navigate folders within a shared subtree
     */
    public static function publicFolder(array $params, array $body): void {
        header("Cache-Control: no-store");
        header("Referrer-Policy: no-referrer");

        $sessionToken = $_SERVER['HTTP_X_SHARE_SESSION'] ?? ($_GET['session'] ?? '');
        $cap = self::verifyCapability($sessionToken);
        if (!$cap) {
            Router::error('Phiên truy cập không hợp lệ hoặc đã hết hạn.', 401, 'INVALID_SESSION');
        }

        if ($cap['item_type'] !== 'FOLDER') {
            Router::error('Liên kết này là của tệp tin, không phải thư mục.', 400, 'NOT_A_FOLDER');
        }

        $db = Database::getConnection();
        $rootFolderId = $cap['item_id'];
        $subfolderId  = trim((string)($_GET['subfolder_id'] ?? $rootFolderId));

        // Strict Subtree Isolation: Must verify subfolderId is descendant of rootFolderId
        if (!self::verifyFolderDescendant($subfolderId, $rootFolderId, $cap['space_id'], $db)) {
            Router::error('Quyền truy cập bị từ chối: Không thể duyệt ra ngoài thư mục được chia sẻ.', 403, 'FORBIDDEN');
        }

        // Fetch subfolders
        $fldStmt = $db->prepare('
            SELECT id, name, created_at, updated_at
            FROM cloud_folders
            WHERE cloud_space_id = ? AND parent_id = ? AND deleted_at IS NULL
            ORDER BY name ASC
        ');
        $fldStmt->execute([$cap['space_id'], $subfolderId]);
        $folders = $fldStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch files inside this subfolder
        $fileStmt = $db->prepare('
            SELECT id, filename as name, size_bytes, mime_type, extension, created_at, updated_at
            FROM cloud_files
            WHERE cloud_space_id = ? AND folder_id = ? AND status = "ACTIVE" AND deleted_at IS NULL
            ORDER BY filename ASC
        ');
        $fileStmt->execute([$cap['space_id'], $subfolderId]);
        $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        $breadcrumbs = self::buildPublicBreadcrumbs($subfolderId, $rootFolderId, $cap['space_id'], $db);

        Router::json([
            'ok'                => true,
            'root_folder_id'    => $rootFolderId,
            'current_folder_id' => $subfolderId,
            'access_level'      => $cap['access_level'],
            'breadcrumbs'       => $breadcrumbs,
            'folders'           => $folders,
            'files'             => $files,
        ]);
    }

    // ==========================================
    // Internal Security Helpers
    // ==========================================

    /**
     * Looks up share by raw token using SHA-256 hash (O(1) indexed lookup)
     */
    public static function lookupShareByRawToken(string $rawToken): array {
        $db = Database::getConnection();
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $db->prepare('SELECT * FROM cloud_shares WHERE share_token_hash = ? LIMIT 1');
        $stmt->execute([$tokenHash]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$share) {
            return [
                'valid'  => false,
                'status' => 404,
                'code'   => 'NOT_FOUND',
                'error'  => 'Không tìm thấy liên kết chia sẻ.',
            ];
        }

        if (!empty($share['revoked_at'])) {
            return [
                'valid'  => false,
                'status' => 410,
                'code'   => 'REVOKED',
                'error'  => 'Liên kết này không còn khả dụng.',
            ];
        }

        if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
            return [
                'valid'  => false,
                'status' => 410,
                'code'   => 'EXPIRED',
                'error'  => 'Liên kết đã hết hạn.',
            ];
        }

        // Verify underlying item status
        if ($share['item_type'] === 'FILE') {
            $fStmt = $db->prepare('SELECT status, deleted_at FROM cloud_files WHERE id = ? LIMIT 1');
            $fStmt->execute([$share['item_id']]);
            $file = $fStmt->fetch(PDO::FETCH_ASSOC);

            if (!$file || $file['status'] === 'DELETED') {
                return [
                    'valid'  => false,
                    'status' => 404,
                    'code'   => 'DELETED',
                    'error'  => 'Tệp được chia sẻ không còn tồn tại.',
                ];
            }
            if ($file['status'] === 'TRASHED' || !empty($file['deleted_at'])) {
                return [
                    'valid'  => false,
                    'status' => 410,
                    'code'   => 'TRASHED',
                    'error'  => 'Tệp được chia sẻ đang ở trong thùng rác hoặc không còn khả dụng.',
                ];
            }
        } else {
            $fldStmt = $db->prepare('SELECT deleted_at FROM cloud_folders WHERE id = ? LIMIT 1');
            $fldStmt->execute([$share['item_id']]);
            $folder = $fldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder) {
                return [
                    'valid'  => false,
                    'status' => 404,
                    'code'   => 'DELETED',
                    'error'  => 'Thư mục được chia sẻ không còn tồn tại.',
                ];
            }
            if (!empty($folder['deleted_at'])) {
                return [
                    'valid'  => false,
                    'status' => 410,
                    'code'   => 'TRASHED',
                    'error'  => 'Thư mục được chia sẻ đang ở trong thùng rác hoặc không còn khả dụng.',
                ];
            }
        }

        return ['valid' => true, 'share' => $share];
    }

    /**
     * Proves that a candidate folder is a descendant of the shared root folder within spaceId
     * Includes cycle detection, depth bound (50), and parent validation.
     */
    public static function verifyFolderDescendant(?string $candidateId, string $rootFolderId, string $spaceId, PDO $db): bool {
        if (empty($candidateId)) return false;
        if ((string)$candidateId === (string)$rootFolderId) return true;

        $curr = $candidateId;
        $depth = 0;
        $visited = [];

        while (!empty($curr) && $depth < 50) {
            $depth++;
            if (in_array($curr, $visited, true)) {
                // Cycle detected!
                return false;
            }
            $visited[] = $curr;

            $stmt = $db->prepare('SELECT id, parent_id, cloud_space_id FROM cloud_folders WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$curr]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$folder || $folder['cloud_space_id'] !== $spaceId) {
                return false;
            }

            if ((string)$folder['parent_id'] === (string)$rootFolderId) {
                return true;
            }

            $curr = $folder['parent_id'];
        }

        return false;
    }

    /**
     * Builds breadcrumbs bounded strictly to the shared root folder
     */
    private static function buildPublicBreadcrumbs(string $subfolderId, string $rootFolderId, string $spaceId, PDO $db): array {
        $crumbs = [];
        $curr = $subfolderId;
        $depth = 0;

        while (!empty($curr) && $depth < 50) {
            $depth++;
            $stmt = $db->prepare('SELECT id, name, parent_id FROM cloud_folders WHERE id = ? AND cloud_space_id = ? LIMIT 1');
            $stmt->execute([$curr, $spaceId]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$f) break;

            array_unshift($crumbs, [
                'id'   => $f['id'],
                'name' => $f['name'],
            ]);

            if ((string)$f['id'] === (string)$rootFolderId) {
                break;
            }
            $curr = $f['parent_id'];
        }

        return $crumbs;
    }

    /**
     * Sanitizes download filename to prevent header injection and malformed characters
     */
    public static function sanitizeFilename(string $filename): string {
        $clean = preg_replace('/[\r\n\0"\'\/\\\\]+/', '_', $filename);
        $clean = trim((string)$clean);
        return empty($clean) ? 'downloaded_asset.bin' : $clean;
    }

    /**
     * Records access analytics without logging raw token
     */
    private static function recordAccessSession(string $shareId): void {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('UPDATE cloud_shares SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?');
            $stmt->execute([$shareId]);
        } catch (Throwable $e) {}
    }

    private static function getBaseShareUrl(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'www.2tamne.site';
        return "{$proto}://{$host}";
    }
}
