<?php
/**
 * 2TOOLNE CLOUD — CLOUD AUTHENTICATION & ACCESS CONTROL HELPER
 * Resolves active user session or API token and validates Cloud Space membership
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class CloudAuthHelper {
    /**
     * Resolve the current authenticated user from Session, Bearer Token, or Device HWID
     */
    public static function getCurrentUser(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $db = Database::getConnection();

        // 1. Check Web Session (Customer)
        if (!empty($_SESSION['user'])) {
            $usernameOrId = $_SESSION['user'];
            $stmt = $db->prepare('SELECT id, username, email, fullname, role FROM users WHERE username = ? OR id = ? LIMIT 1');
            $stmt->execute([$usernameOrId, $usernameOrId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $u['is_admin'] = (!empty($_SESSION['admin_logged']) || in_array($u['role'], ['admin', 'super_admin'], true));
                return $u;
            }
        }

        // 2. Check Admin Session
        if (!empty($_SESSION['admin_logged']) && !empty($_SESSION['admin_user'])) {
            return [
                'id'        => 'admin_system',
                'username'  => $_SESSION['admin_user'],
                'email'     => 'admin@2tamne.site',
                'fullname'  => $_SESSION['admin_fullname'] ?? 'System Administrator',
                'role'      => 'admin',
                'is_admin'  => true,
            ];
        }

        // 3. Check HTTP Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
            // Decode or verify token (or match app_auth_sessions)
            $stmt = $db->prepare('SELECT user_id FROM app_auth_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1');
            $stmt->execute([$token]);
            $sess = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sess) {
                $uStmt = $db->prepare('SELECT id, username, email, fullname, role FROM users WHERE id = ? LIMIT 1');
                $uStmt->execute([$sess['user_id']]);
                $u = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $u['is_admin'] = in_array($u['role'], ['admin', 'super_admin'], true);
                    return $u;
                }
            }
        }

        // 4. Check X-Device-Id (Desktop App)
        $deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        if (!empty($deviceId)) {
            $stmt = $db->prepare('
                SELECT u.id, u.username, u.email, u.fullname, u.role
                FROM devices d
                JOIN users u ON d.user_id = u.id
                WHERE (d.device_fingerprint = ? OR d.id = ?) AND d.status = "ACTIVE"
                LIMIT 1
            ');
            $stmt->execute([$deviceId, $deviceId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $u['is_admin'] = in_array($u['role'], ['admin', 'super_admin'], true);
                return $u;
            }
        }

        // 5. Fallback for Local Testing / Direct Header X-User-Id
        $directUserId = $_SERVER['HTTP_X_USER_ID'] ?? null;
        if ($directUserId) {
            $stmt = $db->prepare('SELECT id, username, email, fullname, role FROM users WHERE id = ? OR username = ? LIMIT 1');
            $stmt->execute([$directUserId, $directUserId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $u['is_admin'] = in_array($u['role'], ['admin', 'super_admin'], true);
                return $u;
            }
        }

        return null;
    }

    /**
     * Check if current user is an admin
     */
    public static function isAdmin(): bool {
        $user = self::getCurrentUser();
        return $user && !empty($user['is_admin']);
    }

    /**
     * Validate whether a user has read/write permission to a Cloud Space
     *
     * @param string $spaceId Target Cloud Space ID
     * @param string $userId User ID to check
     * @return array ['allowed' => bool, 'role' => 'OWNER'|'ADMIN'|'MEMBER'|'DENIED', 'space' => array]
     */
    public static function authorizeSpaceAccess(string $spaceId, string $userId): array {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT * FROM cloud_spaces WHERE id = ? LIMIT 1');
        $stmt->execute([$spaceId]);
        $space = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$space) {
            return ['allowed' => false, 'role' => 'DENIED', 'space' => null];
        }

        // 1. Personal Space: user must be the owner
        if ($space['owner_type'] === 'USER') {
            if ((string)$space['owner_id'] === (string)$userId) {
                return ['allowed' => true, 'role' => 'OWNER', 'space' => $space];
            }
        }

        // 2. Team Space: user must be an active member
        if ($space['owner_type'] === 'TEAM') {
            $tmStmt = $db->prepare('
                SELECT role FROM team_members
                WHERE team_id = ? AND user_id = ? AND status = "ACTIVE"
                LIMIT 1
            ');
            $tmStmt->execute([$space['owner_id'], $userId]);
            $member = $tmStmt->fetch(PDO::FETCH_ASSOC);
            if ($member) {
                return ['allowed' => true, 'role' => $member['role'], 'space' => $space];
            }
        }

        // 3. Super Admin bypass
        $uStmt = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($u && in_array($u['role'], ['admin', 'super_admin'], true)) {
            return ['allowed' => true, 'role' => 'ADMIN', 'space' => $space];
        }

        return ['allowed' => false, 'role' => 'DENIED', 'space' => $space];
    }
}
