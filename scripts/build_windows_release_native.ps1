<#
.SYNOPSIS
    scripts/build_windows_release_native.ps1
    2TOOLNE Official Native Windows Release Builder & Direct Server Deployment Pipeline.

.DESCRIPTION
    ARCHITECTURAL MANDATE:
    1. GitHub Actions is NOT the primary release pipeline.
    2. Windows binaries (autoedit-core.exe and Electron app) MUST be compiled natively
       on a dedicated, fixed Windows build machine using supported Python 3.12 and PyInstaller.
    3. GitHub is used strictly for source code, history, and backup.
    4. Release packages are uploaded directly from the Windows build machine to the
       2TOOLNE production server into C:/2TOOLNE-Private/packages/.
#>

[CmdletBinding()]
param(
    [string]$Version = "2.1.2",
    [string]$ServerHost = $env:FTP_HOST,
    [string]$FtpUser = $env:FTP_USERNAME,
    [string]$FtpPass = $env:FTP_PASSWORD,
    [switch]$SkipUpload = $false
)

$ErrorActionPreference = "Stop"

if (-not $SkipUpload) {
    if (-not $ServerHost -or -not $FtpUser -or -not $FtpPass) {
        Write-Error "FATAL: Deployment credentials missing. FTP_HOST, FTP_USERNAME, and FTP_PASSWORD environment variables or parameters must be provided."
        exit 1
    }
}

Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE NATIVE WINDOWS RELEASE BUILD PIPELINE (v$Version)" -ForegroundColor Cyan
Write-Host "Build Machine   : $env:COMPUTERNAME ($env:USERNAME)" -ForegroundColor Cyan
Write-Host "Target Platform : win32 / x64" -ForegroundColor Cyan
Write-Host "Execution Time  : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "========================================================================" -ForegroundColor Cyan

# ----------------------------------------------------------------------
# 1. Environment Sanity Check
# ----------------------------------------------------------------------
Write-Host "`n[1/7] Checking environment and prerequisites..." -ForegroundColor Yellow

if (-not [System.Runtime.InteropServices.RuntimeInformation]::IsOSPlatform([System.Runtime.InteropServices.OSPlatform]::Windows)) {
    Write-Error "FATAL: This script must be executed on a native Windows workstation or build machine."
    exit 1
}

$pyVer = python --version 2>&1
Write-Host "  Python Version     : $pyVer"
if ($pyVer -notmatch "Python 3\.12") {
    Write-Warning "  WARNING: Expected Python 3.12 environment, detected: $pyVer"
}

$nodeVer = node --version 2>&1
Write-Host "  Node.js Version    : $nodeVer"

$gitSha = git rev-parse HEAD 2>&1
Write-Host "  Git Commit SHA     : $gitSha"

# ----------------------------------------------------------------------
# 2. Deep Clean Build Artifacts and PyInstaller Caches
# ----------------------------------------------------------------------
Write-Host "`n[2/7] Cleaning previous build trees and caches..." -ForegroundColor Yellow
$cleanPaths = @(
    "apps/capcut-v2/packaging/build",
    "apps/capcut-v2/packaging/dist",
    "apps/capcut-v2/desktop/dist",
    "$env:LOCALAPPDATA/pyinstaller"
)

foreach ($cp in $cleanPaths) {
    if (Test-Path $cp) {
        Write-Host "  Removing: $cp"
        Remove-Item -Recurse -Force $cp -ErrorAction SilentlyContinue
    }
}

Get-ChildItem -Path . -Include __pycache__ -Recurse -Force -ErrorAction SilentlyContinue | ForEach-Object {
    Remove-Item -Recurse -Force $_.FullName -ErrorAction SilentlyContinue
}
Write-Host "  ✓ Clean complete."

# ----------------------------------------------------------------------
# 3. Compile Native Python Sidecar via PyInstaller
# ----------------------------------------------------------------------
Write-Host "`n[3/7] Compiling native Windows sidecar (autoedit-core.exe)..." -ForegroundColor Yellow
python apps/capcut-v2/packaging/build_sidecar.py

$sidecarBin = "apps/capcut-v2/packaging/dist/autoedit-core/autoedit-core.exe"
if (-not (Test-Path $sidecarBin)) {
    Write-Error "Sidecar build failed: $sidecarBin not found!"
    exit 1
}

$vadAsset = "apps/capcut-v2/packaging/dist/autoedit-core/_internal/faster_whisper/assets/silero_vad_v6.onnx"
if (-not (Test-Path $vadAsset)) {
    Write-Error "Packaging error: VAD model asset $vadAsset not found in built bundle!"
    exit 1
}

$sidecarSize = (Get-Item $sidecarBin).Length
$sidecarHash = (Get-FileHash -Path $sidecarBin -Algorithm SHA256).Hash
Write-Host "  ✓ Fresh autoedit-core.exe built: $sidecarBin" -ForegroundColor Green
Write-Host "    Size   : $sidecarSize bytes"
Write-Host "    SHA256 : $sidecarHash"
Write-Host "  ✓ Bundled VAD asset verified: $vadAsset" -ForegroundColor Green

