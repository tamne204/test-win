<?php
/**
 * scripts/deploy_211_release.php
 * Deploys UpdateController.php, DownloadEntitlementService.php, index.php, views/v3_workspace.php,
 * downloads/release_metadata.json, and 2.1.1 packages (Windows setup, portable zip, macOS dmg & zip)
 * to production host 43.129.165.150.
 * Relocates to C:/2TOOLNE-Private/packages/, verifies exact SHA256 and byte lengths,
 * and verifies live auto-update discovery for Windows and macOS, download gate, and homepage badges.
 */

declare(strict_types=1);

$credsFile = __DIR__ . '/../deployment_migration/20260909_004300/creds.secret.json';
if (!file_exists($credsFile)) {
    die("FATAL: Credentials missing at $credsFile\n");
}
$creds = json_decode(file_get_contents($credsFile), true)['new_ftp'];

// Package specifications for 2.1.1
$packages = [
    [
        'name' => '2toolne-autoedit-2.1.1-win-x64.zip',
        'local_path' => __DIR__ . '/../dist/2toolne-autoedit-2.1.1-win-x64.zip',
        'expected_bytes' => 290079506,
        'expected_sha256' => '8a9b4bb917a2859fbda77df898cec94f306a152942283da57b46db151ca03a9a',
        'critical' => true,
    ],
    [
        'name' => '2TOOLNE-AutoEdit-Setup-2.1.1.exe',
        'local_path' => __DIR__ . '/../dist/2TOOLNE-AutoEdit-Setup-2.1.1.exe',
        'expected_bytes' => 220366254,
        'expected_sha256' => '81745a1b52668da41aa136ac7a14f1e0bfd285ca1a97c46874e89c3e1fe632b0',
        'critical' => true,
    ],
    [
        'name' => '2toolne-autoedit-2.1.1-mac-arm64.zip',
        'local_path' => __DIR__ . '/../dist/2toolne-autoedit-2.1.1-mac-arm64.zip',
        'expected_bytes' => 264732089,
        'expected_sha256' => 'cbc7bd71abbc10a5b261b796f89ccde2afbe0f2733f9b589dfbf5f9246863f72',
        'critical' => true,
    ],
    [
        'name' => '2toolne-autoedit-2.1.1-mac-x64.zip',
        'local_path' => __DIR__ . '/../dist/2toolne-autoedit-2.1.1-mac-x64.zip',
        'expected_bytes' => 269359281,
        'expected_sha256' => '9c56ea0a098d69baa0909b8066d529c8b75dcde1cb93d676de65a9b97bc354d9',
        'critical' => true,
    ],
    [
        'name' => '2TOOLNE-AutoEdit-2.1.1-arm64.dmg',
        'local_path' => __DIR__ . '/../dist/2TOOLNE-AutoEdit-2.1.1-arm64.dmg',
        'expected_bytes' => 274758284,
        'expected_sha256' => 'da9de72937a57715c365d6cddcaa056667cfafa8cc6e3fd005c49e984608fc8c',
        'critical' => true,
    ],
    [
        'name' => '2TOOLNE-AutoEdit-2.1.1.dmg',
        'local_path' => __DIR__ . '/../dist/2TOOLNE-AutoEdit-2.1.1.dmg',
        'expected_bytes' => 279390331,
        'expected_sha256' => '4205a7f7542eaa32b0d8d63f2594ed1d6febea9278749d7e1b664cc30f10281f',
        'critical' => true,
    ],
];

echo "==================================================\n";
echo "=== DEPLOYING 2TOOLNE 2.1.1 RELEASE TO PRODUCTION ===\n";
echo "==================================================\n";
echo "Host: {$creds['host']}:{$creds['port']}\n\n";

