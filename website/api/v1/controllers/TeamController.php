<?php
/**
 * 2TOOLNE CLOUD — TEAM & WORKSPACE CONTROLLER (V2 PRODUCTION ARCHITECTURE)
 * Complete management of Team Organizations, Members, RBAC Roles, and Invitations.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../services/WorkspacePermissionService.php';
require_once __DIR__ . '/CapCutLicenseController.php';

class TeamController {

    /**
     * POST /api/v1/teams
     * Create a new Team Workspace with Creator as OWNER
     */
    public static function createTeam(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $name = trim((string)($body['name'] ?? ''));
        if (empty($name)) {
            Router::error('Tên Team là bắt buộc', 400, 'MISSING_NAME');
        }
        if (mb_strlen($name) > 128) {
            Router::error('Tên Team tối đa 128 ký tự', 400, 'NAME_TOO_LONG');
        }

        $userId = (string)$user['id'];
        $db = Database::getConnection();

        $teamId = 'tm_' . bin2hex(random_bytes(8));
        $spaceId = 'cs_team_' . bin2hex(random_bytes(8));
        $walletId = 'cw_' . bin2hex(random_bytes(8));

        $db->beginTransaction();
        try {
            // 1. Create Team record
            $tStmt = $db->prepare('
                INSERT INTO teams (id, name, owner_user_id, member_slots, app_key_count, status, created_at)
                VALUES (?, ?, ?, 2, 2, "ACTIVE", NOW())
            ');
            $tStmt->execute([$teamId, $name, $userId]);

            // 2. Add creator as OWNER in team_members
            $tmStmt = $db->prepare('
                INSERT INTO team_members (id, team_id, user_id, role, status, joined_at)
                VALUES (?, ?, ?, "OWNER", "ACTIVE", NOW())
            ');
            $tmStmt->execute(['tm_mem_' . bin2hex(random_bytes(8)), $teamId, $userId]);

            // 3. Create Team Cloud Space
            $csStmt = $db->prepare('
                INSERT INTO cloud_spaces (id, owner_type, owner_id, name, status, created_at)
                VALUES (?, "TEAM", ?, ?, "ACTIVE", NOW())
            ');
            $csStmt->execute([$spaceId, $teamId, $name]);

            // 4. Create Team Space Quota (50GB default)
            $csqStmt = $db->prepare('
                INSERT INTO cloud_space_quotas (cloud_space_id, base_quota_bytes, effective_quota_bytes, used_bytes, reserved_bytes)
                VALUES (?, 53687091200, 53687091200, 0, 0)
            ');
            $csqStmt->execute([$spaceId]);

            // 5. Create Team Wallet (Dedicated tokens)
            $cwStmt = $db->prepare('
                INSERT INTO credit_wallets (id, user_id, team_id, workspace_id, balance, reserved_balance)
                VALUES (?, ?, ?, ?, 0, 0)
            ');
            $cwStmt->execute([$walletId, $userId, $teamId, $spaceId]);

            $db->commit();

            Router::json([
                'ok'        => true,
                'team'      => [
                    'id'            => $teamId,
                    'name'          => $name,
                    'owner_user_id' => $userId,
                    'member_slots'  => 2,
                    'app_key_count' => 2,
                    'workspace_id'  => $spaceId,
                    'role'          => 'OWNER',
                ],
            ], 201);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Không thể tạo Team: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/teams/{id}
     * Get Team metadata, quota, and member count
     */
    public static function getTeam(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $teamId = $params['id'] ?? '';
        $userId = (string)$user['id'];
        $db = Database::getConnection();

        // Verify membership
        $tmStmt = $db->prepare('SELECT role, status FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tmStmt->execute([$teamId, $userId]);
        $member = $tmStmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            Router::error('Bạn không có quyền truy cập Team này', 403, 'FORBIDDEN');
        }

        $tStmt = $db->prepare('SELECT * FROM teams WHERE id = ? AND status = "ACTIVE" LIMIT 1');
        $tStmt->execute([$teamId]);
        $team = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$team) {
            Router::error('Team không tồn tại hoặc đã bị giải tán', 404, 'NOT_FOUND');
        }

        // Get Cloud Space
        $csStmt = $db->prepare('
            SELECT cs.id, cs.name, csq.effective_quota_bytes, csq.used_bytes
            FROM cloud_spaces cs
            LEFT JOIN cloud_space_quotas csq ON cs.id = csq.cloud_space_id
            WHERE cs.owner_type = "TEAM" AND cs.owner_id = ?
            LIMIT 1
        ');
        $csStmt->execute([$teamId]);
        $space = $csStmt->fetch(PDO::FETCH_ASSOC);

        // Get Wallet Balance
        $cwStmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? LIMIT 1');
        $cwStmt->execute([$teamId]);
        $wallet = $cwStmt->fetch(PDO::FETCH_ASSOC);

        // Get Member and Seat Count
        $cntStmt = $db->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = ? AND status = "ACTIVE"');
        $cntStmt->execute([$teamId]);
        $memberCount = (int)$cntStmt->fetchColumn();

        $seatStmt = $db->prepare('SELECT COUNT(*) FROM team_seats WHERE team_id = ? AND status = "ACTIVE"');
        $seatStmt->execute([$teamId]);
        $activeSeats = (int)$seatStmt->fetchColumn();

        $callerRole = strtoupper($member['role']);

        Router::json([
            'ok'   => true,
            'team' => [
                'id'            => $team['id'],
                'name'          => $team['name'],
                'owner_user_id' => $team['owner_user_id'],
                'member_slots'  => (int)$team['member_slots'],
                'member_count'  => $memberCount,
                'app_key_count' => (int)$team['app_key_count'],
                'active_seats'  => $activeSeats,
                'role'          => $callerRole,
                'permissions'   => WorkspacePermissionService::getAllPermissions($callerRole),
                'workspace_id'  => $space ? $space['id'] : null,
                'cloud'         => [
                    'quota_bytes' => (int)($space['effective_quota_bytes'] ?? 0),
                    'used_bytes'  => (int)($space['used_bytes'] ?? 0),
                ],
                'wallet'        => [
                    'balance'          => (int)($wallet['balance'] ?? 0),
                    'reserved_balance' => (int)($wallet['reserved_balance'] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/teams/{id}/members
     * Roster of team members
     */
    public static function listMembers(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $teamId = $params['id'] ?? '';
        $userId = (string)$user['id'];
        $db = Database::getConnection();

        // Verify caller membership
        $tmStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tmStmt->execute([$teamId, $userId]);
        $caller = $tmStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller) {
            Router::error('Quyền truy cập bị từ chối', 403, 'FORBIDDEN');
        }

        $stmt = $db->prepare('
            SELECT tm.id, tm.user_id, tm.role, tm.status, tm.joined_at,
                   u.username, u.email,
                   (SELECT COUNT(*) FROM team_seats ts WHERE ts.team_id = tm.team_id AND ts.user_id = tm.user_id AND ts.status = "ACTIVE") as has_seat
            FROM team_members tm
            LEFT JOIN users u ON tm.user_id = u.id
            WHERE tm.team_id = ? AND tm.status = "ACTIVE"
            ORDER BY FIELD(tm.role, "OWNER", "ADMIN", "EDITOR", "VIEWER"), tm.joined_at ASC
        ');
        $stmt->execute([$teamId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $members = array_map(function($r) {
            return [
                'id'        => $r['id'],
                'user_id'   => $r['user_id'],
                'name'      => $r['username'] ?: $r['email'] ?: 'Thành viên',
                'email'     => $r['email'] ?: '',
                'role'      => strtoupper($r['role']),
                'has_seat'  => (int)$r['has_seat'] > 0,
                'joined_at' => $r['joined_at'],
            ];
        }, $rows);

        Router::json([
            'ok'      => true,
            'members' => $members,
        ]);
    }

    /**
     * POST /api/v1/teams/{id}/invitations
     * Issue cryptographically secure single-use expiring invite token
     */
    public static function createInvitation(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $teamId = $params['id'] ?? '';
        $userId = (string)$user['id'];
        $db = Database::getConnection();

        $tmStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tmStmt->execute([$teamId, $userId]);
        $caller = $tmStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !WorkspacePermissionService::canInviteMember($caller['role'])) {
            Router::error('Bạn không có quyền mời thành viên vào Team', 403, 'FORBIDDEN');
        }

        $offeredRole = strtoupper(trim((string)($body['offered_role'] ?? 'EDITOR')));
        if (!in_array($offeredRole, ['ADMIN', 'EDITOR', 'VIEWER'], true)) {
            $offeredRole = 'EDITOR';
        }
        // ADMIN can only invite EDITOR or VIEWER
        if (strtoupper($caller['role']) === 'ADMIN' && $offeredRole === 'ADMIN') {
            Router::error('Quản trị viên chỉ có thể mời Editor hoặc Viewer', 403, 'FORBIDDEN');
        }

        $email = trim((string)($body['recipient_email'] ?? ''));

        // 32-byte cryptographically secure random token (256 bits entropy)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400); // 7 days

        $invId = 'inv_' . bin2hex(random_bytes(8));
        $stmt = $db->prepare('
            INSERT INTO team_invitations
                (id, team_id, invite_token_hash, offered_role, recipient_email, created_by_user_id, expires_at, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $invId,
            $teamId,
            $tokenHash,
            $offeredRole,
            !empty($email) ? $email : null,
            $userId,
            $expiresAt,
        ]);

        $baseUrl = self::getBaseUrl();
        $inviteUrl = "{$baseUrl}/invite/#{$rawToken}";

        Router::json([
            'ok'           => true,
            'invite_id'    => $invId,
            'offered_role' => $offeredRole,
            'expires_at'   => $expiresAt,
            'invite_token' => $rawToken,
            'invite_url'   => $inviteUrl,
        ], 201);
    }

    /**
     * POST /api/v1/teams/invitations/accept
     * Accept an invitation and join Team with offered role (Single-use burn)
     */
    public static function acceptInvitation(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Vui lòng đăng nhập để chấp nhận lời mời tham gia Team', 401, 'AUTH_REQUIRED');
        }

        $rawToken = trim((string)($body['invite_token'] ?? ($body['token'] ?? '')));
        if (empty($rawToken)) {
            Router::error('Mã lời mời không hợp lệ', 400, 'INVALID_TOKEN');
        }

        $db = Database::getConnection();
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $db->prepare('SELECT * FROM team_invitations WHERE invite_token_hash = ? LIMIT 1');
        $stmt->execute([$tokenHash]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            Router::error('Không tìm thấy lời mời hoặc mã lời mời không tồn tại', 404, 'NOT_FOUND');
        }

        if (!empty($inv['used_at'])) {
            Router::error('Lời mời này đã được sử dụng trước đó (chỉ sử dụng 1 lần)', 410, 'INVITE_ALREADY_USED');
        }

        if (!empty($inv['revoked_at'])) {
            Router::error('Lời mời này đã bị thu hồi bởi người tạo', 410, 'INVITE_REVOKED');
        }

        if (strtotime($inv['expires_at']) < time()) {
            Router::error('Lời mời đã hết hạn', 410, 'INVITE_EXPIRED');
        }

        $teamId = $inv['team_id'];
        $userId = (string)$user['id'];

        // Check if team is active
        $tStmt = $db->prepare('SELECT id, name, member_slots, status FROM teams WHERE id = ? LIMIT 1');
        $tStmt->execute([$teamId]);
        $team = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$team || $team['status'] !== 'ACTIVE') {
            Router::error('Team không còn hoạt động', 410, 'TEAM_INACTIVE');
        }

        // Check already a member
        $tmStmt = $db->prepare('SELECT id, status FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1');
        $tmStmt->execute([$teamId, $userId]);
        $existing = $tmStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['status'] === 'ACTIVE') {
            // Burn token if already member
            $db->prepare('UPDATE team_invitations SET used_at = NOW() WHERE id = ?')->execute([$inv['id']]);
            Router::json([
                'ok'      => true,
                'team_id' => $teamId,
                'message' => 'Bạn đã là thành viên của Team này.',
            ]);
            return;
        }

        // Check member slots limit
        $cntStmt = $db->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = ? AND status = "ACTIVE"');
        $cntStmt->execute([$teamId]);
        $activeMembers = (int)$cntStmt->fetchColumn();

        if ($activeMembers >= (int)$team['member_slots']) {
            Router::error('Team đã đạt giới hạn thành viên tối đa cho gói hiện tại', 400, 'MEMBER_SLOTS_FULL');
        }

        $db->beginTransaction();
        try {
            if ($existing) {
                // Reactivate
                $upStmt = $db->prepare('UPDATE team_members SET status = "ACTIVE", role = ?, joined_at = NOW() WHERE id = ?');
                $upStmt->execute([$inv['offered_role'], $existing['id']]);
            } else {
                $insStmt = $db->prepare('INSERT INTO team_members (id, team_id, user_id, role, status, joined_at) VALUES (?, ?, ?, ?, "ACTIVE", NOW())');
                $insStmt->execute(['tm_mem_' . bin2hex(random_bytes(8)), $teamId, $userId, $inv['offered_role']]);
            }

            // Burn single-use invitation token
            $burnStmt = $db->prepare('UPDATE team_invitations SET used_at = NOW() WHERE id = ?');
            $burnStmt->execute([$inv['id']]);

            $db->commit();

            Router::json([
                'ok'           => true,
                'team_id'      => $teamId,
                'team_name'    => $team['name'],
                'role'         => $inv['offered_role'],
                'message'      => 'Bạn đã tham gia Team thành công!',
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Không thể tham gia Team: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/teams/{id}/members/{userId}/role
     * Change member role (OWNER/ADMIN manages roles)
     */
    public static function changeMemberRole(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) Router::error('Authentication required', 401);

        $teamId = $params['id'] ?? '';
        $targetUserId = $params['userId'] ?? '';
        $newRole = strtoupper(trim((string)($body['role'] ?? '')));

        $callerId = (string)$user['id'];
        $db = Database::getConnection();

        $cStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $cStmt->execute([$teamId, $callerId]);
        $caller = $cStmt->fetch(PDO::FETCH_ASSOC);

        $tStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tStmt->execute([$teamId, $targetUserId]);
        $target = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !$target) {
            Router::error('Thành viên không tồn tại trong Team', 404);
        }

        if (!WorkspacePermissionService::canChangeRole($caller['role'], $target['role'], $newRole)) {
            Router::error('Bạn không có quyền thay đổi vai trò thành viên này', 403, 'FORBIDDEN');
        }

        $up = $db->prepare('UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ?');
        $up->execute([$newRole, $teamId, $targetUserId]);

        Router::json([
            'ok'       => true,
            'user_id'  => $targetUserId,
            'new_role' => $newRole,
        ]);
    }

    /**
     * DELETE /api/v1/teams/{id}/members/{userId}
     * Remove member from Team (Immediate revocation of access and desktop seats)
     */
    public static function removeMember(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) Router::error('Authentication required', 401);

        $teamId = $params['id'] ?? '';
        $targetUserId = $params['userId'] ?? '';
        $callerId = (string)$user['id'];
        $db = Database::getConnection();

        $cStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $cStmt->execute([$teamId, $callerId]);
        $caller = $cStmt->fetch(PDO::FETCH_ASSOC);

        $tStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tStmt->execute([$teamId, $targetUserId]);
        $target = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !$target) {
            Router::error('Thành viên không tồn tại trong Team', 404);
        }

        if (!WorkspacePermissionService::canRemoveMember($caller['role'], $target['role'])) {
            Router::error('Bạn không có quyền xóa thành viên này', 403, 'FORBIDDEN');
        }

        $db->beginTransaction();
        try {
            // Revoke membership immediately
            $del = $db->prepare('UPDATE team_members SET status = "REMOVED" WHERE team_id = ? AND user_id = ?');
            $del->execute([$teamId, $targetUserId]);

            // Revoke active team seats immediately
            $revSeats = $db->prepare('UPDATE team_seats SET status = "REVOKED", revoked_at = NOW() WHERE team_id = ? AND user_id = ?');
            $revSeats->execute([$teamId, $targetUserId]);

            $db->commit();

            Router::json([
                'ok'      => true,
                'removed' => true,
                'message' => 'Đã xóa thành viên khỏi Team thành công.',
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Lỗi khi xóa thành viên: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/teams/{id}
     * Soft delete / Archive Team (Strictly OWNER-only)
     */
    public static function deleteTeam(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) Router::error('Authentication required', 401);

        $teamId = $params['id'] ?? '';
        $callerId = (string)$user['id'];
        $db = Database::getConnection();

        $cStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $cStmt->execute([$teamId, $callerId]);
        $caller = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !WorkspacePermissionService::canManageBilling($caller['role'])) {
            Router::error('Chỉ Chủ nhóm (OWNER) mới có quyền giải tán Team', 403, 'FORBIDDEN');
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE teams SET status = "CANCELLED" WHERE id = ?')->execute([$teamId]);
            $db->prepare('UPDATE cloud_spaces SET status = "CLOSED" WHERE owner_type = "TEAM" AND owner_id = ?')->execute([$teamId]);
            $db->prepare('UPDATE team_seats SET status = "REVOKED", revoked_at = NOW() WHERE team_id = ?')->execute([$teamId]);

            $db->commit();
            Router::json(['ok' => true, 'archived' => true, 'message' => 'Team đã được giải tán.']);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Lỗi khi giải tán Team: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/teams/{id}/seats
     * List all desktop license seats for team (OWNER / ADMIN)
     */
    public static function listSeats(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) Router::error('Authentication required', 401);

        $teamId = $params['id'] ?? '';
        $callerId = (string)$user['id'];
        $db = Database::getConnection();

        $cStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $cStmt->execute([$teamId, $callerId]);
        $caller = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !WorkspacePermissionService::canManageSeats($caller['role'])) {
            Router::error('Bạn không có quyền quản lý ghế bản quyền của Team', 403, 'FORBIDDEN');
        }

        $tStmt = $db->prepare('SELECT app_key_count FROM teams WHERE id = ? LIMIT 1');
        $tStmt->execute([$teamId]);
        $team = $tStmt->fetch(PDO::FETCH_ASSOC);
        $maxSeats = (int)($team['app_key_count'] ?? 2);

        $sStmt = $db->prepare('
            SELECT ts.id, ts.team_id, ts.user_id, ts.device_fingerprint, ts.device_alias,
                   ts.status, ts.assigned_at, ts.revoked_at,
                   u.username, u.fullname, u.email, tm.role as member_role
            FROM team_seats ts
            LEFT JOIN users u ON ts.user_id = u.id
            LEFT JOIN team_members tm ON ts.team_id = tm.team_id AND ts.user_id = tm.user_id
            WHERE ts.team_id = ?
            ORDER BY ts.assigned_at DESC
        ');
        $sStmt->execute([$teamId]);
        $seats = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $activeCount = 0;
        foreach ($seats as $s) {
            if ($s['status'] === 'ACTIVE') $activeCount++;
        }

        Router::json([
            'ok'           => true,
            'max_seats'    => $maxSeats,
            'active_seats' => $activeCount,
            'remaining'    => max(0, $maxSeats - $activeCount),
            'seats'        => $seats,
        ]);
    }

    /**
     * POST /api/v1/teams/{id}/seats/activate
     * Activate a desktop license seat for an active team member
     */
    public static function activateSeat(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        $userId = $user ? (string)$user['id'] : trim((string)($body['user_id'] ?? ''));
        $teamId = $params['id'] ?? '';
        $deviceId = trim((string)($body['device_id'] ?? ($body['device_fingerprint'] ?? '')));
        $deviceAlias = trim((string)($body['device_alias'] ?? ($body['platform'] ?? 'Desktop')));
        $platform = trim((string)($body['platform'] ?? 'mac-arm64'));

        if (empty($userId) || empty($deviceId)) {
            Router::error('user_id and device_id are required', 400);
        }

        $db = Database::getConnection();

        // 1. Verify team active
        $tStmt = $db->prepare('SELECT id, name, app_key_count, status FROM teams WHERE id = ? LIMIT 1');
        $tStmt->execute([$teamId]);
        $team = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$team || $team['status'] !== 'ACTIVE') {
            Router::error('Team không tồn tại hoặc đã bị khóa', 404, 'TEAM_NOT_FOUND');
        }

        // 2. Verify member active
        $tmStmt = $db->prepare('SELECT role, status FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $tmStmt->execute([$teamId, $userId]);
        $member = $tmStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            Router::error('Bạn không phải thành viên hoạt động của Team này', 403, 'FORBIDDEN');
        }

        // 3. Check existing active seat for this user + device
        $sStmt = $db->prepare('SELECT id, status FROM team_seats WHERE team_id = ? AND user_id = ? AND device_fingerprint = ? LIMIT 1');
        $sStmt->execute([$teamId, $userId, $deviceId]);
        $existingSeat = $sStmt->fetch(PDO::FETCH_ASSOC);

        $maxSeats = (int)$team['app_key_count'];

        if (!$existingSeat || $existingSeat['status'] !== 'ACTIVE') {
            // Count current active seats
            $cStmt = $db->prepare('SELECT COUNT(*) as cnt FROM team_seats WHERE team_id = ? AND status = "ACTIVE"');
            $cStmt->execute([$teamId]);
            $activeCnt = (int)$cStmt->fetch()['cnt'];

            if ($activeCnt >= $maxSeats) {
                Router::error("Đã đạt giới hạn số ghế bản quyền Desktop của Team (Tối đa: {$maxSeats} máy). Vui lòng liên hệ Chủ nhóm để nâng cấp thêm ghế.", 403, 'SEAT_LIMIT_EXCEEDED');
            }
        }

        $seatId = $existingSeat ? $existingSeat['id'] : ('seat_' . bin2hex(random_bytes(8)));

        $db->beginTransaction();
        try {
            if ($existingSeat) {
                $up = $db->prepare('UPDATE team_seats SET status = "ACTIVE", assigned_at = NOW(), revoked_at = NULL, device_alias = ? WHERE id = ?');
                $up->execute([$deviceAlias, $seatId]);
            } else {
                $ins = $db->prepare('
                    INSERT INTO team_seats (id, team_id, user_id, device_fingerprint, device_alias, status, assigned_at)
                    VALUES (?, ?, ?, ?, ?, "ACTIVE", NOW())
                ');
                $ins->execute([$seatId, $teamId, $userId, $deviceId, $deviceAlias]);
            }

            // Ensure device is registered in devices table
            $fpHash = hash('sha256', $deviceId);
            $dStmt = $db->prepare('SELECT id FROM devices WHERE user_id = ? AND (device_fingerprint_hash = ? OR device_fingerprint = ?) LIMIT 1');
            $dStmt->execute([$userId, $fpHash, $deviceId]);
            $dev = $dStmt->fetch();
            if (!$dev) {
                $devId = 'dev_' . bin2hex(random_bytes(12));
                $db->prepare('INSERT INTO devices (id, user_id, device_fingerprint, device_fingerprint_hash, device_alias, platform, status, activated_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, "ACTIVE", NOW(), NOW())')
                   ->execute([$devId, $userId, $deviceId, $fpHash, $deviceAlias, $platform]);
            } else {
                $db->prepare('UPDATE devices SET status = "ACTIVE", device_fingerprint_hash = COALESCE(device_fingerprint_hash, ?), last_seen_at = NOW() WHERE id = ?')->execute([$fpHash, $dev['id']]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Không thể kích hoạt ghế bản quyền: ' . $e->getMessage(), 500);
        }

        // 4. Issue Asymmetric Ed25519 Signed Entitlement Envelope with 72-hour offline grace
        $now = time();
        $offlineUntil = $now + 259200; // 72 hours
        $licenseId = "team_seat_{$seatId}";

        $payload = [
            'license_id'    => $licenseId,
            'seat_id'       => $seatId,
            'team_id'       => $teamId,
            'user_id'       => $userId,
            'product_id'    => '2toolne.capcut.v2',
            'device_id'     => $deviceId,
            'plan'          => 'TEAM',
            'issued_at'     => $now,
            'expires_at'    => $now + (30 * 86400),
            'offline_until' => $offlineUntil,
            'features'      => ['capcut_autoedit', 'unlimited_export', 'all_presets', 'script_to_srt', 'team_workspace'],
        ];

        $signedEnvelope = CapCutLicenseController::signPayload($payload);

        Router::json([
            'ok'                  => true,
            'seat_id'             => $seatId,
            'status'              => 'SEAT_ACTIVE',
            'license_id'          => $licenseId,
            'signed_entitlement'  => $signedEnvelope,
            'trusted_server_time' => $now,
        ], 200);
    }

    /**
     * POST /api/v1/teams/{id}/seats/{seatId}/revoke
     * Revoke a desktop license seat immediately (OWNER / ADMIN)
     */
    public static function revokeSeat(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) Router::error('Authentication required', 401);

        $teamId = $params['id'] ?? '';
        $seatId = $params['seatId'] ?? '';
        $callerId = (string)$user['id'];
        $db = Database::getConnection();

        $cStmt = $db->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
        $cStmt->execute([$teamId, $callerId]);
        $caller = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caller || !WorkspacePermissionService::canManageSeats($caller['role'])) {
            Router::error('Bạn không có quyền thu hồi ghế bản quyền của Team', 403, 'FORBIDDEN');
        }

        $sStmt = $db->prepare('SELECT * FROM team_seats WHERE id = ? AND team_id = ? LIMIT 1');
        $sStmt->execute([$seatId, $teamId]);
        $seat = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$seat) {
            Router::error('Không tìm thấy ghế bản quyền', 404);
        }

        $db->prepare('UPDATE team_seats SET status = "REVOKED", revoked_at = NOW() WHERE id = ?')->execute([$seatId]);

        Router::json([
            'ok'      => true,
            'seat_id' => $seatId,
            'revoked' => true,
            'message' => 'Đã thu hồi ghế bản quyền thành công.',
        ]);
    }

    private static function getBaseUrl(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'www.2tamne.site';
        return "{$proto}://{$host}";
    }
}
