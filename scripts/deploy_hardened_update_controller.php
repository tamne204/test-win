<?php
declare(strict_types=1);

$credsFile = __DIR__ . '/../deployment_migration/20260909_004300/creds.secret.json';
if (!file_exists($credsFile)) {
    die("FATAL: Credentials missing\n");
}
$creds = json_decode(file_get_contents($credsFile), true)['new_ftp'];

echo "=== DEPLOYING HARDENED UPDATECONTROLLER.PHP ===\n";
$conn = ftp_connect($creds['host'], $creds['port'], 20);
if (!$conn || !ftp_login($conn, $creds['user'], $creds['pass'])) {
    die("FATAL: FTP connection failed.\n");
}
ftp_pasv($conn, true);

$localController = __DIR__ . '/../website/api/v1/controllers/UpdateController.php';
$remotePath = 'api/v1/controllers/UpdateController.php';

if (!ftp_put($conn, $remotePath, $localController, FTP_BINARY)) {
    die("FATAL: Failed to upload UpdateController.php\n");
}
echo "  ✓ UpdateController.php uploaded successfully (" . filesize($localController) . " bytes).\n";
ftp_close($conn);

echo "\n=== RUNNING TARGETED LIVE TESTS ===\n";

// 1. Test anonymous access to update/download without token -> Expect 401
echo "[Test 1] Anonymous download request (no token)...\n";
$ch = curl_init('https://www.2tamne.site/api/v1/update/download');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['User-Agent: curl/7.88.1'],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP Code: $httpCode (Expected: 401)\n";
echo "  Response: $resp\n\n";

// 2. Test browser navigation to update/check -> Expect 403
echo "[Test 2] Browser navigation check (Sec-Fetch-Dest: document, text/html)...\n";
$ch = curl_init('https://www.2tamne.site/api/v1/update/check?app=autoedit&platform=win32&version=2.0.0');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP Code: $httpCode (Expected: 403)\n";
echo "  Response: $resp\n\n";

// 3. Test valid desktop check -> Expect 200 with signed token (15m TTL)
echo "[Test 3] Valid Desktop client check (app=autoedit, version=2.0.0, channel=stable)...\n";
$ch = curl_init('https://www.2tamne.site/api/v1/update/check?app=autoedit&platform=win32&arch=x64&version=2.0.0&channel=stable');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: 2TOOLNE-Desktop-AutoEdit/2.0.0',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP Code: $httpCode (Expected: 200)\n";
echo "  Response: $resp\n\n";

$data = json_decode($resp, true);
$downloadUrl = $data['data']['download_url'] ?? $data['download_url'] ?? null;
echo "  Download URL: $downloadUrl\n\n";

if (!$downloadUrl) {
    die("FATAL: No download URL returned in test 3\n");
}

parse_str(parse_url($downloadUrl, PHP_URL_QUERY), $queryParams);
$token = $queryParams['token'] ?? '';
echo "  Token extracted: " . substr($token, 0, 30) . "...\n";
$decoded = base64_decode($token);
echo "  Decoded Token: $decoded\n\n";

// 4. Test download with valid token using Range header (bytes=0-15) -> Expect 206
echo "[Test 4] Partial download with valid token (Range: bytes=0-15)...\n";
$ch = curl_init($downloadUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_RANGE => '0-15',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$fullResp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($fullResp, 0, $headerSize);
$body = substr($fullResp, $headerSize);
curl_close($ch);
echo "  HTTP Code: $httpCode (Expected: 206)\n";
echo "  Headers:\n$headers\n";
echo "  Bytes returned: " . strlen($body) . " bytes\n";
echo "  Magic Hex: " . bin2hex($body) . " (PK signature: 504b0304...)\n\n";

// 5. Test tampered token -> Expect 403
echo "[Test 5] Tampered token request...\n";
$tamperedToken = base64_encode($decoded . "TAMPER");
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($tamperedToken));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP Code: $httpCode (Expected: 403)\n";
echo "  Response: $resp\n\n";
