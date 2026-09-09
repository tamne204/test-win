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

:: 3. Launch PowerShell Harness
set SCRIPT_PATH=%~dp0RUN_WINDOWS_PHYSICAL_GATE.ps1

powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%SCRIPT_PATH%" %*

set HARNESS_EXIT=%ERRORLEVEL%
echo.
echo ================================================================================
echo Process finished with exit code: %HARNESS_EXIT%
echo Results directory: %~dp0windows_physical_test\
echo ================================================================================
echo.
echo Press any key to close this window...
pause > nul
exit /b %HARNESS_EXIT%
