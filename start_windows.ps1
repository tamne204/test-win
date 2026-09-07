# Slideshow Builder AI Launcher for Windows PowerShell
Set-Location -Path $PSScriptRoot
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "       SLIDESHOW BUILDER AI - 2TAMNE.SITE" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

$py = Get-Command python -ErrorAction SilentlyContinue
if (-not $py) {
    $py = Get-Command py -ErrorAction SilentlyContinue
}

if (-not $py) {
    Write-Host "[ERROR] Python was not found on your system!" -ForegroundColor Red
    Write-Host "Please install Python 3.10+ and check 'Add Python to PATH'." -ForegroundColor Yellow
    Read-Host "Press Enter to exit..."
    exit 1
}

Write-Host "[OK] Found Python: $($py.Source)" -ForegroundColor Green
Write-Host "[*] Checking and installing dependencies..." -ForegroundColor Yellow
& $py.Source -m pip install -r requirements.txt

Start-Process "http://localhost:8080"
Write-Host "`n[OK] Server is running at http://localhost:8080. Keep this window open!" -ForegroundColor Green
& $py.Source app.py

Read-Host "Press Enter to exit..."
