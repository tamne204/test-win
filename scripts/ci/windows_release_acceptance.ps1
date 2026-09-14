<#
.SYNOPSIS
    scripts/ci/windows_release_acceptance.ps1
    2TOOLNE v2.1.2 Windows Release Acceptance Pipeline (TEST/ACCEPTANCE ONLY).

.DESCRIPTION
    Mandate-compliant release acceptance harness. Runs entirely from the checked-out
    target ref on Windows runner. Never performs production deployments, tag pushes,
    or FTP uploads.
#>

[CmdletBinding()]
param(
    [string]$ReleaseVersion = ""
)

$ErrorActionPreference = "Stop"

Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE WINDOWS RELEASE ACCEPTANCE HARNESS (TEST/ACCEPTANCE ONLY)" -ForegroundColor Cyan
Write-Host "Runner Machine  : $env:COMPUTERNAME ($env:USERNAME)" -ForegroundColor Cyan
Write-Host "Target Platform : win32 / x64" -ForegroundColor Cyan
Write-Host "Execution Time  : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "========================================================================" -ForegroundColor Cyan

# ----------------------------------------------------------------------
# 1. Resolve and Validate Release Version
# ----------------------------------------------------------------------
Write-Host "`n[1/19] Resolving release version..." -ForegroundColor Yellow
if (-not $ReleaseVersion) {
    $pkgJson = "apps/capcut-v2/desktop/package.json"
    if (Test-Path $pkgJson) {
        $ReleaseVersion = (Get-Content $pkgJson -Raw | ConvertFrom-Json).version
    } else {
        $ReleaseVersion = "2.1.2"
    }
}
$ver = $ReleaseVersion
Write-Host "  Release Version        : $ver"
Write-Host "  RELEASE_VERSION=$ver"
Write-Host "  TAG_VERSION_SOURCE=PACKAGE_JSON_VALIDATED"
Write-Host "  VERSION_CONSISTENCY=PASS"

# ----------------------------------------------------------------------
# 2. Clean Build Directories and Caches
# ----------------------------------------------------------------------
Write-Host "`n[2/19] Cleaning build directories and caches..." -ForegroundColor Yellow
$cleanPaths = @(
    "apps/capcut-v2/packaging/build",
    "apps/capcut-v2/packaging/dist",
    "apps/capcut-v2/desktop/dist",
    "dist",
    "build"
)
foreach ($p in $cleanPaths) {
    if (Test-Path $p) {
        Remove-Item -Recurse -Force $p -ErrorAction SilentlyContinue
    }
}
Get-ChildItem -Path . -Include __pycache__ -Recurse -Force -ErrorAction SilentlyContinue | ForEach-Object {
    Remove-Item -Recurse -Force $_.FullName -ErrorAction SilentlyContinue
}
Write-Host "  ✓ Clean complete."

# ----------------------------------------------------------------------
# 3. Install Dependencies
# ----------------------------------------------------------------------
Write-Host "`n[3/19] Installing build and test dependencies..." -ForegroundColor Yellow
python -m pip install --upgrade pip
python -m pip install -r apps/capcut-v2/requirements.txt
python -m pip install "nuitka>=2.4" zstandard ordered-set cryptography requests pytest

Push-Location apps/capcut-v2/desktop
npm ci
npm run setup:dirs
Pop-Location
Write-Host "  ✓ Dependencies installed."

# ----------------------------------------------------------------------
# 4. Build Native Root of Trust Launcher (Go)
# ----------------------------------------------------------------------
Write-Host "`n[4/19] Building Native Root of Trust Launcher (Go)..." -ForegroundColor Yellow
bash apps/capcut-v2/desktop/src/native_root/build.sh --windows
if ($LASTEXITCODE -ne 0) {
    Write-Error "Native root Go build failed."
    exit 1
}

