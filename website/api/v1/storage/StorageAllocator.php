<?php
/**
 * 2TOOLNE CLOUD — STORAGE ALLOCATOR
 * Implements the MOST_FREE_SPACE multi-account allocation algorithm with safety buffer enforcement.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class StorageAllocator {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    /**
     * Select the optimal active physical storage account for an incoming upload
     *
     * @param int $incomingFileSizeBytes Size of incoming file in bytes
     * @return array Selected account record ['id', 'provider', 'display_alias', 'encrypted_credentials', 'root_folder_id', 'allocatable_bytes']
     */
    public function selectAccount(int $incomingFileSizeBytes): array {
        $sql = "
            SELECT id, provider, display_alias, encrypted_credentials, root_folder_id,
                   total_capacity_bytes, used_capacity_bytes, reserved_capacity_bytes,
                   safety_reserve_percent,
                   CAST(
                       (total_capacity_bytes * (100 - safety_reserve_percent) / 100) 
                       - used_capacity_bytes - reserved_capacity_bytes
                   AS SIGNED) AS allocatable_bytes
            FROM storage_accounts
            WHERE status = 'ACTIVE'
              AND health_status IN ('HEALTHY', 'UNKNOWN')
              AND CAST(
                    (total_capacity_bytes * (100 - safety_reserve_percent) / 100) 
                    - used_capacity_bytes - reserved_capacity_bytes
                  AS SIGNED) >= :incoming_size
            ORDER BY priority DESC, allocatable_bytes DESC
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':incoming_size' => $incomingFileSizeBytes]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            // Check why no account was selected to provide an informative error
            $cntStmt = $this->db->query("SELECT COUNT(*) AS total FROM storage_accounts WHERE status = 'ACTIVE'");
            $activeCount = (int)$cntStmt->fetch()['total'];

            if ($activeCount === 0) {
                throw new RuntimeException("NO_ACTIVE_STORAGE_ACCOUNT: No storage accounts in ACTIVE state are available in the storage pool.");
            }

            throw new RuntimeException("INSUFFICIENT_POOL_CAPACITY: All active storage accounts in the pool are near full or lack sufficient allocatable capacity.");
        }

        return $account;
    }
}
