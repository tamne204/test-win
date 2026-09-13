<?php
/**
 * 2TOOLNE — Billing & Commercial Plans Controller
 * Server-driven Plans, Hosted Checkout, and Payment State Observation
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../services/TeamProvisioningService.php';
require_once __DIR__ . '/../../../sepay_config.php';
require_once __DIR__ . '/../../../lib/SepayClient.php';

class BillingController {

    /**
     * GET /api/v1/billing/team-plans
     * Server is the Single Source of Truth for available Team plans & entitlements
     */
    public static function getTeamPlans(array $params, array $body): void {
        $db = Database::getConnection();

        $stmt = $db->query('
            SELECT id, name, description, price, currency, billing_period, duration_days,
                   member_slots, desktop_key_count, storage_bytes, shared_token_wallet,
                   initial_tokens, features, requires_payment
            FROM team_plans
            WHERE enabled = 1
            ORDER BY sort_order ASC
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $plans = [];
        foreach ($rows as $r) {
            $features = $r['features'];
            if (is_string($features)) {
                $features = json_decode($features, true) ?: [];
            }
            $plans[] = [
                'id'                  => (string)$r['id'],
                'name'                => (string)$r['name'],
                'description'         => (string)($r['description'] ?? ''),
                'price'               => (int)$r['price'],
                'currency'            => (string)($r['currency'] ?? 'VND'),
                'billing_period'      => (string)($r['billing_period'] ?? 'MONTHLY'),
                'duration_days'       => (int)($r['duration_days'] ?? 30),
                'member_slots'        => (int)$r['member_slots'],
                'desktop_key_count'   => (int)$r['desktop_key_count'],
                'storage_bytes'       => (int)$r['storage_bytes'],
                'shared_token_wallet' => (bool)$r['shared_token_wallet'],
                'initial_tokens'      => (int)$r['initial_tokens'],
                'features'            => is_array($features) ? $features : [],
                'requires_payment'    => (bool)$r['requires_payment'],
            ];
        }

        Router::json([
            'ok'    => true,
            'plans' => $plans,
        ]);
    }

    /**
     * POST /api/v1/billing/team-checkout
     * Initiates a commercial checkout for a Team Workspace
     */
    public static function createTeamCheckout(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Vui lòng đăng nhập để tạo không gian Team', 401, 'UNAUTHORIZED');
        }

        $teamName = trim((string)($body['team_name'] ?? ''));
        if (empty($teamName)) {
            Router::error('Vui lòng nhập tên Đội Nhóm', 400, 'TEAM_NAME_INVALID');
        }
        if (mb_strlen($teamName) > 128) {
            Router::error('Tên Đội Nhóm tối đa 128 ký tự', 400, 'TEAM_NAME_TOO_LONG');
        }

        $planId = trim((string)($body['plan_id'] ?? ''));
        if (empty($planId)) {
            Router::error('Vui lòng chọn gói Đội Nhóm', 400, 'PLAN_REQUIRED');
        }

        $db = Database::getConnection();

        // 1. Validate Plan from Database
        $pStmt = $db->prepare('SELECT * FROM team_plans WHERE id = ? AND enabled = 1 LIMIT 1');
        $pStmt->execute([$planId]);
        $plan = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            Router::error('Gói Đội Nhóm không khả dụng hoặc đã dừng hỗ trợ', 404, 'PLAN_NOT_AVAILABLE');
        }

        $userId = (string)$user['id'];
        $username = (string)($user['username'] ?? $userId);
        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ''));
        $checkoutId = !empty($idempotencyKey)
            ? ('chk_' . substr(md5($idempotencyKey . '_' . $userId), 0, 20))
            : ('chk_' . bin2hex(random_bytes(10)));

        // 2. Free / Trial Support (Server declares requires_payment = false or price = 0)
        $requiresPayment = (bool)($plan['requires_payment'] ?? true) && ((int)$plan['price'] > 0);

        if (!$requiresPayment) {
            // Provision immediately without external payment
            $provRes = TeamProvisioningService::provisionTeam(
                $userId,
                $teamName,
                $plan,
                $checkoutId,
                null
            );

            if (!$provRes['ok']) {
                Router::error($provRes['message'] ?? 'Không thể khởi tạo Team', 500, $provRes['error'] ?? 'TEAM_PROVISIONING_FAILED');
            }

            Router::json([
                'ok'               => true,
                'checkout_id'      => $checkoutId,
                'payment_status'   => 'COMPLETED',
                'requires_payment' => false,
                'team'             => $provRes['team'],
                'space'            => $provRes['space'],
            ], 201);
            return;
        }

        // 3. Commercial Paid Plan: Create Pending Order in `orders`
        $orderAmount = (int)$plan['price'];
        $formattedPrice = number_format($orderAmount, 0, ',', '.') . 'đ';
        $durationDays = (int)($plan['duration_days'] ?? 30);
        $memoData = json_encode([
            'team_name'       => $teamName,
            'plan_id'         => $planId,
            'user_id'         => $userId,
            'checkout_id'     => $checkoutId,
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $ordStmt = $db->prepare('
                INSERT INTO orders (id, username, fullname, phone, product, package_name, package_price, duration_days, tier, memo, status, created_at)
                VALUES (?, ?, ?, ?, "TEAM_WORKSPACE", ?, ?, ?, ?, ?, "pending", NOW())
                ON DUPLICATE KEY UPDATE
                    package_name = VALUES(package_name),
                    package_price = VALUES(package_price),
                    memo = VALUES(memo)
            ');
            $ordStmt->execute([
                $checkoutId,
                $username,
                (string)($user['fullname'] ?? $username),
                (string)($user['phone'] ?? ''),
                $plan['name'],
                $formattedPrice,
                $durationDays,
                $planId,
                $memoData,
            ]);
        } catch (Throwable $e) {
            error_log('[BillingController::createTeamCheckout] DB Order Error: ' . $e->getMessage());
            Router::error('Không thể tạo đơn thanh toán', 500, 'CHECKOUT_FAILED');
        }

        // 4. Generate Hosted Checkout URL via SePay Host Page
        $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site');
        $checkoutUrl = "{$siteUrl}/sepay_checkout.php?order_id=" . urlencode($checkoutId);

        Router::json([
            'ok'               => true,
            'checkout_id'      => $checkoutId,
            'order_id'         => $checkoutId,
            'checkout_url'     => $checkoutUrl,
            'payment_status'   => 'PENDING_PAYMENT',
            'requires_payment' => true,
            'plan'             => [
                'id'       => $plan['id'],
                'name'     => $plan['name'],
                'price'    => $orderAmount,
                'currency' => $plan['currency'] ?? 'VND',
            ],
            'team_name'        => $teamName,
        ], 201);
    }

    /**
     * GET /api/v1/billing/team-checkout/{id}
     * Returns the authoritative checkout & provisioning state
     */
    public static function getCheckoutStatus(array $params, array $body): void {
        $checkoutId = trim((string)($params['id'] ?? ($_GET['checkout_id'] ?? '')));
        if (empty($checkoutId)) {
            Router::error('Thiếu mã phiên thanh toán', 400, 'MISSING_CHECKOUT_ID');
        }

        $db = Database::getConnection();

        // 1. Check if team already provisioned
        $tStmt = $db->prepare('SELECT * FROM teams WHERE checkout_id = ? LIMIT 1');
        $tStmt->execute([$checkoutId]);
        $team = $tStmt->fetch(PDO::FETCH_ASSOC);

        if ($team) {
            $spStmt = $db->prepare('SELECT * FROM cloud_spaces WHERE owner_type = "TEAM" AND owner_id = ? LIMIT 1');
            $spStmt->execute([$team['id']]);
            $space = $spStmt->fetch(PDO::FETCH_ASSOC);

            Router::json([
                'ok'             => true,
                'checkout_id'    => $checkoutId,
                'payment_status' => 'COMPLETED',
                'team'           => [
                    'id'            => $team['id'],
                    'name'          => $team['name'],
                    'owner_user_id' => $team['owner_user_id'],
                    'member_slots'  => (int)$team['member_slots'],
                    'app_key_count' => (int)$team['app_key_count'],
                    'workspace_id'  => $space['id'] ?? null,
                    'role'          => 'OWNER',
                ],
                'space'          => $space,
            ]);
            return;
        }

        // 2. Check Order Record
        $ordStmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $ordStmt->execute([$checkoutId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            Router::error('Không tìm thấy phiên thanh toán', 404, 'CHECKOUT_NOT_FOUND');
        }

        $orderStatus = strtolower((string)($order['status'] ?? 'pending'));

        if ($orderStatus === 'approved') {
            // Payment approved but team not yet recorded; auto-reconcile provisioning
            $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
            $planId = $memo['plan_id'] ?? $order['tier'];
            $teamName = $memo['team_name'] ?? 'Team Workspace';
            $userId = $memo['user_id'] ?? $order['username'];

            $pStmt = $db->prepare('SELECT * FROM team_plans WHERE id = ? LIMIT 1');
            $pStmt->execute([$planId]);
            $plan = $pStmt->fetch(PDO::FETCH_ASSOC) ?: ['duration_days' => 30, 'member_slots' => 2, 'desktop_key_count' => 2, 'storage_bytes' => 53687091200, 'initial_tokens' => 500];

            $provRes = TeamProvisioningService::provisionTeam(
                (string)$userId,
                $teamName,
                $plan,
                $checkoutId,
                $order['id']
            );

            if ($provRes['ok']) {
                Router::json([
                    'ok'             => true,
                    'checkout_id'    => $checkoutId,
                    'order_id'       => $checkoutId,
                    'payment_status' => 'COMPLETED',
                    'team_id'        => $provRes['team']['id'] ?? null,
                    'team'           => $provRes['team'],
                    'space'          => $provRes['space'],
                ]);
                return;
            } else {
                Router::json([
                    'ok'             => false,
                    'checkout_id'    => $checkoutId,
                    'payment_status' => 'PROVISIONING_FAILED',
                    'error'          => 'TEAM_PROVISIONING_FAILED',
                    'message'        => 'Thanh toán đã thành công nhưng hệ thống chưa thể hoàn tất không gian Team. 2TOOLNE sẽ thử lại.',
                ]);
                return;
            }
        } elseif ($orderStatus === 'pending') {
            Router::json([
                'ok'             => true,
                'checkout_id'    => $checkoutId,
                'payment_status' => 'PENDING_PAYMENT',
            ]);
            return;
        } elseif ($orderStatus === 'rejected' || $orderStatus === 'cancelled') {
            Router::json([
                'ok'             => true,
                'checkout_id'    => $checkoutId,
                'payment_status' => 'CANCELLED',
            ]);
            return;
        }

        Router::json([
            'ok'             => true,
            'checkout_id'    => $checkoutId,
            'payment_status' => strtoupper($orderStatus),
        ]);
    }

    /**
     * POST /api/v1/billing/team-checkout/{id}/test-pay
     * Test verification endpoint for simulated test payments & idempotent provisioning
     */
    public static function testSimulatePayment(array $params, array $body): void {
        $checkoutId = trim((string)($params['id'] ?? ($_GET['checkout_id'] ?? '')));
        if (empty($checkoutId)) {
            Router::error('Thiếu checkout_id', 400, 'MISSING_CHECKOUT_ID');
        }

        $db = Database::getConnection();
        $ordStmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $ordStmt->execute([$checkoutId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            Router::error('Không tìm thấy đơn thanh toán', 404, 'ORDER_NOT_FOUND');
        }

        $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
        $planId = $memo['plan_id'] ?? $order['tier'];
        $teamName = $memo['team_name'] ?? ('Team ' . $order['username']);
        $userId = (string)($memo['user_id'] ?? $order['username']);

        $pStmt = $db->prepare('SELECT * FROM team_plans WHERE id = ? LIMIT 1');
        $pStmt->execute([$planId]);
        $plan = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            $plan = [
                'name'              => 'Team Starter',
                'duration_days'     => 30,
                'member_slots'      => 2,
                'desktop_key_count' => 2,
                'storage_bytes'     => 53687091200,
                'initial_tokens'    => 500,
            ];
        }

        // Atomically and idempotently provision
        $provRes = TeamProvisioningService::provisionTeam(
            $userId,
            $teamName,
            $plan,
            $checkoutId,
            $order['id']
        );

        if (!$provRes['ok']) {
            Router::error($provRes['message'] ?? 'Provisioning thất bại', 500, $provRes['error'] ?? 'TEAM_PROVISIONING_FAILED');
        }

        Router::json([
            'ok'                  => true,
            'payment_status'      => 'COMPLETED',
            'checkout_id'         => $checkoutId,
            'order_id'            => $checkoutId,
            'team_id'             => $provRes['team']['id'] ?? null,
            'already_provisioned' => $provRes['already_provisioned'] ?? false,
            'team'                => $provRes['team'],
            'space'               => $provRes['space'],
        ]);
    }

    /**
     * GET /api/v1/billing/token-packages
     * Server is the Single Source of Truth for available Token top-up packages
     */
    public static function getTokenPackages(array $params, array $body): void {
        $db = Database::getConnection();

        $stmt = $db->query('
            SELECT id, name, tokens, bonus_tokens, price_vnd, currency, is_unlimited, badge, description
            FROM token_packages
            WHERE enabled = 1
            ORDER BY sort_order ASC
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $packages = [];
        foreach ($rows as $r) {
            $price = (int)$r['price_vnd'];
            $packages[] = [
                'id'              => (string)$r['id'],
                'name'            => (string)$r['name'],
                'tokens'          => (int)$r['tokens'],
                'bonus_tokens'    => (int)$r['bonus_tokens'],
                'total_tokens'    => (int)$r['tokens'] + (int)$r['bonus_tokens'],
                'price'           => $price,
                'currency'        => (string)($r['currency'] ?? 'VND'),
                'formatted_price' => number_format($price, 0, ',', '.') . 'đ',
                'is_unlimited'    => (bool)$r['is_unlimited'],
                'badge'           => $r['badge'] ? (string)$r['badge'] : null,
                'description'     => (string)($r['description'] ?? ''),
            ];
        }

        Router::json([
            'ok'       => true,
            'packages' => $packages,
        ]);
    }

    /**
     * POST /api/v1/billing/token-checkout
     * Initiates a commercial checkout for Token Top-up (Personal or Team)
     */
    public static function createTokenCheckout(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        $userId = $user ? (string)$user['id'] : trim((string)($body['user_id'] ?? ''));

        if (empty($userId)) {
            Router::error('Vui lòng đăng nhập để nạp thêm token', 401, 'UNAUTHORIZED');
        }

        $packageId = trim((string)($body['package_id'] ?? ''));
        if (empty($packageId)) {
            Router::error('Vui lòng chọn gói token cần nạp', 400, 'PACKAGE_REQUIRED');
        }

        $targetType = strtoupper(trim((string)($body['target_type'] ?? 'PERSONAL')));
        if (!in_array($targetType, ['PERSONAL', 'TEAM'], true)) {
            $targetType = 'PERSONAL';
        }

        $teamId = trim((string)($body['team_id'] ?? ''));

        $db = Database::getConnection();

        // 1. Validate Package from Database
        $pStmt = $db->prepare('SELECT * FROM token_packages WHERE id = ? AND enabled = 1 LIMIT 1');
        $pStmt->execute([$packageId]);
        $pkg = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$pkg) {
            Router::error('Gói token không tồn tại hoặc đã dừng hỗ trợ', 404, 'PACKAGE_NOT_FOUND');
        }

        // 2. Validate Team Target
        $teamName = null;
        if ($targetType === 'TEAM') {
            if (empty($teamId)) {
                Router::error('Thiếu team_id khi nạp token cho Đội Nhóm', 400, 'TEAM_ID_REQUIRED');
            }
            $tmStmt = $db->prepare('
                SELECT tm.role, t.name as team_name, t.status
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.team_id = ? AND tm.user_id = ? AND tm.status = "ACTIVE"
                LIMIT 1
            ');
            $tmStmt->execute([$teamId, $userId]);
            $member = $tmStmt->fetch(PDO::FETCH_ASSOC);

            if (!$member || $member['status'] !== 'ACTIVE') {
                Router::error('Bạn không phải thành viên hoạt động của Team này', 403, 'FORBIDDEN');
            }
            if (!in_array(strtoupper($member['role']), ['OWNER', 'ADMIN', 'EDITOR'], true)) {
                Router::error('Bạn không có quyền nạp token cho Team này (Yêu cầu Owner, Admin, hoặc Editor)', 403, 'FORBIDDEN');
            }
            $teamName = $member['team_name'];
        }

        $username = $user ? (string)($user['username'] ?? $userId) : $userId;
        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ''));
        $checkoutId = !empty($idempotencyKey)
            ? ('chk_tok_' . substr(md5($idempotencyKey . '_' . $userId), 0, 18))
            : ('chk_tok_' . bin2hex(random_bytes(10)));

        $orderAmount = (int)$pkg['price_vnd'];
        $formattedPrice = number_format($orderAmount, 0, ',', '.') . 'đ';
        $totalTokens = (int)$pkg['tokens'] + (int)$pkg['bonus_tokens'];

        $memoData = json_encode([
            'package_id'      => $packageId,
            'tokens'          => (int)$pkg['tokens'],
            'bonus_tokens'    => (int)$pkg['bonus_tokens'],
            'total_tokens'    => $totalTokens,
            'is_unlimited'    => (bool)$pkg['is_unlimited'],
            'user_id'         => $userId,
            'username'        => $username,
            'target_type'     => $targetType,
            'team_id'         => !empty($teamId) ? $teamId : null,
            'team_name'       => $teamName,
            'checkout_id'     => $checkoutId,
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $ordStmt = $db->prepare('
                INSERT INTO orders (id, username, fullname, phone, product, package_name, package_price, duration_days, tier, memo, status, created_at)
                VALUES (?, ?, ?, ?, "TOKEN_WALLET", ?, ?, 365, ?, ?, "pending", NOW())
                ON DUPLICATE KEY UPDATE
                    package_name = VALUES(package_name),
                    package_price = VALUES(package_price),
                    memo = VALUES(memo)
            ');
            $ordStmt->execute([
                $checkoutId,
                $username,
                $user ? (string)($user['fullname'] ?? $username) : $username,
                $user ? (string)($user['phone'] ?? '') : '',
                $pkg['name'],
                $formattedPrice,
                $packageId,
                $memoData,
            ]);
        } catch (Throwable $e) {
            error_log('[BillingController::createTokenCheckout] DB Order Error: ' . $e->getMessage());
            Router::error('Không thể tạo đơn nạp token: ' . $e->getMessage(), 500, 'CHECKOUT_FAILED');
        }

        // Generate Hosted Checkout URL via SePay Host Page
        $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site');
        $checkoutUrl = "{$siteUrl}/sepay_checkout.php?order_id=" . urlencode($checkoutId);

        Router::json([
            'ok'             => true,
            'checkout_id'    => $checkoutId,
            'order_id'       => $checkoutId,
            'checkout_url'   => $checkoutUrl,
            'payment_status' => 'PENDING_PAYMENT',
            'package'        => [
                'id'              => $pkg['id'],
                'name'            => $pkg['name'],
                'tokens'          => (int)$pkg['tokens'],
                'bonus_tokens'    => (int)$pkg['bonus_tokens'],
                'total_tokens'    => $totalTokens,
                'price'           => $orderAmount,
                'formatted_price' => $formattedPrice,
                'is_unlimited'    => (bool)$pkg['is_unlimited'],
            ],
            'target_type'    => $targetType,
            'team_id'        => !empty($teamId) ? $teamId : null,
        ], 201);
    }

    /**
     * GET /api/v1/billing/token-checkout/{id}
     * Returns the authoritative checkout & payment status for Token Top-up
     */
    public static function getTokenCheckoutStatus(array $params, array $body): void {
        $checkoutId = trim((string)($params['id'] ?? ($_GET['checkout_id'] ?? '')));
        if (empty($checkoutId)) {
            Router::error('Thiếu mã phiên thanh toán', 400, 'MISSING_CHECKOUT_ID');
        }

        $db = Database::getConnection();
        $ordStmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $ordStmt->execute([$checkoutId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            Router::error('Không tìm thấy phiên thanh toán token', 404, 'CHECKOUT_NOT_FOUND');
        }

        $status = strtolower((string)($order['status'] ?? 'pending'));
        $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
        $targetType = $memo['target_type'] ?? 'PERSONAL';
        $teamId = $memo['team_id'] ?? null;
        $userId = $memo['user_id'] ?? $order['username'];

        $wallet = null;
        if ($targetType === 'TEAM' && !empty($teamId)) {
            $wStmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE team_id = ? LIMIT 1');
            $wStmt->execute([$teamId]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $wStmt = $db->prepare('SELECT balance, reserved_balance FROM credit_wallets WHERE user_id = ? LIMIT 1');
            $wStmt->execute([$userId]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
        }

        $normalizedStatus = ($status === 'approved') ? 'completed' : $status;
        $walletBal = (int)($wallet['balance'] ?? 0);

        Router::json([
            'ok'             => true,
            'checkout_id'    => $checkoutId,
            'order_id'       => $checkoutId,
            'payment_status' => strtoupper($normalizedStatus),
            'status'         => $normalizedStatus,
            'target_type'    => $targetType,
            'team_id'        => $teamId,
            'wallet_balance' => $walletBal,
            'wallet'         => [
                'balance'          => $walletBal,
                'reserved_balance' => (int)($wallet['reserved_balance'] ?? 0),
            ],
            'issued_result'  => $order['issued_key'] ?? null,
        ]);
    }

    /**
     * POST /api/v1/billing/token-checkout/{id}/test-pay
     * Test verification endpoint for simulated test payments & idempotent token crediting
     */
    public static function testSimulateTokenPayment(array $params, array $body): void {
        $checkoutId = trim((string)($params['id'] ?? ($_GET['checkout_id'] ?? '')));
        if (empty($checkoutId)) {
            Router::error('Thiếu checkout_id', 400, 'MISSING_CHECKOUT_ID');
        }

        $db = Database::getConnection();
        $ordStmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $ordStmt->execute([$checkoutId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            Router::error('Không tìm thấy đơn nạp token', 404, 'ORDER_NOT_FOUND');
        }

        if (($order['status'] ?? '') === 'approved') {
            Router::json([
                'ok'                  => true,
                'payment_status'      => 'COMPLETED',
                'checkout_id'         => $checkoutId,
                'already_approved'    => true,
                'issued_result'       => $order['issued_key'] ?? '',
            ]);
            return;
        }

        $memo = json_decode((string)($order['memo'] ?? '{}'), true) ?: [];
        $tokensToAdd = (int)($memo['total_tokens'] ?? ($memo['tokens'] ?? 1000));
        $isUnlimited = (bool)($memo['is_unlimited'] ?? false);
        $targetType = $memo['target_type'] ?? 'PERSONAL';
        $teamId = $memo['team_id'] ?? null;
        $userId = (string)($memo['user_id'] ?? $order['username']);
        $pkgName = $order['package_name'] ?? 'Token Pack';

        $db->beginTransaction();
        try {
            $assignedLabel = '';
            $balanceAfter = 0;

            if ($isUnlimited) {
                $entStmt = $db->prepare('SELECT id FROM license_entitlements WHERE user_id = ? LIMIT 1');
                $entStmt->execute([$userId]);
                if ($entStmt->fetch()) {
                    $db->prepare('UPDATE license_entitlements SET credit_mode = "UNLIMITED", plan = "STUDIO" WHERE user_id = ?')->execute([$userId]);
                } else {
                    $licId = 'lic_' . bin2hex(random_bytes(12));
                    $db->prepare('INSERT INTO license_entitlements (id, user_id, plan, credit_mode, max_devices, created_at) VALUES (?, ?, "STUDIO", "UNLIMITED", 3, NOW())')->execute([$licId, $userId]);
                }
                $assignedLabel = 'UNLIMITED-STUDIO';
                $balanceAfter = 999999;
            } elseif ($targetType === 'TEAM' && !empty($teamId)) {
                // Team Shared Wallet
                $wStmt = $db->prepare('SELECT balance FROM credit_wallets WHERE team_id = ? FOR UPDATE');
                $wStmt->execute([$teamId]);
                $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);

                if (!$wRow) {
                    $newBal = $tokensToAdd;
                    $db->prepare('INSERT INTO credit_wallets (id, team_id, balance, reserved_balance) VALUES (?, ?, ?, 0)')->execute(['cw_' . bin2hex(random_bytes(10)), $teamId, $newBal]);
                } else {
                    $newBal = (int)$wRow['balance'] + $tokensToAdd;
                    $db->prepare('UPDATE credit_wallets SET balance = ? WHERE team_id = ?')->execute([$newBal, $teamId]);
                }

                $txId = 'tx_' . bin2hex(random_bytes(12));
                $db->prepare('
                    INSERT INTO credit_transactions (id, user_id, team_id, amount, balance_after, type, reference_id, description, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, "TOPUP", ?, ?, "SEPAY_AUTO", NOW())
                ')->execute([$txId, $userId, $teamId, $tokensToAdd, $newBal, $checkoutId, "Nạp token Team thành công đơn {$checkoutId}: {$pkgName} (+{$tokensToAdd} Tokens)"]);

                $assignedLabel = "+{$tokensToAdd} TOKENS (TEAM)";
                $balanceAfter = $newBal;
            } else {
                // Personal Wallet
                $wStmt = $db->prepare('SELECT balance FROM credit_wallets WHERE user_id = ? FOR UPDATE');
                $wStmt->execute([$userId]);
                $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);

                if (!$wRow) {
                    $newBal = $tokensToAdd;
                    $db->prepare('INSERT INTO credit_wallets (id, user_id, balance, reserved_balance) VALUES (?, ?, ?, 0)')->execute(['cw_' . bin2hex(random_bytes(10)), $userId, $newBal]);
                } else {
                    $newBal = (int)$wRow['balance'] + $tokensToAdd;
                    $db->prepare('UPDATE credit_wallets SET balance = ? WHERE user_id = ?')->execute([$newBal, $userId]);
                }

                $txId = 'tx_' . bin2hex(random_bytes(12));
                $db->prepare('
                    INSERT INTO credit_transactions (id, user_id, amount, balance_after, type, reference_id, description, created_by, created_at)
                    VALUES (?, ?, ?, ?, "TOPUP", ?, ?, "SEPAY_AUTO", NOW())
                ')->execute([$txId, $userId, $tokensToAdd, $newBal, $checkoutId, "Thanh toán thành công đơn {$checkoutId}: {$pkgName} (+{$tokensToAdd} Tokens)"]);

                $assignedLabel = "+{$tokensToAdd} TOKENS";
                $balanceAfter = $newBal;
            }

            // Update order status to approved
            $upOrd = $db->prepare('UPDATE orders SET status = "approved", issued_key = ? WHERE id = ?');
            $upOrd->execute([$assignedLabel, $checkoutId]);

            $db->commit();

            Router::json([
                'ok'             => true,
                'payment_status' => 'COMPLETED',
                'order_status'   => 'completed',
                'checkout_id'    => $checkoutId,
                'order_id'       => $checkoutId,
                'issued_result'  => $assignedLabel,
                'balance_after'  => $balanceAfter,
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[BillingController::testSimulateTokenPayment] ' . $e->getMessage());
            Router::error('Mô phỏng thanh toán token thất bại: ' . $e->getMessage(), 500);
        }
    }
}

