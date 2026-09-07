<?php
/**
 * 2TOOLNE CLOUD — GOOGLE DRIVE OAUTH 2.0 CONTROLLER
 * Handles server-side OAuth connect handshake, code exchange, Drive API discovery,
 * encrypted credential storage (AES-256-GCM), and duplicate prevention.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../storage/CryptoService.php';
require_once __DIR__ . '/../storage/GoogleDriveStorageAdapter.php';

class CloudGoogleOAuthController {

    /**
     * Ensure active admin session before initiating or completing OAuth
     */
    private static function checkAdmin(): array {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $user = CloudAuthHelper::getCurrentUser();
        if (!$user || empty($user['is_admin'])) {
            // If called in browser, redirect with error
            header('Location: /license_admin.php?error=admin_required');
            exit;
        }
        return $user;
    }

    /**
     * GET /api/v1/admin/cloud/google/connect
     * Start Google OAuth 2.0 flow: generate secure state, store params in session, redirect to Google consent
     */
    public static function connect(array $params, array $body): void {
        self::checkAdmin();

        // 1. Verify Google Drive OAuth configuration
        if (!defined('GOOGLE_DRIVE_CLIENT_ID') || !defined('GOOGLE_DRIVE_CLIENT_SECRET') ||
            empty(GOOGLE_DRIVE_CLIENT_ID) || empty(GOOGLE_DRIVE_CLIENT_SECRET)) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=CLOUD_GOOGLE_CONFIG_MISSING');
            exit;
        }

        // 2. Read query parameters
        $alias = trim($_GET['alias'] ?? 'Google Drive Storage');
        $safetyReserve = isset($_GET['safety_reserve']) ? max(0, min(50, (int)$_GET['safety_reserve'])) : 10;

        // 3. Generate cryptographically secure state token
        $state = bin2hex(random_bytes(32));

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_alias'] = $alias;
        $_SESSION['google_oauth_safety_reserve'] = $safetyReserve;

        // 4. Build Google OAuth consent URL
        $oauthParams = [
            'client_id'              => GOOGLE_DRIVE_CLIENT_ID,
            'redirect_uri'           => GOOGLE_DRIVE_REDIRECT_URI,
            'response_type'          => 'code',
            'scope'                  => GOOGLE_DRIVE_SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($oauthParams);

        // 5. Redirect browser to Google consent
        header('Location: ' . $authUrl);
        exit;
    }

    /**
     * GET /api/v1/admin/cloud/google/callback
     * Receive authorization code from Google, exchange for tokens, fetch real capacity,
     * find/create root folder, prevent duplicates, encrypt credentials, and save to storage_accounts.
     */
    public static function callback(array $params, array $body): void {
        $admin = self::checkAdmin();

        // 1. Check for errors returned by Google
        if (!empty($_GET['error'])) {
            $err = $_GET['error_description'] ?? $_GET['error'];
            header('Location: /license_admin.php?tab=cloud&oauth_error=' . urlencode($err));
            exit;
        }

        // 2. Validate state token (CSRF guard)
        $receivedState = $_GET['state'] ?? '';
        $sessionState = $_SESSION['google_oauth_state'] ?? '';

        if (empty($receivedState) || empty($sessionState) || !hash_equals($sessionState, $receivedState)) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=STATE_MISMATCH_OR_EXPIRED');
            exit;
        }

        unset($_SESSION['google_oauth_state']);

        $alias = trim($_SESSION['google_oauth_alias'] ?? 'Google Drive Storage');
        $safetyReserve = (int)($_SESSION['google_oauth_safety_reserve'] ?? 10);
        unset($_SESSION['google_oauth_alias'], $_SESSION['google_oauth_safety_reserve']);

        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=MISSING_AUTHORIZATION_CODE');
            exit;
        }

        // 3. Exchange authorization code for tokens via Google OAuth token endpoint
        $ch = curl_init('https://oauth2.googleapis.com/token');
        $postFields = http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_DRIVE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $tokenResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($tokenResponse === false || $httpCode !== 200) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=' . urlencode("TOKEN_EXCHANGE_FAILED_HTTP_{$httpCode}"));
            exit;
        }

        $tokenData = json_decode($tokenResponse, true);
        $accessToken  = $tokenData['access_token'] ?? '';
        $refreshToken = $tokenData['refresh_token'] ?? '';
        $expiresIn    = (int)($tokenData['expires_in'] ?? 3600);

        if (empty($accessToken) || empty($refreshToken)) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=MISSING_REFRESH_TOKEN');
            exit;
        }

        // 4. Fetch Google Drive Account User Info and Storage Quota
        $chAbout = curl_init('https://www.googleapis.com/drive/v3/about?fields=user,storageQuota');
        curl_setopt_array($chAbout, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $aboutResponse = curl_exec($chAbout);
        $aboutCode = curl_getinfo($chAbout, CURLINFO_HTTP_CODE);
        curl_close($chAbout);

        if ($aboutResponse === false || $aboutCode !== 200) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=' . urlencode("DRIVE_ABOUT_FAILED_HTTP_{$aboutCode}"));
            exit;
        }

        $aboutData = json_decode($aboutResponse, true);
        $userProfile  = $aboutData['user'] ?? [];
        $storageQuota = $aboutData['storageQuota'] ?? [];

        $userEmail    = $userProfile['emailAddress'] ?? '';
        $googleUserId = $userProfile['permissionId'] ?? '';

        $totalCapacityBytes = isset($storageQuota['limit']) ? (int)$storageQuota['limit'] : 0;
        $usedCapacityBytes  = isset($storageQuota['usage']) ? (int)$storageQuota['usage'] : 0;

        // If limit is unlimited or missing, default to 15GB
        if ($totalCapacityBytes <= 0) {
            $totalCapacityBytes = 15 * 1024 * 1024 * 1024; // 15GB default
        }

        // 5. Find or Create dedicated root folder '2TOOLNE Cloud Storage'
        $adapter = new GoogleDriveStorageAdapter();
        $tempCreds = [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
            'expires_at'    => time() + $expiresIn,
        ];

        try {
            $rootFolderId = $adapter->findOrCreateRootFolder($tempCreds, '2TOOLNE Cloud Storage');
        } catch (Throwable $e) {
            header('Location: /license_admin.php?tab=cloud&oauth_error=' . urlencode('ROOT_FOLDER_CREATION_FAILED: ' . $e->getMessage()));
            exit;
        }

        // 6. Duplicate Account Prevention Check (GOOGLE_DRIVE_ALREADY_CONNECTED)
        $db = Database::getConnection();
        $checkStmt = $db->query("SELECT id, display_alias, encrypted_credentials, root_folder_id FROM storage_accounts WHERE status != 'DISCONNECTED'");
        $existingAccounts = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($existingAccounts as $ex) {
            if (!empty($ex['encrypted_credentials'])) {
                try {
                    $decrypted = CryptoService::decryptJson($ex['encrypted_credentials']);
                    if (!empty($userEmail) && !empty($decrypted['user_email']) &&
                        strtolower((string)$decrypted['user_email']) === strtolower((string)$userEmail)) {
                        header('Location: /license_admin.php?tab=cloud&oauth_error=GOOGLE_DRIVE_ALREADY_CONNECTED');
                        exit;
                    }
                    if (!empty($googleUserId) && !empty($decrypted['user_id']) &&
                        (string)$decrypted['user_id'] === (string)$googleUserId) {
                        header('Location: /license_admin.php?tab=cloud&oauth_error=GOOGLE_DRIVE_ALREADY_CONNECTED');
                        exit;
                    }
                } catch (Throwable $e) {
                    // Ignore corrupted account record during duplicate check
                }
            }
        }

        // 7. Encrypt OAuth credentials via CryptoService (AES-256-GCM)
        $credentialsPayload = [
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
            'refresh_token' => $refreshToken,
            'access_token'  => $accessToken,
            'expires_at'    => time() + $expiresIn,
            'user_email'    => $userEmail,
            'user_id'       => $googleUserId,
            'connected_at'  => date('Y-m-d H:i:s'),
        ];

        $encryptedCredentials = CryptoService::encryptJson($credentialsPayload);

        // 8. Insert physical storage account into database
        $accountId = 'sa_gd_' . bin2hex(random_bytes(6));
        $displayAlias = !empty($alias) ? $alias : ('Google Drive (' . ($userEmail ?: 'Storage Node') . ')');

        $insertStmt = $db->prepare('
            INSERT INTO storage_accounts (
                id, provider, display_alias, encrypted_credentials, root_folder_id,
                total_capacity_bytes, used_capacity_bytes, reserved_capacity_bytes,
                safety_reserve_percent, status, priority, health_status,
                last_health_check, last_usage_refresh, active_file_count, created_at, updated_at
            ) VALUES (
                ?, "GOOGLE_DRIVE", ?, ?, ?,
                ?, ?, 0,
                ?, "ACTIVE", 100, "HEALTHY",
                NOW(), NOW(), 0, NOW(), NOW()
            )
        ');

        $insertStmt->execute([
            $accountId,
            $displayAlias,
            $encryptedCredentials,
            $rootFolderId,
            $totalCapacityBytes,
            $usedCapacityBytes,
            $safetyReserve
        ]);

        // 9. Record immutable audit log
        try {
            $auditId = 'aud_' . bin2hex(random_bytes(12));
            $adminUser = $admin['username'] ?? 'admin';
            $capGb = round($totalCapacityBytes / (1024 * 1024 * 1024), 2);
            $reason = "Connected Google Drive account {$displayAlias} ({$userEmail}) with capacity {$capGb} GB";
            $details = json_encode([
                'account_id'             => $accountId,
                'provider'               => 'GOOGLE_DRIVE',
                'user_email'             => $userEmail,
                'google_user_id'         => $googleUserId,
                'root_folder_id'         => $rootFolderId,
                'total_capacity_bytes'   => $totalCapacityBytes,
                'used_capacity_bytes'    => $usedCapacityBytes,
                'safety_reserve_percent' => $safetyReserve,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $auditStmt = $db->prepare('
                INSERT INTO admin_audit_logs (
                    id, admin_user_id, action, target_user_id, reason, details, ip_address, created_at
                ) VALUES (?, ?, "GOOGLE_DRIVE_CONNECTED", NULL, ?, ?, ?, NOW())
            ');
            $auditStmt->execute([$auditId, $adminUser, $reason, $details, $ip]);
        } catch (Throwable $e) {}

        // 10. Record initial health check
        try {
            $db->prepare('
                INSERT INTO cloud_storage_health (
                    id, storage_account_id, check_type, status, response_time_ms, checked_at
                ) VALUES (?, ?, "INITIAL_CONNECT", "HEALTHY", 0, NOW())
            ')->execute(['csh_' . bin2hex(random_bytes(6)), $accountId]);
        } catch (Throwable $e) {}

        // 11. Redirect back to Cloud Admin dashboard with success status
        header('Location: /license_admin.php?tab=cloud&oauth_success=1');
        exit;
    }
}
