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
$nuitkaDistDir = Join-Path $coreDir "2toolne-core.dist"

$nuitkaMode = "UNKNOWN"
$dependencyCount = 0

if (Test-Path $internalDir) {
  $nuitkaMode = "PYINSTALLER_ONEDIR_STANDALONE"
  $deps = Get-ChildItem -Path $internalDir -Recurse -File
  $dependencyCount = $deps.Count
  Write-Host "✓ Detected Packaging Mode: $nuitkaMode ($dependencyCount bundled runtime files in _internal)" -ForegroundColor Green
  
  # Check for silero_vad asset
  $vadAsset = Join-Path $internalDir "faster_whisper\assets\silero_vad_v6.onnx"
  if (Test-Path $vadAsset) {
    Write-Host "✓ Bundled VAD asset verified: $vadAsset ($( (Get-Item $vadAsset).Length.ToString('N0') ) bytes)" -ForegroundColor Green
  }
} elseif (Test-Path $nuitkaDistDir) {
  $nuitkaMode = "NUITKA_STANDALONE_DIR"
  $deps = Get-ChildItem -Path $nuitkaDistDir -Recurse -File
  $dependencyCount = $deps.Count
  Write-Host "✓ Detected Packaging Mode: $nuitkaMode ($dependencyCount bundled files)" -ForegroundColor Green
} else {
  $nuitkaMode = "STANDALONE_SINGLE_EXE"
  Write-Host "✓ Detected Packaging Mode: $nuitkaMode (Self-contained binary)" -ForegroundColor Green
}

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

$outputReport = Join-Path $OutputDir "sidecar-ping.json"

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
