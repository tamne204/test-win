<?php
/**
 * 2TOOLNE UPSCALE — DEVICE ACTIVATION CONTROLLER
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class DeviceController {
    public static function activate(array $params, array $body): void {
        $userId = $body['user_id'] ?? '';
        $deviceFingerprint = trim($body['device_id'] ?? $body['device_fingerprint'] ?? '');
        $deviceAlias = trim($body['device_alias'] ?? 'Desktop PC');
        $platform = $body['platform'] ?? 'windows-x64';

        if (empty($userId) || empty($deviceFingerprint)) {
            Router::error('user_id and device_id are required', 400);
        }

        $db = Database::getConnection();

        // Get license entitlement
        $stmt = $db->prepare('SELECT * FROM license_entitlements WHERE user_id = ?');
        $stmt->execute([$userId]);
        $license = $stmt->fetch();

        if (!$license) {
            Router::error('No active license found for this user', 403, 'LICENSE_REQUIRED');
        }

        // Check if device already active
        $stmt = $db->prepare('SELECT id, status FROM devices WHERE user_id = ? AND device_fingerprint = ?');
        $stmt->execute([$userId, $deviceFingerprint]);
        $existingDevice = $stmt->fetch();

        if ($existingDevice) {
            // Update alias and last seen
            $stmt = $db->prepare('
                UPDATE devices
                SET status = "ACTIVE", device_alias = ?, last_seen_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([$deviceAlias, $existingDevice['id']]);

            Router::json([
                'success' => true,
                'device_id' => $existingDevice['id'],
                'status' => 'ACTIVE',
                'message' => 'Device already activated and reactivated successfully.',
            ]);
            return;
        }

        // Check active device count
        $stmt = $db->prepare('SELECT COUNT(*) as active_count FROM devices WHERE user_id = ? AND status = "ACTIVE"');
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetch()['active_count'];

        $maxAllowed = (int)$license['max_devices'];
        if ($count >= $maxAllowed) {
            Router::error("Device limit reached ({$maxAllowed} devices allowed on this plan). Please deactivate an old device first.", 403, 'DEVICE_LIMIT_EXCEEDED');
        }

        // Register new device
        $deviceId = 'dev_' . bin2hex(random_bytes(12));
        $stmt = $db->prepare('
            INSERT INTO devices (id, user_id, device_fingerprint, device_alias, platform, status, activated_at)
            VALUES (?, ?, ?, ?, ?, "ACTIVE", CURRENT_TIMESTAMP)
        ');
        $stmt->execute([$deviceId, $userId, $deviceFingerprint, $deviceAlias, $platform]);

        Router::json([
            'success' => true,
            'device_id' => $deviceId,
            'status' => 'ACTIVE',
            'message' => 'Device successfully activated.',
        ], 201);
    }

    public static function deactivate(array $params, array $body): void {
        $userId = $body['user_id'] ?? '';
        $deviceId = $body['device_id'] ?? '';

        if (empty($userId) || empty($deviceId)) {
            Router::error('user_id and device_id are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE devices SET status = "REVOKED" WHERE id = ? AND user_id = ?');
        $stmt->execute([$deviceId, $userId]);

        Router::json([
            'success' => true,
            'message' => 'Device deactivated successfully.',
        ]);
    }

    public static function status(array $params, array $body): void {
        $deviceId = $_GET['device_id'] ?? $body['device_id'] ?? '';

        if (empty($deviceId)) {
            Router::error('device_id is required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT d.*, u.email, l.plan, l.credit_mode, l.expires_at, w.balance as token_balance
            FROM devices d
            JOIN users u ON d.user_id = u.id
            LEFT JOIN license_entitlements l ON u.id = l.user_id
            LEFT JOIN credit_wallets w ON u.id = w.user_id
            WHERE (d.device_fingerprint = ? OR d.id = ?) AND d.status = "ACTIVE"
        ');
        $stmt->execute([$deviceId, $deviceId]);
        $row = $stmt->fetch();

        if (!$row) {
            // Unregistered or offline default
            Router::json([
                'is_logged_in' => false,
                'token_balance' => 0,
                'credit_mode' => 'METERED',
                'device_id' => $deviceId,
            ]);
            return;
        }

        Router::json([
            'is_logged_in' => true,
            'user_id' => $row['user_id'],
            'email' => $row['email'],
            'plan' => $row['plan'] ?? 'PRO',
            'credit_mode' => $row['credit_mode'] ?? 'METERED',
            'token_balance' => (int)($row['token_balance'] ?? 0),
            'expires_at' => $row['expires_at'],
            'device_alias' => $row['device_alias'],
        ]);
    }
}
