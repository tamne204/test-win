<?php
declare(strict_types=1);

$credsFile = __DIR__ . '/../deployment_migration/20260909_004300/creds.secret.json';
if (!file_exists($credsFile)) {
    die("FATAL: Credentials missing\n");
}
$creds = json_decode(file_get_contents($credsFile), true)['new_ftp'];

echo "=== [1/4] DEPLOYING UPDATED CONTROLLER & ROUTER TO PRODUCTION ===\n";
$conn = ftp_connect($creds['host'], $creds['port'], 20);
if (!$conn || !ftp_login($conn, $creds['user'], $creds['pass'])) {
    die("FATAL: FTP connection failed.\n");
}
ftp_pasv($conn, true);

$localController = __DIR__ . '/../website/api/v1/controllers/UpdateController.php';
$remoteController = 'api/v1/controllers/UpdateController.php';
if (!ftp_put($conn, $remoteController, $localController, FTP_BINARY)) {
    die("FATAL: Failed to upload UpdateController.php\n");
}
echo "  ✓ UpdateController.php deployed (" . filesize($localController) . " bytes)\n";

$localIndex = __DIR__ . '/../website/api/v1/index.php';
$remoteIndex = 'api/v1/index.php';
if (!ftp_put($conn, $remoteIndex, $localIndex, FTP_BINARY)) {
    die("FATAL: Failed to upload api/v1/index.php\n");
}
echo "  ✓ api/v1/index.php deployed (" . filesize($localIndex) . " bytes)\n";
ftp_close($conn);

echo "\n=== [2/4] ATTACK TEST: SPOOFED DESKTOP HEADERS WITHOUT CREDENTIALS ===\n";
// Attacker sends forged desktop / Electron headers but NO credentials
$ch = curl_init('https://www.2tamne.site/api/v1/update/check?app=autoedit&platform=win32&arch=x64&version=2.0.0&channel=stable');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) 2toolneAutoEdit/2.0.0 Chrome/120.0.6099.291 Electron/30.0.0 Safari/537.36',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "  HTTP Code: $httpCode\n";
echo "  Response: $resp\n";

$spoofedData = json_decode($resp, true);
$spoofedTokenIssued = !empty($spoofedData['download_url']);
echo "  SPOOFED_HEADERS_TOKEN_ISSUED = " . ($spoofedTokenIssued ? 'YES (FAIL)' : 'NO (PASS)') . "\n";
echo "  Public discovery metadata available: " . (!empty($spoofedData['latest_version']) ? 'YES' : 'NO') . "\n";
echo "  Download URL is null: " . ($spoofedData['download_url'] === null ? 'YES (PASS)' : 'NO (FAIL)') . "\n";
echo "  Auth required flag: " . ($spoofedData['auth_required'] === true ? 'YES (PASS)' : 'NO (FAIL)') . "\n";

echo "\n=== [3/4] ATTACK TEST: DIRECT DOWNLOAD ENDPOINT RESISTANCE ===\n";

$tests = [];

// 1. DIRECT_NO_TOKEN
$ch = curl_init('https://www.2tamne.site/api/v1/update/download');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['DIRECT_NO_TOKEN'] = ($code === 401) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 2. RANDOM_TOKEN
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=random_garbage_string_12345');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['RANDOM_TOKEN'] = ($code === 400 || $code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// Helper to generate craft tokens for attack testing
$secret = '2TOOLNE_SECURE_UPDATE_SALT_2026';
function makeTestToken($channel, $app, $platform, $arch, $version, $sha, $principal, $issuedAt, $expires, $nonce, $secret, $tamperSig = false) {
    $payload = "$channel|$app|$platform|$arch|$version|$sha|$principal|$issuedAt|$expires|$nonce";
    $sig = hash_hmac('sha256', $payload, $secret);
    if ($tamperSig) $sig = substr($sig, 0, -4) . 'ffff';
    return base64_encode("$payload|$sig");
}

$now = time();
$sha201 = 'fcdcb6af72d542e6f58bd2b0ca00b82d73c86fdb8cbf4f4f04e6b01b99a8161e';

