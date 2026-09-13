<?php
/**
 * 2TOOLNE TTS — EXTENSIBLE BILLING SERVICE
 * Handles credit calculation, reservation, commit, and release.
 * In Phase 1-4, TTS_BILLING_MODE defaults to 'FREE'.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class TtsBillingService {

    /**
     * Calculate token cost for TTS text generation.
     */
    public static function calculateCost(string $text, string $engine, array $options = []): int {
        $mode = defined('TTS_BILLING_MODE') ? TTS_BILLING_MODE : 'FREE';
        if ($mode === 'FREE') {
            return 0;
        }

        // Future Token-based pricing formula: e.g. 1 token per 100 characters
        $len = mb_strlen($text, 'UTF-8');
        $baseTokens = (int)ceil($len / 100);
        if ($baseTokens < 1) {
            $baseTokens = 1;
        }

        // Voice cloning premium multiplier
        if (!empty($options['voice_clone'])) {
            $baseTokens *= 2;
        }

        return $baseTokens;
    }

    /**
     * Reserve credits before job creation.
     */
    public static function reserve(string $userId, int $amount, string $referenceId): array {
        $mode = defined('TTS_BILLING_MODE') ? TTS_BILLING_MODE : 'FREE';
        if ($mode === 'FREE' || $amount <= 0) {
            return [
                'success' => true,
                'reservation_id' => null,
                'amount' => 0,
                'mode' => 'FREE',
            ];
        }

        $db = Database::getConnection();
        $reservationId = 'res_tts_' . bin2hex(random_bytes(8));

        // Check user balance
        $stmt = $db->prepare('SELECT balance FROM user_wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = $row ? (int)$row['balance'] : 0;

        if ($balance < $amount) {
            return [
                'success' => false,
                'error' => 'INSUFFICIENT_CREDITS',
                'balance' => $balance,
                'required' => $amount,
            ];
        }

        // Insert reservation
        $ins = $db->prepare('
            INSERT INTO credits_reservations (id, user_id, project_id, amount, status, created_at, expires_at)
            VALUES (?, ?, ?, ?, "RESERVED", NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ');
        $ins->execute([$reservationId, $userId, $referenceId, $amount]);

        return [
            'success' => true,
            'reservation_id' => $reservationId,
            'amount' => $amount,
            'mode' => 'TOKEN',
        ];
    }

    /**
     * Commit reserved credits upon job completion.
     */
    public static function commit(string $userId, ?string $reservationId, int $actualCost): bool {
        $mode = defined('TTS_BILLING_MODE') ? TTS_BILLING_MODE : 'FREE';
        if ($mode === 'FREE' || empty($reservationId)) {
            return true;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE credits_reservations SET status = "COMMITTED", updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$reservationId, $userId]);

        // Deduct from wallet
        if ($actualCost > 0) {
            $upd = $db->prepare('UPDATE user_wallets SET balance = balance - ?, updated_at = NOW() WHERE user_id = ?');
            $upd->execute([$actualCost, $userId]);
        }

        return true;
    }

    /**
     * Release reserved credits if job is cancelled or failed.
     */
    public static function release(string $userId, ?string $reservationId): bool {
        $mode = defined('TTS_BILLING_MODE') ? TTS_BILLING_MODE : 'FREE';
        if ($mode === 'FREE' || empty($reservationId)) {
            return true;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE credits_reservations SET status = "RELEASED", updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$reservationId, $userId]);

        return true;
    }
}