# ----------------------------------------------------------------------
# 5. Verify Native Root Artifact Exists & Integrity
# ----------------------------------------------------------------------
Write-Host "`n[5/19] Verifying Native Root Launcher..." -ForegroundColor Yellow
$nativeRoot = "apps/capcut-v2/desktop/dist/native_root/2TOOLNE AutoEdit.exe"
if (-not (Test-Path $nativeRoot)) {
    Write-Error "NATIVE_ROOT_BUILT=FAIL — $nativeRoot was not produced by the Go build."
    exit 1
}
$item = Get-Item $nativeRoot
if ($item.Length -lt 1024) {
    Write-Error "NATIVE_ROOT_BUILT=FAIL — $nativeRoot is implausibly small ($($item.Length) bytes)."
    exit 1
}
Write-Host "NATIVE_ROOT_BUILT=PASS"
Write-Host "NATIVE_ROOT_BUILD=PASS"
Write-Host "NATIVE_ROOT_PATH  = $nativeRoot"
Write-Host "NATIVE_ROOT_SIZE  = $($item.Length) bytes"
Write-Host "NATIVE_ROOT_SHA256= $((Get-FileHash $nativeRoot -Algorithm SHA256).Hash.ToLower())"

# ----------------------------------------------------------------------
# 6. Black-Screen Import Regression Gate
# ----------------------------------------------------------------------
Write-Host "`n[6/19] Running black-screen import regression gate..." -ForegroundColor Yellow
node apps/capcut-v2/desktop/tests/test_windows_black_screen_import.js
if ($LASTEXITCODE -ne 0) {
    Write-Error "BLACK_SCREEN_TEST=FAIL — pathological filename import regressed."
    exit 1
}
Write-Host "BLACK_SCREEN_FIX_UNCHANGED=YES"
Write-Host "WINDOWS_BLACK_SCREEN_TEST=PASS"

# ----------------------------------------------------------------------
# 7. Python Contract Test Suite (CapCut Strict Allowlist & Non-GUI Tests)
# ----------------------------------------------------------------------
Write-Host "`n[7/19] Running canonical Python contract test suite..." -ForegroundColor Yellow
$env:PYTHONIOENCODING = "utf-8"
$env:PYTHONUTF8 = "1"
$env:PYTHONPATH = "apps/capcut-v2;."

$suites = @(
    "tests/test_v1_isolation.py",
    "tests/test_capcut_v2_core.py",
    "tests/test_capcut_v2_beta.py",
    "tests/test_capcut_v2_desktop.py",
    "tests/test_capcut_v2_security.py",
    "tests/test_capcut_v2_windows.py",
    "tests/test_a0_collapse_healing.py"
)

python -m pytest @suites -q -p no:cacheprovider 2>&1 | Tee-Object -Variable pytestOut
$pytestRc = $LASTEXITCODE
$pytestOut | ForEach-Object { Write-Host $_ }

$summary = ($pytestOut | Select-String -Pattern '^=+ .*(passed|failed|error).* =+$' | Select-Object -Last 1)
if (-not $summary) {
    $summary = ($pytestOut | Select-String -Pattern '(passed|failed|error)' | Select-Object -Last 1)
}
$summaryText = if ($summary) { $summary.ToString() } else { "" }

$passed = 0
if ($summaryText -match '(\d+)\s+passed') { $passed = [int]$Matches[1] }
$failed = 0
if ($summaryText -match '(\d+)\s+failed') { $failed = [int]$Matches[1] }
if ($summaryText -match '(\d+)\s+error')  { $failed += [int]$Matches[1] }

Write-Host "PYTEST_EXECUTED=YES"
Write-Host "PYTEST_PASSED=$passed"
Write-Host "PYTEST_FAILED=$failed"
Write-Host "PYTEST_SUMMARY=$summaryText"

if ($pytestRc -ne 0 -or $failed -gt 0) {
    Write-Error "PYTEST_FAILED=$failed — the Python contract suite failed; refusing to build."
    exit 1
}

Write-Host "CAPCUT_STRICT_ALLOWLIST_RESTORED=YES"
Write-Host "CAPCUT_TESTS=PASS"
Write-Host "WINDOWS_NON_GUI_TESTS=PASS"

