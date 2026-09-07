@echo off
title VibeCode Studio - Windows Installer v2.3.9
chcp 65001 >nul 2>nul
cd /d "%~dp0"

echo ========================================================
echo       VIBECODE STUDIO - CÀI ĐẶT TỰ ĐỘNG CHO WINDOWS
echo       Phiên bản: v2.3.9 (64-bit Production)
echo ========================================================
echo.

set "TARGET_DIR=%LOCALAPPDATA%\Programs\VibeCode"
if defined ProgramFiles(x86) (
    echo [INFO] Phát hiện hệ điều hành Windows 64-bit.
)

echo [*] Thư mục cài đặt: %TARGET_DIR%
if not exist "%TARGET_DIR%" mkdir "%TARGET_DIR%"

echo [*] Đang sao chép các tệp tin ứng dụng...
xcopy /E /I /Y /Q "..\app.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\camera_engine.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\diagnostic_collector.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\ffmpeg_utils.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\forced_alignment_engine.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\generate_voice.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\generate_warm_voice.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\license_manager.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\renderer_e_engine.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\renderer_g.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\subtitles_engine.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\subpixel_affine_engine.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\translation_utils.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\tts_utils.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\version.py" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\requirements.txt" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\start_windows.bat" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\start_windows.ps1" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\SlideshowStudio.vbs" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\run.bat" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\README.md" "%TARGET_DIR%\" >nul
xcopy /E /I /Y /Q "..\CHANGELOG.md" "%TARGET_DIR%\" >nul

if exist "..\templates" xcopy /E /I /Y /Q "..\templates" "%TARGET_DIR%\templates" >nul
if exist "..\static" xcopy /E /I /Y /Q "..\static" "%TARGET_DIR%\static" >nul
if exist "..\platform" xcopy /E /I /Y /Q "..\platform" "%TARGET_DIR%\platform" >nul
if exist "..\updater" xcopy /E /I /Y /Q "..\updater" "%TARGET_DIR%\updater" >nul
if exist "..\docs" xcopy /E /I /Y /Q "..\docs" "%TARGET_DIR%\docs" >nul

echo [*] Tạo các thư mục lưu trữ dữ liệu người dùng an toàn...
if not exist "%TARGET_DIR%\projects" mkdir "%TARGET_DIR%\projects"
if not exist "%TARGET_DIR%\outputs" mkdir "%TARGET_DIR%\outputs"
if not exist "%TARGET_DIR%\uploads" mkdir "%TARGET_DIR%\uploads"
if not exist "%TARGET_DIR%\diagnostics" mkdir "%TARGET_DIR%\diagnostics"

echo [*] Tạo shortcut trên Desktop và Start Menu...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut([System.Environment]::GetFolderPath('Desktop') + '\VibeCode Studio.lnk'); $s.TargetPath = '%TARGET_DIR%\SlideshowStudio.vbs'; $s.WorkingDirectory = '%TARGET_DIR%'; $s.Description = 'VibeCode Slideshow Studio AI v2.3.9'; $s.Save()" >nul 2>nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $sm = [System.Environment]::GetFolderPath('StartMenu') + '\Programs'; $s = $ws.CreateShortcut($sm + '\VibeCode Studio.lnk'); $s.TargetPath = '%TARGET_DIR%\SlideshowStudio.vbs'; $s.WorkingDirectory = '%TARGET_DIR%'; $s.Description = 'VibeCode Slideshow Studio AI v2.3.9'; $s.Save()" >nul 2>nul

echo [*] Tạo tệp gỡ cài đặt (Uninstaller)...
copy /Y "uninstall_windows.bat" "%TARGET_DIR%\uninstall.bat" >nul 2>nul

echo.
echo ========================================================
echo       ✅ CÀI ĐẶT VIBECODE STUDIO THÀNH CÔNG!
echo ========================================================
echo Bạn có thể khởi động ứng dụng từ biểu tượng trên Desktop!
echo.
pause
