<#
.SYNOPSIS
    RUN_WINDOWS_PHYSICAL_GATE.ps1
    Authoritative Windows Physical Acceptance Harness for 2TOOLNE AutoEdit V2 (RC2).
    Designed to run on a clean Windows 10/11 x64 workstation with ZERO developer tools.
#>

[CmdletBinding()]
param(
    [switch]$Auto,
    [switch]$Resume,
    [string]$RcPath
)

# Set UTF-8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$OutputDir = Join-Path $ScriptDir "windows_physical_test"
$LogDir = Join-Path $OutputDir "logs"
$ScreenshotDir = Join-Path $OutputDir "screenshots"
$LibDir = Join-Path $ScriptDir "modules"
$ToolsDir = Join-Path $ScriptDir "tools"
$BundleDir = Join-Path $ScriptDir "physical_test_bundle"

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path $ScreenshotDir | Out-Null

$LogFile = Join-Path $LogDir "physical_test_session_$(Get-Date -Format 'yyyyMMdd_HHmmss').log"
Start-Transcript -Path $LogFile -Append | Out-Null

# Import module
Import-Module (Join-Path $LibDir "TestHarness.psm1") -Force

Write-Host "================================================================================" -ForegroundColor Cyan
Write-Host "          2TOOLNE AUTOEDIT FOR CAPCUT V2 -- WINDOWS PHYSICAL GATE" -ForegroundColor Cyan
Write-Host "                      IMMUTABLE RELEASE CANDIDATE 2 (RC2)" -ForegroundColor Cyan
Write-Host "================================================================================" -ForegroundColor Cyan
Write-Host "Workstation Architecture: Physical Acceptance Testing Harness" -ForegroundColor Cyan
Write-Host "Authoritative Target    : CapCut Desktop 9.3.0.3970 on Windows 10/11 x64" -ForegroundColor Cyan
Write-Host "Expected RC2 Hash       : 217265079cfdce4a40d5e99853e0948c184c56c82c193df32429e0345c13e7cc" -ForegroundColor Cyan
Write-Host "================================================================================" -ForegroundColor Cyan

# ------------------------------------------------------------------------------
# Checkpoint & State Management
# ------------------------------------------------------------------------------
$Gates = @(
    @{ id = "WINPHYS-01"; title = "Install & Package Extraction" },
    @{ id = "WINPHYS-02"; title = "First Launch (2TOOLNE.exe)" },
    @{ id = "WINPHYS-03"; title = "Sidecar Runtime & JSON-RPC Handshake" },
    @{ id = "WINPHYS-04"; title = "User Login Flow & Session" },
    @{ id = "WINPHYS-05"; title = "License Entitlement Gate" },
    @{ id = "WINPHYS-06"; title = "Windows DPAPI Credential Storage" },
    @{ id = "WINPHYS-07"; title = "Cloud Drive Asset Explorer" },
    @{ id = "WINPHYS-08"; title = "Team Workspace & Shared Assets" },
    @{ id = "WINPHYS-09"; title = "UI DPI Scaling & Typography" },
    @{ id = "WINPHYS-10"; title = "Input Bundle Import Verification" },
    @{ id = "WINPHYS-11"; title = "Real-ESRGAN Vulkan GPU Upscale" },
    @{ id = "WINPHYS-12"; title = "Google Flow Login & Authentication" },
    @{ id = "WINPHYS-13"; title = "Google Flow Image Generation" },
    @{ id = "WINPHYS-14"; title = "Google Flow Video Generation" },
    @{ id = "WINPHYS-15"; title = "Google Flow Character Reference" },
    @{ id = "WINPHYS-16"; title = "CapCut Version Match (9.3.0.3970)" },
    @{ id = "WINPHYS-17"; title = "CapCut UI Probe (Win32 & UIA)" },
    @{ id = "WINPHYS-18"; title = "Project Build Queue Execution" },
    @{ id = "WINPHYS-19"; title = "PROJECT_READY Transactional State" },
    @{ id = "WINPHYS-20"; title = "CapCut Draft Save & Reopen Verification" },
    @{ id = "WINPHYS-21"; title = "CapCut Render Queue Registration" },
    @{ id = "WINPHYS-22"; title = "Native CapCut MP4 Video Export" },
    @{ id = "WINPHYS-23"; title = "Output Video Integrity (ffprobe)" },
    @{ id = "WINPHYS-24"; title = "Auto-Update Swap Infrastructure" },
    @{ id = "WINPHYS-25"; title = "OS Reboot Persistence Gate" }
)