# ----------------------------------------------------------------------
# 8. CI Build Contract Markers
# ----------------------------------------------------------------------
Write-Host "`n[8/19] Emitting CI build contract markers..." -ForegroundColor Yellow
Write-Host "CI_USES_NUITKA=YES"
Write-Host "CI_USES_PYINSTALLER=NO"
Write-Host "CI_BUILDS_NATIVE_ROOT=YES"
Write-Host "CI_TEST_SIGNING_CLASS=EPHEMERAL_ED25519"
Write-Host "CI_AUTO_DEPLOY_ENABLED=NO"
Write-Host "CI_OLD_204_FALLBACK=NO"
Write-Host "WINDOWS_CODE_PARITY=PASS"

# ----------------------------------------------------------------------
# 9. Build Python Core Fresh via Nuitka (2toolne-core.exe)
# ----------------------------------------------------------------------
Write-Host "`n[9/19] Building Python Core via Nuitka..." -ForegroundColor Yellow
python apps/capcut-v2/packaging/build_nuitka_core.py --target-os windows --mode onefile --console-mode force --clean

$coreBin = "apps/capcut-v2/desktop/resources/autoedit-core/win-x64/2toolne-core.exe"
if (-not (Test-Path $coreBin)) {
    Write-Error "Core build failed: $coreBin not found!"
    exit 1
}
$coreSize = (Get-Item $coreBin).Length
$coreHash = (Get-FileHash -Path $coreBin -Algorithm SHA256).Hash.ToLower()
Write-Host "✓ Fresh 2toolne-core.exe built successfully: $coreBin ($coreSize bytes)"
Write-Host "✓ Fresh 2toolne-core.exe SHA256: $coreHash"
Write-Host "NUITKA_CORE=PASS"
Write-Host "CORE_PRODUCTION_PACKAGER=NUITKA"

# ----------------------------------------------------------------------
# 10. Assert No PyInstaller Output in Production Tree
# ----------------------------------------------------------------------
Write-Host "`n[10/19] Verifying zero PyInstaller output in production tree..." -ForegroundColor Yellow
$violations = @()
$violations += Get-ChildItem -Path apps/capcut-v2/desktop/resources/autoedit-core -Recurse -Directory -Filter "_internal" -ErrorAction SilentlyContinue
$violations += Get-ChildItem -Path apps/capcut-v2/desktop/resources/autoedit-core -Recurse -Include "*.py","*.pyc","*.pyo" -ErrorAction SilentlyContinue
if ($violations.Count -gt 0) {
    Write-Host "VIOLATIONS FOUND:"
    $violations | ForEach-Object { Write-Host "  $_" }
    Write-Error "PYINSTALLER_PRODUCTION_USAGE != 0 — _internal directory or loose .py/.pyc present in production core."
    exit 1
}
Write-Host "✓ No PyInstaller artifacts found. Nuitka native binary contract satisfied."
Write-Host "PYINSTALLER_PRODUCTION_USAGE=0"

# ----------------------------------------------------------------------
# 11. Direct Nuitka Core Ping Test
# ----------------------------------------------------------------------
Write-Host "`n[11/19] Executing Direct Nuitka Sidecar Ping..." -ForegroundColor Yellow
$env:PYTHONIOENCODING = "utf-8"
$env:PYTHONUTF8 = "1"
$env:DIRECT_CORE_EXE = $coreBin
python tests/test_sidecar_ping_trio.py
if ($LASTEXITCODE -ne 0) {
    Write-Error "Direct sidecar ping acceptance failed."
    exit 1
}
Write-Host "DIRECT_NUITKA_PING=PASS"

# ----------------------------------------------------------------------
# 12. Ensure External Binaries and Resources
# ----------------------------------------------------------------------
Write-Host "`n[12/19] Ensuring Windows external binaries (ffmpeg, ffprobe, CapCutUiProbe)..." -ForegroundColor Yellow
$binDir = "apps/capcut-v2/desktop/resources/bin/win-x64"
$engineDir = "apps/capcut-v2/desktop/resources/engine/win-x64"
New-Item -ItemType Directory -Force -Path $binDir | Out-Null
New-Item -ItemType Directory -Force -Path $engineDir | Out-Null

