@echo off
title VibeCode Studio - Windows Uninstaller
chcp 65001 >nul 2>nul
cd /d "%~dp0"

echo ========================================================
echo       VIBECODE STUDIO - GỠ CÀI ĐẶT ỨNG DỤNG
echo ========================================================
echo.

set "TARGET_DIR=%LOCALAPPDATA%\Programs\VibeCode"
if not exist "%TARGET_DIR%" (
    set "TARGET_DIR=%~dp0"
)

echo Bạn có chắc chắn muốn gỡ cài đặt VibeCode Studio?
echo [1] Gỡ cài đặt nhưng GIỮ LẠI Dự Án, File Video Xuất và License Key (Khuyên dùng)
echo [2] Xóa hoàn toàn toàn bộ dữ liệu
echo [3] Hủy bỏ
echo.
set /p choice="Nhập lựa chọn của bạn (1/2/3): "

if "%choice%"=="3" goto :cancel
if "%choice%"=="2" goto :full_uninstall
if "%choice%"=="1" goto :clean_uninstall
goto :cancel

:clean_uninstall
echo.
echo [*] Đang gỡ bỏ các shortcut...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item -Force -ErrorAction SilentlyContinue ([System.Environment]::GetFolderPath('Desktop') + '\VibeCode Studio.lnk')" >nul 2>nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item -Force -ErrorAction SilentlyContinue ([System.Environment]::GetFolderPath('StartMenu') + '\Programs\VibeCode Studio.lnk')" >nul 2>nul

echo [*] Đang xóa các tệp tin hệ thống và giữ lại thư mục projects/outputs/license...
for %%F in (app.py camera_engine.py diagnostic_collector.py ffmpeg_utils.py forced_alignment_engine.py generate_voice.py generate_warm_voice.py license_manager.py renderer_e_engine.py renderer_g.py subtitles_engine.py translation_utils.py tts_utils.py version.py requirements.txt start_windows.bat start_windows.ps1 SlideshowStudio.vbs run.bat README.md CHANGELOG.md) do (
    if exist "%TARGET_DIR%\%%F" del /f /q "%TARGET_DIR%\%%F" >nul 2>nul
)
if exist "%TARGET_DIR%\templates" rmdir /s /q "%TARGET_DIR%\templates" >nul 2>nul
if exist "%TARGET_DIR%\static" rmdir /s /q "%TARGET_DIR%\static" >nul 2>nul
if exist "%TARGET_DIR%\platform" rmdir /s /q "%TARGET_DIR%\platform" >nul 2>nul
if exist "%TARGET_DIR%\updater" rmdir /s /q "%TARGET_DIR%\updater" >nul 2>nul
if exist "%TARGET_DIR%\docs" rmdir /s /q "%TARGET_DIR%\docs" >nul 2>nul

echo.
echo ========================================================
echo       ✅ ĐÃ GỠ CÀI ĐẶT VIBECODE STUDIO THÀNH CÔNG!
echo       (Dự án, video và bản quyền của bạn đã được bảo toàn)
echo ========================================================
pause
exit /b 0

:full_uninstall
echo.
echo [*] Đang gỡ bỏ toàn bộ tệp tin và dữ liệu...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item -Force -ErrorAction SilentlyContinue ([System.Environment]::GetFolderPath('Desktop') + '\VibeCode Studio.lnk')" >nul 2>nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item -Force -ErrorAction SilentlyContinue ([System.Environment]::GetFolderPath('StartMenu') + '\Programs\VibeCode Studio.lnk')" >nul 2>nul
cd ..
rmdir /s /q "%TARGET_DIR%" >nul 2>nul
echo.
echo ========================================================
echo       ✅ ĐÃ XÓA TOÀN BỘ VIBECODE STUDIO KHỎI MÁY!
echo ========================================================
pause
exit /b 0

:cancel
echo.
echo [INFO] Đã hủy thao tác gỡ cài đặt.
pause
exit /b 0
