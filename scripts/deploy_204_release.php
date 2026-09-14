<?php
/**
 * scripts/deploy_204_release.php
 * Deploys UpdateController.php and 2toolne-autoedit-2.0.4-win-x64.zip to production host.
 * Relocates to C:/2TOOLNE-Private/packages/, verifies exact SHA256 and byte length.
 */

declare(strict_types=1);

$credsFile = __DIR__ . '/../deployment_migration/20260909_004300/creds.secret.json';
if (getenv('FTP_HOST') && getenv('FTP_USERNAME') && getenv('FTP_PASSWORD')) {
    $creds = [
        'host' => getenv('FTP_HOST'),
        'port' => (int)(getenv('FTP_PORT') ?: 21),
        'user' => getenv('FTP_USERNAME'),
        'pass' => getenv('FTP_PASSWORD'),
    ];
} elseif (file_exists($credsFile)) {
    $creds = json_decode(file_get_contents($credsFile), true)['new_ftp'];
} else {
    die("FATAL: Deployment credentials missing (FTP_HOST/FTP_USERNAME/FTP_PASSWORD env vars or $credsFile required)\n");
}

$expectedBytes = 706037910;
$expectedSha256 = 'd561055f02db95150e831d1039533a19315b37d8dbcaa437ea37e31899e01459';
$pkgName = '2toolne-autoedit-2.0.4-win-x64.zip';
$localPkg = __DIR__ . '/../dist/' . $pkgName;

if (!file_exists($localPkg)) {
    die("FATAL: Local package not found at $localPkg\n");
}
if (filesize($localPkg) !== $expectedBytes) {
    die("FATAL: Local package byte size mismatch! Expected $expectedBytes, got " . filesize($localPkg) . "\n");
}
$localSha256 = hash_file('sha256', $localPkg);
if ($localSha256 !== $expectedSha256) {
    die("FATAL: Local SHA256 mismatch! Expected $expectedSha256, got $localSha256\n");
}
echo "✓ Local 2.0.4 package verified: $pkgName ($expectedBytes bytes, $expectedSha256)\n\n";

echo "=== DEPLOYING 2TOOLNE 2.0.4 RELEASE TO PRODUCTION ===\n";
echo "Host: {$creds['host']}:{$creds['port']}\n";

$conn = ftp_connect($creds['host'], $creds['port'], 30);
if (!$conn || !ftp_login($conn, $creds['user'], $creds['pass'])) {
    die("FATAL: FTP connection failed.\n");
}
ftp_pasv($conn, true);
echo "Connected and Passive Mode enabled.\n\n";

// 1. Deploy UpdateController.php
echo "[1/4] Uploading api/v1/controllers/UpdateController.php ...\n";
$localController = __DIR__ . '/../website/api/v1/controllers/UpdateController.php';
if (!ftp_put($conn, 'api/v1/controllers/UpdateController.php', $localController, FTP_BINARY)) {
    die("FATAL: Failed to upload UpdateController.php\n");
}
echo "  ✓ UpdateController.php deployed successfully.\n";

// 2. Upload package to storage/packages/
echo "[2/4] Uploading {$pkgName} (" . number_format($expectedBytes / (1024*1024), 2) . " MB) via FTP ...\n";
$remotePkg = "storage/packages/{$pkgName}";

$startTime = microtime(true);
if (!ftp_put($conn, $remotePkg, $localPkg, FTP_BINARY)) {
    die("FATAL: Failed to upload $remotePkg\n");
}
$elapsed = round(microtime(true) - $startTime, 2);
echo "  ✓ Upload completed in {$elapsed}s!\n";

$remoteSize = ftp_size($conn, $remotePkg);
echo "  Remote File Size: " . number_format($remoteSize) . " bytes\n";
if ($remoteSize !== $expectedBytes) {
    die("FATAL: Remote size mismatch! Expected $expectedBytes vs Remote: $remoteSize\n");
}
echo "  ✓ Webroot upload size verified!\n";

