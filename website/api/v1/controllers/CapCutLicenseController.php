<?php
/**
 * 2TOOLNE AUTOEDIT FOR CAPCUT V2 — COMMERCIAL LICENSE CONTROLLER
 * Authoritative Server Controller for License Activation, Verification & Device Management.
 * Implements Ed25519 Asymmetric Digital Signing for Offline Entitlements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../../../storage/db.php';

class CapCutLicenseController {
    // Current Signing Key ID & Default Offline Grace (72 hours)
    private const KEY_ID = 'kid_2026_01';
    private const OFFLINE_GRACE_SECONDS = 259200; // 72 hours (3 days)
    private const DEFAULT_MAX_DEVICES = 3;
    private const TARGET_PRODUCT_ID = '2toolne.capcut.v2';

    // Ed25519 Server Private Signing Key Seed (Base64) - Kept strictly server-side
    // In production, override via getenv('CAPCUT_ED25519_PRIVATE_KEY')
    private static function getSigningSeed(): string {
        $env = getenv('CAPCUT_ED25519_PRIVATE_KEY');
        if (!empty($env)) {
            return base64_decode($env);
        }
        // Authoritative seed for kid_2026_01
        return base64_decode('wXJ/7EIw8tlvSZkEOpBotl3WtrlkFKMBLq1FgorXBgE=');
    }

    /**
     * Server-only pepper for O(1) HMAC-SHA256 lookup hash
     */
    private static function getLookupPepper(): string {
        return db_get_capcut_lookup_pepper();
    }

    /**
     * Rate limiting storage directory
     */
    private static function getRateLimitDir(): string {
        $tmpDir = sys_get_temp_dir() . '/2toolne_ratelimit';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        return $tmpDir;
    }

    /**
     * Multidimensional rate limiting across:
     * - IP rate limit (20 req / 60s)
     * - IP + Device rate limit (10 req / 60s)
     * - Failed lockout: after 5 failed activation attempts within 15 minutes,
     *   lock out for 15 minutes (900 seconds).
     */
    private static function enforceRateLimits(string $action, string $ip, string $deviceId = ''): void {
        $now = time();
        $dir = self::getRateLimitDir();

        // 1. Check failed attempts lockout (15 minutes window = 900s)
        $lockoutHash = hash('sha256', "lockout:{$ip}:" . ($deviceId ?: 'nodev'));
        $lockoutFile = "{$dir}/lock_{$lockoutHash}.json";
        if (file_exists($lockoutFile)) {
            $lockData = json_decode((string)@file_get_contents($lockoutFile), true);
            if (is_array($lockData) && isset($lockData['failures'], $lockData['locked_until'])) {
                if ($now < $lockData['locked_until']) {
                    $remain = $lockData['locked_until'] - $now;
                    Router::error("Quá nhiều lần thử thất bại. Tạm thời khóa {$remain} giây.", 429, 'RATE_LIMIT_EXCEEDED');
                }
            }
        }

        // 2. IP sliding window limit (20 req / 60s)
        self::checkSlidingWindow("ip:{$action}:{$ip}", 60, 20);

        // 3. IP + Device sliding window limit (10 req / 60s)
        if (!empty($deviceId)) {
            self::checkSlidingWindow("dev:{$action}:{$ip}:{$deviceId}", 60, 10);
        }
    }

    private static function checkSlidingWindow(string $key, int $windowSeconds, int $maxRequests): void {
        $now = time();
        $dir = self::getRateLimitDir();
        $hash = hash('sha256', $key);
        $file = "{$dir}/rl_{$hash}.json";

        $data = ['count' => 0, 'start' => $now];
        if (file_exists($file)) {
            $parsed = json_decode((string)@file_get_contents($file), true);
            if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
                if ($now - $parsed['start'] < $windowSeconds) {
                    $data = $parsed;
                }
            }
        }

        $data['count']++;
        if ($data['count'] > $maxRequests) {
            Router::error('Quá nhiều yêu cầu. Vui lòng thử lại sau 1 phút.', 429, 'RATE_LIMIT_EXCEEDED');
        }
        @file_put_contents($file, json_encode($data));
    }

    private static function recordFailedAttempt(string $ip, string $deviceId = ''): void {
        $now = time();
        $dir = self::getRateLimitDir();
        $lockoutHash = hash('sha256', "lockout:{$ip}:" . ($deviceId ?: 'nodev'));
        $lockoutFile = "{$dir}/lock_{$lockoutHash}.json";

        $data = ['failures' => 0, 'locked_until' => 0, 'first_fail' => $now];
        if (file_exists($lockoutFile)) {
            $parsed = json_decode((string)@file_get_contents($lockoutFile), true);
            if (is_array($parsed) && isset($parsed['failures'], $parsed['first_fail'])) {
                if ($now - $parsed['first_fail'] < 900) {
                    $data = $parsed;
                }
            }
        }

        $data['failures']++;
        if ($data['failures'] >= 5) {
            $data['locked_until'] = $now + 900; // Lock for 15 minutes
        }
        @file_put_contents($lockoutFile, json_encode($data));
    }

    private static function clearFailedAttempts(string $ip, string $deviceId = ''): void {
        $dir = self::getRateLimitDir();
        $lockoutHash = hash('sha256', "lockout:{$ip}:" . ($deviceId ?: 'nodev'));
        $lockoutFile = "{$dir}/lock_{$lockoutHash}.json";
        if (file_exists($lockoutFile)) {
            @unlink($lockoutFile);
        }
    }

    /**
     * Redact key for safe response/logging (2TL-CAP-****-****-AB12)
     */
    private static function maskKey(string $key): string {
        $parts = explode('-', trim($key));
        if (count($parts) >= 4) {
            return $parts[0] . '-' . $parts[1] . '-****-****-' . end($parts);
        }
        if (strlen($key) > 8) {
            return substr($key, 0, 4) . '****' . substr($key, -4);
        }
        return '****';
    }

    /**
     * POST /api/v1/capcut/activate
     */
    public static function activate(array $params, array $body): void {
        $licenseKey = trim($body['license_key'] ?? '');
        $deviceId = trim($body['device_id'] ?? '');
        $platform = trim($body['platform'] ?? 'mac-arm64');
        $appVersion = trim($body['app_version'] ?? '2.0.0');

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Multidimensional Rate Limiting
        self::enforceRateLimits('activate', $clientIp, $deviceId);

        if (empty($licenseKey) || empty($deviceId)) {
            Router::error('Mã bản quyền và mã thiết bị là bắt buộc.', 400, 'MISSING_PARAMETERS');
        }

        $cleanKey = strtoupper($licenseKey);
        $pepper = self::getLookupPepper();
        $lookupHash = hash_hmac('sha256', $cleanKey, $pepper);

        $db = Database::getConnection();
        db_ensure_capcut_license_columns();

        // 2. Fast O(1) Lookup by key_lookup_hash
        $stmt = $db->prepare('SELECT * FROM `licenses` WHERE `key_lookup_hash` = ? LIMIT 1');
        $stmt->execute([$lookupHash]);
        $lic = $stmt->fetch();
        $stmt->closeCursor();

        // Lazy migration for legacy plaintext keys
        if (!$lic) {
            $stmtLegacy = $db->prepare('SELECT * FROM `licenses` WHERE `license_key` = ? LIMIT 1');
            $stmtLegacy->execute([$cleanKey]);
            $legacyLic = $stmtLegacy->fetch();
            $stmtLegacy->closeCursor();

            $legProd = $legacyLic['product'] ?? '';
            if ($legacyLic && ($legProd === self::TARGET_PRODUCT_ID || $legProd === 'CAPCUT_V2' || $legProd === 'ALL_ACCESS' || $legProd === '2TOOLNE')) {
                // Lazily upgrade legacy plaintext record to hardened hashes
                $last4 = substr($cleanKey, -4);
                $licId = !empty($legacyLic['license_id']) ? $legacyLic['license_id'] : ('lic_' . bin2hex(random_bytes(10)));
                $secHash = password_hash($cleanKey, PASSWORD_BCRYPT, ['cost' => 10]);

                $upLegacy = $db->prepare('
                    UPDATE `licenses` SET
                        `key_lookup_hash` = ?,
                        `key_secret_hash` = ?,
                        `key_last4` = ?,
                        `license_id` = ?
                    WHERE `id` = ?
                ');
                $upLegacy->execute([$lookupHash, $secHash, $last4, $licId, $legacyLic['id']]);
                $upLegacy->closeCursor();

                // Re-fetch upgraded record
                $stmtRe = $db->prepare('SELECT * FROM `licenses` WHERE `key_lookup_hash` = ? LIMIT 1');
                $stmtRe->execute([$lookupHash]);
                $lic = $stmtRe->fetch();
                $stmtRe->closeCursor();
            }
        }

        // Generic failure: do not leak whether key exists or what field failed
        if (!$lic) {
            self::recordFailedAttempt($clientIp, $deviceId);
            Router::error('Mã bản quyền không hợp lệ hoặc đã hết hạn.', 400, 'LICENSE_INVALID');
        }

        // 3. Cryptographic Verification via key_secret_hash (Bcrypt)
        if (!empty($lic['key_secret_hash'])) {
            if (!password_verify($cleanKey, $lic['key_secret_hash'])) {
                self::recordFailedAttempt($clientIp, $deviceId);
                Router::error('Mã bản quyền không hợp lệ hoặc đã hết hạn.', 400, 'LICENSE_INVALID');
            }
        }

        // 4. Check product entitlement
        $product = $lic['product'] ?? '';
        if ($product !== self::TARGET_PRODUCT_ID && $product !== 'CAPCUT_V2' && $product !== 'ALL_ACCESS' && $product !== '2TOOLNE') {
            self::recordFailedAttempt($clientIp, $deviceId);
            Router::error('Mã bản quyền không hợp lệ hoặc đã hết hạn.', 400, 'LICENSE_INVALID');
        }

        // 5. Check status & revocation
        if (($lic['status'] ?? '') === 'banned' || ($lic['status'] ?? '') === 'revoked') {
            self::recordFailedAttempt($clientIp, $deviceId);
            Router::error('Mã bản quyền đã bị vô hiệu hóa hoặc thu hồi.', 403, 'LICENSE_INVALID');
        }

        $now = time();
        $durationDays = intval($lic['duration_days'] ?? 30);
        $isLifetime = ($durationDays <= 0 || $durationDays >= 9999);

        // 6. Calculate expiration and update HWID / Device binding in licenses table
        if (empty($lic['activated_at'])) {
            $activatedAt = date('Y-m-d H:i:s', $now);
            $expiresAt = $isLifetime ? '2099-12-31 23:59:59' : date('Y-m-d H:i:s', $now + ($durationDays * 86400));

            $up = $db->prepare('
                UPDATE `licenses` SET
                    `activated_at` = ?,
                    `expires_at` = ?,
                    `status` = "active",
                    `tool_version` = ?,
                    `hwid` = ?,
                    `device_name` = ?
                WHERE `id` = ?
            ');
            $up->execute([$activatedAt, $expiresAt, $appVersion, $deviceId, $platform, $lic['id']]);
        } else {
            $expiresAt = $lic['expires_at'];
            $up = $db->prepare('
                UPDATE `licenses` SET
                    `status` = "active",
                    `tool_version` = ?,
                    `hwid` = ?,
                    `device_name` = ?
                WHERE `id` = ?
            ');
            $up->execute([$appVersion, $deviceId, $platform, $lic['id']]);
        }

        $expireTs = strtotime($expiresAt);
        if (!$isLifetime && $now > $expireTs) {
            self::recordFailedAttempt($clientIp, $deviceId);
            Router::error('Mã bản quyền không hợp lệ hoặc đã hết hạn.', 403, 'LICENSE_INVALID');
        }

        // 7. Check Device Limits in `devices` table
        $userId = !empty($lic['owner_username']) ? $lic['owner_username'] : ('usr_' . substr($lic['license_id'] ?: hash('sha256', $cleanKey), 0, 16));

        $devStmt = $db->prepare('SELECT * FROM `devices` WHERE `user_id` = ? AND `device_fingerprint` = ? LIMIT 1');
        $devStmt->execute([$userId, $deviceId]);
        $existingDevice = $devStmt->fetch();

        if (!$existingDevice) {
            $countStmt = $db->prepare('SELECT COUNT(*) as active_count FROM `devices` WHERE `user_id` = ? AND `status` = "ACTIVE"');
            $countStmt->execute([$userId]);
            $activeCount = (int)$countStmt->fetch()['active_count'];

            $maxAllowed = self::DEFAULT_MAX_DEVICES;
            if ($activeCount >= $maxAllowed) {
                Router::error(
                    "Đã đạt giới hạn tối đa {$maxAllowed} thiết bị kích hoạt. Vui lòng hủy kích hoạt thiết bị cũ trước.",
                    403,
                    'LICENSE_DEVICE_LIMIT'
                );
            }

            $devId = 'dev_' . bin2hex(random_bytes(12));
            $insDev = $db->prepare('
                INSERT INTO `devices` (`id`, `user_id`, `device_fingerprint`, `device_alias`, `platform`, `status`, `activated_at`, `last_seen_at`)
                VALUES (?, ?, ?, ?, ?, "ACTIVE", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ');
            $insDev->execute([$devId, $userId, $deviceId, $platform, $platform]);
        } else {
            $touch = $db->prepare('UPDATE `devices` SET `status` = "ACTIVE", `last_seen_at` = CURRENT_TIMESTAMP WHERE `id` = ?');
            $touch->execute([$existingDevice['id']]);
        }

        // Clear failed attempts upon successful activation
        self::clearFailedAttempts($clientIp, $deviceId);

        // 8. Generate Asymmetric Ed25519 Signed Entitlement Envelope
        $offlineUntil = $isLifetime ? ($now + self::OFFLINE_GRACE_SECONDS) : min($expireTs, $now + self::OFFLINE_GRACE_SECONDS);
        $licenseId = !empty($lic['license_id']) ? $lic['license_id'] : ('lic_' . substr(hash('sha256', $cleanKey), 0, 16));
        $last4 = !empty($lic['key_last4']) ? $lic['key_last4'] : substr($cleanKey, -4);
        $maskedKey = "2TL-CAP-****-****-{$last4}";

        $payload = [
            'license_id'    => $licenseId,
            'user_id'       => $userId,
            'product_id'    => self::TARGET_PRODUCT_ID,
            'device_id'     => $deviceId,
            'plan'          => $lic['tier'] ?? 'PRO',
            'issued_at'     => $now,
            'expires_at'    => $isLifetime ? 4102444799 : $expireTs,
            'offline_until' => $offlineUntil,
            'features'      => ['capcut_autoedit', 'unlimited_export', 'all_presets', 'script_to_srt']
        ];

        $signedEnvelope = self::signPayload($payload);

        // Audit log for activation
        db_log_admin_audit('CAPCUT_ACTIVATION_SUCCESS', $userId, 'Client successfully activated CapCut V2 license', [
            'license_id' => $licenseId,
            'device_id'  => $deviceId,
            'platform'   => $platform,
            'ip'         => $clientIp
        ]);

        Router::json([
            'success'             => true,
            'status'              => 'LICENSE_ACTIVE',
            'message'             => 'Kích hoạt bản quyền thành công!',
            'masked_key'          => $maskedKey,
            'license_key_last4'   => $last4,
            'signed_entitlement'  => $signedEnvelope,
            'trusted_server_time' => $now,
        ], 200);
    }

    /**
     * POST /api/v1/capcut/verify
     */
    public static function verify(array $params, array $body): void {
        $licenseKey = trim($body['license_key'] ?? '');
        $licenseId = trim($body['license_id'] ?? '');
        $deviceId = trim($body['device_id'] ?? '');

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        self::enforceRateLimits('verify', $clientIp, $deviceId);

        if (empty($deviceId) || (empty($licenseKey) && empty($licenseId))) {
            Router::error('Thiếu thông tin xác thực bản quyền hoặc thiết bị.', 400, 'MISSING_PARAMETERS');
        }

        $db = Database::getConnection();
        db_ensure_capcut_license_columns();

        $lic = null;
        if (!empty($licenseId)) {
            $stmt = $db->prepare('SELECT * FROM `licenses` WHERE `license_id` = ? LIMIT 1');
            $stmt->execute([$licenseId]);
            $lic = $stmt->fetch();
        }

        if (!$lic && !empty($licenseKey)) {
            $cleanKey = strtoupper($licenseKey);
            $lookupHash = hash_hmac('sha256', $cleanKey, self::getLookupPepper());
            $stmt = $db->prepare('SELECT * FROM `licenses` WHERE `key_lookup_hash` = ? LIMIT 1');
            $stmt->execute([$lookupHash]);
            $lic = $stmt->fetch();

            if (!$lic) {
                $stmtLegacy = $db->prepare('SELECT * FROM `licenses` WHERE `license_key` = ? LIMIT 1');
                $stmtLegacy->execute([$cleanKey]);
                $lic = $stmtLegacy->fetch();
            }
        }

        if (!$lic) {
            Router::error('Bản quyền không hợp lệ hoặc không tồn tại.', 404, 'LICENSE_NOT_FOUND');
        }

        if (($lic['status'] ?? '') === 'banned' || ($lic['status'] ?? '') === 'revoked') {
            Router::error('Bản quyền đã bị thu hồi.', 403, 'LICENSE_REVOKED');
        }

        $now = time();
        $expireTs = strtotime($lic['expires_at'] ?? '2099-12-31');
        $durationDays = intval($lic['duration_days'] ?? 30);
        $isLifetime = ($durationDays <= 0 || $durationDays >= 9999);

        if (!$isLifetime && $now > $expireTs) {
            Router::error('Bản quyền đã hết hạn.', 403, 'LICENSE_EXPIRED');
        }

        // Check device status
        $userId = !empty($lic['owner_username']) ? $lic['owner_username'] : ('usr_' . substr($lic['license_id'] ?: 'default', 0, 16));
        $devStmt = $db->prepare('SELECT status FROM `devices` WHERE `user_id` = ? AND `device_fingerprint` = ? LIMIT 1');
        $devStmt->execute([$userId, $deviceId]);
        $dev = $devStmt->fetch();

        if (!$dev || $dev['status'] !== 'ACTIVE') {
            Router::error('Thiết bị này không có quyền truy cập hoặc đã bị hủy kích hoạt.', 403, 'LICENSE_DEVICE_MISMATCH');
        }

        // Refresh offline window
        $offlineUntil = $isLifetime ? ($now + self::OFFLINE_GRACE_SECONDS) : min($expireTs, $now + self::OFFLINE_GRACE_SECONDS);
        $licId = !empty($lic['license_id']) ? $lic['license_id'] : ('lic_' . substr(hash('sha256', (string)$lic['id']), 0, 16));

        $payload = [
            'license_id'    => $licId,
            'user_id'       => $userId,
            'product_id'    => self::TARGET_PRODUCT_ID,
            'device_id'     => $deviceId,
            'plan'          => $lic['tier'] ?? 'PRO',
            'issued_at'     => $now,
            'expires_at'    => $isLifetime ? 4102444799 : $expireTs,
            'offline_until' => $offlineUntil,
            'features'      => ['capcut_autoedit', 'unlimited_export', 'all_presets', 'script_to_srt']
        ];

        $signedEnvelope = self::signPayload($payload);

        Router::json([
            'success'             => true,
            'status'              => 'LICENSE_ACTIVE',
            'signed_entitlement'  => $signedEnvelope,
            'trusted_server_time' => $now,
        ], 200);
    }

    /**
     * POST /api/v1/capcut/deactivate
     */
    public static function deactivate(array $params, array $body): void {
        $licenseKey = trim($body['license_key'] ?? '');
        $licenseId = trim($body['license_id'] ?? '');
        $deviceId = trim($body['device_id'] ?? '');

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        self::enforceRateLimits('deactivate', $clientIp, $deviceId);

        if (empty($deviceId) && empty($licenseKey) && empty($licenseId)) {
            Router::error('device_id and either license_key or license_id are required.', 400, 'MISSING_PARAMETERS');
        }

        $db = Database::getConnection();
        db_ensure_capcut_license_columns();

        $userId = null;
        if (!empty($licenseId)) {
            $stmt = $db->prepare('SELECT owner_username FROM `licenses` WHERE `license_id` = ? LIMIT 1');
            $stmt->execute([$licenseId]);
            $lic = $stmt->fetch();
            if ($lic && !empty($lic['owner_username'])) {
                $userId = $lic['owner_username'];
            }
        } elseif (!empty($licenseKey)) {
            $cleanKey = strtoupper($licenseKey);
            $lookupHash = hash_hmac('sha256', $cleanKey, self::getLookupPepper());
            $stmt = $db->prepare('SELECT owner_username FROM `licenses` WHERE `key_lookup_hash` = ? LIMIT 1');
            $stmt->execute([$lookupHash]);
            $lic = $stmt->fetch();
            if ($lic && !empty($lic['owner_username'])) {
                $userId = $lic['owner_username'];
            }
        }

        if ($userId && !empty($deviceId)) {
            $up = $db->prepare('UPDATE `devices` SET `status` = "REVOKED" WHERE `user_id` = ? AND `device_fingerprint` = ?');
            $up->execute([$userId, $deviceId]);
            $upLic = $db->prepare('UPDATE `licenses` SET `hwid` = NULL, `device_name` = NULL WHERE `owner_username` = ? AND `hwid` = ?');
            $upLic->execute([$userId, $deviceId]);
        } elseif (!empty($deviceId)) {
            $up = $db->prepare('UPDATE `devices` SET `status` = "REVOKED" WHERE `device_fingerprint` = ?');
            $up->execute([$deviceId]);
            $upLic = $db->prepare('UPDATE `licenses` SET `hwid` = NULL, `device_name` = NULL WHERE `hwid` = ?');
            $upLic->execute([$deviceId]);
        }

        db_log_admin_audit('CAPCUT_DEACTIVATION_SUCCESS', $userId ?: $deviceId, 'Device successfully deactivated', [
            'device_id'  => $deviceId,
            'license_id' => $licenseId,
            'ip'         => $clientIp
        ]);

        Router::json([
            'success' => true,
            'message' => 'Hủy kích hoạt thiết bị thành công.',
        ]);
    }

    /**
     * Digitally sign payload using Ed25519 (Sodium / OpenSSL compatible)
     */
    private static function signPayload(array $payload): array {
        // Canonical JSON serialization: sorted keys, compact separators
        ksort($payload);
        $canonicalJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $seed = self::getSigningSeed();
        $signatureB64 = '';

        if (function_exists('sodium_crypto_sign_seed_keypair')) {
            $keypair = sodium_crypto_sign_seed_keypair($seed);
            $secretKey = sodium_crypto_sign_secretkey($keypair);
            $sig = sodium_crypto_sign_detached($canonicalJson, $secretKey);
            $signatureB64 = base64_encode($sig);
        } else {
            // Fallback for environments without sodium
            $sig = hash_hmac('sha256', $canonicalJson, $seed, true);
            $signatureB64 = base64_encode(str_pad($sig, 64, "\0"));
        }

        return [
            'token_version' => 1,
            'kid'           => self::KEY_ID,
            'payload'       => $payload,
            'signature'     => $signatureB64
        ];
    }
}
