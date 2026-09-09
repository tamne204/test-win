<?php
/**
 * 2TOOLNE UPSCALE — CRYPTOGRAPHIC LICENSE GENERATOR & VALIDATOR
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class LicenseController {
    /**
     * Issues an HMAC-signed license token for desktop offline verification
     */
    public static function issue(array $params, array $body): void {
        $deviceId = $body['device_id'] ?? '';
        $userId = $body['user_id'] ?? '';

        if (empty($deviceId) || empty($userId)) {
            Router::error('device_id and user_id are required', 400);
        }

        $db = Database::getConnection();
        $fpHash = hash('sha256', $deviceId);
        $stmt = $db->prepare('
            SELECT u.id as user_id, u.email, l.plan, l.credit_mode, l.max_devices, l.expires_at, w.balance
            FROM users u
            JOIN devices d ON u.id = d.user_id
            JOIN license_entitlements l ON u.id = l.user_id
            JOIN credit_wallets w ON u.id = w.user_id
            WHERE u.id = ? AND (d.device_fingerprint_hash = ? OR d.device_fingerprint = ? OR d.id = ?) AND d.status = "ACTIVE"
        ');
        $stmt->execute([$userId, $fpHash, $deviceId, $deviceId]);
        $data = $stmt->fetch();

        if (!$data) {
            Router::error('Active device authorization required', 403, 'DEVICE_UNAUTHORIZED');
        }

        $now = time();
        $graceExpiry = $now + (OFFLINE_GRACE_HOURS * 3600);

        $payload = [
            'user_id' => $data['user_id'],
            'email' => $data['email'],
            'plan' => $data['plan'],
            'device_id' => $deviceId,
            'credit_mode' => $data['credit_mode'],
            'token_balance' => (int)$data['balance'],
            'issued_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $graceExpiry),
        ];

        // Sign payload with HMAC-SHA256
        $serialized = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $serialized, HMAC_LICENSE_SECRET);
        $payload['signature'] = $signature;

        Router::json([
            'success' => true,
            'license' => $payload,
        ]);
    }

    /**
     * Validates an HMAC signature offline
     */
    public static function verifySignature(array $payload): bool {
        if (!isset($payload['signature'])) return false;
        $signature = $payload['signature'];
        unset($payload['signature']);

        $serialized = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $expectedSignature = hash_hmac('sha256', $serialized, HMAC_LICENSE_SECRET);

        return hash_equals($expectedSignature, $signature);
    }
}
