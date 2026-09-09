<?php
/**
 * 2TOOLNE CLOUD — WORKSPACE PERMISSION SERVICE (V2 PRODUCTION ARCHITECTURE)
 * Authoritative Server-Side RBAC Enforcement for Personal and Team Workspaces.
 *
 * Supported Roles:
 * - OWNER: Full administrative, billing, quota, seat, and membership control.
 * - ADMIN: Manages files, shares, and invites/removes Editor and Viewer members.
 * - EDITOR: Uploads, downloads, creates folders, creates shares, edits own content, uses team tokens.
 * - VIEWER: Read-only access to view and download files. Zero mutations, zero team token consumption.
 *
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

class WorkspacePermissionService {

    public static function canView(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'], true);
    }

    public static function canDownload(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR', 'VIEWER'], true);
    }

    public static function canUpload(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR'], true);
    }

    public static function canCreateFolder(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR'], true);
    }

    public static function canRename(string $role, bool $isItemCreator = false): bool {
        $r = strtoupper($role);
        if (in_array($r, ['OWNER', 'ADMIN'], true)) return true;
        if ($r === 'EDITOR') return $isItemCreator;
        return false;
    }

    public static function canMove(string $role, bool $isItemCreator = false): bool {
        $r = strtoupper($role);
        if (in_array($r, ['OWNER', 'ADMIN'], true)) return true;
        if ($r === 'EDITOR') return $isItemCreator;
        return false;
    }

    public static function canTrash(string $role, bool $isItemCreator = false): bool {
        $r = strtoupper($role);
        if (in_array($r, ['OWNER', 'ADMIN'], true)) return true;
        if ($r === 'EDITOR') return $isItemCreator;
        return false;
    }

    public static function canRestore(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN'], true);
    }

    public static function canPermanentDelete(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN'], true);
    }

    public static function canCreateShare(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR'], true);
    }

    public static function canRevokeShare(string $role, bool $isShareCreator = false): bool {
        $r = strtoupper($role);
        if (in_array($r, ['OWNER', 'ADMIN'], true)) return true;
        if ($r === 'EDITOR') return $isShareCreator;
        return false;
    }

    public static function canUseWorkspaceTokens(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN', 'EDITOR'], true);
    }

    public static function canInviteMember(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN'], true);
    }

    public static function canRemoveMember(string $role, string $targetMemberRole): bool {
        $r = strtoupper($role);
        $t = strtoupper($targetMemberRole);
        if ($t === 'OWNER') return false; // Last OWNER protected, Owner cannot be removed via standard remove
        if ($r === 'OWNER') return true;
        if ($r === 'ADMIN') return in_array($t, ['EDITOR', 'VIEWER'], true);
        return false;
    }

    public static function canChangeRole(string $role, string $targetMemberRole, string $newRole): bool {
        $r = strtoupper($role);
        $t = strtoupper($targetMemberRole);
        $n = strtoupper($newRole);

        if ($t === 'OWNER' || $n === 'OWNER') return false; // Owner role changes require explicit transfer
        if ($r === 'OWNER') return in_array($n, ['ADMIN', 'EDITOR', 'VIEWER'], true);
        if ($r === 'ADMIN') {
            return in_array($t, ['EDITOR', 'VIEWER'], true) && in_array($n, ['EDITOR', 'VIEWER'], true);
        }
        return false;
    }

    public static function canManageBilling(string $role): bool {
        return strtoupper($role) === 'OWNER';
    }

    public static function canManageSeats(string $role): bool {
        return in_array(strtoupper($role), ['OWNER', 'ADMIN'], true);
    }

    public static function getAllPermissions(string $role): array {
        $r = strtoupper($role);
        return [
            'can_view'             => self::canView($r),
            'can_download'         => self::canDownload($r),
            'can_upload'           => self::canUpload($r),
            'can_create_folder'    => self::canCreateFolder($r),
            'can_rename'           => self::canRename($r, true),
            'can_move'             => self::canMove($r, true),
            'can_trash'            => self::canTrash($r, true),
            'can_restore'          => self::canRestore($r),
            'can_permanent_delete' => self::canPermanentDelete($r),
            'can_create_share'     => self::canCreateShare($r),
            'can_revoke_share'     => self::canRevokeShare($r, true),
            'can_use_tokens'       => self::canUseWorkspaceTokens($r),
            'can_invite_member'    => self::canInviteMember($r),
            'can_remove_member'    => in_array($r, ['OWNER', 'ADMIN'], true),
            'can_change_role'      => in_array($r, ['OWNER', 'ADMIN'], true),
            'can_manage_billing'   => self::canManageBilling($r),
            'can_manage_seats'     => self::canManageSeats($r),
        ];
    }

    /**
     * Resolves user workspace context and returns [workspace_id, type, role, team_id, permissions]
     */
    public static function resolveContext(string $workspaceId, string $userId, PDO $db): ?array {
        $stmt = $db->prepare('SELECT * FROM cloud_spaces WHERE id = ? LIMIT 1');
        $stmt->execute([$workspaceId]);
        $space = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$space) return null;

        if ($space['owner_type'] === 'USER') {
            if ((string)$space['owner_id'] !== (string)$userId) {
                return null;
            }
            return [
                'workspace_id' => $space['id'],
                'type'         => 'PERSONAL',
                'name'         => $space['name'] ?: 'Cá nhân',
                'role'         => 'OWNER',
                'team_id'      => null,
                'permissions'  => self::getAllPermissions('OWNER'),
                'space'        => $space,
            ];
        }

        if ($space['owner_type'] === 'TEAM') {
            $teamId = (string)$space['owner_id'];
            $tmStmt = $db->prepare('
                SELECT role, status FROM team_members
                WHERE team_id = ? AND user_id = ? AND status = "ACTIVE"
                LIMIT 1
            ');
            $tmStmt->execute([$teamId, $userId]);
            $member = $tmStmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                return null; // Not an active member of this team
            }

            $role = strtoupper($member['role']);
            return [
                'workspace_id' => $space['id'],
                'type'         => 'TEAM',
                'name'         => $space['name'],
                'role'         => $role,
                'team_id'      => $teamId,
                'permissions'  => self::getAllPermissions($role),
                'space'        => $space,
            ];
        }

        return null;
    }
}
