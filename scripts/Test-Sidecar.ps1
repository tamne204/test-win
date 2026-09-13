<#
.SYNOPSIS
  Test-Sidecar.ps1 - 2TOOLNE Windows CI Lab: Sidecar PE & Stdio JSON-RPC Audit
.DESCRIPTION
  Verifies the packaged 2toolne-core.exe:
  1. Validates PE32+ (x64 / AMD64) machine header.
  2. Inspects dependencies and packaging mode (onedir vs onefile).
  3. Executes real stdio JSON-RPC PING handshake across multiple cold starts.
  4. Records min, max, avg latencies and generates sidecar-ping.json report.
#>
param (
  [string]$CorePath = "",
  [int]$Iterations = 5,
  [int]$TimeoutSec = 30,
  [string]$OutputDir = "."
)

$ErrorActionPreference = "Stop"

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE WINDOWS CI LAB — SIDECAR RUNTIME & PE AUDIT" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Resolve Core Path
if (-not $CorePath) {
  $candidates = @(
    "resources/autoedit-core/win-x64/2toolne-core.exe",
    "resources/autoedit-core/win-x64/autoedit-core.exe",
    "resources/autoedit-core/2toolne-core.exe",
    "resources/autoedit-core/autoedit-core.exe",
    "2toolne-core.exe",
    "autoedit-core.exe"
  )
  foreach ($c in $candidates) {
    if (Test-Path $c) {
      $CorePath = (Resolve-Path $c).Path
      break
    }
  }
}

if (-not $CorePath -or -not (Test-Path $CorePath)) {
  Write-Error "CRITICAL: 2toolne-core.exe not found at specified or default paths."
  exit 1
}

$coreItem = Get-Item $CorePath
Write-Host "Target Core Binary : $($coreItem.FullName)"
Write-Host "Binary Size        : $($coreItem.Length.ToString('N0')) bytes"

# 2. PE32+ (x64) Header Audit
Write-Host "`n[1/4] Auditing Windows PE Architecture ..." -ForegroundColor Yellow
$bytes = [System.IO.File]::ReadAllBytes($CorePath)
if ($bytes.Length -lt 1024 -or $bytes[0] -ne 0x4D -or $bytes[1] -ne 0x5A) {
  Write-Error "FAIL: Binary does not have a valid DOS 'MZ' header!"
  exit 1
}

$peOffset = [System.BitConverter]::ToUInt32($bytes, 0x3C)
if ($bytes[$peOffset] -ne 0x50 -or $bytes[$peOffset + 1] -ne 0x45) {
  Write-Error "FAIL: Invalid PE signature at offset 0x$($peOffset.ToString('X'))"
  exit 1
}

$machine = [System.BitConverter]::ToUInt16($bytes, $peOffset + 4)
$isX64 = ($machine -eq 0x8664)

if ($isX64) {
  Write-Host "✓ Verified PE32+ Executable: AMD64 (x64) architecture (Machine: 0x8664)" -ForegroundColor Green
} else {
  Write-Error "FAIL: Binary is not AMD64 x64! Machine type: 0x$($machine.ToString('X'))"
  exit 1
}

# 3. Dependency & Packaging Mode Audit
Write-Host "`n[2/4] Auditing Dependency Closure & Packaging Mode ..." -ForegroundColor Yellow
$coreDir = Split-Path -Parent $CorePath
$internalDir = Join-Path $coreDir "_internal"

if (Test-Path $internalDir) {
  Write-Error "REGRESSION FAILURE: Found prohibited PyInstaller _internal/ directory in core package! Nuitka onefile architecture required."
  exit 1
}

# Check for PyInstaller bootloader markers in binary
$coreBytes = [System.IO.File]::ReadAllBytes($CorePath)
$coreText = [System.Text.Encoding]::ASCII.GetString($coreBytes)
if ($coreText.Contains("pyimod01_archive") -or $coreText.Contains("base_library.zip")) {
  Write-Error "REGRESSION FAILURE: Found PyInstaller bootloader signatures in 2toolne-core.exe! Production requires Nuitka onefile."
  exit 1
}

