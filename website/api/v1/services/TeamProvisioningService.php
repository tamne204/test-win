<?php
/**
 * 2TOOLNE — Team Provisioning Service
 * Atomic, Idempotent Team & Workspace Provisioning
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class TeamProvisioningService {

    /**
     * Atomically provision a new Team Workspace after payment or approval.
     * Guaranteed Idempotent by checkout_id / order_id.
     *
     * @param string      $userId     Creator / Owner user ID
     * @param string      $teamName   Team Name
     * @param array       $plan       Plan metadata (member_slots, storage_bytes, etc.)
     * @param string|null $checkoutId Unique checkout session identifier for idempotency
     * @param string|null $orderId    Optional order record ID to approve
     * @return array
     */
    public static function provisionTeam(
        string $userId,
        string $teamName,
        array $plan,
        ?string $checkoutId = null,
        ?string $orderId = null
    ): array {
        $db = Database::getConnection();

        // 1. Idempotency Check: if checkoutId already provisioned, return existing record
        if (!empty($checkoutId)) {
            $checkStmt = $db->prepare('SELECT * FROM teams WHERE checkout_id = ? LIMIT 1');
            $checkStmt->execute([$checkoutId]);
            $existingTeam = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingTeam) {
                $spStmt = $db->prepare('SELECT * FROM cloud_spaces WHERE owner_type = "TEAM" AND owner_id = ? LIMIT 1');
                $spStmt->execute([$existingTeam['id']]);
                $space = $spStmt->fetch(PDO::FETCH_ASSOC);

                return [
                    'ok'                  => true,
                    'already_provisioned' => true,
                    'team'                => [
                        'id'            => $existingTeam['id'],
                        'name'          => $existingTeam['name'],
                        'owner_user_id' => $existingTeam['owner_user_id'],
                        'member_slots'  => (int)$existingTeam['member_slots'],
                        'app_key_count' => (int)$existingTeam['app_key_count'],
                        'workspace_id'  => $space['id'] ?? null,
                        'role'          => 'OWNER',
                    ],
                    'space'               => $space ?: [
                        'id'         => $space['id'] ?? ('cs_team_' . $existingTeam['id']),
                        'name'       => $existingTeam['name'],
                        'owner_type' => 'TEAM',
                        'owner_id'   => $existingTeam['id'],
                    ],
                ];
            }
        }

        $durationDays = max(1, (int)($plan['duration_days'] ?? 30));
        $memberSlots = max(2, (int)($plan['member_slots'] ?? 2));
        $appKeyCount = max(2, (int)($plan['desktop_key_count'] ?? 2));
        $storageBytes = max(1073741824, (int)($plan['storage_bytes'] ?? 53687091200)); // Default 50GB
        $initialTokens = max(0, (int)($plan['initial_tokens'] ?? 0));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

        $teamId = 'tm_' . bin2hex(random_bytes(8));
        $spaceId = 'cs_team_' . bin2hex(random_bytes(8));
        $walletId = 'cw_' . bin2hex(random_bytes(8));

        $db->beginTransaction();
        try {
            // 1. Create Team record
            $tStmt = $db->prepare('
                INSERT INTO teams (id, name, owner_user_id, checkout_id, member_slots, app_key_count, status, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, "ACTIVE", ?, NOW())
            ');
            $tStmt->execute([$teamId, $teamName, $userId, $checkoutId, $memberSlots, $appKeyCount, $expiresAt]);

            // 2. Add creator as OWNER in team_members
            $tmStmt = $db->prepare('
                INSERT INTO team_members (id, team_id, user_id, role, status, joined_at)
                VALUES (?, ?, ?, "OWNER", "ACTIVE", NOW())
            ');
            $tmStmt->execute(['tm_mem_' . bin2hex(random_bytes(8)), $teamId, $userId]);

            // 3. Create Team Cloud Space
            $csStmt = $db->prepare('
                INSERT INTO cloud_spaces (id, owner_type, owner_id, name, status, expires_at, created_at)
                VALUES (?, "TEAM", ?, ?, "ACTIVE", ?, NOW())
            ');
            $csStmt->execute([$spaceId, $teamId, $teamName, $expiresAt]);

            // 4. Create Team Space Quota
            $csqStmt = $db->prepare('
                INSERT INTO cloud_space_quotas (cloud_space_id, base_quota_bytes, effective_quota_bytes, used_bytes, reserved_bytes, quota_expires_at)
                VALUES (?, ?, ?, 0, 0, ?)
            ');
            $csqStmt->execute([$spaceId, $storageBytes, $storageBytes, $expiresAt]);

            // 5. Create Team Shared Wallet
            // CRITICAL FIX: user_id is NULL for Team Wallets. Team wallet is owned by team_id.
            // This prevents duplicate key collision on uk_wallet_user.
            $cwStmt = $db->prepare('
                INSERT INTO credit_wallets (id, user_id, team_id, workspace_id, balance, reserved_balance, currency)
                VALUES (?, NULL, ?, ?, ?, 0, "TOKEN")
            ');
            $cwStmt->execute([$walletId, $teamId, $spaceId, $initialTokens]);

            // 6. Record Initial Tokens transaction if granted
            if ($initialTokens > 0) {
                $txStmt = $db->prepare('
                    INSERT INTO credit_transactions (
                        id, user_id, team_id, workspace_id, amount, balance_after, type, reference_id, description, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, "TEAM_PLAN_INITIAL", ?, ?, "SYSTEM", NOW())
                ');
                $planName = $plan['name'] ?? 'Team Plan';
                $txStmt->execute([
                    'tx_' . bin2hex(random_bytes(10)),
                    $userId,
                    $teamId,
                    $spaceId,
                    $initialTokens,
                    $initialTokens,
                    $checkoutId ?: $orderId ?: $teamId,
                    "Cấp {$initialTokens} Tokens khởi đầu gói {$planName} cho Team {$teamName}",
                ]);
            }

            // 7. Approve order if orderId provided
            if (!empty($orderId)) {
                $ordStmt = $db->prepare('
                    UPDATE orders 
                    SET status = "approved", approved_at = NOW(), issued_key = ? 
                    WHERE id = ?
                ');
                $ordStmt->execute([$teamId, $orderId]);
            }

            $db->commit();

            return [
                'ok'    => true,
                'team'  => [
                    'id'            => $teamId,
                    'name'          => $teamName,
                    'owner_user_id' => $userId,
                    'member_slots'  => $memberSlots,
                    'app_key_count' => $appKeyCount,
                    'workspace_id'  => $spaceId,
                    'role'          => 'OWNER',
                ],
                'space' => [
                    'id'         => $spaceId,
                    'name'       => $teamName,
                    'owner_type' => 'TEAM',
                    'owner_id'   => $teamId,
                ],
                'wallet' => [
                    'id'       => $walletId,
                    'team_id'  => $teamId,
                    'balance'  => $initialTokens,
                ],
            ];
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[TeamProvisioningService::provisionTeam] ERROR: ' . $e->getMessage());
            return [
                'ok'      => false,
                'error'   => 'TEAM_PROVISIONING_FAILED',
                'message' => 'Thanh toán đã thành công nhưng hệ thống chưa thể hoàn tất không gian Team. 2TOOLNE sẽ thử lại.',
            ];
        }
    }
}
