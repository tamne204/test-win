@echo off
title Slideshow Builder AI v2.3.9 - 2tamne.site
cd /d "%~dp0"

set "PYTHONUTF8=1"
set "PYTHONIOENCODING=utf-8"
set "HF_HUB_DISABLE_SYMLINKS_WARNING=1"
chcp 65001 >nul 2>nul

echo ========================================================
echo        SLIDESHOW BUILDER AI v2.3.9 - 2TAMNE.SITE (x64)
echo ========================================================
echo.

set PYCMD=

where python >nul 2>nul
if %errorlevel% equ 0 (
    set PYCMD=python
    goto :run_server
)

where py >nul 2>nul
if %errorlevel% equ 0 (
    set PYCMD=py
    goto :run_server
)

if exist "%LOCALAPPDATA%\Programs\Python\Python313\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python313\python.exe"
    goto :run_server
)
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
    goto :run_server
)
if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    goto :run_server
)
if exist "%LOCALAPPDATA%\Programs\Python\Python310\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python310\python.exe"
    goto :run_server
)
if exist "C:\Program Files\Python313\python.exe" (
    set "PYCMD=C:\Program Files\Python313\python.exe"
    goto :run_server
)
if exist "C:\Program Files\Python312\python.exe" (
    set "PYCMD=C:\Program Files\Python312\python.exe"
    goto :run_server
)
if exist "C:\Program Files\Python311\python.exe" (
    set "PYCMD=C:\Program Files\Python311\python.exe"
    goto :run_server
)
if exist "C:\Program Files\Python310\python.exe" (
    set "PYCMD=C:\Program Files\Python310\python.exe"
    goto :run_server
)
if exist "C:\Python313\python.exe" (
    set "PYCMD=C:\Python313\python.exe"
    goto :run_server
)
if exist "C:\Python312\python.exe" (
    set "PYCMD=C:\Python312\python.exe"
    goto :run_server
)
if exist "C:\Python311\python.exe" (
    set "PYCMD=C:\Python311\python.exe"
    goto :run_server
)
if exist "C:\Python310\python.exe" (
    set "PYCMD=C:\Python310\python.exe"
    goto :run_server
)

echo [ERROR] Python was not found on your computer!
echo Please install Python from https://www.python.org/
echo Make sure to check 'Add Python to PATH' during installation.
echo.
pause
exit /b 1

:run_server
echo [OK] Found Python: %PYCMD%
"%PYCMD%" -c "import struct; assert struct.calcsize('P') * 8 == 64" >nul 2>nul
if %errorlevel% equ 0 (
    echo [OK] Architecture: 64-bit (High Performance Mode)
) else (
    echo [WARN] Architecture: 32-bit (Limited to 2GB RAM. Recommend installing Python 64-bit)
)
echo [*] Checking dependencies...
"%PYCMD%" -c "import flask, faster_whisper, requests, PIL, psutil, cv2, numpy" >nul 2>nul
if %errorlevel% neq 0 (
    echo [*] Installing missing requirements (one-time setup)...
    "%PYCMD%" -m pip install -r requirements.txt
) else (
    echo [OK] All dependencies verified!
)
echo.
echo [*] Launching browser at http://localhost:8080 in background...
start /b cmd /c "timeout /t 2 /nobreak >nul & start http://localhost:8080"
:loop_server
echo ========================================================
echo  SERVER IS RUNNING - PLEASE KEEP THIS WINDOW OPEN!
echo ========================================================
echo.
"%PYCMD%" app.py
set APP_EXIT_CODE=%errorlevel%

if %APP_EXIT_CODE% equ 10 (
    echo.
    echo [*] Restarting server...
    timeout /t 1 /nobreak >nul
    goto :loop_server
)

echo.
echo [INFO] Server stopped (Exit code: %APP_EXIT_CODE%).
pause