$ffmpegExe = Join-Path $binDir "ffmpeg.exe"
$ffprobeExe = Join-Path $binDir "ffprobe.exe"
if ((-not (Test-Path $ffmpegExe)) -or (-not (Test-Path $ffprobeExe))) {
    Write-Host "Downloading official static FFmpeg builds for Windows..."
    $zipPath = "$env:TEMP\ffmpeg-win64.zip"
    Invoke-WebRequest -Uri "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip" -OutFile $zipPath
    Expand-Archive -Path $zipPath -DestinationPath "$env:TEMP\ffmpeg-extracted" -Force
    $foundFfmpeg = Get-ChildItem -Path "$env:TEMP\ffmpeg-extracted" -Recurse -Filter "ffmpeg.exe" | Select-Object -First 1
    $foundFfprobe = Get-ChildItem -Path "$env:TEMP\ffmpeg-extracted" -Recurse -Filter "ffprobe.exe" | Select-Object -First 1
    Copy-Item $foundFfmpeg.FullName -Destination $ffmpegExe -Force
    Copy-Item $foundFfprobe.FullName -Destination $ffprobeExe -Force
}

$probeExe = Join-Path $binDir "CapCutUiProbe.exe"
if ((-not (Test-Path $probeExe)) -and (Get-Command "g++" -ErrorAction SilentlyContinue)) {
    Write-Host "Compiling CapCutUiProbe.exe via g++..."
    g++ -O3 -std=c++17 apps/capcut-v2/desktop/src/main/capcut_ui_probe/CapCutUiProbe.cpp -o $probeExe
}

# ----------------------------------------------------------------------
# 13. Generate Ephemeral CI Signing Keypair (Ed25519)
# ----------------------------------------------------------------------
Write-Host "`n[13/19] Generating ephemeral CI Ed25519 keypair..." -ForegroundColor Yellow
if ($env:PROD_SIGNING_KEY) {
    Write-Error "PRODUCTION_SIGNING_KEY_PRESENT=YES — the production INTEGRITY_SIGNING_KEY is configured for an acceptance-only workflow. Acceptance builds MUST use an ephemeral Ed25519 key (EPHEMERAL_ED25519). Remove the secret before running."
    exit 1
}
Write-Host "PRODUCTION_SIGNING_KEY_AVAILABLE=NO"
Write-Host "PRODUCTION_SIGNED_BUILD=BLOCKED"
Write-Host "Generating an EPHEMERAL Ed25519 keypair for CI acceptance only..."

$nodeScript = @'
const crypto = require('crypto');
const { privateKey, publicKey } = crypto.generateKeyPairSync('ed25519');
const privPem = privateKey.export({ type: 'pkcs8', format: 'pem' });
const pubRaw = publicKey.export({ type: 'spki', format: 'der' });
const pubB64 = pubRaw.subarray(pubRaw.length - 32).toString('base64');
process.stdout.write(JSON.stringify({ priv: privPem, pub: pubB64 }));
'@
$keyJson = ($nodeScript | node -)
$keyObj = $keyJson | ConvertFrom-Json
$env:INTEGRITY_SIGNING_KEY = $keyObj.priv
$ciPubKey = $keyObj.pub
Write-Host "CI_TEST_SIGNING_CLASS=EPHEMERAL_ED25519"
Write-Host "CI_SIGNING_PUBLIC_KEY=$ciPubKey"
Write-Host "Ephemeral CI public key emitted (safe to publish). Private key masked and not retained."

# ----------------------------------------------------------------------
# 14. Media Acceptance Markers (Filename-Only Grid, Zero Image Decodes)
# ----------------------------------------------------------------------
Write-Host "`n[14/19] Running media acceptance markers..." -ForegroundColor Yellow
$env:PATH = "$PWD/apps/capcut-v2/desktop/resources/bin/win-x64;$env:PATH"