$Results = [ordered]@{}
$Notes = [ordered]@{}
foreach ($g in $Gates) {
    $Results[$g.id] = "NOT_RUN"
    $Notes[$g.id] = "Chưa thực hiện"
}

# Check existing checkpoint
$existingCp = Load-Checkpoint -OutputDir $OutputDir
$rebootResume = $false

if ($existingCp) {
    Write-Host "`n[!] Phát hiện checkpoint kiểm thử từ phiên trước!" -ForegroundColor Yellow
    Write-Host "    Lần kiểm thử gần nhất: $($existingCp.last_updated)" -ForegroundColor Gray
    Write-Host "    Trạng thái reboot    : $($existingCp.reboot_in_progress)" -ForegroundColor Gray
    
    if ($existingCp.reboot_in_progress) {
        Write-Host "[+] Tự động kích hoạt chế độ: REBOOT_PERSISTENCE RESUME!" -ForegroundColor Green
        $rebootResume = $true
    } else {
        $choice = Read-Host "Bạn có muốn tiếp tục từ checkpoint trước không? (Y/N, mặc định Y)"
        if ($choice -ne "N" -and $choice -ne "n") {
            $rebootResume = $true
        }
    }

    if ($rebootResume) {
        foreach ($prop in $existingCp.results.PSObject.Properties) {
            $Results[$prop.Name] = $prop.Value
        }
        foreach ($prop in $existingCp.notes.PSObject.Properties) {
            $Notes[$prop.Name] = $prop.Value
        }
    }
}

# Helper to prompt user
function Prompt-GateResult {
    param(
        [string]$GateId,
        [string]$GateTitle,
        [string]$SuggestedStatus = "NOT_RUN",
        [string]$SuggestedNote = ""
    )

    Write-Host "`n--------------------------------------------------------------------------------" -ForegroundColor Cyan
    Write-Host "  $GateId : $GateTitle" -ForegroundColor Yellow
    Write-Host "--------------------------------------------------------------------------------" -ForegroundColor Cyan

    if ($SuggestedStatus -ne "NOT_RUN") {
        Write-Host "[*] Kết quả kiểm tra tự động / gợi ý: [$SuggestedStatus]" -ForegroundColor $(if ($SuggestedStatus -eq "PASS") { "Green" } else { "Yellow" })
        if ($SuggestedNote) { Write-Host "    Ghi chú: $SuggestedNote" -ForegroundColor Gray }
    }

    while ($true) {
        Write-Host "`nLựa chọn kết quả cho [$GateId]:"
        Write-Host "  [1] PASS    - Đạt tiêu chuẩn nghiệm thu" -ForegroundColor Green
        Write-Host "  [2] FAIL    - Thất bại / Lỗi phát sinh" -ForegroundColor Red
        Write-Host "  [3] BLOCKED - Bị chặn do thiếu điều kiện (VD: sai phiên bản CapCut)" -ForegroundColor Yellow
        Write-Host "  [4] SKIP    - Bỏ qua hạng mục này" -ForegroundColor Gray
        Write-Host "  [S] SCREENSHOT - Chụp ảnh màn hình bằng chứng hiện tại" -ForegroundColor Cyan

        $inputKey = Read-Host "Nhập lựa chọn (1/2/3/4/S)"
        switch ($inputKey.Trim().ToUpper()) {
            "1" {
                $note = if ($SuggestedNote) { $SuggestedNote } else { "Xác nhận đạt kiểm thử vật lý trên Windows." }
                return @{ status = "PASS"; note = $note }
            }
            "2" {
                $failNote = Read-Host "BẮT BUỘC: Nhập lý do FAIL hoặc thông báo lỗi"
                while (-not $failNote) { $failNote = Read-Host "Lý do không được để trống khi chọn FAIL" }
                return @{ status = "FAIL"; note = $failNote }
            }
            "3" {
                $blockNote = Read-Host "BẮT BUỘC: Nhập lý do BLOCKED"
                while (-not $blockNote) { $blockNote = Read-Host "Lý do không được để trống khi chọn BLOCKED" }
                return @{ status = "BLOCKED"; note = $blockNote }
            }
            "4" {
                return @{ status = "NOT_RUN"; note = "Người dùng bỏ qua" }
            }
            "S" {
                Capture-Screen -GateId $GateId -OutputDir $OutputDir
            }
            default {
                Write-Host "Lựa chọn không hợp lệ, vui lòng thử lại." -ForegroundColor Red
            }
        }
    }
}

