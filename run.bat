@echo off
title Slideshow Builder AI v2.3.9 - 2tamne.site
cd /d "%~dp0"

echo ========================================================
echo        SLIDESHOW BUILDER AI v2.3.9 - 2TAMNE.SITE
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
echo [*] Checking dependencies...
"%PYCMD%" -m pip install -r requirements.txt
echo.
echo [*] Starting web server at http://localhost:8080 ...
start "" http://localhost:8080
echo.
echo ========================================================
echo  SERVER IS RUNNING - PLEASE KEEP THIS WINDOW OPEN!
echo ========================================================
echo.
"%PYCMD%" app.py
echo.
echo [INFO] Server stopped.
pause
