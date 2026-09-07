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
        $clientVersion = trim($_GET['version'] ?? '1.0.0');
        $platform = strtolower(trim($_GET['platform'] ?? 'windows'));

        $db = Database::getConnection();

        // Fetch system_config settings
        $stmt = $db->query("SELECT `config_key`, `config_value` FROM `system_config`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $config = [];
        foreach ($rows as $r) {
            $config[$r['config_key']] = $r['config_value'];
        }

        $latestVersion = $config['upscale_app_version'] ?? '1.0.2';
        $releaseNotes = $config['upscale_release_notes'] ?? "Phiên bản {$latestVersion}: Tính năng Đăng Nhập Nhanh Qua Trình Duyệt Web (Browser SSO), hỗ trợ Tên đăng nhập và Email, tối ưu hóa hiệu năng.";
        $isMandatory = filter_var($config['upscale_update_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $isMac = in_array($platform, ['darwin', 'mac', 'macos', 'osx'], true);
        if ($isMac) {
            $downloadUrl = $config['upscale_download_url_mac'] ?? "https://www.2tamne.site/downloads/2toolne_Upscale_latest.dmg?v={$latestVersion}";
            $fileSize = 130.0;
        } else {
            $downloadUrl = $config['upscale_download_url_win'] ?? "https://www.2tamne.site/downloads/2toolne_Upscale_Setup_latest.exe?v={$latestVersion}";
            $fileSize = 150.0;
        }

        $hasUpdate = version_compare($latestVersion, $clientVersion, '>');

        Router::json([
            'success' => true,
            'has_update' => $hasUpdate,
            'current_version' => $clientVersion,
            'latest_version' => $latestVersion,
            'release_notes' => $releaseNotes,
            'download_url' => $downloadUrl,
            'mandatory' => $isMandatory,
            'file_size_mb' => $fileSize,
            'published_at' => $config['upscale_published_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}
