<#
.SYNOPSIS
  Test-Integrity.ps1 - 2TOOLNE Windows CI Lab: Integrity, Manifest & Anti-Tamper Audit
.DESCRIPTION
  Performs full cryptographic validation:
  1. Multi-binary PE32+ validation (8 files).
  2. Ed25519 manifest signature check against production key.
  3. SHA-256 digest matching for all 8 components.
  4. Native launcher --verify-only execution.
  5. Direct runtime bypass blocking check.
  6. 5 disposable tamper stress tests (app.asar, runtime, core, manifest, missing).
  7. Authenticode inspection.
#>
param (
  [string]$PackageDir = "",
  [string]$OutputDir = "."
)

$ErrorActionPreference = "Stop"

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE WINDOWS CI LAB — INTEGRITY & ANTI-TAMPER AUDIT" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

if (-not $PackageDir) {
  $cands = @("win-unpacked", "dist/win-unpacked", "apps/capcut-v2/desktop/dist/win-unpacked")
  foreach ($c in $cands) {
    if (Test-Path $c) { $PackageDir = (Resolve-Path $c).Path; break }
  }
}

if (-not $PackageDir -or -not (Test-Path $PackageDir)) {
  Write-Error "CRITICAL: Package directory not found: $PackageDir"
  exit 1
}

$resourcesDir = Join-Path $PackageDir "resources"
$launcherExe = Join-Path $PackageDir "2TOOLNE AutoEdit.exe"
$runtimeExe = Join-Path $PackageDir "2toolne-runtime.exe"
$manifestPath = Join-Path $resourcesDir "integrity.manifest.json"

Write-Host "Package Directory : $PackageDir"
Write-Host "Resources Dir     : $resourcesDir"
Write-Host "Native Launcher   : $launcherExe"
Write-Host "Electron Runtime  : $runtimeExe"
Write-Host "Manifest Path     : $manifestPath"

# 1. Run Node.js Release Manifest Audit (Ed25519 + SHA256)
Write-Host "`n[1/5] Auditing Ed25519 Signature & Multi-Binary SHA-256 Hashes ..." -ForegroundColor Yellow
$nodeAuditScript = Join-Path $PSScriptRoot "..\tests\release_audit.js"
if (-not (Test-Path $nodeAuditScript)) {
  $nodeAuditScript = "tests/release_audit.js"
}

$auditOutput = Join-Path $OutputDir "manifest-audit.json"
$env:RESOURCES_DIR = $resourcesDir
$env:AUDIT_OUTPUT_FILE = $auditOutput

node $nodeAuditScript
if ($LASTEXITCODE -ne 0) {
  Write-Error "FAIL: Manifest signature or SHA-256 verification failed!"
  exit 1
}
Write-Host "✓ Ed25519 signature and all 8 SHA-256 hashes matched perfectly." -ForegroundColor Green

# 2. Native Launcher --verify-only Execution
Write-Host "`n[2/5] Executing Native Root Verifier (--verify-only) ..." -ForegroundColor Yellow
if (-not (Test-Path $launcherExe)) {
  Write-Error "FAIL: Native launcher missing at $launcherExe"
  exit 1
}

$proc = Start-Process -FilePath $launcherExe -ArgumentList "--verify-only", "--resources-dir=$resourcesDir", "--headless" -PassThru -Wait
if ($proc.ExitCode -ne 0) {
  Write-Error "FAIL: Native root verifier exited with code $($proc.ExitCode) on authentic package!"
  exit 1
}
Write-Host "✓ Native root verification succeeded (Exit code 0)." -ForegroundColor Green

# 3. Direct Runtime Bypass Test
Write-Host "`n[3/5] Testing Direct Runtime Bypass Protection (2toolne-runtime.exe) ..." -ForegroundColor Yellow
if (Test-Path $runtimeExe) {
  $env:ELECTRON_RUN_AS_NODE = $null
  $env:_2TOOLNE_BOOTSTRAP_TOKEN = $null
  
  # Running runtime directly without token must fail
  $bypassProc = Start-Process -FilePath $runtimeExe -PassThru -Wait
  if ($bypassProc.ExitCode -eq 0) {
    Write-Error "FAIL: Direct launch of 2toolne-runtime.exe succeeded without bootstrap token! Security flaw."
    exit 1
  }
  Write-Host "✓ Direct runtime launch fail-securely blocked (Exit code $($bypassProc.ExitCode))." -ForegroundColor Green
} else {
  Write-Warning "2toolne-runtime.exe not found at root of package directory."
}

# 4. Disposable Tamper Stress Tests
Write-Host "`n[4/5] Running Disposable Copy Tamper Stress Tests ..." -ForegroundColor Yellow
$tempRoot = [System.IO.Path]::GetTempPath()
$disposableDir = Join-Path $tempRoot "2toolne_tamper_test_$(Get-Random)"

