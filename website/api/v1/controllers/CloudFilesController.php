<?php
/**
 * 2TOOLNE CLOUD — CLOUD FILES & VIRTUAL FILE SYSTEM CONTROLLER
 * Handles Cloud Space enumeration, file/folder navigation, trash, and metadata mutations.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../storage/CloudQuotaManager.php';
require_once __DIR__ . '/../services/WorkspacePermissionService.php';

class CloudFilesController {

    /**
     * GET /api/v1/cloud/spaces
     * List all Cloud Spaces accessible by the authenticated user
     */
    public static function listSpaces(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $userId = (string)$user['id'];
        $db = Database::getConnection();

        // 1. Fetch Personal Space(s)
        $pStmt = $db->prepare('
            SELECT cs.id, cs.name, cs.owner_type, cs.owner_id, cs.status, cs.created_at,
                   "OWNER" AS user_role,
                   csq.base_quota_bytes, csq.addon_quota_bytes, csq.admin_adjustment_bytes,
                   csq.effective_quota_bytes, csq.used_bytes, csq.reserved_bytes
            FROM cloud_spaces cs
            LEFT JOIN cloud_space_quotas csq ON cs.id = csq.cloud_space_id
            WHERE cs.owner_type = "USER" AND cs.owner_id = ?
        ');
        $pStmt->execute([$userId]);
        $personalSpaces = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Team Spaces where user is a member
        $tStmt = $db->prepare('
            SELECT cs.id, cs.name, cs.owner_type, cs.owner_id, cs.status, cs.created_at,
                   tm.role AS user_role,
                   csq.base_quota_bytes, csq.addon_quota_bytes, csq.admin_adjustment_bytes,
                   csq.effective_quota_bytes, csq.used_bytes, csq.reserved_bytes
            FROM team_members tm
            JOIN teams t ON tm.team_id = t.id
            JOIN cloud_spaces cs ON cs.owner_type = "TEAM" AND cs.owner_id = t.id
            LEFT JOIN cloud_space_quotas csq ON cs.id = csq.cloud_space_id
            WHERE tm.user_id = ? AND tm.status = "ACTIVE"
        ');
        $tStmt->execute([$userId]);
        $teamSpaces = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        $allSpaces = array_merge($personalSpaces, $teamSpaces);

        if (empty($allSpaces)) {
            if (file_exists(__DIR__ . '/../../../storage/db.php')) {
                require_once __DIR__ . '/../../../storage/db.php';
                if (function_exists('db_ensure_user_personal_space')) {
                    db_ensure_user_personal_space($userId);
                    $pStmt->execute([$userId]);
                    $personalSpaces = $pStmt->fetchAll(PDO::FETCH_ASSOC);
                    $allSpaces = array_merge($personalSpaces, $teamSpaces);
                }
            }
        }

        $formatted = array_map(function($s) {
            $effective = (int)($s['effective_quota_bytes'] ?? 5368709120);
            $used      = (int)($s['used_bytes'] ?? 0);
            $reserved  = (int)($s['reserved_bytes'] ?? 0);
            $free      = max(0, $effective - ($used + $reserved));
            return [
                'id'                   => $s['id'],
                'name'                 => $s['name'],
                'space_type'           => $s['owner_type'],
                'owner_id'             => $s['owner_id'],
                'team_id'              => ($s['owner_type'] === 'TEAM') ? $s['owner_id'] : null,
                'status'               => $s['status'],
                'user_role'            => $s['user_role'],
                'permissions'          => WorkspacePermissionService::getAllPermissions($s['user_role'] ?? 'MEMBER'),
                'effective_quota_bytes'=> $effective,
                'used_bytes'           => $used,
                'reserved_bytes'       => $reserved,
                'free_bytes'           => $free,
                'is_over_quota'        => ($used > $effective),
                'created_at'           => $s['created_at'],
            ];
        }, $allSpaces);

        Router::json([
            'success' => true,
            'spaces'  => $formatted,
        ]);
    }

    /**
     * GET /api/v1/cloud/spaces/{spaceId}/quota
     */
    public static function getSpaceQuota(array $params, array $body): void {
        $spaceId = $params['spaceId'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied to this cloud space', 403, 'FORBIDDEN');
        }

        $qm = new CloudQuotaManager();
        $quota = $qm->getSpaceQuota($spaceId);

        Router::json([
            'success' => true,
            'quota'   => $quota,
        ]);
    }

    /**
     * GET /api/v1/cloud/spaces/{spaceId}/files
     * List files and folders in a specific directory or root
     */
    public static function listFiles(array $params, array $body): void {
        $spaceId = $params['spaceId'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied to this cloud space', 403, 'FORBIDDEN');
        }

        $folderId = !empty($_GET['folder_id']) ? trim($_GET['folder_id']) : null;
        $search   = !empty($_GET['q']) ? trim($_GET['q']) : null;
        $db = Database::getConnection();

        // 1. Fetch Subfolders
        if ($search !== null) {
            $fStmt = $db->prepare('
                SELECT f.*, u.fullname as creator_name, u.username as creator_username
                FROM cloud_folders f
                LEFT JOIN users u ON f.created_by_user_id = u.id
                WHERE f.cloud_space_id = ? AND f.deleted_at IS NULL AND f.name LIKE ?
                ORDER BY f.name ASC
            ');
            $fStmt->execute([$spaceId, "%{$search}%"]);
        } elseif ($folderId === null) {
            $fStmt = $db->prepare('
                SELECT f.*, u.fullname as creator_name, u.username as creator_username
                FROM cloud_folders f
                LEFT JOIN users u ON f.created_by_user_id = u.id
                WHERE f.cloud_space_id = ? AND f.parent_id IS NULL AND f.deleted_at IS NULL
                ORDER BY f.name ASC
            ');
            $fStmt->execute([$spaceId]);
        } else {
            $fStmt = $db->prepare('
                SELECT f.*, u.fullname as creator_name, u.username as creator_username
                FROM cloud_folders f
                LEFT JOIN users u ON f.created_by_user_id = u.id
                WHERE f.cloud_space_id = ? AND f.parent_id = ? AND f.deleted_at IS NULL
                ORDER BY f.name ASC
            ');
            $fStmt->execute([$spaceId, $folderId]);
        }
        $folders = $fStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Files
        if ($search !== null) {
            $fileStmt = $db->prepare('
                SELECT cf.id, cf.cloud_space_id, cf.folder_id, cf.created_by_user_id,
                       cf.filename, cf.extension, cf.mime_type, cf.size_bytes, cf.status,
                       cf.created_at, cf.updated_at,
                       u.fullname as creator_name, u.username as creator_username
                FROM cloud_files cf
                LEFT JOIN users u ON cf.created_by_user_id = u.id
                WHERE cf.cloud_space_id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL AND cf.filename LIKE ?
                ORDER BY cf.created_at DESC
            ');
            $fileStmt->execute([$spaceId, "%{$search}%"]);
        } elseif ($folderId === null) {
            $fileStmt = $db->prepare('
                SELECT cf.id, cf.cloud_space_id, cf.folder_id, cf.created_by_user_id,
                       cf.filename, cf.extension, cf.mime_type, cf.size_bytes, cf.status,
                       cf.created_at, cf.updated_at,
                       u.fullname as creator_name, u.username as creator_username
                FROM cloud_files cf
                LEFT JOIN users u ON cf.created_by_user_id = u.id
                WHERE cf.cloud_space_id = ? AND cf.folder_id IS NULL AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
                ORDER BY cf.created_at DESC
            ');
            $fileStmt->execute([$spaceId]);
        } else {
            $fileStmt = $db->prepare('
                SELECT cf.id, cf.cloud_space_id, cf.folder_id, cf.created_by_user_id,
                       cf.filename, cf.extension, cf.mime_type, cf.size_bytes, cf.status,
                       cf.created_at, cf.updated_at,
                       u.fullname as creator_name, u.username as creator_username
                FROM cloud_files cf
                LEFT JOIN users u ON cf.created_by_user_id = u.id
                WHERE cf.cloud_space_id = ? AND cf.folder_id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
                ORDER BY cf.created_at DESC
            ');
            $fileStmt->execute([$spaceId, $folderId]);
        }
        $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Compute Breadcrumbs if inside a folder
        $breadcrumbs = [];
        $currId = $folderId;
        while ($currId !== null) {
            $bStmt = $db->prepare('SELECT id, name, parent_id FROM cloud_folders WHERE id = ? LIMIT 1');
            $bStmt->execute([$currId]);
            $parent = $bStmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) break;
            array_unshift($breadcrumbs, ['id' => $parent['id'], 'name' => $parent['name']]);
            $currId = $parent['parent_id'];
        }

        Router::json([
            'success'     => true,
            'space_id'    => $spaceId,
            'folder_id'   => $folderId,
            'breadcrumbs' => $breadcrumbs,
            'folders'     => $folders,
            'files'       => $files,
        ]);
    }

    /**
     * POST /api/v1/cloud/spaces/{spaceId}/folders
     */
    public static function createFolder(array $params, array $body): void {
        $spaceId = $params['spaceId'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied to this cloud space', 403, 'FORBIDDEN');
        }

        if (!WorkspacePermissionService::canCreateFolder($auth['role'] ?? 'MEMBER')) {
            Router::error('Bạn không có quyền tạo thư mục trong không gian làm việc này (Role VIEWER)', 403, 'FORBIDDEN');
        }

        $name = trim($body['name'] ?? '');
        $parentId = !empty($body['parent_id']) ? trim($body['parent_id']) : null;

        if (empty($name)) {
            Router::error('Folder name is required', 400);
        }

        $db = Database::getConnection();
        $folderId = 'cfl_' . bin2hex(random_bytes(8));

        $stmt = $db->prepare('
            INSERT INTO cloud_folders (id, cloud_space_id, parent_id, name, created_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$folderId, $spaceId, $parentId, $name, (string)$user['id']]);

        Router::json([
            'success' => true,
            'folder'  => [
                'id'        => $folderId,
                'name'      => $name,
                'parent_id' => $parentId,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/cloud/files/{id}/trash
     */
    public static function trashFile(array $params, array $body): void {
        $fileId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_files WHERE id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            Router::error('File not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($file['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        // Granular Workspace RBAC: OWNER/ADMIN can trash any file. EDITOR can trash own file. VIEWER cannot trash.
        $isCreator = ((string)$file['created_by_user_id'] === (string)$user['id']);
        if (!WorkspacePermissionService::canTrash($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền chuyển tệp tin này vào thùng rác', 403, 'FORBIDDEN');
        }

        $trashId = 'ctr_' . bin2hex(random_bytes(8));
        $purgeDue = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days retention

        $db->beginTransaction();
        try {
            // Update cloud_files status to TRASHED
            $upd = $db->prepare('UPDATE cloud_files SET status = "TRASHED", deleted_at = NOW() WHERE id = ?');
            $upd->execute([$fileId]);

            // Record in cloud_trash
            $ins = $db->prepare('
                INSERT INTO cloud_trash (id, cloud_space_id, cloud_file_id, original_folder_id, trashed_by_user_id, trashed_at, purge_due_at)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE trashed_at = NOW(), purge_due_at = VALUES(purge_due_at)
            ');
            $ins->execute([$trashId, $file['cloud_space_id'], $fileId, $file['folder_id'], (string)$user['id'], $purgeDue]);

            $db->commit();
            Router::json(['success' => true, 'message' => 'File moved to virtual trash.']);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Failed to trash file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/cloud/spaces/{spaceId}/trash
     */
    public static function listTrash(array $params, array $body): void {
        $spaceId = $params['spaceId'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT t.id as trash_id, t.trashed_at, t.purge_due_at,
                   cf.id, cf.id as file_id, cf.filename, cf.extension, cf.size_bytes, cf.mime_type,
                   u.fullname as trashed_by_name
            FROM cloud_trash t
            JOIN cloud_files cf ON t.cloud_file_id = cf.id
            LEFT JOIN users u ON t.trashed_by_user_id = u.id
            WHERE t.cloud_space_id = ?
            ORDER BY t.trashed_at DESC
        ');
        $stmt->execute([$spaceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Router::json(['success' => true, 'trash' => $items]);
    }

    /**
     * POST /api/v1/cloud/files/{id}/restore
     */
    public static function restoreFile(array $params, array $body): void {
        $fileId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_files WHERE id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            Router::error('File not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($file['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        if (!WorkspacePermissionService::canRestore($auth['role'] ?? 'MEMBER')) {
            Router::error('Bạn không có quyền khôi phục tệp tin trong không gian làm việc này', 403, 'FORBIDDEN');
        }

        $db->beginTransaction();
        try {
            $upd = $db->prepare('UPDATE cloud_files SET status = "ACTIVE", deleted_at = NULL WHERE id = ?');
            $upd->execute([$fileId]);

            $del = $db->prepare('DELETE FROM cloud_trash WHERE cloud_file_id = ?');
            $del->execute([$fileId]);

            $db->commit();
            Router::json(['success' => true, 'message' => 'File restored successfully.']);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Failed to restore file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/cloud/files/{id}/permanent
     */
    public static function permanentDeleteFile(array $params, array $body): void {
        $fileId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_files WHERE id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            Router::error('File not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($file['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        if (!WorkspacePermissionService::canPermanentDelete($auth['role'] ?? 'MEMBER')) {
            Router::error('Bạn không có quyền xóa vĩnh viễn tệp tin trong không gian làm việc này', 403, 'FORBIDDEN');
        }

        $qm = new CloudQuotaManager($db);
        $db->beginTransaction();
        try {
            $delTrash = $db->prepare('DELETE FROM cloud_trash WHERE cloud_file_id = ?');
            $delTrash->execute([$fileId]);

            $delFile = $db->prepare('DELETE FROM cloud_files WHERE id = ?');
            $delFile->execute([$fileId]);

            // Release quota usage
            $qm->releaseFileUsage($file['cloud_space_id'], $file['storage_account_id'], (int)$file['size_bytes']);

            $db->commit();
            Router::json(['success' => true, 'message' => 'File permanently deleted.']);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Failed to delete file permanently: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/cloud/files/{id}/rename
     */
    public static function renameFile(array $params, array $body): void {
        $fileId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $newName = trim($body['name'] ?? ($body['filename'] ?? ''));
        if (empty($newName)) {
            Router::error('File name is required', 400);
        }

        // Clean filename (strip slashes)
        $newName = basename($newName);
        $ext = strtolower(pathinfo($newName, PATHINFO_EXTENSION));

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_files WHERE id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            Router::error('File not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($file['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $isCreator = ((string)$file['created_by_user_id'] === (string)$user['id']);
        if (!WorkspacePermissionService::canRename($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền đổi tên tệp tin này', 403, 'FORBIDDEN');
        }

        $upd = $db->prepare('UPDATE cloud_files SET filename = ?, extension = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$newName, $ext, $fileId]);

        Router::json([
            'success' => true,
            'file' => [
                'id' => $fileId,
                'filename' => $newName,
                'extension' => $ext,
            ],
        ]);
    }

    /**
     * POST /api/v1/cloud/folders/{id}/rename
     */
    public static function renameFolder(array $params, array $body): void {
        $folderId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $newName = trim($body['name'] ?? '');
        if (empty($newName)) {
            Router::error('Folder name is required', 400);
        }
        $newName = basename($newName);

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$folder) {
            Router::error('Folder not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($folder['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $isCreator = ((string)($folder['created_by_user_id'] ?? '') === (string)$user['id']);
        if (!WorkspacePermissionService::canRename($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền đổi tên thư mục này', 403, 'FORBIDDEN');
        }

        $upd = $db->prepare('UPDATE cloud_folders SET name = ? WHERE id = ?');
        $upd->execute([$newName, $folderId]);

        Router::json([
            'success' => true,
            'folder' => [
                'id' => $folderId,
                'name' => $newName,
            ],
        ]);
    }

    /**
     * POST /api/v1/cloud/files/{id}/move
     */
    public static function moveFile(array $params, array $body): void {
        $fileId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $targetFolderId = !empty($body['folder_id']) ? trim($body['folder_id']) : null;

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_files WHERE id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            Router::error('File not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($file['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $isCreator = ((string)$file['created_by_user_id'] === (string)$user['id']);
        if (!WorkspacePermissionService::canMove($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền di chuyển tệp tin này', 403, 'FORBIDDEN');
        }

        // If target folder is specified, verify it exists and is in the same space
        if ($targetFolderId !== null) {
            $fStmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? AND cloud_space_id = ? LIMIT 1');
            $fStmt->execute([$targetFolderId, $file['cloud_space_id']]);
            if (!$fStmt->fetch()) {
                Router::error('Target folder not found in this space', 404);
            }
        }

        $upd = $db->prepare('UPDATE cloud_files SET folder_id = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$targetFolderId, $fileId]);

        Router::json([
            'success' => true,
            'file_id' => $fileId,
            'folder_id' => $targetFolderId,
        ]);
    }

    /**
     * POST /api/v1/cloud/folders/{id}/move
     */
    public static function moveFolder(array $params, array $body): void {
        $folderId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $targetParentId = !empty($body['parent_id']) ? trim($body['parent_id']) : null;
        if ($targetParentId === $folderId) {
            Router::error('Cannot move a folder into itself', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$folder) {
            Router::error('Folder not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($folder['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $isCreator = ((string)($folder['created_by_user_id'] ?? '') === (string)$user['id']);
        if (!WorkspacePermissionService::canMove($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền di chuyển thư mục này', 403, 'FORBIDDEN');
        }

        if ($targetParentId !== null) {
            $pStmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? AND cloud_space_id = ? LIMIT 1');
            $pStmt->execute([$targetParentId, $folder['cloud_space_id']]);
            if (!$pStmt->fetch()) {
                Router::error('Target parent folder not found in this space', 404);
            }
        }

        $upd = $db->prepare('UPDATE cloud_folders SET parent_id = ? WHERE id = ?');
        $upd->execute([$targetParentId, $folderId]);

        Router::json([
            'success' => true,
            'folder_id' => $folderId,
            'parent_id' => $targetParentId,
        ]);
    }

    /**
     * POST /api/v1/cloud/folders/{id}/trash
     */
    public static function trashFolder(array $params, array $body): void {
        $folderId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$folder) {
            Router::error('Folder not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($folder['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        $isCreator = ((string)($folder['created_by_user_id'] ?? '') === (string)$user['id']);
        if (!WorkspacePermissionService::canTrash($auth['role'] ?? 'MEMBER', $isCreator)) {
            Router::error('Bạn không có quyền chuyển thư mục này vào thùng rác', 403, 'FORBIDDEN');
        }

        $upd = $db->prepare('UPDATE cloud_folders SET deleted_at = NOW() WHERE id = ?');
        $upd->execute([$folderId]);

        Router::json(['success' => true, 'message' => 'Folder moved to trash.']);
    }

    /**
     * POST /api/v1/cloud/folders/{id}/restore
     */
    public static function restoreFolder(array $params, array $body): void {
        $folderId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$folder) {
            Router::error('Folder not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($folder['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        if (!WorkspacePermissionService::canRestore($auth['role'] ?? 'MEMBER')) {
            Router::error('Bạn không có quyền khôi phục thư mục trong không gian này', 403, 'FORBIDDEN');
        }

        $upd = $db->prepare('UPDATE cloud_folders SET deleted_at = NULL WHERE id = ?');
        $upd->execute([$folderId]);

        Router::json(['success' => true, 'message' => 'Folder restored.']);
    }

    /**
     * DELETE /api/v1/cloud/folders/{id}/permanent
     */
    public static function permanentDeleteFolder(array $params, array $body): void {
        $folderId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM cloud_folders WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$folder) {
            Router::error('Folder not found', 404);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($folder['cloud_space_id'], (string)$user['id']);
        if (!$auth['allowed']) {
            Router::error('Access denied', 403);
        }

        if (!WorkspacePermissionService::canPermanentDelete($auth['role'] ?? 'MEMBER')) {
            Router::error('Bạn không có quyền xóa vĩnh viễn thư mục trong không gian này', 403, 'FORBIDDEN');
        }

        $del = $db->prepare('DELETE FROM cloud_folders WHERE id = ?');
        $del->execute([$folderId]);

        Router::json(['success' => true, 'message' => 'Folder permanently deleted.']);
    }
}
