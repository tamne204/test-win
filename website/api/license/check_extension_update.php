<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$ver = '1.5.0.8';
$notes = 'Khắc phục triệt để mất bản quyền khi cập nhật, sửa lỗi HWID Mismatch & Tích hợp 1-click update.bat';

$latest_pkg = '2tamne_Labs_Extension_latest.zip';
$versioned_pkg = "2tamne_Labs_Extension_v{$ver}.zip";

echo json_encode([
    'ok' => true,
    'version' => $ver,
    'tag_name' => "v{$ver}",
    'notes' => $notes,
    'download_url' => "https://www.2tamne.site/downloads/{$latest_pkg}",
    'versioned_url' => "https://www.2tamne.site/downloads/{$versioned_pkg}",
    'updated_at' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);