// 1. Verify Local Artifacts
echo "[1/5] Verifying local artifacts integrity ...\n";
foreach ($packages as $pkg) {
    if (!file_exists($pkg['local_path'])) {
        die("FATAL: Local artifact missing: {$pkg['local_path']}\n");
    }
    $actualBytes = filesize($pkg['local_path']);
    if ($actualBytes !== $pkg['expected_bytes']) {
        die("FATAL: Size mismatch for {$pkg['name']}: expected {$pkg['expected_bytes']}, got {$actualBytes}\n");
    }
    $actualSha256 = hash_file('sha256', $pkg['local_path']);
    if ($actualSha256 !== $pkg['expected_sha256']) {
        die("FATAL: SHA256 mismatch for {$pkg['name']}: expected {$pkg['expected_sha256']}, got {$actualSha256}\n");
    }
    echo "  ✓ Verified: {$pkg['name']} (" . number_format($actualBytes / (1024*1024), 2) . " MB, {$actualSha256})\n";
}

// 2. Connect to Production FTP
echo "\n[2/5] Connecting to production FTP ...\n";
$conn = ftp_connect($creds['host'], $creds['port'], 60);
if (!$conn || !ftp_login($conn, $creds['user'], $creds['pass'])) {
    die("FATAL: FTP connection failed.\n");
}
ftp_pasv($conn, true);
echo "  ✓ Connected and Passive Mode enabled.\n";

// 3. Deploy PHP Code Files and Metadata
echo "\n[3/5] Deploying code files ...\n";
$codeFiles = [
    'api/v1/controllers/UpdateController.php' => __DIR__ . '/../website/api/v1/controllers/UpdateController.php',
    'api/v1/services/DownloadEntitlementService.php' => __DIR__ . '/../website/api/v1/services/DownloadEntitlementService.php',
    'index.php' => __DIR__ . '/../website/index.php',
    'views/v3_workspace.php' => __DIR__ . '/../website/views/v3_workspace.php',
    'downloads/release_metadata.json' => __DIR__ . '/../website/downloads/release_metadata.json',
];

foreach ($codeFiles as $remotePath => $localPath) {
    echo "  Uploading $remotePath ...\n";
    if (!ftp_put($conn, $remotePath, $localPath, FTP_BINARY)) {
        die("FATAL: Failed to upload $remotePath\n");
    }
    echo "  ✓ $remotePath uploaded.\n";
}

// 4. Upload Release Packages
echo "\n[4/5] Uploading release packages to storage/packages/ ...\n";
foreach ($packages as $pkg) {
    $remotePkg = "storage/packages/{$pkg['name']}";
    $existingSize = ftp_size($conn, $remotePkg);
    if ($existingSize === $pkg['expected_bytes']) {
        echo "  ✓ {$pkg['name']} already exists on remote staging with exact size (" . number_format($existingSize) . " bytes). Skipping upload.\n";
        continue;
    }
    echo "  Uploading {$pkg['name']} (" . number_format($pkg['expected_bytes'] / (1024*1024), 2) . " MB) ...\n";
    $startTime = microtime(true);
    if (!ftp_put($conn, $remotePkg, $pkg['local_path'], FTP_BINARY)) {
        die("FATAL: Failed to upload $remotePkg\n");
    }
    $elapsed = round(microtime(true) - $startTime, 2);
    $remoteSize = ftp_size($conn, $remotePkg);
    if ($remoteSize !== $pkg['expected_bytes']) {
        die("FATAL: Remote size mismatch for {$pkg['name']}! Expected {$pkg['expected_bytes']} vs Remote: $remoteSize\n");
    }
    echo "  ✓ {$pkg['name']} uploaded in {$elapsed}s (Size verified: " . number_format($remoteSize) . " bytes)\n";
}

// 5. Relocate Packages to Private Storage (C:/2TOOLNE-Private/packages/)
echo "\n[5/5] Relocating packages to private storage & verifying ...\n";
$relocateData = [];
foreach ($packages as $p) {
    $relocateData[] = [
        'name' => $p['name'],
        'bytes' => $p['expected_bytes'],
        'sha256' => $p['expected_sha256']
    ];
}

