# BÁO CÁO NGHIỆM THU MILESTONE 4: QUEUE UI & USER INTERACTION
**PHÂN HỆ HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**

> **Tài liệu**: Milestone 4 Implementation Acceptance Report  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Commit kiểm toán**: `9ad3f04`  
> **Trạng thái**: PASSED (Đạt 100% tiêu chí)  

---

## 1. CÁC HẠNG MỤC GIAO DIỆN ĐÃ HIỆN THỰC

### 1.1. Cấu Trúc Dual Sub-tabs Trong Tab Hàng Đợi (Mục 13.1)
* Tệp: `apps/capcut-v2/desktop/src/renderer/index.html`, `styles.css`, `app.js`
* Thiết kế thanh điều hướng tab con chuyên biệt:
  * **Sub-tab 1: `🏗 TẠO DỰ ÁN CAPCUT` (`#subtabBtnBuildQueue`)**: Quản lý hàng đợi biên dịch cấu hình Studio thành dự án CapCut hoàn chỉnh (`ProjectBuildQueueManager` - QUEUE A).
  * **Sub-tab 2: `🎬 RENDER VIDEO` (`#subtabBtnRenderQueue`)**: Quản lý hàng đợi tự động xuất video CapCut tuần tự (`RenderQueueManager` - QUEUE B).
* Chuyển đổi mượt mà giữa hai màn hình con mà không gây gián đoạn trạng thái hay tiến trình ngầm.

### 1.2. Thẻ Tác Vụ Dự Án (Build Job Cards) & Checklist Trực Quan (Mục 13.2)
* Mỗi tác vụ trong Hàng Đợi Tạo Dự Án được hiển thị dưới dạng card thông tin chi tiết:
  * **Tiêu đề & Badge trạng thái**: Màu sắc phân định rõ ràng (Cam cho `WAITING_SRT_REVIEW`, Xanh lá cho `PROJECT_READY`, Đỏ cho `FAILED`, Xanh dương cho đang xử lý).
  * **Hộp tiến trình chi tiết**: Hiển thị thông báo giai đoạn thời gian thực kèm chỉ số đếm được (Countable metrics: `Đã ghim X/Y file`, `Đã nâng cấp A/B ảnh`, v.v.).
  * **Thanh 7 bước kiểm định (Checklist)**:
    1. Ghim nguyên liệu (`pinning`)
    2. Nâng cấp ảnh (`upscale`)
    3. Phụ đề (`subtitles`)
    4. Duyệt phụ đề (`srtReview`)
    5. Dòng thời gian (`timeline`)
    6. Tạo nháp CapCut (`draftGeneration`)
    7. Kiểm định (`draftVerification`)
  * Trạng thái các bước được cập nhật sống động (`step-done`, `step-running`, `step-failed`, `step-skipped`).

### 1.3. Cổng Kiểm Duyệt Phụ Đề Tại Chỗ (SRT Review Modal)
* Tệp: Modal 8 `#modalSrtReview` trong `index.html` và trình xử lý trong `app.js`.
* Khi tác vụ dừng ở cổng `WAITING_SRT_REVIEW`:
  * Card hiển thị nút nổi bật `📝 Duyệt Phụ Đề`.
  * Mở Modal biên tập phụ đề với trình soạn thảo trực tiếp `#txtSrtReviewEditor`.
  * Hỗ trợ 2 chế độ duyệt:
    * `Chấp Nhận Không Sửa`: Sử dụng nguyên văn phụ đề do AI sinh ra.
    * `💾 Lưu & Duyệt Phụ Đề`: Lưu phụ đề người dùng đã chỉnh sửa thành `subtitles/reviewed.srt` và kích hoạt worker tiếp tục tự động.

### 1.4. Bộ Điều Khiển Hàng Loạt (Batch Controls) & Nút Thao Tác
* Thanh điều khiển hàng loạt phía trên:
  * `▶️ Chạy Hàng Đợi` (`#btnStartBuildQueue`): Tiếp tục xử lý tất cả tác vụ đang chờ.
  * `⏸️ Tạm Dừng` (`#btnPauseBuildQueue`): Tạm dừng worker an toàn.
  * `🧹 Xóa Đã Xong` (`#btnClearCompletedBuildQueue`): Dọn dẹp các tác vụ `PROJECT_READY` hoặc `CANCELLED`.
* Thao tác trên từng tác vụ:
  * `🎬 Chuyển Sang Render`: Bàn giao dự án `PROJECT_READY` sang Hàng Đợi Render Video kèm chữ ký `buildManifestHash`.
  * `📂 Mở Thư Mục Nháp`: Mở trực tiếp thư mục CapCut Draft trên ổ đĩa.
  * `🔄 Thử Lại`: Cho phép chạy lại các tác vụ gặp lỗi (`FAILED`).
  * `🗑️ Xóa`: Hủy tác vụ và giải phóng không gian làm việc.

### 1.5. Hệ Thống Badge Số Lượng Kép
* Huy hiệu tổng trên thanh Menu chính phản ánh tổng số tác vụ đang chờ xử lý của cả hai hàng đợi.
* Huy hiệu con trên từng sub-tab (`#buildQueueCountBadge`, `#renderQueueCountBadge`) cập nhật độc lập theo thời gian thực từ sự kiện IPC backend.

---

## 2. KẾT QUẢ KIỂM TOÁN VÀ BẢO TỒN LÕI BĂNG

1. **Bảo tồn lõi đông cứng (Frozen Core Diff = 0)**:
   * Không chạm vào FFmpeg V1, `subpixel_affine_engine.py`, TimelineBuilder/RuleEngine cốt lõi, schema CapCut hay license cryptography.
   * Toàn bộ thay đổi nằm trong giao diện renderer (`app.js`, `index.html`, `styles.css`).
2. **Kiểm thử cú pháp**:
   * Kiểm tra cú pháp JavaScript: `node --check apps/capcut-v2/desktop/src/renderer/app.js` -> PASSED (0 lỗi).
3. **Bộ kiểm thử tự động toàn diện**:
   * 20/20 test cases pytest đạt chuẩn 100% không suy thoái.

---

## 3. KẾT LUẬN & SẴN SÀNG CHO MILESTONE 5

* **Milestone 4 Status**: PASSED.
* **Commit**: `9ad3f04` (Fast-forward merged vào `main`).
* **Sẵn sàng tiến hành Milestone 5**: Triển khai `Resource Scheduler` (Khóa tài nguyên GPU/ASR/CapCut), `Billing Idempotency` (Chống trừ credit kép qua SHA256 key), `Crash Recovery & Orphan Cleanup`, và kiểm tra xác thực băm bàn giao `buildManifestHash` trong `RenderQueueManager`.
