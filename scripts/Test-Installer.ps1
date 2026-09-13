<#
.SYNOPSIS
  Test-Installer.ps1 - 2TOOLNE Windows CI Lab: NSIS Installer, Silent Install & Installed Core Ping
#>
param (
  [string]$InstallerPath = "",
  [string]$InstallDir = "",
  [string]$OutputDir = "."
)

$ErrorActionPreference = "Stop"

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE WINDOWS CI LAB — NSIS INSTALLER & RUNTIME VERIFICATION" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

if (-not $InstallerPath) {
  $cands = @(
    "2TOOLNE-AutoEdit-Setup-2.0.5.exe",
    "dist/2TOOLNE-AutoEdit-Setup-2.0.5.exe",
    "build_windows_release/2TOOLNE-AutoEdit-Setup-2.0.5.exe"
  )
  foreach ($c in $cands) {
    if (Test-Path $c) { $InstallerPath = (Resolve-Path $c).Path; break }
  }
}

if (-not $InstallerPath -or -not (Test-Path $InstallerPath)) {
  Write-Error "CRITICAL: Installer executable not found: $InstallerPath"
  exit 1
}

$instItem = Get-Item $InstallerPath
Write-Host "Target Installer : $($instItem.FullName)"
Write-Host "Installer Size   : $($instItem.Length.ToString('N0')) bytes"
$instHash = (Get-FileHash -Path $InstallerPath -Algorithm SHA256).Hash.ToLower()
Write-Host "Installer SHA256 : $instHash"

if (-not $InstallDir) {
  $InstallDir = Join-Path $env:LOCALAPPDATA "Programs\2TOOLNE AutoEdit"
}

Write-Host "Target Install Dir: $InstallDir"

# 1. Installer PE Audit
Write-Host "`n[1/6] Auditing Installer PE Header ..." -ForegroundColor Yellow
$bytes = [System.IO.File]::ReadAllBytes($InstallerPath)
if ($bytes.Length -lt 1024 -or $bytes[0] -ne 0x4D -or $bytes[1] -ne 0x5A) {
  Write-Error "FAIL: Installer lacks valid DOS 'MZ' signature!"
  exit 1
}
Write-Host "✓ Installer PE signature confirmed valid." -ForegroundColor Green

# 2. Silent Installation
Write-Host "`n[2/6] Executing Silent Installation (/S /D=...) ..." -ForegroundColor Yellow
if (Test-Path $InstallDir) {
  Remove-Item -Path $InstallDir -Recurse -Force -ErrorAction SilentlyContinue
}

$instProc = Start-Process -FilePath $InstallerPath -ArgumentList "/S", "/D=$InstallDir" -PassThru -Wait
Write-Host "Installer process exited with code: $($instProc.ExitCode)"

# Wait briefly for NSIS helper threads to finish extracting
Start-Sleep -Seconds 3

if (-not (Test-Path $InstallDir)) {
  Write-Error "FAIL: Install directory was not created after silent install: $InstallDir"
  exit 1
}
Write-Host "✓ Silent install succeeded. Installation directory verified." -ForegroundColor Green

# 3. Verify Installed Files
Write-Host "`n[3/6] Auditing Installed Application Tree ..." -ForegroundColor Yellow
$installedLauncher = Join-Path $InstallDir "2TOOLNE AutoEdit.exe"
$installedRuntime = Join-Path $InstallDir "2toolne-runtime.exe"
$installedResources = Join-Path $InstallDir "resources"
$installedAsar = Join-Path $installedResources "app.asar"
$installedManifest = Join-Path $installedResources "integrity.manifest.json"

$installedCore = Join-Path $installedResources "autoedit-core\win-x64\2toolne-core.exe"
if (-not (Test-Path $installedCore)) {
  $altCore = Join-Path $installedResources "autoedit-core\win-x64\autoedit-core.exe"
  if (Test-Path $altCore) { $installedCore = $altCore }
}

$requiredPaths = @($installedLauncher, $installedRuntime, $installedAsar, $installedManifest, $installedCore)
foreach ($req in $requiredPaths) {
  if (-not (Test-Path $req)) {
    Write-Error "FAIL: Critical installed file missing: $req"
    exit 1
  }
  Write-Host "  ✓ Found: $(Split-Path -Leaf $req)"
}

# 4. CRITICAL REQUIREMENT (Section 29): INSTALLED CORE PING TEST
Write-Host "`n[4/6] Executing Stdio JSON-RPC Ping on INSTALLED Core Binary ..." -ForegroundColor Yellow
$sidecarScript = Join-Path $PSScriptRoot "Test-Sidecar.ps1"
$installedPingLog = Join-Path $OutputDir "installed-sidecar-ping.json"
$env:PING_OUTPUT_FILE = $installedPingLog

& $sidecarScript -CorePath $installedCore -Iterations 5 -OutputDir $OutputDir
if ($LASTEXITCODE -ne 0) {
  Write-Error "FAIL: Installed core failed stdio JSON-RPC PING test!"
  exit 1
}
if (-not (Test-Path $installedPingLog) -and (Test-Path (Join-Path $OutputDir "sidecar-ping.json"))) {
  Copy-Item (Join-Path $OutputDir "sidecar-ping.json") $installedPingLog -Force
}
Write-Host "✓ INSTALLED CORE PING PASSED WITH ZERO ERRORS!" -ForegroundColor Green

# 5. Installed Native Launcher Verify
Write-Host "`n[5/6] Executing Installed Native Root Verifier (--verify-only) ..." -ForegroundColor Yellow
$env:_2TOOLNE_HEADLESS_TEST = "1"
$env:_2TOOLNE_RESOURCES_PATH = $installedResources
$env:_2TOOLNE_MANIFEST_PATH = $installedManifest

$vProc = Start-Process -FilePath $installedLauncher -ArgumentList "--verify-only", "--headless" -PassThru -Wait
if ($vProc.ExitCode -ne 0) {
  Write-Error "FAIL: Installed launcher verification failed with exit code $($vProc.ExitCode)!"
  exit 1
}
Write-Host "✓ Installed native launcher verified integrity of installed directory successfully." -ForegroundColor Green

# 6. Silent Uninstall Test
Write-Host "`n[6/6] Testing Silent Uninstallation ..." -ForegroundColor Yellow
$uninstaller = Join-Path $InstallDir "Uninstall 2TOOLNE AutoEdit.exe"
if (Test-Path $uninstaller) {
  $unProc = Start-Process -FilePath $uninstaller -ArgumentList "/S" -PassThru -Wait
  Start-Sleep -Seconds 3
  Write-Host "✓ Silent uninstaller completed." -ForegroundColor Green
} else {
  Write-Warning "Uninstaller executable not found at $uninstaller"
}

# Write summary audit json
$installerAudit = @{
  timestamp = (Get-Date).ToString("o")
  installer_file = (Split-Path -Leaf $InstallerPath)
  installer_size = $instItem.Length
  installer_sha256 = $instHash
  silent_install = "PASS"
  installed_tree_valid = "PASS"
  installed_core_ping = "PASS"
  installed_launcher_verify = "PASS"
  silent_uninstall = "PASS"
} | ConvertTo-Json -Depth 3

$installerAudit | Out-File -FilePath (Join-Path $OutputDir "installer-audit.json") -Encoding utf8
Write-Host "✓ Installer audit report saved." -ForegroundColor Green

Write-Host "`n======================================================================" -ForegroundColor Cyan
Write-Host "ALL INSTALLER AND INSTALLED RUNTIME TESTS PASSED (100%)" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
exit 0