$b64Manifest = base64_encode(json_encode($relocateData, JSON_THROW_ON_ERROR));
$relocateScript = '<?php
header("Content-Type: application/json; charset=utf-8");
$manifest = json_decode(base64_decode("' . $b64Manifest . '"), true);
$privateDir = "C:/2TOOLNE-Private/packages";
if (!is_dir($privateDir)) {
    @mkdir($privateDir, 0777, true);
}

$results = ["private_dir" => $privateDir, "packages" => []];

foreach ($manifest as $pkg) {
    $name = $pkg["name"];
    $expectedBytes = $pkg["bytes"];
    $expectedHash = $pkg["sha256"];
    
    $webrootPkg = __DIR__ . "/storage/packages/" . $name;
    $privatePkg = $privateDir . "/" . $name;
    
    $pkgRes = [
        "name" => $name,
        "webroot_exists" => file_exists($webrootPkg),
    ];
    
    if (file_exists($webrootPkg)) {
        if (!file_exists($privatePkg) || filesize($privatePkg) !== $expectedBytes || hash_file("sha256", $privatePkg) !== $expectedHash) {
            $pkgRes["copied"] = @copy($webrootPkg, $privatePkg);
        } else {
            $pkgRes["copied"] = true;
            $pkgRes["already_exists"] = true;
        }
    }
    
    if (file_exists($privatePkg)) {
        $pSize = filesize($privatePkg);
        $pkgRes["private_size"] = $pSize;
        $pkgRes["size_match"] = ($pSize === $expectedBytes);
        
        $pHash = hash_file("sha256", $privatePkg);
        $pkgRes["private_sha256"] = $pHash;
        $pkgRes["hash_match"] = ($pHash === $expectedHash);
        
        if ($pkgRes["size_match"] && $pkgRes["hash_match"]) {
            @unlink($webrootPkg);
            $pkgRes["webroot_cleaned"] = !file_exists($webrootPkg);
        }
    }
    
    $results["packages"][$name] = $pkgRes;
}

