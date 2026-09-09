@echo off
setlocal EnableDelayedExpansion
title 2TOOLNE AutoEdit V2 - Windows Acceptance Test
chcp 65001 > nul

echo ================================================================================
echo           2TOOLNE AUTOEDIT FOR CAPCUT V2 -- WINDOWS ACCEPTANCE GATE
echo                            CUSTOMER TEST RUNNER
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
if "%1"=="--capcut-probe" (
    call "%~dp0RUN_CAPCUT_PROBE.bat" %*
    exit /b %ERRORLEVEL%
)

:: 2. Check PowerShell availability
where powershell.exe > nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [PRE-FLIGHT FAILED] powershell.exe was not found in System PATH.
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

echo BAT_BOOT = PASS

:: 3. PowerShell 5.1 Parser Preflight
set SCRIPT_PATH=%~dp0TestHarness\harness.ps1
set MODULE_PATH=%~dp0TestHarness\modules\TestHarness.psm1

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$errs = $null; [System.Management.Automation.Language.Parser]::ParseFile('%SCRIPT_PATH%', [ref]$null, [ref]$errs); if ($errs.Count -gt 0) { Write-Host 'POWERSHELL_PARSE = FAIL' -ForegroundColor Red; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo [PRE-FLIGHT FAILED] PowerShell 5.1 Parser Error in harness.ps1.
    echo POWERSHELL_PARSE = FAIL
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$errs = $null; [System.Management.Automation.Language.Parser]::ParseFile('%MODULE_PATH%', [ref]$null, [ref]$errs); if ($errs.Count -gt 0) { Write-Host 'POWERSHELL_PARSE = FAIL' -ForegroundColor Red; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo [PRE-FLIGHT FAILED] PowerShell 5.1 Parser Error in TestHarness.psm1.
    echo POWERSHELL_PARSE = FAIL
    if "%1"=="--ci-smoke" exit /b 1
    if "%CI%"=="" pause
    exit /b 1
)

:: 4. Launch PowerShell Harness
set PASS_ARGS=%*
if "%1"=="--ci-smoke" set PASS_ARGS=-CiSmoke
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%SCRIPT_PATH%" %PASS_ARGS%

set HARNESS_EXIT=%ERRORLEVEL%
echo.
if %HARNESS_EXIT% NEQ 0 (
    echo [!] Test session finished with exit code: %HARNESS_EXIT%
) else (
    echo [*] Test session finished successfully.
)

if "%1"=="--ci-smoke" exit /b %HARNESS_EXIT%
if "%CI%"=="" (
    echo.
    echo Press any key to close this window...
    pause > nul
)
exit /b %HARNESS_EXIT%
