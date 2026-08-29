# BÁO CÁO KHÔI PHỤC VÀ BẢO TỒN HIỆU ỨNG ZOOM GỐC (WINDOWS 2.2.3.15 GOLDEN BASELINE)

**Nhánh phát triển:** `fix/restore-legacy-zoom-2.2.3.15`  
**Ngày hoàn thành:** 2026-08-29  
**Mục tiêu:** Khôi phục và bảo tồn 100% cơ chế Zoom In / Zoom Out chuẩn nguyên bản của Windows 2.2.3.15, dừng mọi thử nghiệm làm ảnh hưởng tới độ mượt mà thực tế của video.

---

## 1. COMMIT & PHIÊN BẢN GỐC CHUẨN (GOLDEN COMMIT)
- **Commit gốc trên Windows:** `02cfc48` (*feat(render): resolve zoom jitter with adaptive 4X supersampling and constant perceptual velocity*).
- **Trạng thái mã nguồn:** File [`ffmpeg_utils.py`](file:///Users/2tamne/tool%20ffmpeg/ffmpeg_utils.py) đã được xác nhận khớp 100% với commit `02cfc48`.

---

## 2. CHUỖI FILTER GRAPH GỐC CHUẨN (EXACT LEGACY FILTER GRAPH)

```text
scale=7680:4320:force_original_aspect_ratio=increase,
crop=7680:4320,
zoompan=z='1.0+0.20000*(on/149)':x='(iw-iw/zoom)/2':y='(ih-ih/zoom)/2':d=150:s=1920x1080:fps=30,
format=yuv420p
```

- **Độ phân giải Canvas:** $7680 	imes 4320$ ($4	imes$ supersampling trên $1080	ext{p}$, $3	imes$ trên $2	ext{K}$, $2	imes$ trên $4	ext{K}$).
- **Quỹ đạo Zoom In:** $Z(t) = 1.0 + M \cdot \left(rac{	ext{on}}{	ext{NF}}ight)$
- **Quỹ đạo Zoom Out:** $Z(t) = 1.0 + M \cdot \left(1.0 - rac{	ext{on}}{	ext{NF}}ight)$
- **Tọa độ tâm:** $X = rac{	ext{iw} - 	ext{iw}/	ext{zoom}}{2}, \; Y = rac{	ext{ih} - 	ext{ih}/	ext{zoom}}{2}$

---

## 3. CÁC TẬP TIN VIDEO KIỂM CHUẨN ĐÃ LƯU (GOLDEN REFERENCE FIXTURES)

Đã lưu vĩnh viễn các video chuẩn mực tại thư mục `tests/golden/`:
- 📁 [`tests/golden/legacy_zoom_in_30fps.mp4`](file:///Users/2tamne/tool%20ffmpeg/tests/golden/legacy_zoom_in_30fps.mp4) (Zoom In 1.0 $	o$ 1.2, 5s @ 30fps)
- 📁 [`tests/golden/legacy_zoom_out_30fps.mp4`](file:///Users/2tamne/tool%20ffmpeg/tests/golden/legacy_zoom_out_30fps.mp4) (Zoom Out 1.2 $	o$ 1.0, 5s @ 30fps)
- 📁 [`tests/golden/legacy_zoom_slow_1.05_30fps.mp4`](file:///Users/2tamne/tool%20ffmpeg/tests/golden/legacy_zoom_slow_1.05_30fps.mp4) (Zoom chậm 1.0 $	o$ 1.05, 10s @ 30fps)
- 📁 [`tests/golden/legacy_zoom_slow_1.01_30fps.mp4`](file:///Users/2tamne/tool%20ffmpeg/tests/golden/legacy_zoom_slow_1.01_30fps.mp4) (Zoom siêu chậm 1.0 $	o$ 1.01, 10s @ 30fps)
- 📁 [`tests/golden/legacy_zoom_in_60fps.mp4`](file:///Users/2tamne/tool%20ffmpeg/tests/golden/legacy_zoom_in_60fps.mp4) (Zoom In 60fps siêu mượt)

---

## 4. BỘ KIỂM THỬ BẢO VỆ CHỐNG THỤT LÙI CHẤT LƯỢNG (REGRESSION PROTECTION)

Đã tạo bộ kiểm thử tự động [`tests/test_zoom_regression_golden.py`](file:///Users/2tamne/tool%20ffmpeg/tests/test_zoom_regression_golden.py):
- Kiểm tra tính bất biến của công thức Zoom In / Zoom Out.
- Kiểm tra bắt buộc phải tồn tại Canvas $4	imes$ ($7680 	imes 4320$).
- Ngăn chặn mọi thay đổi vô tình làm hỏng chất lượng Zoom trong tương lai.
- **Kết quả:** `3/3 PASSED`.

---

## 5. BẢO TỒN TOÀN BỘ TÍNH NĂNG KHÁC
- **Giao diện Web, Timeline, Quản lý dự án:** Giữ nguyên $100\%$.
- **Căn chỉnh phụ đề và Font chữ:** Giữ nguyên vị trí phụ đề 1 dòng cân đối ở giữa 2 dòng và hỗ trợ font Apple/Windows.
- **Đồng bộ âm thanh/video:** Giữ nguyên cơ chế tự động bù thời lượng slide cuối để không bị cắt thiếu 5s.
- **Hiệu ứng Pan và Tilt:** Giữ nguyên $100\%$, không can thiệp.

---

## 6. KẾT LUẬN CUỐI CÙNG (FINAL DECISION)

> **KẾT LUẬN: `LEGACY IS BETTER`.**

Hệ thống chính thức chọn **Windows 2.2.3.15 Legacy Zoom (Renderer D 4X)** làm **Production Default Zoom Engine**. Dừng toàn bộ các thử nghiệm GPU Tensor / RGSS trên nhánh chính để đảm bảo sự ổn định tuyệt đối và trải nghiệm hoàn hảo cho khách hàng.