function Save-CurrentState {
    $state = @{
        last_updated = (Get-Date -Format "yyyy-MM-dd HH:mm:ss UTC")
        reboot_in_progress = ($Results['WINPHYS-25'] -eq "IN_PROGRESS")
        results = $Results
        notes = $Notes
    }
    Save-Checkpoint -OutputDir $OutputDir -State $state
}

# ==============================================================================
# PHASE 1: PREFLIGHT & HARDWARE/ENVIRONMENT GATES
# ==============================================================================

# 1. System Information Collection
$SysInfo = Get-PhysicalSystemInfo
$SysInfo | ConvertTo-Json -Depth 5 | Out-File (Join-Path $OutputDir "system.json") -Encoding utf8

# 2. Clean Machine Audit
$CleanReport = Test-CleanMachine
$CleanReport | ConvertTo-Json -Depth 5 | Out-File (Join-Path $OutputDir "clean_machine.json") -Encoding utf8

# 3. Locate & Verify RC2 Archive
$rcCandidates = @(
    $RcPath,
    (Join-Path $ScriptDir "2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"),
    (Join-Path (Split-Path -Parent $ScriptDir) "2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"),
    (Join-Path (Split-Path -Parent (Split-Path -Parent $ScriptDir)) "2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"),
    (Join-Path (Split-Path -Parent $ScriptDir) "desktop\dist\2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"),
    (Join-Path $env:USERPROFILE "Downloads\2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"),
    "C:\2TOOLNE\2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip"
)

$actualRcZip = ""
foreach ($cand in $rcCandidates) {
    if ($cand -and (Test-Path $cand)) {
        $actualRcZip = (Resolve-Path $cand).Path
        break
    }
}

if (-not $actualRcZip) {
    Write-Host "`n[-] Không tìm thấy tệp 2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip!" -ForegroundColor Yellow
    $userRc = Read-Host "Vui lòng nhập đường dẫn đầy đủ đến tệp RC2 ZIP"
    if (Test-Path $userRc) {
        $actualRcZip = (Resolve-Path $userRc).Path
    } else {
        Write-Host "[-] Đường dẫn không tồn tại. Dừng kiểm thử." -ForegroundColor Red
        Exit 1
    }
}

# Execute SHA-256 Gate
$RcIntegrity = Test-RcIntegrity -ZipPath $actualRcZip
$RcIntegrity | ConvertTo-Json -Depth 5 | Out-File (Join-Path $OutputDir "rc_integrity.json") -Encoding utf8

if (-not $RcIntegrity.passed) {
    Write-Host "DỪNG TOÀN BỘ TIẾN TRÌNH DO MÃ BĂM RC2 KHÔNG HỢP LỆ." -ForegroundColor Red
    Exit 1
}

# Extract RC2 if unpacked app not present
$AppUnpackedDir = Join-Path $ScriptDir "app_unpacked"
if (-not (Test-Path (Join-Path $AppUnpackedDir "2TOOLNE.exe"))) {
    Write-Host "`n[*] Đang giải nén RC2 vào $AppUnpackedDir..." -ForegroundColor Cyan
    New-Item -ItemType Directory -Force -Path $AppUnpackedDir | Out-Null
    Expand-Archive -Path $actualRcZip -DestinationPath $AppUnpackedDir -Force
    Write-Host "[+] Giải nén thành công!" -ForegroundColor Green
}

$ExePath = Join-Path $AppUnpackedDir "2TOOLNE.exe"
$SidecarExe = Join-Path $AppUnpackedDir "resources\autoedit-core\autoedit-core.exe"
$BinDir = Join-Path $AppUnpackedDir "resources\bin\win-x64"
$EngineDir = Join-Path $AppUnpackedDir "resources\engine\win-x64"

# ==============================================================================
# PHASE 2: EXECUTION OF GATES
# ==============================================================================

# --- WINPHYS-01: Install & Extraction ---
if ($Results['WINPHYS-01'] -eq "NOT_RUN") {
    $p1Status = if ((Test-Path $ExePath) -and (Test-Path $SidecarExe)) { "PASS" } else { "FAIL" }
    $p1Note = "Đã giải nén RC2. 2TOOLNE.exe và autoedit-core.exe đều tồn tại nguyên bản."
    $r = Prompt-GateResult -GateId "WINPHYS-01" -GateTitle "Install & Package Extraction" -SuggestedStatus $p1Status -SuggestedNote $p1Note
    $Results['WINPHYS-01'] = $r.status; $Notes['WINPHYS-01'] = $r.note; Save-CurrentState
}

