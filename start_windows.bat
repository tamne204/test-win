@echo off
chcp 65001 >nul
title Slideshow Builder AI - 2tamne.site
color 0b

echo ========================================================
echo        🎬 SLIDESHOW BUILDER AI - 2TAMNE.SITE
echo        Tự Động Hóa Video Khớp Nhạc & Phụ Đề AI
echo ========================================================
echo.

:: 1. Kiểm tra Python
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [LOI] Khong tim thay Python tren may!
    echo Vui long cai dat Python 3.10 tro len tu python.org va tich vao 'Add Python to PATH'.
    pause
    exit /b
)

:: 2. Kiem tra thu vien can thiet
if not exist ".venv" (
    echo [*] Dang khoi tao moi truong ao Virtualenv (.venv)...
    python -m venv .venv
    call .venv\Scriptsctivate
    echo [*] Dang cai dat cac thu vien can thiet...
    pip install -r requirements.txt
) else (
    call .venv\Scriptsctivate
)

:: 3. Kiem tra FFmpeg
if exist "binfmpeg.exe" (
    set "PATH=%CD%in;%PATH%"
    echo [OK] Da tim thay FFmpeg cuc bo trong thu muc bin.
)

:: 4. Mo trinh duyet tu dong sau 2 giay
start "" http://localhost:8080

:: 5. Chay Flask Server
echo.
echo [*] Dang khoi chay may chu Slideshow Builder tai http://localhost:8080 ...
echo [TIP] Hay giu nguyen cua so nay khi dang lam viec voi tool.
echo.
python app.py

pause
