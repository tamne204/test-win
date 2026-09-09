================================================================================
2TOOLNE AUTOEDIT FOR CAPCUT V2 — WINDOWS PHYSICAL ACCEPTANCE TEST KIT
================================================================================
Bản dựng mục tiêu : Release Candidate 2 (RC2-2.0.0-WIN-X64-bad0afc)
Mã băm SHA-256    : 217265079cfdce4a40d5e99853e0948c184c56c82c193df32429e0345c13e7cc
Hệ điều hành đích : Windows 10 / Windows 11 x64 (Khuyến nghị cài CapCut Desktop 9.3.0.3970)
================================================================================

I. ĐẶC ĐIỂM QUAN TRỌNG:
- Bộ công cụ này (Physical Test Harness) chạy 100% bằng công cụ có sẵn của Windows (PowerShell 5.1+ và Windows API).
- KHÔNG CẦN CÀI ĐẶT: Python, Node.js, Git, PyInstaller, Antigravity hay bất kỳ môi trường lập trình nào.
- Ứng dụng 2TOOLNE RC2 mang theo đầy đủ:
  + CPython 3.12 Standalone Runtime nội bộ (autoedit-core.exe)
  + FFmpeg & FFprobe 64-bit
  + CapCutUiProbe 64-bit
  + Real-ESRGAN Vulkan GPU Upscale Engine + Model x4plus

II. HƯỚNG DẪN KIỂM THỬ TRÊN MÁY TÍNH WINDOWS:
1. Sao chép 2 mục sau vào cùng một thư mục trên máy Windows (ví dụ C:\2TOOLNE_TEST\):
   - Thư mục: windows_physical_gate\
   - Tệp nén : 2toolne-autoedit-windows-rc2-2.0.0-win-x64.zip

2. Mở thư mục windows_physical_gate\ và BẤM ĐÚP CHUỘT VÀO:
   ==> RUN_WINDOWS_PHYSICAL_GATE.bat

3. Harness sẽ tự động:
   - Kiểm tra mã băm SHA-256 của tệp RC2 ZIP (Bảo đảm nguyên bản 100%).
   - Giải nén bản dựng ứng dụng vào thư mục app_unpacked\.
   - Thu thập thông số phần cứng, GPU, màn hình, DPI Scaling.
   - Hướng dẫn bạn kiểm thử từng tính năng trực quan (Menu chọn 1: PASS, 2: FAIL, 3: BLOCKED, 4: SKIP, S: Chụp ảnh màn hình).

4. Kiểm thử Hàng Đợi Tạo Dự Án (Project Build Queue):
   - Khi đến tiêu chí WINPHYS-10 & WINPHYS-18, mở ứng dụng 2TOOLNE.
   - Chọn tab 'Hàng Đợi Tạo Dự Án' -> Bấm '[Import Bundle]'.
   - Chọn thư mục: windows_physical_gate\physical_test_bundle\
   - Bấm '[Chạy tất cả]' để tạo dự án CapCut tự động.

5. Kiểm thử Khởi động lại máy (WINPHYS-25 Reboot Gate):
   - Khi đến mục WINPHYS-25, chọn Y để lưu Checkpoint và khởi động lại Windows.
   - Sau khi máy khởi động lại xong, chỉ cần BẤM ĐÚP CHUỘT LẠI VÀO:
     ==> RUN_WINDOWS_PHYSICAL_GATE.bat
   - Harness sẽ tự động nhận diện Checkpoint và tiếp tục đúng vị trí!

III. KẾT QUẢ ĐẦU RA:
Toàn bộ kết quả, nhật ký và báo cáo nghiệm thu sẽ được xuất tự động tại:
   windows_physical_gate\windows_physical_test\FINAL_REPORT.md
Kèm các tệp JSON chi tiết (system.json, dpapi.json, ffmpeg.json, upscale.json, capcut.json...).

================================================================================
2TOOLNE Team — Authoritative Windows Acceptance Kit