// 3. EXPIRED_TOKEN
$expiredToken = makeTestToken('stable', 'autoedit', 'win32', 'x64', '2.0.1', $sha201, 'lic_test:usr:dev', $now - 3600, $now - 100, 'nonce1', $secret);
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($expiredToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['EXPIRED_TOKEN'] = ($code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 4. TAMPERED_TOKEN
$tamperedToken = makeTestToken('stable', 'autoedit', 'win32', 'x64', '2.0.1', $sha201, 'lic_test:usr:dev', $now, $now + 900, 'nonce2', $secret, true);
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($tamperedToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['TAMPERED_TOKEN'] = ($code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 5. CROSS_RELEASE_TOKEN (Token for fake release 2.0.2)
$crossRelToken = makeTestToken('stable', 'autoedit', 'win32', 'x64', '2.0.2', $sha201, 'lic_test:usr:dev', $now, $now + 900, 'nonce3', $secret);
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($crossRelToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['CROSS_RELEASE_TOKEN'] = ($code === 404 || $code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 6. CROSS_PRODUCT_TOKEN (Token for upscale requested against autoedit release)
$crossProdToken = makeTestToken('stable', 'upscale', 'win32', 'x64', '2.0.1', $sha201, 'lic_test:usr:dev', $now, $now + 900, 'nonce4', $secret);
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($crossProdToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['CROSS_PRODUCT_TOKEN'] = ($code === 404 || $code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 7. CROSS_PLATFORM_TOKEN (Token for darwin requested on win32)
$crossPlatToken = makeTestToken('stable', 'autoedit', 'darwin', 'x64', '2.0.1', $sha201, 'lic_test:usr:dev', $now, $now + 900, 'nonce5', $secret);
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($crossPlatToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['CROSS_PLATFORM_TOKEN'] = ($code === 404 || $code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

// 8. CROSS_PRINCIPAL_TOKEN (Tampering principal in valid token)
$validTokPlain = "stable|autoedit|win32|x64|2.0.1|$sha201|lic_user_A:usr_A|{$now}|" . ($now + 900) . "|nonce6";
$validTokSig = hash_hmac('sha256', $validTokPlain, $secret);
$tamperedPrinPlain = "stable|autoedit|win32|x64|2.0.1|$sha201|lic_user_B:usr_B|{$now}|" . ($now + 900) . "|nonce6";
$crossPrinToken = base64_encode("$tamperedPrinPlain|$validTokSig");
$ch = curl_init('https://www.2tamne.site/api/v1/update/download?token=' . urlencode($crossPrinToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tests['CROSS_PRINCIPAL_TOKEN'] = ($code === 403) ? "DENIED (HTTP $code)" : "FAIL ($code: $resp)";

foreach ($tests as $tName => $tRes) {
    echo "  $tName = $tRes\n";
}

echo "\n=== [4/4] VALID CLIENT TEST (AUTHORIZED AUTOEDIT CLIENT) ===\n";
// Use the active Windows CapCut entitlement:
// license_id: lic_0d1de1907653cceabfa2
// device_id: dev_7cb0e484f1bc1a580efae823ad6686e89af8cf92bdbe3969c03f08e1ec423264
$authUrl = 'https://www.2tamne.site/api/v1/update/check?app=autoedit&platform=win32&arch=x64&version=2.0.0&channel=stable';
$ch = curl_init($authUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: 2TOOLNE-Desktop-AutoEdit/2.0.0',
        'Accept: application/json',
        'X-License-Id: lic_0d1de1907653cceabfa2',
        'X-Device-Id: dev_7cb0e484f1bc1a580efae823ad6686e89af8cf92bdbe3969c03f08e1ec423264',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "  Check HTTP Code: $code\n";
echo "  Response: $resp\n\n";

$authData = json_decode($resp, true);
$authDownloadUrl = $authData['download_url'] ?? null;
$authAuthorized = $authData['authorized'] ?? false;

echo "  AUTHORIZED_TOKEN_ISSUED = " . ($authDownloadUrl ? 'YES (PASS)' : 'NO (FAIL)') . "\n";
echo "  AUTHORIZED_FLAG = " . ($authAuthorized ? 'TRUE (PASS)' : 'FALSE (FAIL)') . "\n";

if (!$authDownloadUrl) {
    die("FATAL: Authorized client did not receive download URL\n");
}

// Test download stream using issued authorized token
echo "\n  Streaming test with Range: bytes=0-15...\n";
$ch = curl_init($authDownloadUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_RANGE => '0-15',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$fullResp = curl_exec($ch);
$dlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($fullResp, $headerSize);

echo "  Download HTTP Code: $dlCode (Expected: 206)\n";
echo "  Bytes returned: " . strlen($body) . " bytes\n";
echo "  Magic Hex: " . bin2hex($body) . "\n";
$isZipMagic = (bin2hex(substr($body, 0, 4)) === '504b0304');
echo "  ZIP Magic (PK\\x03\\x04): " . ($isZipMagic ? 'MATCH (PASS)' : 'FAIL') . "\n";

echo "\n  Testing authorize endpoint: POST /api/v1/update/authorize...\n";
$ch = curl_init('https://www.2tamne.site/api/v1/update/authorize');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'app' => 'autoedit',
        'version' => '2.0.1',
        'platform' => 'win32',
        'arch' => 'x64',
        'license_id' => 'lic_0d1de1907653cceabfa2',
        'device_id' => 'dev_7cb0e484f1bc1a580efae823ad6686e89af8cf92bdbe3969c03f08e1ec423264',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$authPostResp = curl_exec($ch);
$authPostCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "  POST /update/authorize Code: $authPostCode\n";
echo "  POST /update/authorize Response: $authPostResp\n";
$authPostData = json_decode($authPostResp, true);
echo "  Explicit Token Issued: " . (!empty($authPostData['token']) ? 'YES (PASS)' : 'NO (FAIL)') . "\n";
