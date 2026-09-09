@echo off
setlocal EnableDelayedExpansion
title 2TOOLNE AutoEdit V2 - Windows Physical Acceptance Gate
chcp 65001 > nul

echo ================================================================================
echo           2TOOLNE AUTOEDIT FOR CAPCUT V2 -- WINDOWS PHYSICAL GATE
echo                       IMMUTABLE RELEASE CANDIDATE 2
echo ================================================================================
echo.

:: 1. Architecture Check (Must be 64-bit)
if "%PROCESSOR_ARCHITECTURE%"=="AMD64" goto :ArchOk
if "%PROCESSOR_ARCHITEW6432%"=="AMD64" goto :ArchOk

echo [ERROR] Windows 64-bit (x64) is required.
echo Detected Architecture: %PROCESSOR_ARCHITECTURE%
echo Press any key to exit...
pause > nul
exit /b 1

:ArchOk
:: 2. Check PowerShell availability
where powershell.exe > nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] powershell.exe was not found in System PATH.
    pause
    exit /b 1
)

echo BAT_BOOT = PASS

:: 3. PowerShell 5.1 Parser Preflight
set SCRIPT_PATH=%~dp0RUN_WINDOWS_PHYSICAL_GATE.ps1
set MODULE_PATH=%~dp0modules\TestHarness.psm1

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$errs = $null; [System.Management.Automation.Language.Parser]::ParseFile('%SCRIPT_PATH%', [ref]$null, [ref]$errs); if ($errs.Count -gt 0) { Write-Host 'POWERSHELL_PARSE = FAIL' -ForegroundColor Red; foreach ($e in $errs) { Write-Host ('  ' + $e.Message) -ForegroundColor Red }; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] PowerShell 5.1 Parser Preflight Failed for RUN_WINDOWS_PHYSICAL_GATE.ps1
    echo POWERSHELL_PARSE = FAIL
    if "%CI%"=="" pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
  "$errs = $null; [System.Management.Automation.Language.Parser]::ParseFile('%MODULE_PATH%', [ref]$null, [ref]$errs); if ($errs.Count -gt 0) { Write-Host 'POWERSHELL_PARSE = FAIL' -ForegroundColor Red; foreach ($e in $errs) { Write-Host ('  ' + $e.Message) -ForegroundColor Red }; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] PowerShell 5.1 Parser Preflight Failed for TestHarness.psm1
    echo POWERSHELL_PARSE = FAIL
    if "%CI%"=="" pause
    exit /b 1
)

:: 4. Launch PowerShell Harness
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%SCRIPT_PATH%" %*

set HARNESS_EXIT=%ERRORLEVEL%
echo.
echo ================================================================================
echo Process finished with exit code: %HARNESS_EXIT%
echo Results directory: %~dp0windows_physical_test\
echo ================================================================================
echo.
if "%CI%"=="" (
    echo Press any key to close this window...
    pause > nul
)
exit /b %HARNESS_EXIT%
