<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
$storage_dir = dirname(dirname(__DIR__)) . '/storage';
$sys_config = json_decode(@file_get_contents($storage_dir . '/system_config.json'), true) ?: [];

$client_plat = strtolower(trim($_GET['client_platform'] ?? $_POST['client_platform'] ?? 'windows'));
$is_mac = (strpos($client_plat, 'mac') !== false || strpos($client_plat, 'darwin') !== false);

if ($is_mac) {
    $ver = $sys_config['app_version_mac'] ?? '2.3.9';
    $pkg = "SlideshowBuilder_macOS_v{$ver}.zip";
    $ptag = 'mac-arm64';
    $sz = 6599000;
    $active_broadcast = null;
} else {
    $ver = $sys_config['app_version_win'] ?? $sys_config['app_version'] ?? '2.3.9';
    $pkg = "SlideshowBuilder_Windows_v{$ver}.zip";
    $ptag = 'win-x64';
    $sz = 6599000;
    $active_broadcast = (!empty($sys_config['broadcast_notice']['active'])) ? $sys_config['broadcast_notice'] : null;
}

$url = "https://www.2tamne.site/downloads/{$pkg}";
echo json_encode([
    'ok' => true,
    'tag_name' => "v{$ver}",
    'version' => $ver,
    'official_version' => $ver,
    'release_notes' => $sys_config['release_notes'] ?? '',
    'published_at' => date('Y-m-d H:i:s'),
    'html_url' => $url,
    'download_url' => $url,
    'assets' => [
        [
            'name' => $pkg,
            'download_url' => $url,
            'platform_tag' => $ptag,
            'size' => $sz
        ]
    ],
    'broadcast_notice' => $active_broadcast,
    'error' => null
], JSON_UNESCAPED_UNICODE);