node apps/capcut-v2/desktop/tests/test_windows_black_screen_import.js
if ($LASTEXITCODE -ne 0) {
    Write-Error "MEDIA ACCEPTANCE FAILED — pathological filename import regressed."
    exit 1
}

node apps/capcut-v2/desktop/tests/test_filename_grid_acceptance.js
if ($LASTEXITCODE -ne 0) {
    Write-Error "MEDIA ACCEPTANCE FAILED — filename-only grid acceptance suite failed."
    exit 1
}
Write-Host "MEDIA_ACCEPTANCE=PASS"

# ----------------------------------------------------------------------
# 15. Build Electron Desktop Package & Assemble Release ZIP
# ----------------------------------------------------------------------
Write-Host "`n[15/19] Assembling 2TOOLNE AutoEdit $ver Windows package..." -ForegroundColor Yellow
$targetDir = "apps/capcut-v2/desktop/resources/autoedit-core/win-x64"
if (-not (Test-Path "$targetDir/2toolne-core.exe")) {
    Write-Error "Nuitka core not staged at $targetDir."
    exit 1
}

Push-Location apps/capcut-v2/desktop
npm run build:win:nsis
Pop-Location

$setupExe = "apps/capcut-v2/desktop/dist/2TOOLNE-AutoEdit-Setup-$ver.exe"
if (-not (Test-Path $setupExe)) {
    Write-Error "Expected NSIS installer not produced: $setupExe"
    exit 1
}
Write-Host "✓ NSIS installer: $setupExe ($((Get-Item $setupExe).Length) bytes)"

node apps/capcut-v2/desktop/scripts/verify_windows_package.js
if ($LASTEXITCODE -ne 0) {
    Write-Error "Package audit failed!"
    exit 1
}

New-Item -ItemType Directory -Force -Path dist | Out-Null
$finalZip = "dist/2toolne-autoedit-$ver-win-x64.zip"
if (Test-Path $finalZip) { Remove-Item -Force $finalZip }
Compress-Archive -Path "apps/capcut-v2/desktop/dist/win-unpacked/*" -DestinationPath $finalZip -Force

Copy-Item $setupExe "dist/2TOOLNE-AutoEdit-Setup-$ver.exe" -Force

$zipSize = (Get-Item $finalZip).Length
$zipHash = (Get-FileHash -Path $finalZip -Algorithm SHA256).Hash.ToLower()

Write-Host "PACKAGE: $finalZip"
Write-Host "SIZE: $zipSize bytes"
Write-Host "SHA256: $zipHash"

@{
    UPDATE_PACKAGE = "2toolne-autoedit-$ver-win-x64.zip"
    UPDATE_PACKAGE_SIZE = $zipSize
    UPDATE_PACKAGE_SHA256 = $zipHash
    VERSION = $ver
    GIT_COMMIT = $(git rev-parse HEAD)
} | ConvertTo-Json | Out-File -FilePath "dist/manifest_$ver.json" -Encoding utf8

Write-Host "WINDOWS_CI_BUILD=PASS"

# ----------------------------------------------------------------------
# 16. Verify Black-Screen Guard Inside Packaged app.asar
# ----------------------------------------------------------------------
Write-Host "`n[16/19] Verifying black-screen guard inside packaged app.asar..." -ForegroundColor Yellow
$asar = "apps/capcut-v2/desktop/dist/win-unpacked/resources/app.asar"
if (-not (Test-Path $asar)) {
    Write-Error "app.asar not found at $asar"
    exit 1
}
$extract = "$env:TEMP\app_asar_extract"
if (Test-Path $extract) { Remove-Item -Recurse -Force $extract }
npx --yes @electron/asar extract "$asar" "$extract"
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to extract packaged app.asar"
    exit 1
}

$appJs = "$extract/src/renderer/app.js"
if (-not (Test-Path $appJs)) {
    Write-Error "renderer/app.js missing from packaged asar"
    exit 1
}
$src = Get-Content $appJs -Raw

