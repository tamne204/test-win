@echo off
setlocal EnableDelayedExpansion
title 2TOOLNE AutoEdit V2 - CapCut 9.3.0.3970 Physical UI Probe
chcp 65001 > nul

echo ================================================================================
echo           2TOOLNE AUTOEDIT V2 -- CAPCUT PHYSICAL UI PROBE RUNNER
echo                      TARGET: CAPCUT DESKTOP 9.3.0.3970
echo ================================================================================
echo.

:: 1. Architecture Check (Must be 64-bit)
if "%PROCESSOR_ARCHITECTURE%"=="AMD64" goto :ArchOk
if "%PROCESSOR_ARCHITEW6432%"=="AMD64" goto :ArchOk

echo [PRE-FLIGHT FAILED] Windows 64-bit (x64) is required.
echo Detected Architecture: %PROCESSOR_ARCHITECTURE%
if "%1"=="--ci-smoke" exit /b 1
if "%CI%"=="" pause
exit /b 1

:ArchOk
:: 2. Check PowerShell availability
where powershell.exe > nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [PRE-FLIGHT FAILED] powershell.exe was not found in System PATH.
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

:: 3. Locate Probe Script
set PROBE_SCRIPT=%~dp0TestHarness\Deepen-CapCutUiProbe.ps1
if not exist "%PROBE_SCRIPT%" (
    set PROBE_SCRIPT=%~dp0Deepen-CapCutUiProbe.ps1
)
if not exist "%PROBE_SCRIPT%" (
    set PROBE_SCRIPT=%~dp0TestHarness\modules\Deepen-CapCutUiProbe.ps1
)

if not exist "%PROBE_SCRIPT%" (
    echo [ERROR] Deepen-CapCutUiProbe.ps1 script not found.
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

:: 4. PowerShell 5.1 Parser Preflight
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$errs = $null; [System.Management.Automation.Language.Parser]::ParseFile('%PROBE_SCRIPT%', [ref]$null, [ref]$errs); if ($errs.Count -gt 0) { Write-Host 'POWERSHELL_PARSE = FAIL' -ForegroundColor Red; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo [PRE-FLIGHT FAILED] PowerShell 5.1 Parser Error in Deepen-CapCutUiProbe.ps1.
    echo POWERSHELL_PARSE = FAIL
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

echo BAT_BOOT = PASS
echo POWERSHELL_5_1_PARSE = PASS

:: 5. Launch Probe
set PASS_ARGS=%*
if "%1"=="--ci-smoke" set PASS_ARGS=-Mock
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%PROBE_SCRIPT%" %PASS_ARGS%

set PROBE_EXIT=%ERRORLEVEL%
echo.
if %PROBE_EXIT% NEQ 0 (
    echo [!] CapCut UI Probe completed with exit code: %PROBE_EXIT%
) else (
    echo [*] CapCut UI Probe completed successfully.
)

if "%1"=="--ci-smoke" exit /b %PROBE_EXIT%
if "%CI%"=="" (
    echo.
    echo Press any key to close this window...
    pause > nul
)
exit /b %PROBE_EXIT%