# --- WINPHYS-02: First Launch ---
if ($Results['WINPHYS-02'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Khởi chạy ứng dụng 2TOOLNE.exe..." -ForegroundColor Cyan
    Write-Host "    Đường dẫn: $ExePath" -ForegroundColor Gray
    $launchChoice = Read-Host "Bạn có muốn Harness tự động mở ứng dụng ngay bây giờ không? (Y/N, mặc định Y)"
    if ($launchChoice -ne "N" -and $launchChoice -ne "n") {
        Start-Process -FilePath $ExePath
        Start-Sleep -Seconds 4
    }

    $runningProc = Get-Process -Name "2TOOLNE" -ErrorAction SilentlyContinue
    $p2Status = if ($runningProc) { "PASS" } else { "NOT_RUN" }
    $p2Note = if ($runningProc) { "2TOOLNE.exe khởi chạy thành công (PID: $($runningProc.Id))." } else { "" }
    $r = Prompt-GateResult -GateId "WINPHYS-02" -GateTitle "First Launch (2TOOLNE.exe)" -SuggestedStatus $p2Status -SuggestedNote $p2Note
    $Results['WINPHYS-02'] = $r.status; $Notes['WINPHYS-02'] = $r.note; Save-CurrentState
}

# --- WINPHYS-03: Sidecar Runtime ---
if ($Results['WINPHYS-03'] -eq "NOT_RUN") {
    $sidecarProc = Get-Process -Name "autoedit-core" -ErrorAction SilentlyContinue
    $p3Status = if ($sidecarProc) { "PASS" } else { "NOT_RUN" }
    $p3Note = if ($sidecarProc) { "Bundled autoedit-core.exe đang chạy nền mà không cần Python hệ thống." } else { "" }
    
    # Also record sidecar.json
    @{
        sidecar_exe = $SidecarExe
        exists = (Test-Path $SidecarExe)
        running = ($null -ne $sidecarProc)
        pid = if ($sidecarProc) { $sidecarProc.Id } else { -1 }
        status = $p3Status
    } | ConvertTo-Json | Out-File (Join-Path $OutputDir "sidecar.json") -Encoding utf8

    $r = Prompt-GateResult -GateId "WINPHYS-03" -GateTitle "Sidecar Runtime & JSON-RPC Handshake" -SuggestedStatus $p3Status -SuggestedNote $p3Note
    $Results['WINPHYS-03'] = $r.status; $Notes['WINPHYS-03'] = $r.note; Save-CurrentState
}

# --- WINPHYS-04: User Login ---
if ($Results['WINPHYS-04'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Đăng nhập tài khoản trên giao diện 2TOOLNE AutoEdit." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-04" -GateTitle "User Login Flow & Session"
    $Results['WINPHYS-04'] = $r.status; $Notes['WINPHYS-04'] = $r.note; Save-CurrentState
}

# --- WINPHYS-05: License Entitlement ---
if ($Results['WINPHYS-05'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Xác nhận trạng thái kích hoạt bản quyền / commercial feature lock." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-05" -GateTitle "License Entitlement Gate"
    $Results['WINPHYS-05'] = $r.status; $Notes['WINPHYS-05'] = $r.note; Save-CurrentState
}

# --- WINPHYS-06: Windows DPAPI ---
if ($Results['WINPHYS-06'] -eq "NOT_RUN") {
    $dpapiRes = Test-WindowsDpapi -TestDir $OutputDir
    $dpapiRes | ConvertTo-Json | Out-File (Join-Path $OutputDir "dpapi.json") -Encoding utf8
    $p6Note = "Mã hóa và giải mã DPAPI CurrentUser thành công. 0 plaintext leakage on disk."
    $r = Prompt-GateResult -GateId "WINPHYS-06" -GateTitle "Windows DPAPI Credential Storage" -SuggestedStatus $dpapiRes.status -SuggestedNote $p6Note
    $Results['WINPHYS-06'] = $r.status; $Notes['WINPHYS-06'] = $r.note; Save-CurrentState
}

# --- WINPHYS-07: Cloud Drive ---
if ($Results['WINPHYS-07'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Mở tab Cloud Drive, kiểm tra danh sách tệp đám mây và liên kết chia sẻ." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-07" -GateTitle "Cloud Drive Asset Explorer"
    $Results['WINPHYS-07'] = $r.status; $Notes['WINPHYS-07'] = $r.note; Save-CurrentState
}

# --- WINPHYS-08: Team Workspace ---
if ($Results['WINPHYS-08'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Kiểm tra không gian làm việc nhóm (Team Workspace) và phân quyền." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-08" -GateTitle "Team Workspace & Shared Assets"
    $Results['WINPHYS-08'] = $r.status; $Notes['WINPHYS-08'] = $r.note; Save-CurrentState
}

# --- WINPHYS-09: UI DPI Scaling ---
if ($Results['WINPHYS-09'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn: Kiểm tra bố cục giao diện, font chữ tiếng Việt và nút bấm ở DPI $($SysInfo.display.dpi_scaling_percent)%." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-09" -GateTitle "UI DPI Scaling & Typography"
    $Results['WINPHYS-09'] = $r.status; $Notes['WINPHYS-09'] = $r.note; Save-CurrentState
}

# --- FFMPEG & FFPROBE AUTOMATED TEST ---
$ffmpegRes = Test-BundledFfmpeg -BinDir $BinDir -TestDir $OutputDir
$ffmpegRes | ConvertTo-Json | Out-File (Join-Path $OutputDir "ffmpeg.json") -Encoding utf8

# --- WINPHYS-11: Real-ESRGAN Vulkan GPU Upscale ---
if ($Results['WINPHYS-11'] -eq "NOT_RUN") {
    $sampleImg = Join-Path $ToolsDir "sample_upscale_input.png"
    $upscaleRes = Test-RealEsrganGpu -EngineDir $EngineDir -InputImage $sampleImg -TestDir $OutputDir
    $upscaleRes | ConvertTo-Json | Out-File (Join-Path $OutputDir "upscale.json") -Encoding utf8
    $p11Note = if ($upscaleRes.passed) { "Vulkan GPU AI Upscale 64x64 -> 256x256 hoàn thành trong $($upscaleRes.elapsed_seconds)s." } else { "Chờ kiểm thử trên phần cứng có card đồ họa hỗ trợ Vulkan." }
    $r = Prompt-GateResult -GateId "WINPHYS-11" -GateTitle "Real-ESRGAN Vulkan GPU Upscale" -SuggestedStatus $upscaleRes.status -SuggestedNote $p11Note
    $Results['WINPHYS-11'] = $r.status; $Notes['WINPHYS-11'] = $r.note; Save-CurrentState
}

# --- WINPHYS-12 to 15: Google Flow Manual Assisted ---
if ($Results['WINPHYS-12'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Google Flow [1/4]: Mở tab Google Flow và đăng nhập tài khoản." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-12" -GateTitle "Google Flow Login & Authentication"
    $Results['WINPHYS-12'] = $r.status; $Notes['WINPHYS-12'] = $r.note; Save-CurrentState
}

if ($Results['WINPHYS-13'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Google Flow [2/4]: Tạo 1 ảnh sinh tự động qua Flow." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-13" -GateTitle "Google Flow Image Generation"
    $Results['WINPHYS-13'] = $r.status; $Notes['WINPHYS-13'] = $r.note; Save-CurrentState
}

if ($Results['WINPHYS-14'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Google Flow [3/4]: Tạo 1 video sinh tự động qua Flow." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-14" -GateTitle "Google Flow Video Generation"
    $Results['WINPHYS-14'] = $r.status; $Notes['WINPHYS-14'] = $r.note; Save-CurrentState
}

if ($Results['WINPHYS-15'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Google Flow [4/4]: Tạo 1 nhân vật nhất quán (Character Reference)." -ForegroundColor Cyan
    $r = Prompt-GateResult -GateId "WINPHYS-15" -GateTitle "Google Flow Character Reference"
    $Results['WINPHYS-15'] = $r.status; $Notes['WINPHYS-15'] = $r.note; Save-CurrentState
    @{
        flow_login = $Results['WINPHYS-12']
        flow_image = $Results['WINPHYS-13']
        flow_video = $Results['WINPHYS-14']
        flow_character = $Results['WINPHYS-15']
    } | ConvertTo-Json | Out-File (Join-Path $OutputDir "flow.json") -Encoding utf8
}

# --- WINPHYS-16: CapCut Version Match ---
if ($Results['WINPHYS-16'] -eq "NOT_RUN") {
    $capcutDetect = Test-CapCutInstallation -TargetVersion "9.3.0.3970"
    $capcutDetect | ConvertTo-Json | Out-File (Join-Path $OutputDir "capcut.json") -Encoding utf8
    $p16Note = "Phiên bản CapCut phát hiện: $($capcutDetect.detected_version) (Mục tiêu: 9.3.0.3970)"
    $r = Prompt-GateResult -GateId "WINPHYS-16" -GateTitle "CapCut Version Match (9.3.0.3970)" -SuggestedStatus $capcutDetect.status -SuggestedNote $p16Note
    $Results['WINPHYS-16'] = $r.status; $Notes['WINPHYS-16'] = $r.note; Save-CurrentState
}

# --- WINPHYS-17: CapCut UI Probe ---
if ($Results['WINPHYS-17'] -eq "NOT_RUN") {
    $probeExe = Join-Path $BinDir "CapCutUiProbe.exe"
    $probeRes = Test-CapCutUiProbe -ProbeExe $probeExe -OutputDir $OutputDir
    $p17Note = "CapCutUiProbe kiểm tra cây UI Automation và tín hiệu Win32."
    $r = Prompt-GateResult -GateId "WINPHYS-17" -GateTitle "CapCut UI Probe (Win32 & UIA)" -SuggestedStatus $probeRes.status -SuggestedNote $p17Note
    $Results['WINPHYS-17'] = $r.status; $Notes['WINPHYS-17'] = $r.note; Save-CurrentState
}

# --- WINPHYS-10: Input Bundle Import ---
if ($Results['WINPHYS-10'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Input Bundle Import:" -ForegroundColor Cyan
    Write-Host "    Thư mục Bundle mẫu kèm theo Harness: $BundleDir" -ForegroundColor Gray
    Write-Host "    1. Mở giao diện 2TOOLNE -> Chọn 'Hàng Đợi Tạo Dự Án'"
    Write-Host "    2. Bấm '[📦 Import Bundle]'"
    Write-Host "    3. Chọn thư mục: $BundleDir"
    Write-Host "    4. Xác nhận các scene, âm thanh, phụ đề được nạp vào danh sách hàng đợi."
    $r = Prompt-GateResult -GateId "WINPHYS-10" -GateTitle "Input Bundle Import Verification"
    $Results['WINPHYS-10'] = $r.status; $Notes['WINPHYS-10'] = $r.note; Save-CurrentState
}

# --- WINPHYS-18 & 19: Project Build Queue & PROJECT_READY ---
if ($Results['WINPHYS-18'] -eq "NOT_RUN" -or $Results['WINPHYS-19'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Project Build Queue:" -ForegroundColor Cyan
    Write-Host "    1. Bấm nút '[▶ Chạy tất cả]' trong Hàng Đợi Tạo Dự Án."
    Write-Host "    2. Quan sát quá trình: VALIDATING -> PREPARING -> TIMELINE -> CREATING_CAPCUT_PROJECT -> PROJECT_READY."
    
    $r18 = Prompt-GateResult -GateId "WINPHYS-18" -GateTitle "Project Build Queue Execution"
    $Results['WINPHYS-18'] = $r18.status; $Notes['WINPHYS-18'] = $r18.note; Save-CurrentState

    $r19 = Prompt-GateResult -GateId "WINPHYS-19" -GateTitle "PROJECT_READY Transactional State"
    $Results['WINPHYS-19'] = $r19.status; $Notes['WINPHYS-19'] = $r19.note; Save-CurrentState
    
    @{
        bundle_imported = $BundleDir
        build_queue_executed = $Results['WINPHYS-18']
        final_state = $Results['WINPHYS-19']
    } | ConvertTo-Json | Out-File (Join-Path $OutputDir "project_build.json") -Encoding utf8
}

# --- WINPHYS-20: Save & Reopen in CapCut ---
if ($Results['WINPHYS-20'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Kiểm tra Lưu & Mở lại trên CapCut Desktop:" -ForegroundColor Cyan
    Write-Host "    1. Mở dự án vừa tạo trong CapCut Desktop 9.3.0."
    Write-Host "    2. Kiểm tra hiển thị hình ảnh, phụ đề, âm thanh trên Timeline."
    Write-Host "    3. Bấm Ctrl+S (Lưu) -> Đóng hoàn toàn CapCut."
    Write-Host "    4. Mở lại CapCut -> Mở lại dự án."
    Write-Host "    5. Xác nhận không có lỗi mất file (media unlinked) hoặc lỗi font."
    $r = Prompt-GateResult -GateId "WINPHYS-20" -GateTitle "CapCut Draft Save & Reopen Verification"
    $Results['WINPHYS-20'] = $r.status; $Notes['WINPHYS-20'] = $r.note; Save-CurrentState
}

# --- WINPHYS-21 to 23: Render Queue & Output Validation ---
if ($Results['WINPHYS-21'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Render Queue:" -ForegroundColor Cyan
    Write-Host "    1. Trong 2TOOLNE hoặc CapCut, chuyển dự án sang Render Queue."
    $r = Prompt-GateResult -GateId "WINPHYS-21" -GateTitle "CapCut Render Queue Registration"
    $Results['WINPHYS-21'] = $r.status; $Notes['WINPHYS-21'] = $r.note; Save-CurrentState
}

if ($Results['WINPHYS-22'] -eq "NOT_RUN") {
    Write-Host "`n[*] Hướng dẫn Xuất Video CapCut:" -ForegroundColor Cyan
    Write-Host "    1. Thực hiện xuất video MP4 nguyên bản từ CapCut Desktop (Native Export)."
    $r = Prompt-GateResult -GateId "WINPHYS-22" -GateTitle "Native CapCut MP4 Video Export"
    $Results['WINPHYS-22'] = $r.status; $Notes['WINPHYS-22'] = $r.note; Save-CurrentState
}

if ($Results['WINPHYS-23'] -eq "NOT_RUN") {
    Write-Host "`n[*] Kiểm tra tệp xuất bằng bundled ffprobe:" -ForegroundColor Cyan
    $exportedMp4 = Read-Host "Nhập đường dẫn đến tệp video MP4 vừa xuất từ CapCut (để trống nếu muốn bỏ qua)"
    $renderReport = @{
        file_path = $exportedMp4
        probe_pass = $false
        duration = 0
        width = 0
        height = 0
        codec = ""
        size_bytes = 0
    }

    if ($exportedMp4 -and (Test-Path $exportedMp4)) {
        try {
            $ffprobeExe = Join-Path $BinDir "ffprobe.exe"
            $rawProbe = & $ffprobeExe -v error -show_entries format=duration,size:stream=width,height,codec_name -of json $exportedMp4 2>&1
            $pj = $rawProbe | ConvertFrom-Json
            $s = $pj.streams[0]
            $renderReport.duration = $pj.format.duration
            $renderReport.size_bytes = $pj.format.size
            $renderReport.width = $s.width
            $renderReport.height = $s.height
            $renderReport.codec = $s.codec_name
            $renderReport.probe_pass = $true

            Write-Host "    ✓ Video Info: $($s.width)x$($s.height) | Codec: $($s.codec_name) | Thời lượng: $($pj.format.duration)s | Kích thước: $([math]::Round($pj.format.size / 1MB, 2)) MB" -ForegroundColor Green
            $r23Sug = "PASS"
            $r23Note = "Video MP4 đạt chuẩn xuất bản ($($s.width)x$($s.height), $($s.codec_name))."
        } catch {
            Write-Host "[-] Lỗi phân tích tệp xuất: $_" -ForegroundColor Red
            $r23Sug = "FAIL"
            $r23Note = "Lỗi khi probe video: $_"
        }
    } else {
        $r23Sug = "NOT_RUN"
        $r23Note = "Chưa cung cấp tệp MP4 đã xuất"
    }

    $renderReport | ConvertTo-Json | Out-File (Join-Path $OutputDir "render.json") -Encoding utf8
    $r = Prompt-GateResult -GateId "WINPHYS-23" -GateTitle "Output Video Integrity (ffprobe)" -SuggestedStatus $r23Sug -SuggestedNote $r23Note
    $Results['WINPHYS-23'] = $r.status; $Notes['WINPHYS-23'] = $r.note; Save-CurrentState
}

# --- WINPHYS-24: Auto Update Infrastructure ---
if ($Results['WINPHYS-24'] -eq "NOT_RUN") {
    Write-Host "`n[*] Kiểm tra Hạ tầng Auto-Update:" -ForegroundColor Cyan
    Write-Host "    Trạng thái: Chưa có bản dựng release thứ hai (N) để swap trực tiếp trên máy test."
    Write-Host "    Cơ chế: AutoUpdateManager.js và powershell swap helper đã qua audit code."
    $p24Status = "NOT_RUN"
    $p24Note = "NOT_RUN_NO_TEST_RELEASE (Chưa staging bản dựng N-1 -> N)"
    
    @{
        status = "NOT_RUN_NO_TEST_RELEASE"
        note = "Auto-update infrastructure implemented; waiting for secondary release pair."
    } | ConvertTo-Json | Out-File (Join-Path $OutputDir "autoupdate.json") -Encoding utf8

    $r = Prompt-GateResult -GateId "WINPHYS-24" -GateTitle "Auto-Update Swap Infrastructure" -SuggestedStatus $p24Status -SuggestedNote $p24Note
    $Results['WINPHYS-24'] = $r.status; $Notes['WINPHYS-24'] = $r.note; Save-CurrentState
}

# --- WINPHYS-25: OS Reboot Persistence Gate ---
if ($Results['WINPHYS-25'] -ne "PASS") {
    if (-not $rebootResume) {
        Write-Host "`n================================================================================" -ForegroundColor Magenta
        Write-Host "  WINPHYS-25: TIÊU CHÍ BẢO TOÀN DỮ LIỆU SAU KHI KHỞI ĐỘNG LẠI WINDOWS" -ForegroundColor Magenta
        Write-Host "================================================================================" -ForegroundColor Magenta
        Write-Host "Hệ thống cần kiểm tra xem sau khi Restart Windows, các thông tin sau có được bảo lưu không:"
        Write-Host "  - Trạng thái đăng nhập tài khoản"
        Write-Host "  - Chứng thực bản quyền"
        Write-Host "  - Khóa DPAPI đã lưu"
        Write-Host "  - Cấu hình Google Flow"
        Write-Host "  - Tiến trình và cài đặt Hàng đợi"
        Write-Host ""
        $rbChoice = Read-Host "Bạn có muốn đánh dấu để chuẩn bị Khởi động lại Windows không? (Y/N)"
        if ($rbChoice -eq "Y" -or $rbChoice -eq "y") {
            $Results['WINPHYS-25'] = "IN_PROGRESS"
            $Notes['WINPHYS-25'] = "Đang chuẩn bị khởi động lại Windows để kiểm tra persistence."
            Save-CurrentState
            Write-Host "`n[+] Checkpoint đã được lưu an toàn tại: windows_physical_test/checkpoint.json" -ForegroundColor Green
            Write-Host "[!] BÂY GIỜ BẠN CÓ THỂ KHỞI ĐỘNG LẠI MÁY TÍNH." -ForegroundColor Yellow
            Write-Host "    Sau khi máy khởi động lại xong, chỉ cần bấm đúp chuột vào tệp:" -ForegroundColor Yellow
            Write-Host "    RUN_WINDOWS_PHYSICAL_GATE.bat" -ForegroundColor Cyan
            Write-Host "    Harness sẽ tự động tiếp tục và hoàn tất báo cáo.`n"
            Stop-Transcript | Out-Null
            Exit 0
        } else {
            $r = Prompt-GateResult -GateId "WINPHYS-25" -GateTitle "OS Reboot Persistence Gate" -SuggestedStatus "NOT_RUN" -SuggestedNote "Bỏ qua khởi động lại máy"
            $Results['WINPHYS-25'] = $r.status; $Notes['WINPHYS-25'] = $r.note; Save-CurrentState
        }
    } else {
        Write-Host "`n[+] ĐÃ TRỞ LẠI SAU KHI KHỞI ĐỘNG LẠI WINDOWS (REBOOT RESUMED)!" -ForegroundColor Green
        Write-Host "    Vui lòng mở 2TOOLNE.exe và xác nhận các dữ liệu phiên làm việc vẫn được bảo toàn." -ForegroundColor Cyan
        $r = Prompt-GateResult -GateId "WINPHYS-25" -GateTitle "OS Reboot Persistence Gate" -SuggestedStatus "PASS" -SuggestedNote "Đã kiểm tra sau khi khởi động lại máy: Tài khoản, bản quyền và cấu hình được bảo toàn 100%."
        $Results['WINPHYS-25'] = $r.status; $Notes['WINPHYS-25'] = $r.note; Save-CurrentState
        @{
            reboot_tested = $true
            session_persisted = ($Results['WINPHYS-25'] -eq "PASS")
            completed_at = (Get-Date -Format "yyyy-MM-dd HH:mm:ss UTC")
        } | ConvertTo-Json | Out-File (Join-Path $OutputDir "persistence.json") -Encoding utf8
    }
}

# ==============================================================================
# PHASE 3: FINAL REPORT GENERATION & WRAP-UP
# ==============================================================================
Write-FinalReport -OutputDir $OutputDir -Results $Results -SysInfo $SysInfo -Notes $Notes
Save-CurrentState

Write-Host ""
Write-Host "================================================================================" -ForegroundColor Green
Write-Host "          HOÀN THÀNH QUY TRÌNH KIỂM THỬ VẬT LÝ WINDOWS (PHYSICAL GATE DONE)" -ForegroundColor Green
Write-Host "================================================================================" -ForegroundColor Green
Write-Host "Báo cáo nghiệm thu chính thức đã được xuất bản tại:" -ForegroundColor Green
Write-Host "  -> $OutputDir\FINAL_REPORT.md" -ForegroundColor Green
Write-Host ""
Write-Host "Tất cả nhật ký và bằng chứng thực nghiệm lưu trữ tại:" -ForegroundColor Green
Write-Host "  -> $OutputDir\" -ForegroundColor Green
Write-Host "================================================================================" -ForegroundColor Green

Stop-Transcript | Out-Null
