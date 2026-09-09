<?php
/**
 * 2TOOLNE UPSCALE — AUTO-UPDATE CONTROLLER
 * Checks system_config table for latest desktop app releases
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Router.php';

class UpdateController {
    public static function check(array $params, array $body): void {
        $appName = strtolower(trim($_GET['app'] ?? 'autoedit'));
        $clientVersion = trim($_GET['version'] ?? '2.0.0');
        $platform = strtolower(trim($_GET['platform'] ?? (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'windows')));
        $arch = strtolower(trim($_GET['arch'] ?? 'arm64'));
        $channel = strtolower(trim($_GET['channel'] ?? 'stable'));

        $db = Database::getConnection();

        // Fetch system_config settings
        $stmt = $db->query("SELECT `config_key`, `config_value` FROM `system_config`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $config = [];
        foreach ($rows as $r) {
            $config[$r['config_key']] = $r['config_value'];
        }

        $isMac = in_array($platform, ['darwin', 'mac', 'macos', 'osx'], true);

        if ($appName === 'autoedit') {
            $latestVersion = $config['autoedit_app_version'] ?? '2.0.1';
            $releaseNotes = $config['autoedit_release_notes'] ?? "2TOOLNE AutoEdit v{$latestVersion}: Bản cập nhật tự động với engine Flow AI, bảo mật OS Keychain, và tối ưu hóa hàng đợi kết xuất.";
            $isMandatory = filter_var($config['autoedit_update_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($isMac) {
                $filename = "2toolne-autoedit-{$latestVersion}-mac-{$arch}.zip";
                $downloadUrl = $config['autoedit_download_url_mac'] ?? "https://www.2tamne.site/downloads/releases/{$filename}";
                $sha256 = $config['autoedit_sha256_mac'] ?? '';
                $fileSize = floatval($config['autoedit_file_size_mac'] ?? 145.0);
            } else {
                $filename = "2toolne-autoedit-Setup-{$latestVersion}.exe";
                $downloadUrl = $config['autoedit_download_url_win'] ?? "https://www.2tamne.site/downloads/releases/{$filename}";
                $sha256 = $config['autoedit_sha256_win'] ?? '';
                $fileSize = floatval($config['autoedit_file_size_win'] ?? 160.0);
            }
            $publishedAt = $config['autoedit_published_at'] ?? date('Y-m-d H:i:s');
        } else {
            // Legacy Upscale app
            $latestVersion = $config['upscale_app_version'] ?? '1.0.2';
            $releaseNotes = $config['upscale_release_notes'] ?? "Phiên bản {$latestVersion}: Tính năng Đăng Nhập Nhanh Qua Trình Duyệt Web (Browser SSO), hỗ trợ Tên đăng nhập và Email, tối ưu hóa hiệu năng.";
            $isMandatory = filter_var($config['upscale_update_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($isMac) {
                $filename = "2toolne_Upscale_latest.dmg";
                $downloadUrl = $config['upscale_download_url_mac'] ?? "https://www.2tamne.site/downloads/{$filename}?v={$latestVersion}";
                $sha256 = $config['upscale_sha256_mac'] ?? '';
                $fileSize = 130.0;
            } else {
                $filename = "2toolne_Upscale_Setup_latest.exe";
                $downloadUrl = $config['upscale_download_url_win'] ?? "https://www.2tamne.site/downloads/{$filename}?v={$latestVersion}";
                $sha256 = $config['upscale_sha256_win'] ?? '';
                $fileSize = 150.0;
            }
            $publishedAt = $config['upscale_published_at'] ?? date('Y-m-d H:i:s');
        }

        $hasUpdate = version_compare($latestVersion, $clientVersion, '>');

        Router::json([
            'success' => true,
            'has_update' => $hasUpdate,
            'current_version' => $clientVersion,
            'latest_version' => $latestVersion,
            'channel' => $channel,
            'release_notes' => $releaseNotes,
            'download_url' => $downloadUrl,
            'mandatory' => $isMandatory,
            'file_size_mb' => $fileSize,
            'filename' => $filename,
            'sha256' => $sha256,
            'package' => [
                'filename' => $filename,
                'url' => $downloadUrl,
                'sha256' => $sha256,
                'size_bytes' => intval($fileSize * 1024 * 1024),
            ],
            'published_at' => $publishedAt,
        ]);
    }
}
