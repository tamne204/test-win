<?php
/**
 * 2TOOLNE UPSCALE — REST API CONFIGURATION
 * DirectAdmin Shared Hosting (PHP 8.x + MySQL/MariaDB)
 */

declare(strict_types=1);

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'ecxaebka_bot');
define('DB_USER', getenv('DB_USER') ?: 'ecxaebka_bot');
define('DB_PASS', getenv('DB_PASS') ?: 'JTV3SQ6bPqkwZ5UAVa7e');
define('DB_CHARSET', 'utf8mb4');

// Cryptographic Security Secrets
define('HMAC_LICENSE_SECRET', getenv('HMAC_SECRET') ?: '2toolne_license_signature_key_2026_super_secure');
define('JWT_AUTH_SECRET', getenv('JWT_SECRET') ?: '2toolne_jwt_auth_secret_token_key_2026');
define('CLOUD_MASTER_KEY', getenv('CLOUD_MASTER_KEY') ?: '2toolne_cloud_master_aes256_secret_key_2026_salt');

// Application Rules & Feature Flags
define('OFFLINE_GRACE_HOURS', 72);
define('DEFAULT_DEVICE_LIMIT', 3);
define('FREE_SIGNUP_TOKENS', 50);
define('TEAM_PLANS_ENABLED', true); // Live Production Verified: Team Plans enabled
define('STORAGE_SAFETY_PERCENT', 10); // 10% capacity safety reserve buffer

// Token Costs
define('TOKEN_COST_2K', 1);
define('TOKEN_COST_4K', 2);

// Google Drive OAuth 2.0 Configuration (Protected Server-Side Credentials)
define('GOOGLE_DRIVE_CLIENT_ID', getenv('GOOGLE_DRIVE_CLIENT_ID') ?: '672703700939-vovbmvjtakmah8p1ge6c05etfos54uo2.apps.googleusercontent.com');
define('GOOGLE_DRIVE_CLIENT_SECRET', getenv('GOOGLE_DRIVE_CLIENT_SECRET') ?: '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');
define('GOOGLE_DRIVE_REDIRECT_URI', getenv('GOOGLE_DRIVE_REDIRECT_URI') ?: 'https://2tamne.site/api/v1/admin/cloud/google/callback');
define('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file');

// TTS & Voice Cloning Configuration
define('TTS_ENABLED', getenv('TTS_ENABLED') !== 'false');
define('TTS_BILLING_MODE', getenv('TTS_BILLING_MODE') ?: 'FREE');
define('TTS_MAX_TEXT_LENGTH', (int)(getenv('TTS_MAX_TEXT_LENGTH') ?: 10000));
define('TTS_MAX_ACTIVE_JOBS_PER_USER', (int)(getenv('TTS_MAX_ACTIVE_JOBS_PER_USER') ?: 5));
define('TTS_MAX_RETRIES', (int)(getenv('TTS_MAX_RETRIES') ?: 3));
define('TTS_WORKER_LEASE_SECONDS', (int)(getenv('TTS_WORKER_LEASE_SECONDS') ?: 90));
define('TTS_WORKER_HEARTBEAT_SECONDS', (int)(getenv('TTS_WORKER_HEARTBEAT_SECONDS') ?: 30));
define('TTS_DEFAULT_ENGINE', getenv('TTS_DEFAULT_ENGINE') ?: 'QWEN3_TTS');
define('TTS_WORKER_SECRET', getenv('TTS_WORKER_SECRET') ?: '2toolne_tts_worker_secret_key_2026');

