# BÁO CÁO NGHIỆM THU MILESTONE 5: RESOURCE SCHEDULER, BILLING IDEMPOTENCY, RECOVERY & RENDER HANDOFF
**PHÂN HỆ HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**

> **Tài liệu**: Milestone 5 Implementation Acceptance Report  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Commit kiểm toán**: `e0fb4d8`  
> **Trạng thái**: PASSED (Đạt 100% tiêu chí)  

---

## 1. CÁC HẠNG MỤC ĐÃ HIỆN THỰC

### 1.1. Bộ Điều Phối & Khóa Phân Phối Tài Nguyên `ResourceScheduler` (Mục 14, TC-21)
* Tệp: `apps/capcut-v2/adapters/capcut/resource_scheduler.py`
* Hiện thực 3 lớp khóa tài nguyên độc quyền luồng:
  * `CAPCUT_AUTOMATION_LOCK`: Quyền ưu tiên cao nhất dành cho tương tác CapCut GUI, xuất video, cài đặt dự án.
  * `HEAVY_GPU_LOCK`: Bảo vệ bộ nhớ VRAM GPU cho Real-ESRGAN và hardware video encode.
  * `HEAVY_ASR_LOCK`: Bảo vệ CPU đa lõi và RAM hệ thống cho Faster-Whisper ASR.
* **Quy tắc điều phối ranh giới an toàn (Safe Boundary Yielding)**:
  * Khi `CAPCUT_AUTOMATION_LOCK` đang được giữ hoặc yêu cầu ưu tiên (`request_capcut_priority()`), các tác vụ mức thấp (`priority <= 0`) trong Build Queue không được cấp phát `HEAVY_GPU_LOCK` hoặc `HEAVY_ASR_LOCK`.
  * Tại từng vòng lặp xử lý ảnh của bước Upscale, Build Queue kiểm tra `should_yield_to_render()`. Nếu Render Queue cần tài nguyên, Build Queue hoàn tất nốt tệp hiện tại và chuyển trạng thái sang `PAUSED` an toàn tại ranh giới tệp, nhường quyền hoàn toàn cho Render Queue mà không làm gián đoạn hay crash tiến trình.

### 1.2. Bảo Vệ Điểm Thưởng Idempotent Bằng Full Content Hash (Mục 16)
* Tệp: `apps/capcut-v2/adapters/capcut/billing_idempotency.py`
* **Công thức băm khóa giao dịch chuẩn tắc**:
  ```python
  idempotency_key = sha256(
      f"{user_id}:{job_id}:{authoritative_full_content_hash}:{engine}:{target_resolution}".encode("utf-8")
  ).hexdigest()
  ```
* Bắt buộc sử dụng `calculate_full_sha256(file_path)` trên toàn bộ tệp, nghiêm cấm dùng 64KB fast fingerprint cho giao dịch trừ điểm.
* Lớp quản lý `BillingIdempotencyManager` đối soát khóa giao dịch theo ràng buộc `UNIQUE(idempotency_key)`: Lần gọi đầu tiên trừ điểm (`deducted = 1`), các lần thử lại từ checkpoint hoặc retry trả về giao dịch cũ mà **tuyệt đối không trừ thêm điểm** (`already_charged = True, deducted = 0`).

### 1.3. Cơ Chế Dọn Rác & Phục Hồi Sau Sự Cố (Section 7, 11, TC-14, TC-15, TC-16)
* Tệp: `apps/capcut-v2/adapters/capcut/project_build_queue_manager.py`
* Phương thức `cleanup_orphan_artifacts()` tự động chạy khi nạp snapshot (`load_snapshot`):
  * Dọn sạch mọi tệp/thư mục tạm `.tmp_pin_*`, `.tmp_build_*` do sự cố nguồn điện hoặc crash để lại.
  * Dọn dẹp các thư mục nháp tạm `staging_draft/` đối với các job chưa hoàn tất `PROJECT_READY`.
  * Bảo toàn 100% dữ liệu gốc của người dùng và các dự án CapCut chính thức.
