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

        // 3. Check HTTP Authorization: Bearer <token> or X-API-Key
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $rawAiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($rawAiKey) && preg_match('/Bearer\s+(2tl_ai_\S+)/i', $authHeader, $aiMatches)) {
            $rawAiKey = $aiMatches[1];
        }
        if (!empty($rawAiKey)) {
            $aiAuth = self::authenticateAiKey($rawAiKey);
            if ($aiAuth) {
                return $aiAuth;
            }
        }

        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $stmt = $db->prepare('SELECT user_id FROM app_auth_sessions WHERE (token = ? OR refresh_token = ? OR code = ? OR id = ?) AND expires_at > NOW() LIMIT 1');
                $stmt->execute([$token, $token, $token, $token]);
                $sess = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sess && !empty($sess['user_id'])) {
                    $uStmt = $db->prepare('SELECT id, username, email, fullname, role FROM users WHERE id = ? LIMIT 1');
                    $uStmt->execute([$sess['user_id']]);
                    $u = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $u['is_admin'] = in_array($u['role'], ['admin', 'super_admin'], true);
                        return $u;
                    }
                }
            } catch (Throwable $e) {
                // Ignore schema variations
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
     * Authenticate an AI Access Key (2tl_ai_<prefix>_<secret>)
     */
    public static function authenticateAiKey(string $rawKey): ?array {
        if (!preg_match('/^(2tl_ai_[a-zA-Z0-9]{8})_([a-zA-Z0-9]{32,128})$/', trim($rawKey), $matches)) {
            return null;
        }

        $prefix = $matches[1];
        $secret = $matches[2];

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM ai_access_keys WHERE key_prefix = ? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([$prefix]);
        $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyRow) {
            return null;
        }

        // Verify cryptographic SHA-256 hash
        $expectedHash = hash('sha256', $secret);
        if (!hash_equals($keyRow['secret_hash'], $expectedHash)) {
            return null;
        }

        // Check expiration
        if (!empty($keyRow['expires_at']) && strtotime($keyRow['expires_at']) < time()) {
            return null;
        }

        // Check Team RBAC if scoped to TEAM workspace
        if ($keyRow['workspace_type'] === 'TEAM' && !empty($keyRow['team_id'])) {
            $tmStmt = $db->prepare('SELECT role, status FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1');
            $tmStmt->execute([$keyRow['team_id'], $keyRow['user_id']]);
            $member = $tmStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member || $member['status'] !== 'ACTIVE') {
                return null;
            }
        }

        // Fetch user data
        $uStmt = $db->prepare('SELECT id, username, email, fullname, role FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$keyRow['user_id']]);
        $u = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return null;
        }

        // Update last_used_at
        try {
            $updStmt = $db->prepare('UPDATE ai_access_keys SET last_used_at = NOW() WHERE id = ?');
            $updStmt->execute([$keyRow['id']]);
        } catch (Throwable $e) {}

        $u['is_admin'] = in_array($u['role'], ['admin', 'super_admin'], true);
        $u['ai_access_key'] = [
            'id'             => $keyRow['id'],
            'display_name'   => $keyRow['display_name'],
            'workspace_type' => $keyRow['workspace_type'],
            'workspace_id'   => $keyRow['workspace_id'],
            'team_id'        => $keyRow['team_id'],
            'root_folder_id' => $keyRow['root_folder_id'],
            'scopes'         => json_decode($keyRow['scopes'] ?? '[]', true) ?: [],
        ];
        return $u;
    }

    /**
     * Validate and normalize a relative path strictly jailed within AI Inputs/
     */
    public static function validateJailedPath(string $rawPath): array {
        $raw = trim($rawPath);
        if (strpos($raw, "\0") !== false) {
            return ['valid' => false, 'error' => 'Null bytes prohibited', 'segments' => []];
        }

        $raw = str_replace('\\', '/', $raw);

        // Strip leading slashes, Windows drive letters, and user home tildes
        if (preg_match('/^([a-zA-Z]:|\/|~)/', $raw)) {
            return ['valid' => false, 'error' => 'Absolute paths prohibited', 'segments' => []];
        }

        $parts = explode('/', $raw);
        $clean = [];

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || $p === '.') continue;
            if ($p === '..') {
                return ['valid' => false, 'error' => 'Path traversal (..) prohibited', 'segments' => []];
            }
            if (preg_match('/[<>:"|?*]/', $p)) {
                return ['valid' => false, 'error' => 'Invalid characters in path segment', 'segments' => []];
            }
            $clean[] = $p;
        }

        // If the path starts with "AI Inputs" (case-insensitive), strip it so it is relative to the jailed root
        if (!empty($clean) && strcasecmp($clean[0], 'AI Inputs') === 0) {
            array_shift($clean);
        }

        return [
            'valid'      => true,
            'segments'   => $clean,
            'normalized' => implode('/', $clean),
        ];
    }

    /**
     * Validate whether a user has read/write permission to a Cloud Space
     */
    public static function authorizeSpaceAccess(string $spaceId, string $userId): array {
        $db = Database::getConnection();

        // If authenticated via AI Access Key, strictly enforce key workspace binding
        $currentUser = self::getCurrentUser();
        if ($currentUser && !empty($currentUser['ai_access_key'])) {
            $keyWs = $currentUser['ai_access_key']['workspace_id'];
            if ($spaceId !== $keyWs) {
                return ['allowed' => false, 'role' => 'DENIED', 'space' => null, 'reason' => 'AI_KEY_WORKSPACE_MISMATCH'];
            }
        }

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

        // 3. Super Admin bypass (only if not restricted by AI key)
        if (empty($currentUser['ai_access_key'])) {
            $uStmt = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([$userId]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($u && in_array($u['role'], ['admin', 'super_admin'], true)) {
                return ['allowed' => true, 'role' => 'ADMIN', 'space' => $space];
            }
        }

        return ['allowed' => false, 'role' => 'DENIED', 'space' => $space];
    }
}