// 3. Relocate to C:/2TOOLNE-Private/packages/ via server-side script
echo "[3/4] Relocating to private durable storage and verifying SHA256 ...\n";
$relocateScript = '<?php
header("Content-Type: application/json; charset=utf-8");

$expectedHash = "' . $expectedSha256 . '";
$expectedBytes = ' . $expectedBytes . ';
$pkgName = "' . $pkgName . '";

$webrootPkg = __DIR__ . "/storage/packages/" . $pkgName;
$privateDir = "C:/2TOOLNE-Private/packages";
$privatePkg = $privateDir . "/" . $pkgName;

$results = [
    "timestamp" => date("Y-m-d H:i:s"),
    "webroot_pkg_exists" => file_exists($webrootPkg),
    "webroot_pkg_size" => file_exists($webrootPkg) ? filesize($webrootPkg) : 0,
    "private_dir_exists" => is_dir($privateDir),
];

if (!is_dir($privateDir)) {
    @mkdir($privateDir, 0777, true);
    $results["private_dir_created"] = is_dir($privateDir);
}

if (file_exists($webrootPkg)) {
    if (!file_exists($privatePkg) || filesize($privatePkg) !== $expectedBytes) {
        $copied = @copy($webrootPkg, $privatePkg);
        $results["copy_success"] = $copied;
    } else {
        $results["copy_success"] = true;
        $results["already_in_private"] = true;
    }

    if (file_exists($privatePkg)) {
        $pSize = filesize($privatePkg);
        $results["private_pkg_size"] = $pSize;
        $results["size_match"] = ($pSize === $expectedBytes);
        
        $pHash = hash_file("sha256", $privatePkg);
        $results["private_sha256"] = $pHash;
        $results["hash_match"] = ($pHash === $expectedHash);

        if ($results["size_match"] && $results["hash_match"]) {
            // Delete webroot copy to protect private package storage
            @unlink($webrootPkg);
            $results["webroot_cleaned"] = !file_exists($webrootPkg);
        }
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
';

$tmpRelocate = tempnam(sys_get_temp_dir(), 'rel204_');
file_put_contents($tmpRelocate, $relocateScript);
ftp_put($conn, 'relocate_204.php', $tmpRelocate, FTP_BINARY);
unlink($tmpRelocate);

$ch = curl_init('https://www.2tamne.site/relocate_204.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$relocateResp = curl_exec($ch);
curl_close($ch);

echo "Relocation Response:\n$relocateResp\n\n";

$relResult = json_decode($relocateResp, true);
if (empty($relResult['hash_match'])) {
    die("FATAL: Private storage SHA256 mismatch or copy failed!\n");
}
echo "✓ Relocated to C:/2TOOLNE-Private/packages/ with exact SHA256 match!\n";
echo "✓ Webroot staging safely deleted: " . ($relResult['webroot_cleaned'] ? 'YES' : 'NO') . "\n";

// 4. Verify Live Discovery and Download Token
echo "\n[4/4] Verifying Live Discovery Endpoint (/api/v1/update/check) ...\n";
$checkUrl = 'https://www.2tamne.site/api/v1/update/check?app=autoedit&version=2.0.3&platform=win32&arch=x64';
$ch = curl_init($checkUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$checkRaw = curl_exec($ch);
curl_close($ch);

echo "Discovery Response:\n$checkRaw\n\n";
$checkRes = json_decode($checkRaw, true);

if (empty($checkRes['success']) || empty($checkRes['has_update']) || ($checkRes['latest_version'] ?? '') !== '2.0.4') {
    die("FATAL: Live discovery endpoint did not return 2.0.4 update!\n");
}

echo "✓ Live Update Discovery Verified: 2.0.3 -> 2.0.4\n";
echo "  Update URL: {$checkRes['download_url']}\n";
echo "  Release Notes: {$checkRes['release_notes']}\n";

// Cleanup relocate script
ftp_delete($conn, 'relocate_204.php');
ftp_close($conn);

echo "\n==================================================\n";
echo "2TOOLNE 2.0.4 PRODUCTION RELEASE COMPLETE & VERIFIED!\n";
echo "==================================================\n";