# Authoritative Build Metadata Verification
$metaCandidates = @(
  (Join-Path $coreDir "core-build-metadata.json"),
  "apps/capcut-v2/packaging/dist/core-build-metadata.json",
  "build_out/core-build-metadata.json"
)
$metaPath = $null
foreach ($m in $metaCandidates) {
  if (Test-Path $m) {
    $metaPath = (Resolve-Path $m).Path
    break
  }
}

if (-not $metaPath) {
  Write-Error "CRITICAL: Authoritative core-build-metadata.json missing! Cannot verify Nuitka build provenance."
  exit 1
}

$metadata = Get-Content -Raw -Path $metaPath | ConvertFrom-Json
$actualHash = (Get-FileHash -Path $CorePath -Algorithm SHA256).Hash.ToLower()

if ($metadata.packager.ToLower() -ne "nuitka") {
  Write-Error "CRITICAL: Build metadata specifies non-Nuitka packager: $($metadata.packager)"
  exit 1
}

if ($metadata.mode.ToLower() -ne "onefile") {
  Write-Error "CRITICAL: Build metadata specifies non-onefile mode: $($metadata.mode)"
  exit 1
}

if ($metadata.sha256.ToLower() -ne $actualHash) {
  Write-Error "CRITICAL: Core SHA256 mismatch! Metadata: $($metadata.sha256) vs Actual: $actualHash"
  exit 1
}

Write-Host "✓ Verified Authoritative Build Metadata from ${metaPath}:" -ForegroundColor Green
Write-Host "  CORE_PACKAGER=NUITKA" -ForegroundColor Green
Write-Host "  CORE_MODE=ONEFILE" -ForegroundColor Green
Write-Host "  NUITKA_VERSION=$($metadata.nuitka_version)" -ForegroundColor Green
Write-Host "  SOURCE_COMMIT=$($metadata.source_commit)" -ForegroundColor Green
Write-Host "  CORE_SHA256=$actualHash" -ForegroundColor Green
Write-Host "✓ PYINSTALLER_PRODUCTION_USAGE=0" -ForegroundColor Green
Write-Host "✓ PYINSTALLER_INTERNAL_PRESENT=NO" -ForegroundColor Green

# 4. Windows Defender / Environment Context
Write-Host "`n[3/4] Recording System & Security Context ..." -ForegroundColor Yellow
try {
  $defStatus = Get-MpComputerStatus -ErrorAction SilentlyContinue
  if ($defStatus) {
    Write-Host "  Antivirus Enabled    : $($defStatus.AntivirusEnabled)"
    Write-Host "  RealTime Protection  : $($defStatus.RealTimeProtectionEnabled)"
  }
} catch {
  Write-Host "  Defender inspection skipped (non-admin or non-supported runner environment)."
}

# 5. Execute Stdio JSON-RPC Ping via Node.js Harness
Write-Host "`n[4/4] Executing Stdio JSON-RPC Ping ($Iterations Cold Starts) ..." -ForegroundColor Yellow
$nodeHarness = Join-Path $PSScriptRoot "..\tests\sidecar_ping.js"
if (-not (Test-Path $nodeHarness)) {
  $nodeHarness = "tests/sidecar_ping.js"
}

$outputReport = if ($env:PING_OUTPUT_FILE) { $env:PING_OUTPUT_FILE } else { Join-Path $OutputDir "sidecar-ping.json" }

$env:CORE_EXE_PATH = $CorePath
$env:PING_ITERATIONS = $Iterations.ToString()
$env:PING_TIMEOUT_MS = ($TimeoutSec * 1000).ToString()
$env:PING_OUTPUT_FILE = $outputReport

node $nodeHarness
$nodeExit = $LASTEXITCODE

if ($nodeExit -ne 0) {
  Write-Error "FAIL: Sidecar JSON-RPC Ping failed!"
  exit $nodeExit
}

Write-Host "✓ Test-Sidecar completed successfully." -ForegroundColor Green
exit 0