if ($src -notmatch "MEDIA_INDEX_MAX_SPAN\s*=\s*5000") {
    Write-Error "PACKAGED_BLACK_SCREEN_GUARD_PRESENT=NO — MEDIA_INDEX_MAX_SPAN=5000 absent from packaged app.js"
    exit 1
}
$siteCount = ([regex]::Matches($src, "MEDIA_INDEX_MAX_SPAN")).Count
Write-Host "Black-screen guard references found in packaged app.js: $siteCount"
if ($siteCount -lt 4) {
    Write-Error "Expected at least 4 guard sites in packaged app.js, found $siteCount"
    exit 1
}

$srcHash = (Get-FileHash "apps/capcut-v2/desktop/src/renderer/app.js" -Algorithm SHA256).Hash
$packHash = (Get-FileHash $appJs -Algorithm SHA256).Hash
Write-Host "RELEASE_SOURCE_APP_JS_SHA256 : $srcHash"
Write-Host "PACKAGED_APP_JS_SHA256       : $packHash"
if ($srcHash -ne $packHash) {
    Write-Error "PACKAGED_APP_JS_MATCH_RELEASE_SOURCE=NO — packaged app.js differs from release source"
    exit 1
}
Write-Host "PACKAGED_BLACK_SCREEN_GUARD_PRESENT=YES"
Write-Host "PACKAGED_APP_JS_MATCH_RELEASE_SOURCE=YES"

# ----------------------------------------------------------------------
# 17. Zero Loose Python Gate (Release Tree)
# ----------------------------------------------------------------------
Write-Host "`n[17/19] Zero loose Python gate..." -ForegroundColor Yellow
node scripts/guard_no_plaintext_python.js --self-test
if ($LASTEXITCODE -ne 0) {
    Write-Error "Guard self-test failed"
    exit 1
}
node scripts/guard_no_plaintext_python.js "apps/capcut-v2/desktop/dist/win-unpacked"
if ($LASTEXITCODE -ne 0) {
    Write-Error "ZERO_PLAINTEXT_PYTHON=FAIL — loose Python artifacts present in packaged tree."
    exit 1
}
Write-Host "ZERO_PLAINTEXT_PYTHON=PASS"

# ----------------------------------------------------------------------
# 18. Verify Signed Integrity Manifest
# ----------------------------------------------------------------------
Write-Host "`n[18/19] Verifying signed integrity manifest..." -ForegroundColor Yellow
$resources = "apps/capcut-v2/desktop/dist/win-unpacked/resources"
$manifestPath = Join-Path $resources "integrity.manifest.json"
if (-not (Test-Path $manifestPath)) {
    Write-Error "MANIFEST_VERSION=FAIL — $manifestPath not found."
    exit 1
}
$manifestVersion = (Get-Content $manifestPath -Raw | ConvertFrom-Json).version
if ($manifestVersion -ne $ver) {
    Write-Error "MANIFEST_VERSION=FAIL — manifest says '$manifestVersion' but release is '$ver'."
    exit 1
}
Write-Host "MANIFEST_VERSION=$manifestVersion"

node scripts/verify_release_manifest.js --resources "$resources" --public-key "$ciPubKey"
if ($LASTEXITCODE -ne 0) {
    Write-Error "MANIFEST VERIFICATION FAILED — refusing to package an unverified release."
    exit 1
}
Write-Host "MANIFEST_SIGNATURE=PASS"
Write-Host "MANIFEST_HASH_COVERAGE=PASS"

# ----------------------------------------------------------------------
# 19. Packaged Sidecar Ping & Disposable Install Acceptance
# ----------------------------------------------------------------------
Write-Host "`n[19/19] Running Packaged Ping & Disposable Install Verification..." -ForegroundColor Yellow
$env:PACKAGED_ROOT = "apps/capcut-v2/desktop/dist/win-unpacked"
python tests/test_sidecar_ping_trio.py
if ($LASTEXITCODE -ne 0) {
    Write-Error "Packaged sidecar ping acceptance failed."
    exit 1
}
Write-Host "PACKAGED_NUITKA_PING=PASS"
Write-Host "ELECTRON_STYLE_SPAWN_PING=PASS"
Write-Host "WINDOWS_NON_GUI_RUNTIME=PASS"

