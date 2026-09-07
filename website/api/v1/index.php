<?php
/**
 * 2TOOLNE UPSCALE — REST API ENTRY POINT (/api/v1/*)
 * DirectAdmin Shared Hosting compatible (PHP 8.x + MySQL/MariaDB)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DeviceController.php';
require_once __DIR__ . '/controllers/LicenseController.php';
require_once __DIR__ . '/controllers/CreditsController.php';
require_once __DIR__ . '/controllers/WalletController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/UpdateController.php';
require_once __DIR__ . '/controllers/CloudFilesController.php';
require_once __DIR__ . '/controllers/CloudUploadsController.php';
require_once __DIR__ . '/controllers/CloudDownloadController.php';
require_once __DIR__ . '/controllers/CloudAdminController.php';
require_once __DIR__ . '/controllers/CloudGoogleOAuthController.php';
require_once __DIR__ . '/controllers/CapCutLicenseController.php';

$router = new Router();

// Auto-Update Check
$router->get('/update/check', [UpdateController::class, 'check']);

// Health Check
$router->get('/', function() {
    Router::json([
        'status' => 'ONLINE',
        'service' => '2toolne Cloud & Upscale API',
        'version' => '2.0.0',
        'runtime' => 'PHP ' . PHP_VERSION,
        'timestamp' => date('c'),
    ]);
});

// Authentication
$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login', [AuthController::class, 'login']);
$router->post('/auth/session/create', [AuthController::class, 'createSession']);
$router->get('/auth/session/status', [AuthController::class, 'getSessionStatus']);

// Devices
$router->post('/devices/activate', [DeviceController::class, 'activate']);
$router->post('/devices/deactivate', [DeviceController::class, 'deactivate']);
$router->get('/devices/status', [DeviceController::class, 'status']);

// License Signing
$router->post('/license/issue', [LicenseController::class, 'issue']);

// CapCut V2 Commercial License Endpoints (Phase 4)
$router->post('/capcut/activate', [CapCutLicenseController::class, 'activate']);
$router->post('/capcut/verify', [CapCutLicenseController::class, 'verify']);
$router->post('/capcut/deactivate', [CapCutLicenseController::class, 'deactivate']);

// Strict 3-Step Token Lifecycle
$router->post('/credits/reserve', [CreditsController::class, 'reserve']);
$router->post('/credits/commit', [CreditsController::class, 'commit']);
$router->post('/credits/release', [CreditsController::class, 'release']);

// Wallet & Purchases
$router->get('/wallet/balance', [WalletController::class, 'getBalance']);
$router->get('/wallet/transactions', [WalletController::class, 'getTransactions']);
$router->get('/packages', [WalletController::class, 'getPackages']);
$router->post('/payments/create', [WalletController::class, 'createPayment']);

// Admin Management
$router->get('/admin/users', [AdminController::class, 'listUsers']);
$router->post('/admin/wallet/adjust', [AdminController::class, 'adjustWallet']);
$router->get('/admin/audit-logs', [AdminController::class, 'getAuditLogs']);

// ==========================================
// 2TOOLNE CLOUD V2 ENDPOINTS
// ==========================================
// Spaces & Quota
$router->get('/cloud/spaces', [CloudFilesController::class, 'listSpaces']);
$router->get('/cloud/spaces/{spaceId}/quota', [CloudFilesController::class, 'getSpaceQuota']);

// Virtual File System (Folders, Files, Trash)
$router->get('/cloud/spaces/{spaceId}/files', [CloudFilesController::class, 'listFiles']);
$router->post('/cloud/spaces/{spaceId}/folders', [CloudFilesController::class, 'createFolder']);
$router->post('/cloud/files/{id}/trash', [CloudFilesController::class, 'trashFile']);
$router->get('/cloud/spaces/{spaceId}/trash', [CloudFilesController::class, 'listTrash']);
$router->post('/cloud/files/{id}/restore', [CloudFilesController::class, 'restoreFile']);
$router->delete('/cloud/files/{id}/permanent', [CloudFilesController::class, 'permanentDeleteFile']);

// Uploads (Direct Resumable & Relay Fallback)
$router->post('/cloud/spaces/{spaceId}/uploads/create', [CloudUploadsController::class, 'create']);
$router->post('/cloud/uploads/{id}/relay', [CloudUploadsController::class, 'relay']);
$router->post('/cloud/spaces/{spaceId}/uploads/{id}/relay', [CloudUploadsController::class, 'relay']);
$router->post('/cloud/uploads/{id}/finalize', [CloudUploadsController::class, 'finalize']);
$router->post('/cloud/uploads/{id}/abort', [CloudUploadsController::class, 'abort']);
$router->post('/cloud/spaces/{spaceId}/uploads/{id}/finalize', [CloudUploadsController::class, 'finalize']);
$router->post('/cloud/spaces/{spaceId}/uploads/{id}/abort', [CloudUploadsController::class, 'abort']);

// Downloads & Streaming
$router->get('/cloud/files/{id}/download', [CloudDownloadController::class, 'download']);
$router->get('/cloud/files/{id}/preview', [CloudDownloadController::class, 'preview']);

// Storage Pool Admin & Operations
$router->get('/cloud/admin/pool/metrics', [CloudAdminController::class, 'getPoolMetrics']);
$router->get('/cloud/admin/accounts', [CloudAdminController::class, 'listAccounts']);
$router->post('/cloud/admin/accounts/add', [CloudAdminController::class, 'addAccount']);
$router->patch('/cloud/admin/accounts/{id}/status', [CloudAdminController::class, 'updateAccountStatus']);
$router->post('/cloud/admin/accounts/{id}/disconnect', [CloudAdminController::class, 'disconnectAccount']);
$router->post('/cloud/admin/accounts/{id}/refresh-usage', [CloudAdminController::class, 'refreshUsage']);
$router->post('/cloud/admin/accounts/{id}/health-check', [CloudAdminController::class, 'healthCheck']);
$router->post('/cloud/admin/spaces/{spaceId}/adjust-quota', [CloudAdminController::class, 'adjustQuota']);
$router->get('/cloud/admin/quota-adjustments', [CloudAdminController::class, 'getAdjustmentsLedger']);

// Google Drive OAuth Flow
$router->get('/admin/cloud/google/connect', [CloudGoogleOAuthController::class, 'connect']);
$router->get('/admin/cloud/google/callback', [CloudGoogleOAuthController::class, 'callback']);

$router->dispatch();