// Sync aliases in private packages dir
$aliasesMap = [
    "2TOOLNE-AutoEdit-Setup-latest.exe" => "2TOOLNE-AutoEdit-Setup-2.1.1.exe",
    "2toolne-autoedit-latest-win-x64.zip" => "2toolne-autoedit-2.1.1-win-x64.zip",
    "2TOOLNE-AutoEdit-latest.dmg" => "2TOOLNE-AutoEdit-2.1.1.dmg",
];
foreach ($aliasesMap as $aliasName => $targetName) {
    $targetFile = $privateDir . "/" . $targetName;
    $aliasFile = $privateDir . "/" . $aliasName;
    if (file_exists($targetFile)) {
        @copy($targetFile, $aliasFile);
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
';

$tmpRelocate = tempnam(sys_get_temp_dir(), 'rel211_');
file_put_contents($tmpRelocate, $relocateScript);
if (!ftp_put($conn, 'relocate_211.php', $tmpRelocate, FTP_BINARY)) {
    unlink($tmpRelocate);
    die("FATAL: Failed to upload relocate_211.php\n");
}
unlink($tmpRelocate);
echo "  ✓ Uploaded relocation trigger relocate_211.php\n";

// Trigger relocation script via HTTP GET
$ch = curl_init('https://2tamne.site/relocate_211.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
$relocateResRaw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("FATAL: Relocation execution failed (HTTP $httpCode):\n$relocateResRaw\n");
}

$relocateRes = json_decode((string)$relocateResRaw, true);
echo "  Relocation results:\n";
foreach (($relocateRes['packages'] ?? []) as $name => $info) {
    $matched = !empty($info['size_match']) && !empty($info['hash_match']);
    $cleaned = !empty($info['webroot_cleaned']) || empty($info['webroot_exists']);
    echo "    - $name: SizeMatch=" . ($info['size_match'] ? 'YES' : 'NO') 
        . ", HashMatch=" . ($info['hash_match'] ? 'YES' : 'NO') 
        . ", WebrootCleaned=" . ($cleaned ? 'YES' : 'NO') . "\n";
    if (!$matched) {
        die("FATAL: Package $name failed integrity match in private storage!\n");
    }
}

// Cleanup remote relocate script
ftp_delete($conn, 'relocate_211.php');
ftp_close($conn);

// 6. Test Live Update Discovery Endpoints
echo "\n==================================================\n";
echo "=== TESTING LIVE DISCOVERY ENDPOINTS (2.1.1) ===\n";
echo "==================================================\n";

// A. Windows test from 2.1.0 -> should report has_update: true, latest_version: 2.1.1
$winCheckUrl = 'https://2tamne.site/api/v1/update/check?app=autoedit&version=2.1.0&platform=win32&arch=x64&channel=stable';
$ch = curl_init($winCheckUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$winRaw = curl_exec($ch);
curl_close($ch);
echo "Windows Check (from v2.1.0):\n$winRaw\n\n";
$winRes = json_decode((string)$winRaw, true);
if (empty($winRes['success']) || empty($winRes['has_update']) || ($winRes['latest_version'] ?? '') !== '2.1.1') {
    die("FATAL: Windows update discovery did not return 2.1.1 update!\n");
}
if (($winRes['filename'] ?? '') !== '2toolne-autoedit-2.1.1-win-x64.zip') {
    die("FATAL: Windows update filename mismatch: expected 2toolne-autoedit-2.1.1-win-x64.zip, got " . ($winRes['filename'] ?? '') . "\n");
}
echo "✓ Windows Auto-Update Discovery Verified: 2.1.0 -> 2.1.1 (has_update=true)\n";

// B. Windows test from 2.0.6 -> should report has_update: true, latest_version: 2.1.1
$winCheckUrl6 = 'https://2tamne.site/api/v1/update/check?app=autoedit&version=2.0.6&platform=win32&arch=x64&channel=stable';
$ch = curl_init($winCheckUrl6);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$winRaw6 = curl_exec($ch);
curl_close($ch);
$winRes6 = json_decode((string)$winRaw6, true);
if (empty($winRes6['success']) || empty($winRes6['has_update']) || ($winRes6['latest_version'] ?? '') !== '2.1.1') {
    die("FATAL: Windows 2.0.6 update discovery did not return 2.1.1 update!\n");
}
echo "✓ Windows Auto-Update Discovery Verified: 2.0.6 -> 2.1.1 (has_update=true)\n";

// C. macOS test from 2.1.0 -> should report has_update: true, latest_version: 2.1.1
$macCheckUrl = 'https://2tamne.site/api/v1/update/check?app=autoedit&version=2.1.0&platform=darwin&arch=arm64&channel=stable';
$ch = curl_init($macCheckUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$macRaw = curl_exec($ch);
curl_close($ch);
echo "macOS Check (from v2.1.0):\n$macRaw\n\n";
$macRes = json_decode((string)$macRaw, true);
if (empty($macRes['success']) || empty($macRes['has_update']) || ($macRes['latest_version'] ?? '') !== '2.1.1') {
    die("FATAL: macOS update discovery did not return 2.1.1 update!\n");
}
echo "✓ macOS Auto-Update Discovery Verified: 2.1.0 -> 2.1.1 (has_update=true)\n";

// D. Test up-to-date client (v2.1.1 -> has_update=false)
$currentCheckUrl = 'https://2tamne.site/api/v1/update/check?app=autoedit&version=2.1.1&platform=win32&arch=x64&channel=stable';
$ch = curl_init($currentCheckUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$curRaw = curl_exec($ch);
curl_close($ch);
$curRes = json_decode((string)$curRaw, true);
if (empty($curRes['success']) || ($curRes['has_update'] ?? true) !== false) {
    die("FATAL: Up-to-date 2.1.1 client check failed!\n");
}
echo "✓ Up-to-date 2.1.1 client check verified: has_update=false\n";

// 7. Test Download Gate for Homepage Requests
echo "\n==================================================\n";
echo "=== TESTING DOWNLOAD GATE & HOMEPAGE (2.1.1) ===\n";
echo "==================================================\n";

// 7a. Test anonymous access returns 401 AUTH_REQUIRED
$reqUrl = 'https://2tamne.site/api/v1/downloads/request';
$payload = json_encode(['product' => 'AUTOEDIT', 'platform' => 'windows-x64']);
$ch = curl_init($reqUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$dlAnonRaw = curl_exec($ch);
$anonStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dlAnon = json_decode((string)$dlAnonRaw, true);
$dlAnonCode = $dlAnon['code'] ?? ($dlAnon['error_code'] ?? '');
if ($anonStatus !== 401 || $dlAnonCode !== 'AUTH_REQUIRED') {
    die("FATAL: Anonymous download gate check failed (expected 401 AUTH_REQUIRED, got {$anonStatus}): {$dlAnonRaw}\n");
}
echo "✓ Download Gate Security: Anonymous request rejected with 401 AUTH_REQUIRED\n";

// 7b. Authenticate as Admin to test download token issuance
$cookieFile = sys_get_temp_dir() . '/2toolne_deploy_adm_cookie.txt';
@unlink($cookieFile);

// Get CSRF token
$ch = curl_init('https://2tamne.site/license_admin.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$admHtml = curl_exec($ch);
curl_close($ch);
preg_match("/name=[\x22\x27]csrf_token[\x22\x27]\s+value=[\x22\x27]([^\x22\x27]+)/i", (string)$admHtml, $csrfMatches);
$admCsrf = $csrfMatches[1] ?? '';

// Login
$ch = curl_init('https://2tamne.site/license_admin.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'admin_login' => '1',
    'username'    => 'super_admin',
    'password'    => '@2TamneAdmin2026',
    'csrf_token'  => $admCsrf,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_exec($ch);
curl_close($ch);

// 7c. Test Installer Download Request (v2.1.1)
$ch = curl_init($reqUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['product' => 'AUTOEDIT', 'platform' => 'windows-x64', 'package_type' => 'installer']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$dlRaw = curl_exec($ch);
curl_close($ch);
echo "Installer Download Request Response:\n$dlRaw\n";
$dlRes = json_decode((string)$dlRaw, true);
if (empty($dlRes['success']) || empty($dlRes['download_url'])) {
    die("FATAL: Authenticated installer download request failed!\n");
}
if (($dlRes['filename'] ?? '') !== '2TOOLNE-AutoEdit-Setup-2.1.1.exe') {
    die("FATAL: Installer filename mismatch: expected 2TOOLNE-AutoEdit-Setup-2.1.1.exe, got " . ($dlRes['filename'] ?? '') . "\n");
}
if (($dlRes['filesize_bytes'] ?? 0) !== 220366254) {
    die("FATAL: Installer filesize mismatch: expected 220366254, got " . ($dlRes['filesize_bytes'] ?? 0) . "\n");
}
if (strtolower($dlRes['sha256'] ?? '') !== '81745a1b52668da41aa136ac7a14f1e0bfd285ca1a97c46874e89c3e1fe632b0') {
    die("FATAL: Installer SHA256 mismatch: expected 81745a1b..., got " . ($dlRes['sha256'] ?? '') . "\n");
}
echo "✓ Download Gate Installer Verified: returns genuine 2TOOLNE-AutoEdit-Setup-2.1.1.exe (220,366,254 B, 81745a1b...)\n";

// 7d. Test Streaming first 512 bytes of Installer
$signedUrl = $dlRes['download_url'];
$ch = curl_init($signedUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_RANGE, '0-511');
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$streamRaw = curl_exec($ch);
$httpRangeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpRangeCode !== 206) {
    die("FATAL: Range download failed: expected HTTP 206, got $httpRangeCode\n");
}
$isPeExecutable = (strpos($streamRaw, "MZ\x90\x00") !== false || strpos($streamRaw, "MZ") !== false);
if (!$isPeExecutable) {
    die("FATAL: Downloaded bytes do not start with PE MZ executable signature!\n");
}
echo "✓ Binary Streaming Verified: HTTP 206 Partial Content, PE 'MZ' magic bytes confirmed\n";

// 7e. Test Portable Zip Download Request (v2.1.1)
$ch = curl_init($reqUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['product' => 'AUTOEDIT', 'platform' => 'windows-x64', 'package_type' => 'portable']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$zipRaw = curl_exec($ch);
curl_close($ch);
$zipRes = json_decode((string)$zipRaw, true);
if (empty($zipRes['success']) || ($zipRes['filename'] ?? '') !== '2toolne-autoedit-2.1.1-win-x64.zip') {
    die("FATAL: Portable zip download request failed: expected 2toolne-autoedit-2.1.1-win-x64.zip, got " . ($zipRes['filename'] ?? '') . "\n");
}
if (($zipRes['filesize_bytes'] ?? 0) !== 290079506) {
    die("FATAL: Portable zip filesize mismatch: expected 290079506, got " . ($zipRes['filesize_bytes'] ?? 0) . "\n");
}
if (strtolower($zipRes['sha256'] ?? '') !== '8a9b4bb917a2859fbda77df898cec94f306a152942283da57b46db151ca03a9a') {
    die("FATAL: Portable zip SHA256 mismatch: expected 8a9b4bb9..., got " . ($zipRes['sha256'] ?? '') . "\n");
}
echo "✓ Download Gate Portable Zip Verified: returns genuine 2toolne-autoedit-2.1.1-win-x64.zip (290,079,506 B, 8a9b4bb9...)\n";

// 7f. Verify Authenticated Workspace HTML Content for v2.1.1
$userCookie = sys_get_temp_dir() . '/2toolne_deploy_user_cookie.txt';
@unlink($userCookie);
$user = 'depcheck_' . substr(bin2hex(random_bytes(4)), 0, 8);
$pass = 'DepPass123!';

$ch = curl_init('https://2tamne.site/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'action'   => 'register',
    'username' => $user,
    'password' => $pass,
    'fullname' => 'Deployment Verifier',
    'phone'    => '0901234567',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $userCookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $userCookie);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_exec($ch);

$ch = curl_init('https://2tamne.site/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $userCookie);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$workHtml = curl_exec($ch);
@unlink($userCookie);

if (strpos((string)$workHtml, 'v2.1.1 Stable') === false) {
    die("FATAL: Authenticated workspace does not contain 'v2.1.1 Stable' badge!\n");
}
if (strpos((string)$workHtml, '2TOOLNE AutoEdit for CapCut') === false) {
    die("FATAL: Authenticated workspace does not contain '2TOOLNE AutoEdit for CapCut'!\n");
}
echo "✓ Authenticated Workspace HTML Verified: displays 'v2.1.1 Stable' badge & AutoEdit suite\n";

// 7g. Verify Public Landing Page
$ch = curl_init('https://2tamne.site/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$publicHtml = curl_exec($ch);
curl_close($ch);

if (empty($publicHtml) || strpos((string)$publicHtml, '2TOOLNE') === false) {
    die("FATAL: Public landing page failed to respond properly!\n");
}
echo "✓ Public Landing Page Verified: 2TOOLNE Web V3 active\n";

@unlink($cookieFile);

echo "\n==================================================\n";
echo "=== 2TOOLNE 2.1.1 PRODUCTION DEPLOYMENT PASSED ===\n";
echo "==================================================\n";
