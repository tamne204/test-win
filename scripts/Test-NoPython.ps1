<#
.SYNOPSIS
  Test-NoPython.ps1 - Tests that 2toolne-core.exe runs with zero system Python dependencies.
#>
param (
  [string]$CorePath = ""
)

$ErrorActionPreference = "Stop"

if (-not $CorePath -or -not (Test-Path $CorePath)) {
  $cands = @(
    "build_out/2toolne-core.exe",
    "apps/capcut-v2/packaging/dist/2toolne-core.exe",
    "resources/autoedit-core/win-x64/2toolne-core.exe"
  )
  foreach ($c in $cands) {
    if (Test-Path $c) { $CorePath = (Resolve-Path $c).Path; break }
  }
}

if (-not $CorePath -or -not (Test-Path $CorePath)) {
  Write-Error "CRITICAL: 2toolne-core.exe not found for No-Python audit."
  exit 1
}

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "2TOOLNE WINDOWS CI LAB — NO SYSTEM PYTHON DEPENDENCY AUDIT" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "Target Core Binary : $CorePath"

# Sanitize PATH: Strip all python paths
$origPath = $env:PATH
$pathParts = $origPath -split [System.IO.Path]::PathSeparator
$sanitizedParts = $pathParts | Where-Object {
  $_ -notmatch "python" -and $_ -notmatch "Scripts" -and $_ -notmatch "pyenv" -and $_ -notmatch "conda"
}
$sanitizedPath = $sanitizedParts -join [System.IO.Path]::PathSeparator

Write-Host "Original PATH entries  : $($pathParts.Count)"
Write-Host "Sanitized PATH entries : $($sanitizedParts.Count) (Python directories completely removed)"

# Create a child process with sanitized environment
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $CorePath
$psi.WorkingDirectory = (Split-Path -Parent $CorePath)
$psi.UseShellExecute = $false
$psi.RedirectStandardInput = $true
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.CreateNoWindow = $true

# Set sanitized PATH
$psi.EnvironmentVariables["PATH"] = $sanitizedPath
$psi.EnvironmentVariables["PYTHONPATH"] = ""
$psi.EnvironmentVariables["PYTHONHOME"] = ""

$proc = New-Object System.Diagnostics.Process
$proc.StartInfo = $psi

try {
  $proc.Start() | Out-Null
  Write-Host "Spawned process in sanitized environment (PID: $($proc.Id))..."

  # Send JSON-RPC PING
  $pingPayload = '{"jsonrpc": "2.0", "id": 999, "method": "PING"}'
  $proc.StandardInput.WriteLine($pingPayload)
  $proc.StandardInput.Flush()

  # Read response with 15s timeout
  $readTask = $proc.StandardOutput.ReadLineAsync()
  if ($readTask.Wait(15000)) {
    $responseLine = $readTask.Result
    Write-Host "Received response: $responseLine"
    if ($responseLine -and ($responseLine -match '"pong":\s*true' -or $responseLine -match '"pong":true')) {
      Write-Host "✓ CORE_REQUIRES_SYSTEM_PYTHON=NO (Verified self-contained standalone execution)" -ForegroundColor Green
      $proc.Kill()
      exit 0
    } else {
      Write-Error "FAIL: Unexpected response from core in sanitized environment: $responseLine"
      $proc.Kill()
      exit 1
    }
  } else {
    Write-Error "FAIL: Timeout waiting for response from core in sanitized environment (No Python on PATH)."
    $proc.Kill()
    exit 1
  }
} catch {
  Write-Error "FAIL: Exception launching core in sanitized environment: $_"
  exit 1
} finally {
  $env:PATH = $origPath
}