* Hoàn thiện 2 chính sách xóa công việc `remove_job(job_id, delete_workspace)`:
  * `delete_workspace = False`: Gỡ job khỏi hàng đợi, giữ nguyên thư mục workspace trên đĩa.
  * `delete_workspace = True`: Gỡ job và xóa sạch thư mục workspace `<jobId>`, không bao giờ chạm đến tệp ảnh/âm thanh gốc của người dùng.

### 1.4. Hợp Đồng Bàn Giao Render & Kiểm Định Hồ Sơ Đóng Băng (Mục 12, 13, TC-22, TC-30, TC-31)
* Tệp: `apps/capcut-v2/adapters/capcut/render_queue_manager.py`, `render_job.py`, `desktop_bridge/bridge.py`
* Bổ sung trường `build_manifest_hash` vào mô hình dữ liệu `RenderJob`.
* Phương thức kiểm định `RenderQueueManager.verify_build_manifest(draft_path, expected_manifest_hash)`:
  1. Kiểm tra sự tồn tại của `build_manifest.json` tại thư mục draft hoặc workspace.
  2. Đối chiếu trực tiếp mã băm `buildManifestHash`.
  3. Kiểm tra tính toàn vẹn toán học: Tái tính toán băm chuẩn tắc SHA256 của toàn bộ nội dung manifest (bỏ qua khóa `buildManifestHash`). Báo lỗi `ERR_CORRUPT_BUILD_MANIFEST` nếu phát hiện can thiệp vào manifest.
  4. Kiểm tra mã băm nội dung `draft_content.json` của CapCut so với `capcutContentHash`. Báo lỗi `ERR_CORRUPT_BUILD_MANIFEST` nếu dự án CapCut bị chỉnh sửa sau khi đóng băng.
* Cập nhật `DesktopBridge._handle_render_now` tiếp nhận an toàn các tham số bàn giao từ giao diện renderer (`draft_dir`, `output_dir`, `project_name`, `build_manifest_hash`).

---

## 2. KẾT QUẢ KIỂM THỬ TỰ ĐỘNG

Chạy toàn bộ 32 test cases liên hoàn Milestone 1, 2, 3, 5, IPC Bridge và Render Queue:
* `test_resource_scheduler_lock_semantics`: PASSED (Đảm bảo đúng ngữ nghĩa 3 lớp khóa và ưu tiên CapCut automation).
* `test_billing_idempotency_full_sha256_and_duplicate_deduction_protection`: PASSED (Băm full SHA256, chống trừ trùng lặp điểm khi replay/retry).
* `test_crash_recovery_orphan_cleanup`: PASSED (Dọn dẹp sạch tệp rác `.tmp_*` và staging, chuyển đổi trạng thái dở dang sang `PAUSED`).
* `test_workspace_deletion_policies`: PASSED (Chính sách xóa workspace độc lập, an toàn tuyệt đối cho file gốc).
* `test_render_queue_handoff_manifest_verification_valid`: PASSED (Nhận việc bàn giao hợp lệ kèm băm manifest).
* `test_render_queue_handoff_manifest_verification_tampered_manifest`: PASSED (Từ chối nhận việc khi mã băm manifest bị sai lệch).
* `test_render_queue_handoff_manifest_verification_tampered_draft_content`: PASSED (Phát hiện tệp `draft_content.json` bị chỉnh sửa và từ chối).

Tổng số test passed: **32/32 passed in 0.51s**.

---

## 3. KẾT LUẬN & SẴN SÀNG CHO MILESTONE 6

* **Milestone 5 Status**: PASSED.
* **Commit**: `e0fb4d8` (Fast-forward merged vào `main`).
* **Sẵn sàng tiến hành Milestone 6**: Triển khai bộ kiểm thử ma trận toàn diện 36+ Test Cases (End-to-End Acceptance) và xuất biên bản nghiệm thu chung cuộc.
