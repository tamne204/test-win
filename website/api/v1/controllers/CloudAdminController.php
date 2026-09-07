<?php
/**
 * 2TOOLNE CLOUD — STORAGE POOL & ADMIN OPERATIONS CONTROLLER
 * Admin operations: Pool metrics, account lifecycle (ACTIVE -> DRAINING -> EMPTY -> DISCONNECTED),
 * Safe Disconnect Guard, and Immutable Quota Adjustments Ledger.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../storage/CloudQuotaManager.php';
require_once __DIR__ . '/../storage/CryptoService.php';
require_once __DIR__ . '/../storage/GoogleDriveStorageAdapter.php';


class CloudAdminController {

    private static function checkAdmin(): array {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user || empty($user['is_admin'])) {
            Router::error('Admin privileges required', 403, 'FORBIDDEN');
        }
        return $user;
    }

    /**
     * GET /api/v1/cloud/admin/pool/metrics
     * Retrieve the 9 core operational storage pool metrics
     */
    public static function getPoolMetrics(array $params, array $body): void {
        self::checkAdmin();
        $db = Database::getConnection();

        // 1. Physical Storage Accounts metrics
        $saStmt = $db->query('
            SELECT 
                COALESCE(SUM(total_capacity_bytes), 0) AS pool_total_physical,
                COALESCE(SUM(used_capacity_bytes), 0) AS pool_used_physical,
                COALESCE(SUM(reserved_capacity_bytes), 0) AS pool_reserved,
                COALESCE(SUM(total_capacity_bytes * safety_reserve_percent / 100), 0) AS pool_safety_buffer,
                COALESCE(SUM(
                    CASE WHEN status = "ACTIVE" AND health_status IN ("HEALTHY", "UNKNOWN")
                    THEN GREATEST(0, CAST((total_capacity_bytes * (100 - safety_reserve_percent) / 100) - used_capacity_bytes - reserved_capacity_bytes AS SIGNED))
                    ELSE 0 END
                ), 0) AS pool_allocatable_free,
                COUNT(*) AS total_accounts,
                COUNT(CASE WHEN status = "ACTIVE" THEN 1 END) AS active_accounts,
                COUNT(CASE WHEN status = "DRAINING" THEN 1 END) AS draining_accounts
            FROM storage_accounts
            WHERE status != "DISCONNECTED"
        ');
        $phy = $saStmt->fetch(PDO::FETCH_ASSOC);

        $totalPhysical = (float)($phy['pool_total_physical'] ?? 0);
        $usedPhysical  = (float)($phy['pool_used_physical'] ?? 0);
        $freePhysical  = max(0, $totalPhysical - $usedPhysical);
        $reserved      = (float)($phy['pool_reserved'] ?? 0);
        $safetyBuffer  = (float)($phy['pool_safety_buffer'] ?? 0);
        $allocatableFree = (float)($phy['pool_allocatable_free'] ?? 0);

        // 2. Logical Space Quotas metrics
        $lqStmt = $db->query('
            SELECT 
                COALESCE(SUM(effective_quota_bytes), 0) AS total_logical_allocated,
                COALESCE(SUM(used_bytes), 0) AS total_logical_used,
                COUNT(*) AS total_spaces
            FROM cloud_space_quotas
        ');
        $log = $lqStmt->fetch(PDO::FETCH_ASSOC);

        $logicalAllocated = (float)($log['total_logical_allocated'] ?? 0);
        $logicalUsed      = (float)($log['total_logical_used'] ?? 0);

        // 3. Overcommit Ratio
        $overcommitRatio = ($totalPhysical > 0) ? round($logicalAllocated / $totalPhysical, 2) : 1.0;

        $riskLevel = 'SAFE';
        if ($overcommitRatio >= 2.0 || ($totalPhysical > 0 && ($allocatableFree / $totalPhysical) < 0.10)) {
            $riskLevel = 'CRITICAL';
        } elseif ($overcommitRatio >= 1.5 || ($totalPhysical > 0 && ($allocatableFree / $totalPhysical) < 0.25)) {
            $riskLevel = 'WARNING';
        }

        Router::json([
            'success' => true,
            'metrics' => [
                'POOL_TOTAL_PHYSICAL'           => (int)$totalPhysical,
                'POOL_USED_PHYSICAL'            => (int)$usedPhysical,
                'POOL_FREE_PHYSICAL'            => (int)$freePhysical,
                'POOL_RESERVED'                 => (int)$reserved,
                'POOL_SAFETY_BUFFER'            => (int)$safetyBuffer,
                'POOL_ALLOCATABLE_FREE'         => (int)$allocatableFree,
                'TOTAL_LOGICAL_QUOTA_ALLOCATED' => (int)$logicalAllocated,
                'TOTAL_LOGICAL_USED'            => (int)$logicalUsed,
                'OVERCOMMIT_RATIO'              => $overcommitRatio,
                'RISK_LEVEL'                    => $riskLevel,
                'total_accounts'                => (int)$phy['total_accounts'],
                'active_accounts'               => (int)$phy['active_accounts'],
                'draining_accounts'             => (int)$phy['draining_accounts'],
                'total_spaces'                  => (int)$log['total_spaces'],
            ]
        ]);
    }

    /**
     * GET /api/v1/cloud/admin/accounts
     */
    public static function listAccounts(array $params, array $body): void {
        self::checkAdmin();
        $db = Database::getConnection();

        $stmt = $db->query('
            SELECT id, provider, display_alias, root_folder_id, total_capacity_bytes,
                   used_capacity_bytes, reserved_capacity_bytes, safety_reserve_percent,
                   status, priority, health_status, last_health_check, active_file_count,
                   created_at, updated_at
            FROM storage_accounts
            ORDER BY priority DESC, created_at DESC
        ');
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Router::json(['success' => true, 'accounts' => $accounts]);
    }

    /**
     * POST /api/v1/cloud/admin/accounts/add
     */
    public static function addAccount(array $params, array $body): void {
        $admin = self::checkAdmin();
        $alias = trim($body['display_alias'] ?? ($body['label'] ?? ''));
        $provider = trim($body['provider'] ?? 'GOOGLE_DRIVE');
        $totalBytes = (int)($body['total_capacity_bytes'] ?? 5368709120000); // Default 5TB
        $rootFolder = !empty($body['root_folder_id']) ? trim($body['root_folder_id']) : 'root';
        $safetyPercent = isset($body['safety_reserve_percent']) ? max(0, min(50, (int)$body['safety_reserve_percent'])) : 10;
        $credentials = $body['credentials'] ?? [];

        if (empty($alias)) {
            Router::error('display_alias is required', 400);
        }

        $encryptedCreds = '';
        if (!empty($credentials)) {
            $encryptedCreds = is_string($credentials) ? CryptoService::encrypt($credentials) : CryptoService::encryptJson($credentials);
        }

        $id = 'sa_' . bin2hex(random_bytes(6));
        $db = Database::getConnection();

        $stmt = $db->prepare('
            INSERT INTO storage_accounts (
                id, provider, display_alias, encrypted_credentials, root_folder_id,
                total_capacity_bytes, safety_reserve_percent, status, priority, health_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, "ACTIVE", 100, "HEALTHY", NOW())
        ');
        $stmt->execute([$id, $provider, $alias, $encryptedCreds, $rootFolder, $totalBytes, $safetyPercent]);

        Router::json([
            'success' => true,
            'message' => "Storage account {$alias} added successfully.",
            'account_id' => $id,
        ], 201);
    }

    /**
     * PATCH /api/v1/cloud/admin/accounts/{id}/status
     */
    public static function updateAccountStatus(array $params, array $body): void {
        self::checkAdmin();
        $accountId = $params['id'] ?? '';
        $newStatus = trim($body['status'] ?? '');

        $validStatuses = ['ACTIVE', 'NEAR_FULL', 'DRAINING', 'OFFLINE', 'DISABLED'];
        if (!in_array($newStatus, $validStatuses, true)) {
            Router::error('Invalid status. Use: ' . implode(', ', $validStatuses), 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE storage_accounts SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $accountId]);

        Router::json([
            'success' => true,
            'message' => "Account {$accountId} status updated to {$newStatus}."
        ]);
    }

    /**
     * POST /api/v1/cloud/admin/accounts/{id}/disconnect
     * SAFE DISCONNECT GUARD: Blocks disconnection if active_file_count > 0
     */
    public static function disconnectAccount(array $params, array $body): void {
        $admin = self::checkAdmin();
        $accountId = $params['id'] ?? '';
        $db = Database::getConnection();

        // 1. Inspect account and query active file count
        $stmt = $db->prepare('
            SELECT id, display_alias, status,
                   (SELECT COUNT(*) FROM cloud_files WHERE storage_account_id = sa.id AND status NOT IN ("DELETED", "PURGING")) as live_files
            FROM storage_accounts sa
            WHERE sa.id = ?
            LIMIT 1
        ');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            Router::error('Storage account not found', 404);
        }

        $liveFiles = (int)$account['live_files'];

        // DISCONNECT GUARD ENFORCEMENT
        if ($liveFiles > 0) {
            Router::error(
                "KHÔNG THỂ NGẮT KẾT NỐI: Tài khoản vẫn còn {$liveFiles} tệp tin đang hoạt động. " .
                "Hãy chuyển sang trạng thái DRAINING và di chuyển hết tệp tin sang tài khoản khác trước khi ngắt kết nối.",
                412,
                'DISCONNECT_GUARD_BLOCKED'
            );
        }

        // Safe Disconnect: Revoke and set DISCONNECTED
        $upd = $db->prepare('
            UPDATE storage_accounts
            SET status = "DISCONNECTED", encrypted_credentials = "", updated_at = NOW()
            WHERE id = ?
        ');
        $upd->execute([$accountId]);

        Router::json([
            'success' => true,
            'message' => "Tài khoản {$account['display_alias']} đã được ngắt kết nối an toàn (0 active files).",
        ]);
    }

    /**
     * POST /api/v1/cloud/admin/spaces/{spaceId}/adjust-quota
     * Adjust quota with mandatory reason into immutable ledger
     */
    public static function adjustQuota(array $params, array $body): void {
        $admin = self::checkAdmin();
        $spaceId = $params['spaceId'] ?? '';
        $deltaGb = (float)($body['delta_gb'] ?? 0);
        $deltaBytes = !empty($body['delta_bytes']) ? (int)$body['delta_bytes'] : (int)round($deltaGb * 1073741824);
        $reason = trim($body['reason'] ?? '');
        $type = trim($body['type'] ?? ($deltaBytes >= 0 ? 'ADMIN_GRANT' : 'ADMIN_REDUCTION'));

        if ($deltaBytes === 0) {
            Router::error('A non-zero delta_bytes or delta_gb is required', 400);
        }

        if (strlen($reason) < 5) {
            Router::error('A mandatory justification reason (at least 5 characters) is required.', 400, 'REASON_REQUIRED');
        }

        $qm = new CloudQuotaManager();
        try {
            $result = $qm->adjustQuota($spaceId, $deltaBytes, $type, $reason, (string)$admin['id']);
            Router::json($result);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/cloud/admin/quota-adjustments
     * Query immutable quota adjustments ledger
     */
    public static function getAdjustmentsLedger(array $params, array $body): void {
        self::checkAdmin();
        $db = Database::getConnection();

        $spaceId = !empty($_GET['space_id']) ? trim($_GET['space_id']) : null;
        $limit   = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        if ($spaceId) {
            $stmt = $db->prepare('
                SELECT cqa.*, cs.name as space_name
                FROM cloud_quota_adjustments cqa
                JOIN cloud_spaces cs ON cqa.cloud_space_id = cs.id
                WHERE cqa.cloud_space_id = ?
                ORDER BY cqa.created_at DESC
                LIMIT ?
            ');
            $stmt->bindValue(1, $spaceId, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('
                SELECT cqa.*, cs.name as space_name
                FROM cloud_quota_adjustments cqa
                JOIN cloud_spaces cs ON cqa.cloud_space_id = cs.id
                ORDER BY cqa.created_at DESC
                LIMIT ?
            ');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Router::json(['success' => true, 'ledger' => $ledger]);
    }

    /**
     * POST /api/v1/cloud/admin/accounts/{id}/refresh-usage
     * Re-queries Google Drive about?fields=storageQuota and updates capacity metrics
     */
    public static function refreshUsage(array $params, array $body): void {
        self::checkAdmin();
        $accountId = $params['id'] ?? '';
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT id, provider, display_alias, encrypted_credentials, root_folder_id, total_capacity_bytes, used_capacity_bytes FROM storage_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            Router::error('Tài khoản lưu trữ không tồn tại', 404);
        }

        if (empty($account['encrypted_credentials'])) {
            Router::error('Tài khoản không có thông tin xác thực', 400);
        }

        try {
            $credentials = CryptoService::decryptJson($account['encrypted_credentials']);
            $adapter = new GoogleDriveStorageAdapter();

            $usage = $adapter->getCapacityUsage($accountId, $credentials);
            $totalBytes = !empty($usage['total_bytes']) ? (int)$usage['total_bytes'] : (int)$account['total_capacity_bytes'];
            $usedBytes = (int)($usage['used_bytes'] ?? 0);

            // If access_token was refreshed, update encrypted credentials
            $updatedCreds = CryptoService::encryptJson($credentials);

            $upd = $db->prepare('
                UPDATE storage_accounts
                SET total_capacity_bytes = ?,
                    used_capacity_bytes = ?,
                    encrypted_credentials = ?,
                    last_usage_refresh = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([$totalBytes, $usedBytes, $updatedCreds, $accountId]);

            Router::json([
                'success'              => true,
                'message'              => "Đã làm mới dung lượng tài khoản {$account['display_alias']} thành công.",
                'account_id'           => $accountId,
                'total_capacity_bytes' => $totalBytes,
                'used_capacity_bytes'  => $usedBytes,
                'free_bytes'           => max(0, $totalBytes - $usedBytes),
                'last_usage_refresh'   => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Router::error('Lỗi khi truy vấn dung lượng Google Drive: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/cloud/admin/accounts/{id}/health-check
     * Validates decryption, token refresh, Drive API ping, and root folder access
     */
    public static function healthCheck(array $params, array $body): void {
        self::checkAdmin();
        $accountId = $params['id'] ?? '';
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT id, provider, display_alias, encrypted_credentials, root_folder_id FROM storage_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            Router::error('Tài khoản lưu trữ không tồn tại', 404);
        }

        if (empty($account['encrypted_credentials'])) {
            Router::error('Tài khoản chưa có thông tin xác thực', 400);
        }

        $checkId = 'csh_' . bin2hex(random_bytes(8));
        try {
            $credentials = CryptoService::decryptJson($account['encrypted_credentials']);
            $adapter = new GoogleDriveStorageAdapter();

            $check = $adapter->healthCheck($accountId, $credentials);
            $status = $check['status'] ?? 'ERROR';
            $responseTimeMs = (int)($check['response_time_ms'] ?? 0);
            $errorMessage = $check['error_message'] ?? null;

            // If HEALTHY, also verify root folder access
            if ($status === 'HEALTHY' && !empty($account['root_folder_id'])) {
                $folderValid = $adapter->verifyFolder($credentials, $account['root_folder_id']);
                if (!$folderValid) {
                    $status = 'UNHEALTHY';
                    $errorMessage = "Thư mục gốc '{$account['root_folder_id']}' không thể truy cập hoặc đã bị chuyển vào Thùng rác trên Google Drive.";
                }
            }

            // Save refreshed credentials if updated
            $updatedCreds = CryptoService::encryptJson($credentials);

            // Update account health in storage_accounts
            $upd = $db->prepare('
                UPDATE storage_accounts
                SET health_status = ?,
                    encrypted_credentials = ?,
                    last_health_check = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([$status, $updatedCreds, $accountId]);

            // Append record to cloud_storage_health
            $logStmt = $db->prepare('
                INSERT INTO cloud_storage_health (
                    id, storage_account_id, check_type, status, response_time_ms, error_message, checked_at
                ) VALUES (?, ?, "MANUAL_CHECK", ?, ?, ?, NOW())
            ');
            $logStmt->execute([$checkId, $accountId, $status, $responseTimeMs, $errorMessage]);

            Router::json([
                'success'           => true,
                'account_id'        => $accountId,
                'health_status'     => $status,
                'response_time_ms'  => $responseTimeMs,
                'error_message'     => $errorMessage,
                'last_health_check' => date('Y-m-d H:i:s'),
                'message'           => ($status === 'HEALTHY')
                    ? "Tài khoản {$account['display_alias']} hoạt động tốt ({$responseTimeMs}ms)."
                    : "Tài khoản {$account['display_alias']} phát hiện sự cố: {$errorMessage}",
            ]);
        } catch (Throwable $e) {
            $logStmt = $db->prepare('
                INSERT INTO cloud_storage_health (
                    id, storage_account_id, check_type, status, error_message, checked_at
                ) VALUES (?, ?, "MANUAL_CHECK", "ERROR", ?, NOW())
            ');
            $logStmt->execute([$checkId, $accountId, $e->getMessage()]);

            $db->prepare('UPDATE storage_accounts SET health_status = "ERROR", last_health_check = NOW() WHERE id = ?')
               ->execute([$accountId]);

            Router::error('Lỗi kiểm tra kết nối: ' . $e->getMessage(), 500);
        }
    }
}

