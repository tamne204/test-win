<?php
/**
 * 2TOOLNE UPSCALE — STRICT 3-STEP TOKEN LIFECYCLE CONTROLLER
 * Step 1: RESERVE (Pre-flight check & atomic hold)
 * Step 2: COMMIT (Permanent deduction per successful image + Idempotency)
 * Step 3: RELEASE (Atomic refund on cancellation or error)
 * Fully supports Personal Wallets and Team Workspaces with zero-fallback guarantee.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../services/WorkspacePermissionService.php';

class CreditsController {
    /**
     * STEP 1: RESERVE TOKENS
     */
    public static function reserve(array $params, array $body): void {
        $deviceId = $body['device_id'] ?? '';
        $projectId = $body['project_id'] ?? '';
        $amount = (int)($body['amount'] ?? 0);
        $reservationId = $body['reservation_id'] ?? ('res_' . bin2hex(random_bytes(10)));
        $userId = trim((string)($body['user_id'] ?? ''));
        $teamId = trim((string)($body['team_id'] ?? ''));
        $workspaceId = trim((string)($body['workspace_id'] ?? ''));

        if (empty($projectId) || $amount <= 0) {
            Router::error('project_id and positive amount are required', 400);
        }

        $db = Database::getConnection();

        // 0. Resolve teamId from workspaceId if needed
        if (!empty($workspaceId) && empty($teamId)) {
            $spStmt = $db->prepare('SELECT owner_id, owner_type FROM cloud_spaces WHERE id = ? LIMIT 1');
            $spStmt->execute([$workspaceId]);
            $sp = $spStmt->fetch(PDO::FETCH_ASSOC);
            if ($sp && $sp['owner_type'] === 'TEAM') {
                $teamId = (string)$sp['owner_id'];
            }
        }

        // --- BRANCH A: TEAM WORKSPACE WALLET ---
        if (!empty($teamId)) {
            // Identify user from device if not explicitly provided
            if (empty($userId) && !empty($deviceId)) {
                $fpHash = hash('sha256', $deviceId);
                $dStmt = $db->prepare('SELECT user_id FROM devices WHERE (device_fingerprint_hash = ? OR device_fingerprint = ? OR id = ?) AND status = "ACTIVE" LIMIT 1');
                $dStmt->execute([$fpHash, $deviceId, $deviceId]);
                $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
                if ($dRow) {
                    $userId = (string)$dRow['user_id'];
                }
            }

            if (empty($userId)) {
                Router::error('Tài khoản hợp lệ hoặc thiết bị kích hoạt là bắt buộc.', 403, 'UNAUTHORIZED');
            }

            // Verify team membership & role permission
            $tmStmt = $db->prepare('SELECT role, status FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
            $tmStmt->execute([$teamId, $userId]);
            $member = $tmStmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                Router::error('Bạn không phải thành viên hoạt động của Team này', 403, 'FORBIDDEN');
            }

            if (!WorkspacePermissionService::canUseWorkspaceTokens($member['role'])) {
                Router::error('Bạn không có quyền sử dụng token của Team (Role VIEWER)', 403, 'WORKSPACE_TOKEN_PERMISSION_DENIED');
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? FOR UPDATE');
                $stmt->execute([$teamId]);
                $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$wallet) {
                    $stmt = $db->prepare('INSERT INTO credit_wallets (id, team_id, balance, reserved_balance) VALUES (?, ?, 0, 0)');
                    $stmt->execute(['cw_' . bin2hex(random_bytes(10)), $teamId]);
                    $wallet = ['balance' => 0, 'reserved_balance' => 0];
                }

                $currentBalance = (int)$wallet['balance'];
                // ZERO-FALLBACK INVARIANT: Never charge personal wallet on insufficient team tokens
                if ($currentBalance < $amount) {
                    $db->rollBack();
                    Router::error("Team không đủ token để thực hiện thao tác này. (Hiện có: {$currentBalance}, Cần: {$amount}). Vui lòng liên hệ Chủ nhóm để nạp thêm.", 402, 'INSUFFICIENT_TOKENS');
                }

                $newBalance = $currentBalance - $amount;
                $newReserved = (int)$wallet['reserved_balance'] + $amount;

                $stmt = $db->prepare('UPDATE credit_wallets SET balance = ?, reserved_balance = ? WHERE team_id = ?');
                $stmt->execute([$newBalance, $newReserved, $teamId]);

                $stmt = $db->prepare('
                    INSERT INTO credit_reservations (
                        reservation_id, user_id, team_id, workspace_id, device_id, project_id, amount, committed_amount, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, "PENDING")
                ');
                $stmt->execute([$reservationId, $userId, $teamId, !empty($workspaceId) ? $workspaceId : null, $deviceId, $projectId, $amount]);

                $db->commit();

                Router::json([
                    'success'           => true,
                    'reservation_id'    => $reservationId,
                    'remaining_balance' => $newBalance,
                    'reserved_amount'   => $amount,
                    'team_id'           => $teamId,
                ]);
                return;
            } catch (Throwable $e) {
                $db->rollBack();
                Router::error('Team reservation failed: ' . $e->getMessage(), 500);
            }
        }

        // --- BRANCH B: PERSONAL WALLET ---
        $client = null;

        // 1. Identify User from explicit user_id (Web Account Login)
        if (!empty($userId)) {
            $stmt = $db->prepare('
                SELECT w.user_id, COALESCE(l.credit_mode, "METERED") as credit_mode, w.balance, w.reserved_balance
                FROM credit_wallets w
                LEFT JOIN license_entitlements l ON w.user_id = l.user_id
                WHERE w.user_id = ?
            ');
            $stmt->execute([$userId]);
            $client = $stmt->fetch();
        }

        // 2. Fallback: Identify User from Device Fingerprint
        if (!$client && !empty($deviceId)) {
            $fpHash = hash('sha256', $deviceId);
            $stmt = $db->prepare('
                SELECT d.user_id, l.credit_mode, w.balance, w.reserved_balance
                FROM devices d
                JOIN license_entitlements l ON d.user_id = l.user_id
                JOIN credit_wallets w ON d.user_id = w.user_id
                WHERE (d.device_fingerprint_hash = ? OR d.device_fingerprint = ? OR d.id = ?) AND d.status = "ACTIVE"
            ');
            $stmt->execute([$fpHash, $deviceId, $deviceId]);
            $client = $stmt->fetch();
        }

        if (!$client) {
            Router::error('Tài khoản hợp lệ hoặc thiết bị kích hoạt là bắt buộc.', 403, 'UNAUTHORIZED');
        }

        $userId = $client['user_id'];

        // Unlimited Studio Plan: no deduction needed
        if ($client['credit_mode'] === 'UNLIMITED') {
            Router::json([
                'success' => true,
                'reservation_id' => 'unlimited_' . $projectId,
                'remaining_balance' => 999999,
                'message' => 'Unlimited Studio license active.',
            ]);
            return;
        }

        $db->beginTransaction();
        try {
            // Lock wallet row for update
            $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch();

            $currentBalance = (int)$wallet['balance'];
            if ($currentBalance < $amount) {
                $db->rollBack();
                Router::error("Insufficient tokens. Required: {$amount}, Available: {$currentBalance}.", 402, 'INSUFFICIENT_TOKENS');
            }

            // Move balance to reserved_balance
            $newBalance = $currentBalance - $amount;
            $newReserved = (int)$wallet['reserved_balance'] + $amount;

            $stmt = $db->prepare('UPDATE credit_wallets SET balance = ?, reserved_balance = ? WHERE user_id = ?');
            $stmt->execute([$newBalance, $newReserved, $userId]);

            // Create Reservation Record
            $stmt = $db->prepare('
                INSERT INTO credit_reservations (
                    reservation_id, user_id, device_id, project_id, amount, committed_amount, status
                ) VALUES (?, ?, ?, ?, ?, 0, "PENDING")
            ');
            $stmt->execute([$reservationId, $userId, $deviceId, $projectId, $amount]);

            $db->commit();

            Router::json([
                'success' => true,
                'reservation_id' => $reservationId,
                'remaining_balance' => $newBalance,
                'reserved_amount' => $amount,
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Reservation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * STEP 2: COMMIT TOKENS (WITH ATOMIC IDEMPOTENCY KEY PROTECTION)
     * Conforms to MySQL 5.7 / InnoDB strict ACID transaction semantics.
     */
    public static function commit(array $params, array $body): void {
        $reservationId = trim((string)($body['reservation_id'] ?? ''));
        $projectId = trim((string)($body['project_id'] ?? ($body['job_id'] ?? '')));
        $committedAmount = (int)($body['committed_amount'] ?? 0);
        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ''));
        $userId = trim((string)($body['user_id'] ?? ''));
        $teamId = trim((string)($body['team_id'] ?? ''));
        $workspaceId = trim((string)($body['workspace_id'] ?? ''));

        if ($committedAmount <= 0) {
            Router::error('Positive committed_amount required', 400);
        }

        $db = Database::getConnection();

        // 1. Fast pre-flight check for idempotency key
        if (!empty($idempotencyKey)) {
            $stmt = $db->prepare('SELECT id, balance_after FROM credit_transactions WHERE idempotency_key = ?');
            $stmt->execute([$idempotencyKey]);
            $existingTx = $stmt->fetch();
            if ($existingTx) {
                Router::json([
                    'success' => true,
                    'already_committed' => true,
                    'transaction_id' => $existingTx['id'],
                    'balance_after' => (int)$existingTx['balance_after'],
                ]);
                return;
            }
        }

        // Resolve teamId from workspaceId if needed
        if (!empty($workspaceId) && empty($teamId)) {
            $spStmt = $db->prepare('SELECT owner_id, owner_type FROM cloud_spaces WHERE id = ? LIMIT 1');
            $spStmt->execute([$workspaceId]);
            $sp = $spStmt->fetch(PDO::FETCH_ASSOC);
            if ($sp && $sp['owner_type'] === 'TEAM') {
                $teamId = (string)$sp['owner_id'];
            }
        }

        $db->beginTransaction();
        try {
            $targetTeamId = null;
            $targetWorkspaceId = null;

            // Case A: Reservation-based commit
            if (!empty($reservationId)) {
                $stmt = $db->prepare('SELECT * FROM credit_reservations WHERE reservation_id = ? FOR UPDATE');
                $stmt->execute([$reservationId]);
                $res = $stmt->fetch();

                if (!$res) {
                    $db->rollBack();
                    Router::error('Reservation not found', 404);
                }

                $userId = $res['user_id'];
                $projectId = $projectId ?: ($res['project_id'] ?? 'project_default');
                $targetTeamId = !empty($res['team_id']) ? $res['team_id'] : null;
                $targetWorkspaceId = !empty($res['workspace_id']) ? $res['workspace_id'] : null;

                if (!empty($targetTeamId)) {
                    // Team reservation commit
                    $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? FOR UPDATE');
                    $stmt->execute([$targetTeamId]);
                    $wallet = $stmt->fetch();

                    if (!$wallet) {
                        $db->rollBack();
                        Router::error('Team wallet not found', 404);
                    }

                    $currentReserved = (int)$wallet['reserved_balance'];
                    $newReserved = max(0, $currentReserved - $committedAmount);

                    $stmt = $db->prepare('UPDATE credit_wallets SET reserved_balance = ? WHERE team_id = ?');
                    $stmt->execute([$newReserved, $targetTeamId]);

                    $balanceAfter = (int)$wallet['balance'];
                } else {
                    // Personal reservation commit
                    $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
                    $stmt->execute([$userId]);
                    $wallet = $stmt->fetch();

                    if (!$wallet) {
                        $db->rollBack();
                        Router::error('Wallet not found', 404);
                    }

                    $currentReserved = (int)$wallet['reserved_balance'];
                    $newReserved = max(0, $currentReserved - $committedAmount);

                    $stmt = $db->prepare('UPDATE credit_wallets SET reserved_balance = ? WHERE user_id = ?');
                    $stmt->execute([$newReserved, $userId]);

                    $balanceAfter = (int)$wallet['balance'];
                }

                // Update reservation record
                $newCommittedTotal = (int)$res['committed_amount'] + $committedAmount;
                $resStatus = ($newCommittedTotal >= (int)$res['amount']) ? 'COMMITTED' : 'PENDING';

                $stmt = $db->prepare('
                    UPDATE credit_reservations
                    SET committed_amount = ?, status = ?
                    WHERE reservation_id = ?
                ');
                $stmt->execute([$newCommittedTotal, $resStatus, $reservationId]);
            }
            // Case B: Direct Team Wallet Commit
            else if (!empty($teamId)) {
                $targetTeamId = $teamId;
                $targetWorkspaceId = !empty($workspaceId) ? $workspaceId : null;

                if (empty($userId)) {
                    $db->rollBack();
                    Router::error('user_id is required for team wallet commit', 400);
                }

                $tmStmt = $db->prepare('SELECT role, status FROM team_members WHERE team_id = ? AND user_id = ? AND status = "ACTIVE" LIMIT 1');
                $tmStmt->execute([$teamId, $userId]);
                $member = $tmStmt->fetch(PDO::FETCH_ASSOC);

                if (!$member) {
                    $db->rollBack();
                    Router::error('Bạn không phải thành viên hoạt động của Team này', 403, 'FORBIDDEN');
                }

                if (!WorkspacePermissionService::canUseWorkspaceTokens($member['role'])) {
                    $db->rollBack();
                    Router::error('Bạn không có quyền sử dụng token của Team (Role VIEWER)', 403, 'WORKSPACE_TOKEN_PERMISSION_DENIED');
                }

                $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? FOR UPDATE');
                $stmt->execute([$teamId]);
                $wallet = $stmt->fetch();

                if (!$wallet) {
                    $db->rollBack();
                    Router::error('Team wallet not found', 404);
                }

                $currentBalance = (int)$wallet['balance'];
                if ($currentBalance < $committedAmount) {
                    $db->rollBack();
                    Router::error("Team không đủ token để thực hiện thao tác này. (Hiện có: {$currentBalance}, Cần: {$committedAmount}). Vui lòng liên hệ Chủ nhóm để nạp thêm.", 402, 'INSUFFICIENT_TOKENS');
                }

                $balanceAfter = $currentBalance - $committedAmount;
                $stmt = $db->prepare('UPDATE credit_wallets SET balance = ? WHERE team_id = ?');
                $stmt->execute([$balanceAfter, $teamId]);
            }
            // Case C: Direct Personal Wallet Commit
            else if (!empty($userId)) {
                $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $wallet = $stmt->fetch();

                if (!$wallet) {
                    $db->rollBack();
                    Router::error('Wallet not found', 404);
                }

                $currentBalance = (int)$wallet['balance'];
                if ($currentBalance < $committedAmount) {
                    $db->rollBack();
                    Router::error("Insufficient tokens. Available: {$currentBalance}, Required: {$committedAmount}", 402, 'INSUFFICIENT_TOKENS');
                }

                $balanceAfter = $currentBalance - $committedAmount;
                $stmt = $db->prepare('UPDATE credit_wallets SET balance = ? WHERE user_id = ?');
                $stmt->execute([$balanceAfter, $userId]);
            } else {
                $db->rollBack();
                Router::error('Either reservation_id, team_id, or user_id is required', 400);
            }

            // Extract metadata details
            $fileName = trim((string)($body['file_name'] ?? $body['filename'] ?? ''));
            $resolution = strtoupper(trim((string)($body['resolution'] ?? '')));
            if (empty($resolution)) {
                $resolution = ($committedAmount === 1) ? '2K' : (($committedAmount === 2) ? '4K' : '');
            }
            $txType = !empty($resolution) ? "UPSCALE {$resolution}" : "UPSCALE";

            if (!empty($body['description'])) {
                $description = trim((string)$body['description']);
            } elseif (!empty($fileName)) {
                $description = !empty($resolution) ? "{$fileName} {$resolution} upscale completed" : "{$fileName} upscale completed";
            } else {
                $description = !empty($resolution) ? "{$resolution} upscale completed" : "Local image upscale completed";
            }

            $txId = 'tx_' . bin2hex(random_bytes(12));

            // Record immutable ledger entry with authoritative UNIQUE idempotency_key
            $stmt = $db->prepare('
                INSERT INTO credit_transactions (
                    id, user_id, team_id, workspace_id, amount, balance_after, type, reference_id,
                    idempotency_key, description, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "ENGINE")
            ');
            $stmt->execute([
                $txId,
                $userId,
                $targetTeamId,
                $targetWorkspaceId,
                -$committedAmount,
                $balanceAfter,
                $txType,
                $projectId ?: null,
                $idempotencyKey ?: null,
                $description,
            ]);

            $db->commit();

            Router::json([
                'success' => true,
                'already_committed' => false,
                'transaction_id' => $txId,
                'committed_amount' => $committedAmount,
                'balance_after' => $balanceAfter,
                'team_id' => $targetTeamId,
            ]);
        } catch (PDOException $e) {
            $db->rollBack();
            // Handle MySQL error 1062 / SQLSTATE 23000: Duplicate entry for key uk_credit_transactions_idempotency
            if ($e->getCode() === '23000' && !empty($idempotencyKey)) {
                $stmt = $db->prepare('SELECT id, balance_after FROM credit_transactions WHERE idempotency_key = ?');
                $stmt->execute([$idempotencyKey]);
                $existingTx = $stmt->fetch();
                if ($existingTx) {
                    Router::json([
                        'success' => true,
                        'already_committed' => true,
                        'transaction_id' => $existingTx['id'],
                        'balance_after' => (int)$existingTx['balance_after'],
                    ]);
                    return;
                }
            }
            Router::error('Commit failed: ' . $e->getMessage(), 500);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Commit failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * STEP 3: RELEASE UNUSED TOKENS (ON CANCEL OR ERROR)
     */
    public static function release(array $params, array $body): void {
        $reservationId = $body['reservation_id'] ?? '';
        $projectId = $body['project_id'] ?? '';
        $unspentAmount = (int)($body['unspent_amount'] ?? 0);
        $reason = trim($body['reason'] ?? 'Job cancelled or partial failure');

        if (empty($reservationId) || $unspentAmount <= 0) {
            Router::error('reservation_id and positive unspent_amount required', 400);
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Fetch reservation
            $stmt = $db->prepare('SELECT * FROM credit_reservations WHERE reservation_id = ? FOR UPDATE');
            $stmt->execute([$reservationId]);
            $res = $stmt->fetch();

            if (!$res) {
                $db->rollBack();
                Router::error('Reservation not found', 404);
            }

            $userId = $res['user_id'];
            $targetTeamId = !empty($res['team_id']) ? $res['team_id'] : null;
            $targetWorkspaceId = !empty($res['workspace_id']) ? $res['workspace_id'] : null;

            if (!empty($targetTeamId)) {
                // Team wallet refund
                $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? FOR UPDATE');
                $stmt->execute([$targetTeamId]);
                $wallet = $stmt->fetch();

                $refundAmount = min((int)$wallet['reserved_balance'], $unspentAmount);
                $newReserved = (int)$wallet['reserved_balance'] - $refundAmount;
                $newBalance = (int)$wallet['balance'] + $refundAmount;

                $stmt = $db->prepare('UPDATE credit_wallets SET balance = ?, reserved_balance = ? WHERE team_id = ?');
                $stmt->execute([$newBalance, $newReserved, $targetTeamId]);
            } else {
                // Personal wallet refund
                $stmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $wallet = $stmt->fetch();

                $refundAmount = min((int)$wallet['reserved_balance'], $unspentAmount);
                $newReserved = (int)$wallet['reserved_balance'] - $refundAmount;
                $newBalance = (int)$wallet['balance'] + $refundAmount;

                $stmt = $db->prepare('UPDATE credit_wallets SET balance = ?, reserved_balance = ? WHERE user_id = ?');
                $stmt->execute([$newBalance, $newReserved, $userId]);
            }

            // Update reservation status to RELEASED
            $stmt = $db->prepare('UPDATE credit_reservations SET status = "RELEASED" WHERE reservation_id = ?');
            $stmt->execute([$reservationId]);

            // Record transaction ledger entry
            $txId = 'tx_' . bin2hex(random_bytes(12));
            $stmt = $db->prepare('
                INSERT INTO credit_transactions (
                    id, user_id, team_id, workspace_id, amount, balance_after, type, reference_id,
                    description, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, "RESERVATION_RELEASE", ?, ?, "ENGINE")
            ');
            $stmt->execute([
                $txId,
                $userId,
                $targetTeamId,
                $targetWorkspaceId,
                $refundAmount,
                $newBalance,
                $projectId,
                "Unspent tokens released: {$reason}",
            ]);

            $db->commit();

            Router::json([
                'success' => true,
                'refunded_amount' => $refundAmount,
                'new_balance' => $newBalance,
                'team_id' => $targetTeamId,
                'message' => 'Unspent tokens successfully returned to balance.',
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Release failed: ' . $e->getMessage(), 500);
        }
    }
}
