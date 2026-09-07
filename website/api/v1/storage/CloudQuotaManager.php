<?php
/**
 * 2TOOLNE CLOUD — CLOUD QUOTA MANAGER
 * Manages Cloud Space quotas, atomic reservation locks, over-quota detection, and admin adjustments.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class CloudQuotaManager {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    /**
     * Get detailed quota and usage status for a Cloud Space
     */
    public function getSpaceQuota(string $spaceId): array {
        $stmt = $this->db->prepare("
            SELECT cs.id AS space_id, cs.name AS space_name, cs.status AS space_status,
                   csq.base_quota_bytes, csq.addon_quota_bytes, csq.admin_adjustment_bytes,
                   csq.effective_quota_bytes, csq.used_bytes, csq.reserved_bytes,
                   csq.updated_at
            FROM cloud_spaces cs
            LEFT JOIN cloud_space_quotas csq ON cs.id = csq.cloud_space_id
            WHERE cs.id = :space_id
            LIMIT 1
        ");
        $stmt->execute([':space_id' => $spaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException("Cloud Space not found: {$spaceId}");
        }

        $effective = (int)($row['effective_quota_bytes'] ?? 5368709120);
        $used      = (int)($row['used_bytes'] ?? 0);
        $reserved  = (int)($row['reserved_bytes'] ?? 0);
        $free      = max(0, $effective - ($used + $reserved));
        $isOver    = ($used > $effective);

        return [
            'space_id'               => $row['space_id'],
            'space_name'             => $row['space_name'],
            'space_status'           => $row['space_status'],
            'base_quota_bytes'       => (int)($row['base_quota_bytes'] ?? 5368709120),
            'addon_quota_bytes'      => (int)($row['addon_quota_bytes'] ?? 0),
            'admin_adjustment_bytes' => (int)($row['admin_adjustment_bytes'] ?? 0),
            'effective_quota_bytes'  => $effective,
            'used_bytes'             => $used,
            'reserved_bytes'         => $reserved,
            'free_bytes'             => $free,
            'is_over_quota'          => $isOver,
            'updated_at'             => $row['updated_at'],
        ];
    }

    /**
     * Atomically reserve quota for an incoming upload session
     */
    public function reserveQuota(
        string $spaceId,
        int $fileSizeBytes,
        string $reservationId,
        string $userId,
        string $cloudFileId,
        string $storageAccountId,
        string $sessionUrl,
        ?string $idempotencyKey = null
    ): array {
        $this->db->beginTransaction();
        try {
            // Pessimistic lock on cloud_space_quotas
            $stmt = $this->db->prepare("
                SELECT effective_quota_bytes, used_bytes, reserved_bytes
                FROM cloud_space_quotas
                WHERE cloud_space_id = :space_id
                FOR UPDATE
            ");
            $stmt->execute([':space_id' => $spaceId]);
            $quota = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quota) {
                throw new RuntimeException("Quota record not found for space {$spaceId}");
            }

            $effective = (int)$quota['effective_quota_bytes'];
            $used      = (int)$quota['used_bytes'];
            $reserved  = (int)$quota['reserved_bytes'];

            if (($used + $reserved + $fileSizeBytes) > $effective) {
                $this->db->rollBack();
                throw new RuntimeException('QUOTA_EXCEEDED');
            }

            // Lock and verify physical storage account capacity
            $saStmt = $this->db->prepare("
                SELECT total_capacity_bytes, used_capacity_bytes, reserved_capacity_bytes, safety_reserve_percent
                FROM storage_accounts
                WHERE id = :acc_id AND status = 'ACTIVE'
                FOR UPDATE
            ");
            $saStmt->execute([':acc_id' => $storageAccountId]);
            $sa = $saStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sa) {
                $this->db->rollBack();
                throw new RuntimeException('STORAGE_ACCOUNT_UNAVAILABLE');
            }

            $saTotal = (int)$sa['total_capacity_bytes'];
            $saSafety = (int)($saTotal * (int)$sa['safety_reserve_percent'] / 100);
            $saUsed = (int)$sa['used_capacity_bytes'];
            $saReserved = (int)$sa['reserved_capacity_bytes'];
            $saAllocatable = max(0, $saTotal - $saSafety - $saUsed - $saReserved);

            if ($fileSizeBytes > $saAllocatable) {
                $this->db->rollBack();
                throw new RuntimeException('PHYSICAL_CAPACITY_EXHAUSTED');
            }

            // Update reserved bytes on Space
            $updSpace = $this->db->prepare("
                UPDATE cloud_space_quotas
                SET reserved_bytes = reserved_bytes + :bytes, updated_at = NOW()
                WHERE cloud_space_id = :space_id
            ");
            $updSpace->execute([':bytes' => $fileSizeBytes, ':space_id' => $spaceId]);

            // Update reserved capacity on Physical Account
            $updSa = $this->db->prepare("
                UPDATE storage_accounts
                SET reserved_capacity_bytes = reserved_capacity_bytes + :bytes, updated_at = NOW()
                WHERE id = :acc_id
            ");
            $updSa->execute([':bytes' => $fileSizeBytes, ':acc_id' => $storageAccountId]);

            // Record reservation
            $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours
            $insRes = $this->db->prepare("
                INSERT INTO cloud_upload_reservations (
                    id, cloud_space_id, user_id, cloud_file_id, storage_account_id,
                    session_url, reserved_bytes, status, idempotency_key, expires_at, created_at
                ) VALUES (
                    :id, :space_id, :user_id, :file_id, :acc_id,
                    :url, :bytes, 'RESERVED', :idemp, :expires, NOW()
                )
            ");
            $insRes->execute([
                ':id'       => $reservationId,
                ':space_id' => $spaceId,
                ':user_id'  => $userId,
                ':file_id'  => $cloudFileId,
                ':acc_id'   => $storageAccountId,
                ':url'      => $sessionUrl,
                ':bytes'    => $fileSizeBytes,
                ':idemp'    => $idempotencyKey,
                ':expires'  => $expiresAt,
            ]);

            $this->db->commit();
            return [
                'success'        => true,
                'reservation_id' => $reservationId,
                'reserved_bytes' => $fileSizeBytes,
                'expires_at'     => $expiresAt,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Commit an upload reservation: converts reserved_bytes into used_bytes
     */
    public function commitReservation(string $reservationId, int $actualSizeBytes): void {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT cloud_space_id, storage_account_id, reserved_bytes, status
                FROM cloud_upload_reservations
                WHERE id = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reservationId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$res) {
                throw new RuntimeException("Reservation not found: {$reservationId}");
            }

            if ($res['status'] === 'COMMITTED') {
                $this->db->commit();
                return; // Idempotent
            }

            $spaceId   = $res['cloud_space_id'];
            $accId     = $res['storage_account_id'];
            $resBytes  = (int)$res['reserved_bytes'];

            // 1. Update cloud_space_quotas
            $updSpace = $this->db->prepare("
                UPDATE cloud_space_quotas
                SET reserved_bytes = GREATEST(0, CAST(reserved_bytes AS SIGNED) - :res_bytes),
                    used_bytes     = used_bytes + :actual_bytes,
                    updated_at     = NOW()
                WHERE cloud_space_id = :space_id
            ");
            $updSpace->execute([
                ':res_bytes'    => $resBytes,
                ':actual_bytes' => $actualSizeBytes,
                ':space_id'     => $spaceId,
            ]);

            // 2. Update storage_accounts
            $updSa = $this->db->prepare("
                UPDATE storage_accounts
                SET reserved_capacity_bytes = GREATEST(0, CAST(reserved_capacity_bytes AS SIGNED) - :res_bytes),
                    used_capacity_bytes     = used_capacity_bytes + :actual_bytes,
                    active_file_count       = active_file_count + 1,
                    updated_at              = NOW()
                WHERE id = :acc_id
            ");
            $updSa->execute([
                ':res_bytes'    => $resBytes,
                ':actual_bytes' => $actualSizeBytes,
                ':acc_id'       => $accId,
            ]);

            // 3. Mark reservation COMMITTED
            $updRes = $this->db->prepare("
                UPDATE cloud_upload_reservations
                SET status = 'COMMITTED'
                WHERE id = :id
            ");
            $updRes->execute([':id' => $reservationId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Abort / cancel an upload reservation: releases reserved_bytes back to free pool
     */
    public function abortReservation(string $reservationId, string $reason = 'ABORTED'): void {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT cloud_space_id, storage_account_id, reserved_bytes, status
                FROM cloud_upload_reservations
                WHERE id = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reservationId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$res || $res['status'] !== 'RESERVED') {
                $this->db->commit();
                return;
            }

            $spaceId  = $res['cloud_space_id'];
            $accId    = $res['storage_account_id'];
            $resBytes = (int)$res['reserved_bytes'];

            // Release space reservation
            $this->db->prepare("
                UPDATE cloud_space_quotas
                SET reserved_bytes = GREATEST(0, CAST(reserved_bytes AS SIGNED) - :res_bytes),
                    updated_at     = NOW()
                WHERE cloud_space_id = :space_id
            ")->execute([':res_bytes' => $resBytes, ':space_id' => $spaceId]);

            // Release storage account reservation
            $this->db->prepare("
                UPDATE storage_accounts
                SET reserved_capacity_bytes = GREATEST(0, CAST(reserved_capacity_bytes AS SIGNED) - :res_bytes),
                    updated_at              = NOW()
                WHERE id = :acc_id
            ")->execute([':res_bytes' => $resBytes, ':acc_id' => $accId]);

            // Mark reservation ABORTED
            $this->db->prepare("
                UPDATE cloud_upload_reservations
                SET status = 'ABORTED'
                WHERE id = :id
            ")->execute([':id' => $reservationId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Release used quota when a file is deleted permanently
     */
    public function releaseFileUsage(string $spaceId, string $storageAccountId, int $fileSizeBytes): void {
        $this->db->prepare("
            UPDATE cloud_space_quotas
            SET used_bytes = GREATEST(0, CAST(used_bytes AS SIGNED) - :bytes),
                updated_at = NOW()
            WHERE cloud_space_id = :space_id
        ")->execute([':bytes' => $fileSizeBytes, ':space_id' => $spaceId]);

        $this->db->prepare("
            UPDATE storage_accounts
            SET used_capacity_bytes = GREATEST(0, CAST(used_capacity_bytes AS SIGNED) - :bytes),
                active_file_count   = GREATEST(0, CAST(active_file_count AS SIGNED) - 1),
                updated_at          = NOW()
            WHERE id = :acc_id
        ")->execute([':bytes' => $fileSizeBytes, ':acc_id' => $storageAccountId]);
    }

    /**
     * Adjust quota by Admin with mandatory justification recorded into immutable ledger
     */
    public function adjustQuota(
        string $spaceId,
        int $deltaBytes,
        string $type,
        string $reason,
        string $adminUserId,
        ?string $refId = null
    ): array {
        if (trim($reason) === '' || strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException('A mandatory reason of at least 5 characters is required for quota adjustment.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT base_quota_bytes, addon_quota_bytes, admin_adjustment_bytes, used_bytes
                FROM cloud_space_quotas
                WHERE cloud_space_id = :space_id
                FOR UPDATE
            ");
            $stmt->execute([':space_id' => $spaceId]);
            $quota = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quota) {
                throw new RuntimeException("Space quota not found: {$spaceId}");
            }

            $base = (int)$quota['base_quota_bytes'];
            $addon = (int)$quota['addon_quota_bytes'];
            $newAdj = (int)$quota['admin_adjustment_bytes'] + $deltaBytes;
            $newEffective = max(0, $base + $addon + $newAdj);
            $used = (int)$quota['used_bytes'];

            // Update cloud_space_quotas
            $upd = $this->db->prepare("
                UPDATE cloud_space_quotas
                SET admin_adjustment_bytes = :new_adj,
                    effective_quota_bytes  = :new_eff,
                    updated_at             = NOW()
                WHERE cloud_space_id = :space_id
            ");
            $upd->execute([
                ':new_adj'  => $newAdj,
                ':new_eff'  => $newEffective,
                ':space_id' => $spaceId,
            ]);

            // Insert into immutable ledger
            $adjId = 'cqa_' . bin2hex(random_bytes(8));
            $insLedger = $this->db->prepare("
                INSERT INTO cloud_quota_adjustments (
                    id, cloud_space_id, delta_bytes, type, reason, admin_user_id, reference_id, balance_after_bytes, created_at
                ) VALUES (
                    :id, :space_id, :delta, :type, :reason, :admin_id, :ref_id, :after, NOW()
                )
            ");
            $insLedger->execute([
                ':id'       => $adjId,
                ':space_id' => $spaceId,
                ':delta'    => $deltaBytes,
                ':type'     => $type,
                ':reason'   => $reason,
                ':admin_id' => $adminUserId,
                ':ref_id'   => $refId,
                ':after'    => $newEffective,
            ]);

            // Update Space status to OVER_QUOTA if usage exceeds new effective quota
            $newStatus = ($used > $newEffective) ? 'OVER_QUOTA' : 'ACTIVE';
            $this->db->prepare("
                UPDATE cloud_spaces
                SET status = :status, updated_at = NOW()
                WHERE id = :space_id AND status IN ('ACTIVE', 'OVER_QUOTA')
            ")->execute([':status' => $newStatus, ':space_id' => $spaceId]);

            $this->db->commit();
            return [
                'success'               => true,
                'adjustment_id'         => $adjId,
                'space_id'              => $spaceId,
                'delta_bytes'           => $deltaBytes,
                'new_effective_quota'   => $newEffective,
                'used_bytes'            => $used,
                'space_status'          => $newStatus,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