# ----------------------------------------------------------------------
# 4. Packaged Sidecar Direct Runtime Test
# ----------------------------------------------------------------------
Write-Host "`n[4/7] Executing direct packaged sidecar RPC tests..." -ForegroundColor Yellow
$env:PYTHONIOENCODING = "utf-8"
$env:PYTHONUTF8 = "1"
$env:AUTOEDIT_CORE_EXE = $sidecarBin
python tests/test_packaged_windows_sidecar_rpc.py

if ($LASTEXITCODE -ne 0) {
    Write-Error "Packaged sidecar runtime validation failed!"
    exit 1
}
Write-Host "  ✓ Packaged sidecar validation PASSED!" -ForegroundColor Green

# ----------------------------------------------------------------------
# 5. Assemble Electron Desktop Distribution
# ----------------------------------------------------------------------
Write-Host "`n[5/7] Building Electron desktop package..." -ForegroundColor Yellow

$targetDir = "apps/capcut-v2/desktop/resources/autoedit-core/win-x64"
Remove-Item -Recurse -Force $targetDir -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
Copy-Item "apps/capcut-v2/packaging/dist/autoedit-core/*" -Destination $targetDir -Recurse -Force

Push-Location apps/capcut-v2/desktop
npm run pack
Pop-Location

node apps/capcut-v2/desktop/scripts/verify_windows_package.js
if ($LASTEXITCODE -ne 0) {
    Write-Error "Package audit failed!"
    exit 1
}

New-Item -ItemType Directory -Force -Path dist | Out-Null
$finalZip = "dist/2toolne-autoedit-$Version-win-x64.zip"
if (Test-Path $finalZip) { Remove-Item -Force $finalZip }

Write-Host "  Compressing $finalZip ..."
Compress-Archive -Path "apps/capcut-v2/desktop/dist/win-unpacked/*" -DestinationPath $finalZip -Force

$zipSize = (Get-Item $finalZip).Length
$zipHash = (Get-FileHash -Path $finalZip -Algorithm SHA256).Hash.ToLower()

Write-Host "  ✓ Package Assembled: $finalZip" -ForegroundColor Green
Write-Host "    Size   : $zipSize bytes"
Write-Host "    SHA256 : $zipHash"

# Generate local manifest
$manifestObj = @{
    UPDATE_PACKAGE = "2toolne-autoedit-$Version-win-x64.zip"
    UPDATE_PACKAGE_SIZE = $zipSize
    UPDATE_PACKAGE_SHA256 = $zipHash
    VERSION = $Version
    GIT_COMMIT = $gitSha
    BUILD_HOST = $env:COMPUTERNAME
    BUILD_DATE = (Get-Date -Format 'o')
}
$manifestObj | ConvertTo-Json | Out-File -FilePath "dist/manifest_$Version.json" -Encoding utf8

# ----------------------------------------------------------------------
# 6. Direct Upload to 2TOOLNE Server
# ----------------------------------------------------------------------
if ($SkipUpload) {
    Write-Host "`n[6/7] Skipping upload (-SkipUpload specified)." -ForegroundColor Yellow
} else {
    Write-Host "`n[6/7] Uploading release package directly to 2TOOLNE production server..." -ForegroundColor Yellow
    Write-Host "  Destination: ftp://${ServerHost}:21/storage/packages/"
    
    # 1. Upload manifest
    curl.exe -s -T "dist/manifest_$Version.json" -u "${FtpUser}:${FtpPass}" "ftp://${ServerHost}:21/storage/packages/manifest_${Version}.json"
    
    # 2. Upload autoedit-core binary backup
    curl.exe -s -T $sidecarBin -u "${FtpUser}:${FtpPass}" "ftp://${ServerHost}:21/storage/packages/autoedit-core-${Version}.exe"
    
    # 3. Upload package zip
    Write-Host "  Uploading $finalZip ($zipSize bytes)..."
    curl.exe -T $finalZip -u "${FtpUser}:${FtpPass}" "ftp://${ServerHost}:21/storage/packages/2toolne-autoedit-${Version}-win-x64.zip"
    
    Write-Host "  ✓ Direct upload completed successfully!" -ForegroundColor Green
}

# ----------------------------------------------------------------------
# 7. Final Verification Summary
# ----------------------------------------------------------------------
Write-Host "`n========================================================================" -ForegroundColor Cyan
Write-Host "BUILD COMPLETE — 2TOOLNE RELEASE $Version" -ForegroundColor Cyan
Write-Host "PACKAGE   : 2toolne-autoedit-$Version-win-x64.zip" -ForegroundColor Cyan
Write-Host "SIZE      : $zipSize bytes" -ForegroundColor Cyan
Write-Host "SHA256    : $zipHash" -ForegroundColor Cyan
Write-Host "GIT SHA   : $gitSha" -ForegroundColor Cyan
Write-Host "========================================================================" -ForegroundColor Cyan
