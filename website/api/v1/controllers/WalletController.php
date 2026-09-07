<?php
/**
 * 2TOOLNE UPSCALE — WALLET & PAYMENT CONTROLLER
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class WalletController {
    public static function getBalance(array $params, array $body): void {
        $userId = $_GET['user_id'] ?? $body['user_id'] ?? '';
        if (empty($userId)) {
            Router::error('user_id is required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT w.balance, w.reserved_balance, l.credit_mode, l.plan
            FROM credit_wallets w
            JOIN license_entitlements l ON w.user_id = l.user_id
            WHERE w.user_id = ?
        ');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            Router::error('Wallet not found', 404);
        }

        Router::json([
            'balance' => (int)$row['balance'],
            'reserved' => (int)$row['reserved_balance'],
            'credit_mode' => $row['credit_mode'],
            'plan' => $row['plan'],
        ]);
    }

    public static function getTransactions(array $params, array $body): void {
        $userId = $_GET['user_id'] ?? '';
        if (empty($userId)) {
            Router::error('user_id is required', 400);
        }

        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT id, amount, balance_after, type, reference_id, description, created_at
            FROM credit_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $userId, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        Router::json([
            'transactions' => $rows,
        ]);
    }

    public static function getPackages(array $params, array $body): void {
        $db = Database::getConnection();
        $stmt = $db->query('
            SELECT id, name, tokens, price_vnd, bonus_tokens, description
            FROM token_packages
            WHERE enabled = 1
            ORDER BY sort_order ASC
        ');
        $packages = $stmt->fetchAll();

        Router::json([
            'packages' => $packages,
        ]);
    }

    public static function createPayment(array $params, array $body): void {
        $userId = $body['user_id'] ?? '';
        $packageId = $body['package_id'] ?? '';
        $method = $body['payment_method'] ?? 'BANK_TRANSFER';

        if (empty($userId) || empty($packageId)) {
            Router::error('user_id and package_id are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM token_packages WHERE id = ? AND enabled = 1');
        $stmt->execute([$packageId]);
        $pkg = $stmt->fetch();

        if (!$pkg) {
            Router::error('Invalid or disabled token package', 404);
        }

        $orderId = 'pay_' . bin2hex(random_bytes(10));
        $tokensCredited = (int)$pkg['tokens'] + (int)$pkg['bonus_tokens'];
        $amountVnd = (int)$pkg['price_vnd'];

        $stmt = $db->prepare('
            INSERT INTO payments (
                id, user_id, package_id, amount_vnd, tokens_credited, payment_method, status
            ) VALUES (?, ?, ?, ?, ?, ?, "PENDING")
        ');
        $stmt->execute([$orderId, $userId, $packageId, $amountVnd, $tokensCredited, $method]);

        Router::json([
            'success' => true,
            'payment_id' => $orderId,
            'package_name' => $pkg['name'],
            'amount_vnd' => $amountVnd,
            'tokens_to_receive' => $tokensCredited,
            'bank_info' => [
                'bank_name' => 'Vietcombank',
                'account_number' => '1029384756',
                'account_holder' => 'CONG TY TNHH 2TAMNE',
                'transfer_content' => "2TOOLNE {$orderId}",
            ],
        ], 201);
    }
}