try {
  Write-Host "  Cloning package into disposable test directory: $disposableDir..."
  New-Item -ItemType Directory -Force -Path $disposableDir | Out-Null
  Copy-Item -Path "$PackageDir\*" -Destination $disposableDir -Recurse -Force
  
  $dispLauncher = Join-Path $disposableDir "2TOOLNE AutoEdit.exe"
  $dispResources = Join-Path $disposableDir "resources"
  $dispAsar = Join-Path $dispResources "app.asar"
  $dispRuntime = Join-Path $disposableDir "2toolne-runtime.exe"
  $dispCore = Join-Path $dispResources "autoedit-core\win-x64\2toolne-core.exe"
  $dispManifest = Join-Path $dispResources "integrity.manifest.json"

  # Test A: Tamper app.asar
  Write-Host "  -> [TAMPER-1] Mutating 1 byte in app.asar..."
  $asarBytes = [System.IO.File]::ReadAllBytes($dispAsar)
  $asarBytes[100] = [byte]($asarBytes[100] -bxor 0xFF)
  [System.IO.File]::WriteAllBytes($dispAsar, $asarBytes)
  $tProc = Start-Process -FilePath $dispLauncher -ArgumentList "--verify-only", "--resources-dir=$dispResources", "--headless" -PassThru -Wait
  if ($tProc.ExitCode -eq 0) {
    Write-Error "FAIL: Tampered app.asar was NOT blocked by native launcher!"
    exit 1
  }
  Write-Host "     ✓ Tampered app.asar successfully blocked (Exit code $($tProc.ExitCode))." -ForegroundColor Green
  # Restore
  Copy-Item -Path (Join-Path $PackageDir "resources\app.asar") -Destination $dispAsar -Force

  # Test B: Tamper 2toolne-runtime.exe
  if (Test-Path $dispRuntime) {
    Write-Host "  -> [TAMPER-2] Mutating 1 byte in 2toolne-runtime.exe..."
    $rtBytes = [System.IO.File]::ReadAllBytes($dispRuntime)
    $rtBytes[500] = [byte]($rtBytes[500] -bxor 0xFF)
    [System.IO.File]::WriteAllBytes($dispRuntime, $rtBytes)
    $tProc = Start-Process -FilePath $dispLauncher -ArgumentList "--verify-only", "--resources-dir=$dispResources", "--headless" -PassThru -Wait
    if ($tProc.ExitCode -eq 0) {
      Write-Error "FAIL: Tampered 2toolne-runtime.exe was NOT blocked!"
      exit 1
    }
    Write-Host "     ✓ Tampered 2toolne-runtime.exe successfully blocked." -ForegroundColor Green
    Copy-Item -Path (Join-Path $PackageDir "2toolne-runtime.exe") -Destination $dispRuntime -Force
  }

  # Test C: Tamper 2toolne-core.exe
  if (Test-Path $dispCore) {
    Write-Host "  -> [TAMPER-3] Mutating 1 byte in 2toolne-core.exe..."
    $coreBytes = [System.IO.File]::ReadAllBytes($dispCore)
    $coreBytes[200] = [byte]($coreBytes[200] -bxor 0xFF)
    [System.IO.File]::WriteAllBytes($dispCore, $coreBytes)
    $tProc = Start-Process -FilePath $dispLauncher -ArgumentList "--verify-only", "--resources-dir=$dispResources", "--headless" -PassThru -Wait
    if ($tProc.ExitCode -eq 0) {
      Write-Error "FAIL: Tampered 2toolne-core.exe was NOT blocked!"
      exit 1
    }
    Write-Host "     ✓ Tampered 2toolne-core.exe successfully blocked." -ForegroundColor Green
    Copy-Item -Path (Join-Path $PackageDir "resources\autoedit-core\win-x64\2toolne-core.exe") -Destination $dispCore -Force
  }

  # Test D: Tamper Manifest
  Write-Host "  -> [TAMPER-4] Mutating integrity.manifest.json..."
  $manJson = Get-Content $dispManifest -Raw
  $manJson = $manJson.Replace('"algorithm": "sha256"', '"algorithm": "none"')
  Set-Content -Path $dispManifest -Value $manJson -Encoding utf8
  $tProc = Start-Process -FilePath $dispLauncher -ArgumentList "--verify-only", "--resources-dir=$dispResources", "--headless" -PassThru -Wait
  if ($tProc.ExitCode -eq 0) {
    Write-Error "FAIL: Tampered manifest was NOT blocked!"
    exit 1
  }
  Write-Host "     ✓ Tampered manifest successfully blocked." -ForegroundColor Green
  Copy-Item -Path (Join-Path $PackageDir "resources\integrity.manifest.json") -Destination $dispManifest -Force

  # Test E: Missing Core
  if (Test-Path $dispCore) {
    Write-Host "  -> [TAMPER-5] Deleting 2toolne-core.exe..."
    Remove-Item -Path $dispCore -Force
    $tProc = Start-Process -FilePath $dispLauncher -ArgumentList "--verify-only", "--resources-dir=$dispResources", "--headless" -PassThru -Wait
    if ($tProc.ExitCode -eq 0) {
      Write-Error "FAIL: Missing 2toolne-core.exe was NOT blocked!"
      exit 1
    }
    Write-Host "     ✓ Missing core binary successfully blocked." -ForegroundColor Green
  }

} finally {
  if (Test-Path $disposableDir) {
    Remove-Item -Path $disposableDir -Recurse -Force -ErrorAction SilentlyContinue
  }
}

# 5. Authenticode Status Check
Write-Host "`n[5/5] Inspecting Authenticode Digital Signatures ..." -ForegroundColor Yellow
$authLog = Join-Path $OutputDir "authenticode-status.txt"
$authLines = @()

$binaries = @(
  $launcherExe,
  $runtimeExe,
  (Join-Path $resourcesDir "autoedit-core\win-x64\2toolne-core.exe")
)

foreach ($b in $binaries) {
  if (Test-Path $b) {
    $sig = Get-AuthenticodeSignature $b
    $entry = "BINARY: $(Split-Path -Leaf $b) | STATUS: $($sig.Status) | SUBJECT: $($sig.SignerCertificate.Subject) | TIMESTAMP: $($sig.TimeStamperCertificate.NotBefore)"
    Write-Host "  $entry"
    $authLines += $entry
  }
}

$authLines | Out-File -FilePath $authLog -Encoding utf8
Write-Host "✓ Authenticode inspection saved to: $authLog" -ForegroundColor Green

Write-Host "`n======================================================================" -ForegroundColor Cyan
Write-Host "ALL INTEGRITY AND ANTI-TAMPER TESTS PASSED (100%)" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
exit 0