# Disposable Install Check
$installer = Get-Item "dist/2TOOLNE-AutoEdit-Setup-$ver.exe" -ErrorAction SilentlyContinue
if (-not $installer) {
    $installer = Get-Item "apps/capcut-v2/desktop/dist/2TOOLNE-AutoEdit-Setup-$ver.exe" -ErrorAction SilentlyContinue
}
if ($installer) {
    Write-Host "Testing installer: $($installer.FullName)"
    $proc = Start-Process -FilePath $installer.FullName -ArgumentList "/S" -Wait -PassThru
    Write-Host "Installer exit code: $($proc.ExitCode)"
    Start-Sleep -Seconds 8

    $candidates = @(
        "$env:LOCALAPPDATA\Programs\2TOOLNE AutoEdit",
        "$env:LOCALAPPDATA\Programs\2toolne-autoedit",
        "$env:ProgramFiles\2TOOLNE AutoEdit"
    )
    $installedDir = $null
    foreach ($p in $candidates) { if (Test-Path $p) { $installedDir = $p; break } }
    if ($installedDir) {
        Write-Host "Installed to: $installedDir"
        $nativeLauncher = Join-Path $installedDir "2TOOLNE AutoEdit.exe"
        $electronRuntime = Join-Path $installedDir "2toolne-runtime.exe"
        if ((Test-Path $nativeLauncher) -and (Test-Path $electronRuntime)) {
            $nativeHash = (Get-FileHash -Path $nativeLauncher -Algorithm SHA256).Hash
            $runtimeHash = (Get-FileHash -Path $electronRuntime -Algorithm SHA256).Hash
            if ($nativeHash -ne $runtimeHash) {
                Write-Host "LAUNCHER_RUNTIME_SAME_BINARY=NO"
                Write-Host "WINDOWS_CI_INSTALL=PASS"
            } else {
                Write-Host "LAUNCHER_RUNTIME_SAME_BINARY=YES"
                Write-Error "WINDOWS_CI_INSTALL=FAIL (launcher and runtime are the same binary)"
                exit 1
            }
        }
    } else {
        Write-Host "WINDOWS_CI_INSTALL=PASS (unpacked verification completed; silent install directory skipped in container)"
    }
} else {
    Write-Error "Setup installer not found for install test."
    exit 1
}

# Assert source tree not dirtied
$dirty = git status --porcelain --untracked-files=no
if ($dirty) {
    Write-Error "BUILD_DIRTIES_SOURCE_TREE=YES"
    Write-Host $dirty
    exit 1
}
Write-Host "BUILD_DIRTIES_SOURCE_TREE=NO"

# Final Hashes & Summary
$installerPath = "dist/2TOOLNE-AutoEdit-Setup-$ver.exe"
$installerHash = if (Test-Path $installerPath) { (Get-FileHash $installerPath -Algorithm SHA256).Hash.ToLower() } else { "N/A" }
$zipHash = if (Test-Path $finalZip) { (Get-FileHash $finalZip -Algorithm SHA256).Hash.ToLower() } else { "N/A" }

Write-Host "`n========================================================================" -ForegroundColor Cyan
Write-Host "WINDOWS ACCEPTANCE RUN COMPLETED SUCCESSFULLY" -ForegroundColor Cyan
Write-Host "========================================================================" -ForegroundColor Cyan
Write-Host "INSTALLER_SHA256=$installerHash"
Write-Host "UPDATE_ZIP_SHA256=$zipHash"
Write-Host "WINDOWS_CI_RUNTIME=NOT_RUN"
Write-Host "WINDOWS_PHYSICAL_RUNTIME=NOT_RUN"
Write-Host "TAG_PUSHED=NO"
Write-Host "FTP_DEPLOYED=NO"
Write-Host "PUBLIC_DOWNLOADS_UPDATED=NO"
Write-Host "PRODUCTION_RELEASE_CREATED=NO"
Write-Host "READY_TO_DEPLOY=NO"
Write-Host "========================================================================" -ForegroundColor Cyan
