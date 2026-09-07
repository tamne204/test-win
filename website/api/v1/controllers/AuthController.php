<?php
/**
 * 2TOOLNE UPSCALE — AUTHENTICATION CONTROLLER
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class AuthController {
    public static function register(array $params, array $body): void {
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $fullName = trim($body['full_name'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Router::error('Invalid email address format', 422, 'INVALID_EMAIL');
        }

        if (strlen($password) < 8) {
            Router::error('Password must be at least 8 characters long', 422, 'WEAK_PASSWORD');
        }

        $db = Database::getConnection();

        // Check if email already registered
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Router::error('Email already registered', 409, 'EMAIL_EXISTS');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            // 1. Create User
            $stmt = $db->prepare('
                INSERT INTO users (username, email, password_hash, fullname, role, created_at)
                VALUES (?, ?, ?, ?, "user", ?)
            ');
            $stmt->execute([$email, $email, $passwordHash, $fullName, $now]);
            $userId = (string)$db->lastInsertId();

            // 2. Create License Entitlement
            $licenseId = 'lic_' . bin2hex(random_bytes(12));
            $stmt = $db->prepare('
                INSERT INTO license_entitlements (id, user_id, plan, credit_mode, max_devices, created_at)
                VALUES (?, ?, "PRO", "METERED", ?, ?)
            ');
            $stmt->execute([$licenseId, $userId, DEFAULT_DEVICE_LIMIT, $now]);

            // 3. Create Wallet with Welcome Tokens
            $walletId = 'wal_' . bin2hex(random_bytes(12));
            $initialTokens = FREE_SIGNUP_TOKENS;
            $stmt = $db->prepare('
                INSERT INTO credit_wallets (id, user_id, balance, reserved_balance, currency)
                VALUES (?, ?, ?, 0, "TOKEN")
            ');
            $stmt->execute([$walletId, $userId, $initialTokens]);

            // 4. Record Welcome Transaction
            $txId = 'tx_' . bin2hex(random_bytes(12));
            $stmt = $db->prepare('
                INSERT INTO credit_transactions (
                    id, user_id, amount, balance_after, type, reference_id,
                    description, created_by, created_at
                ) VALUES (?, ?, ?, ?, "PROMOTION", "SIGNUP_BONUS", "Welcome signup bonus tokens", "SYSTEM", ?)
            ');
            $stmt->execute([$txId, $userId, $initialTokens, $initialTokens, $now]);

            $db->commit();

            Router::json([
                'success' => true,
                'user' => [
                    'id' => $userId,
                    'email' => $email,
                    'full_name' => $fullName,
                    'plan' => 'PRO',
                    'token_balance' => $initialTokens,
                ],
                'message' => 'Account successfully created with 50 bonus tokens.',
            ], 201);
        } catch (Throwable $e) {
            $db->rollBack();
            Router::error('Failed to create account: ' . $e->getMessage(), 500);
        }
    }

    public static function login(array $params, array $body): void {
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (empty($email) || empty($password)) {
            Router::error('Email and password are required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT u.*, l.plan, l.credit_mode, l.expires_at, w.balance as token_balance
            FROM users u
            LEFT JOIN license_entitlements l ON u.id = l.user_id
            LEFT JOIN credit_wallets w ON u.id = w.user_id
            WHERE (u.email = ? OR u.username = ?)
        ');
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Router::error('Invalid email or password', 401, 'INVALID_CREDENTIALS');
        }

        // Generate signed token
        $token = hash_hmac('sha256', (string)$user['id'] . time(), JWT_AUTH_SECRET);

        Router::json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (string)$user['id'],
                'email' => $user['email'] ?: $user['username'],
                'username' => $user['username'],
                'full_name' => $user['fullname'] ?? '',
                'role' => $user['role'] ?? 'user',
                'plan' => $user['plan'] ?? 'PRO',
                'credit_mode' => $user['credit_mode'] ?? 'METERED',
                'token_balance' => (int)($user['token_balance'] ?? 0),
                'expires_at' => $user['expires_at'],
            ],
        ]);
    }

    public static function createSession(array $params, array $body): void {
        $challenge = trim($body['challenge'] ?? bin2hex(random_bytes(16)));
        $port = intval($body['port'] ?? 0);
        $sessionId = 'auth_' . bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $db = Database::getConnection();
        $stmt = $db->prepare('
            INSERT INTO app_auth_sessions (id, challenge, port, status, expires_at)
            VALUES (?, ?, ?, "PENDING", ?)
        ');
        $stmt->execute([$sessionId, $challenge, $port, $expiresAt]);

        $authUrl = "https://www.2tamne.site/index.php?app_auth=1&session=" . urlencode($sessionId) . "&challenge=" . urlencode($challenge) . "&port=" . $port;

        Router::json([
            'success' => true,
            'session_id' => $sessionId,
            'challenge' => $challenge,
            'auth_url' => $authUrl,
            'expires_at' => $expiresAt,
        ]);
    }

    public static function getSessionStatus(array $params, array $body): void {
        $sessionId = trim($_GET['session_id'] ?? ($_GET['id'] ?? ''));
        if (empty($sessionId)) {
            Router::error('session_id parameter required', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM app_auth_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            Router::error('Auth session not found', 404);
        }

        if (strtotime($row['expires_at']) < time()) {
            Router::json(['success' => false, 'status' => 'EXPIRED', 'error' => 'Session expired']);
        }

        if ($row['status'] === 'APPROVED') {
            $userPayload = !empty($row['payload']) ? json_decode($row['payload'], true) : [];
            Router::json([
                'success' => true,
                'status' => 'APPROVED',
                'token' => $row['token'],
                'user' => $userPayload,
            ]);
        }

        Router::json([
            'success' => true,
            'status' => $row['status'], // PENDING or REJECTED
        ]);
    }
}
