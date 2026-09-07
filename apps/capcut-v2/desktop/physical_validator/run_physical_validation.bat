@echo off
title 2TOOLNE AutoEdit — Windows Physical Release Acceptance Harness
cd /d "%~dp0"
set "PYTHONUTF8=1"
set "PYTHONIOENCODING=utf-8"
chcp 65001 >nul 2>nul

echo ====================================================================
echo    2TOOLNE AUTOEDIT FOR CAPCUT V2 — PHYSICAL LAB ACCEPTANCE
echo ====================================================================
echo.
echo Target Environment : Windows 11 Build 26200 / CapCut 9.3.0.3970
echo Objective          : Run Gates A, B, C, D and collect physical evidence
echo.

set PYCMD=
where python >nul 2>nul
if %errorlevel% equ 0 (
    set PYCMD=python
    goto :run_test
)
where py >nul 2>nul
if %errorlevel% equ 0 (
    set PYCMD=py
    goto :run_test
)
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
    goto :run_test
)
if exist "%LOCALAPPDATA%\Programs\Python\Python311\python.exe" (
    set "PYCMD=%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    goto :run_test
)
if exist "..\resources\python\python.exe" (
    set "PYCMD=..\resources\python\python.exe"
    goto :run_test
)

echo [ERROR] Python 3.10+ was not found on this computer.
echo Please ensure Python is installed or run with packaged AutoEdit runtime.
pause
exit /b 1

:run_test
echo Using Python: %PYCMD%
echo Running automated acceptance test harness...
echo.

"%PYCMD%" validate_physical_release.py "2TOOLNE_AUTOEDIT_CAPCUT_V2_FINAL_WINDOWS_PHYSICAL_ACCEPTANCE.md" "physical_evidence_report.zip"

echo.
echo ====================================================================
echo    PHYSICAL ACCEPTANCE HARNESS COMPLETED
echo ====================================================================
echo Report generated at  : 2TOOLNE_AUTOEDIT_CAPCUT_V2_FINAL_WINDOWS_PHYSICAL_ACCEPTANCE.md
echo Evidence bundle at   : physical_evidence_report.zip
echo.
echo Please transfer 'physical_evidence_report.zip' to the AutoEdit repository.
pause
