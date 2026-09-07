<?php
/**
 * 2TOOLNE UPSCALE — ADMIN MANAGEMENT CONTROLLER
 * Strict Requirement: Every wallet mutation requires mandatory justification reason and immutable audit log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class AdminController {
    /**
     * Search and list users
     */
    public static function listUsers(array $params, array $body): void {
        $q = trim($_GET['q'] ?? '');
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

        $db = Database::getConnection();
        if (!empty($q)) {
            $stmt = $db->prepare('
                SELECT u.id, u.email, COALESCE(u.fullname, u.username) as full_name, u.role, "ACTIVE" as status, u.created_at,
                       l.plan, l.credit_mode, w.balance as token_balance
                FROM users u
                LEFT JOIN license_entitlements l ON u.id = l.user_id
                LEFT JOIN credit_wallets w ON u.id = w.user_id
                WHERE u.email LIKE ? OR u.username LIKE ? OR u.fullname LIKE ?
                ORDER BY u.created_at DESC LIMIT ?
            ');
            $like = "%{$q}%";
            $stmt->bindValue(1, $like, PDO::PARAM_STR);
            $stmt->bindValue(2, $like, PDO::PARAM_STR);
            $stmt->bindValue(3, $like, PDO::PARAM_STR);
            $stmt->bindValue(4, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('
                SELECT u.id, u.email, COALESCE(u.fullname, u.username) as full_name, u.role, "ACTIVE" as status, u.created_at,
                       l.plan, l.credit_mode, w.balance as token_balance
                FROM users u
                LEFT JOIN license_entitlements l ON u.id = l.user_id
                LEFT JOIN credit_wallets w ON u.id = w.user_id
                ORDER BY u.created_at DESC LIMIT ?
            ');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $users = $stmt->fetchAll();
        Router::json(['users' => $users]);
    }

    /**
     * MANUALLY ADJUST USER TOKENS WITH MANDATORY AUDIT LOG & REASON
     */
    public static function adjustWallet(array $params, array $body): void {
        $adminUserId = $body['admin_user_id'] ?? 'admin_system';
        $targetUserId = trim($body['target_user_id'] ?? '');
        $tokenDelta = (int)($body['amount'] ?? 0);
        $reason = trim($body['reason'] ?? '');

        // MANDATORY REASON VALIDATION
        if (empty($targetUserId)) {
            Router::error('target_user_id is required', 400);
        }

        if ($tokenDelta === 0) {
            Router::error('Adjustment amount cannot be zero', 400);
        }

        if (empty($reason) || strlen($reason) < 5) {
            Router::error('Mandatory justification reason is required (minimum 5 characters)', 400, 'REASON_REQUIRED');
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Lock target wallet
            $stmt = $db->prepare('SELECT balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$targetUserId]);
            $wallet = $stmt->fetch();

            if (!$wallet) {
                $db->rollBack();
                Router::error('User wallet not found', 404);
            }

            $currentBalance = (int)$wallet['balance'];
            $newBalance = $currentBalance + $tokenDelta;

            if ($newBalance < 0) {
                $db->rollBack();
                Router::error('Adjustment would result in negative token balance', 400);
            }

            // 1. Update Wallet
            $stmt = $db->prepare('UPDATE credit_wallets SET balance = ? WHERE user_id = ?');
            $stmt->execute([$newBalance, $targetUserId]);

            // 2. Record Transaction in Immutable Ledger
            $txId = 'tx_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $refId = 'ADM_' . date('YmdHis');

            $stmt = $db->prepare('
                INSERT INTO credit_transactions (
                    id, user_id, amount, balance_after, type, reference_id,
                    description, created_by, created_at
                ) VALUES (?, ?, ?, ?, "ADMIN_ADJUSTMENT", ?, ?, ?, ?)
            ');
            $stmt->execute([
                $txId,
                $targetUserId,
                $tokenDelta,
                $newBalance,
                $refId,
                "Admin adjustment: {$reason}",
                $adminUserId,
                $now,
            ]);

            // 3. MANDATORY ADMIN AUDIT LOG
            $auditId = 'aud_' . bin2hex(random_bytes(12));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $details = json_encode([
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'delta' => $tokenDelta,
                'reference_id' => $refId,
            ]);

            $stmt = $db->prepare('
                INSERT INTO admin_audit_logs (
                    id, admin_user_id, action, target_user_id, reason, details, ip_address, created_at
                ) VALUES (?, ?, "WALLET_ADJUSTMENT", ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$auditId, $adminUserId, $targetUserId, $reason, $details, $ipAddress, $now]);

            $db->commit();

            Router::json([
                'success' => true,
                'target_user_id' => $targetUserId,
                'old_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'delta' => $tokenDelta,
                'audit_id' => $auditId,
                'message' => 'Wallet balance successfully adjusted and recorded in audit log.',
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Admin wallet adjustment failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * View audit logs
     */
    public static function getAuditLogs(array $params, array $body): void {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT a.*, u.email as target_email
            FROM admin_audit_logs a
            LEFT JOIN users u ON a.target_user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        Router::json(['audit_logs' => $logs]);
    }
}
