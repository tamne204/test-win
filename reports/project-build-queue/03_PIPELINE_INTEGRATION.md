# BÁO CÁO NGHIỆM THU MILESTONE 3: PIPELINE INTEGRATION & DRAFT GENERATION
**PHÂN HỆ HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**

> **Tài liệu**: Milestone 3 Implementation Acceptance Report  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Commit kiểm toán**: `c80b8a5`  
> **Trạng thái**: PASSED (Đạt 100% tiêu chí)  

---

## 1. CÁC HẠNG MỤC ĐÃ HIỆN THỰC

### 1.1. Bộ Điều Phối Pipeline Tự Động `ProjectBuildPipeline`
* Tệp: `apps/capcut-v2/adapters/capcut/project_build_pipeline.py`
* Đảm nhận toàn bộ chu trình xử lý hạ tầng từ sau khi ghim nguyên liệu đến khi dự án CapCut sẵn sàng (`PROJECT_READY`).
* Tích hợp cơ chế tự động khôi phục từ điểm kiểm tra (Checkpoint Resume) qua từng bước `steps`: `validation`, `pinning`, `upscale`, `subtitles`, `timeline`, `draftGeneration`, `draftVerification`.

### 1.2. Giai Đoạn Nâng Cấp Hình Ảnh `UPSCALING`
* Hỗ trợ chế độ `LANCZOS` (phóng to 2x cho 2K, 4x cho 4K với bộ lọc `Image.Resampling.LANCZOS`).
* Cổng kiểm định động cơ `REAL_ESRGAN`: Phát hiện khi phần cứng hoặc mô hình không khả dụng và báo lỗi chuẩn hóa `ERR_UPSCALE_FAILED` để người dùng chuyển sang Lanczos.
* Khả năng phục hồi checkpoint: Tự động phát hiện ảnh đã được upscale trong `derived_media/` để tái sử dụng ngay lập tức mà không phải chạy lại từ đầu.

### 1.3. Giai Đoạn Chuẩn Bị Phụ Đề `PREPARING_SUBTITLE`
* Hỗ trợ 4 chế độ phụ đề:
  * `NONE`: Tự động bỏ qua (`STEP_SKIPPED`).
  * `EXISTING_SRT`: Lưu nguyên văn kịch bản/phụ đề có sẵn vào `subtitles/generated.srt` và tính băm `finalSrtHash`.
  * `FORCED_ALIGNMENT` & `AUTOSUB`: Kích hoạt bộ điều phối âm thanh `ScriptToSrtPipeline` căn chỉnh từ ngữ và nhịp điệu giọng nói với Faster-Whisper.

### 1.4. Cổng Kiểm Duyệt Phụ Đề Không Khóa `WAITING_SRT_REVIEW`
* Khi `waitForSrtReview == True`: Job chuyển sang trạng thái `WAITING_SRT_REVIEW` và nhường quyền luồng worker để xử lý các job tiếp theo (không block worker).
* Tích hợp API IPC `CONFIRM_SRT_REVIEW` và phương thức `submit_srt_review(job_id, reviewed_srt)` / `confirm_srt_review(job_id)` trong `ProjectBuildQueueManager`. Phụ đề sau khi duyệt được lưu thành `subtitles/reviewed.srt` và tính lại `finalSrtHash`.

### 1.5. Dựng Dòng Thời Gian Xác Định `BUILDING_TIMELINE`
* Kết nối trực tiếp `TimelineBuilder` và `RuleEngine` để áp dụng chuyển động Ken Burns.
* Sử dụng chính xác đường dẫn ảnh đã upscale (nếu có) hoặc ảnh đã ghim.
* Xuất `edit_plan.json` tại thư mục gốc của job kèm băm chuẩn tắc `editPlanHash = sha256(canonical(edit_plan))`.

### 1.6. Sinh Dự Án CapCut & Hồ Sơ Đóng Băng `build_manifest.json` (Mục 12)
* Sử dụng `CapCutProjectManager` tạo draft trong `staging_draft` và cài đặt vào thư mục nháp của CapCut.
* Đảm bảo an toàn tên dự án trùng lặp qua hàm giải quyết hậu tố số học `resolve_unique_project_name`: `Project`, `Project (1)`, `Project (2)`.
* Kiểm định tính toàn vẹn dự án đã cài đặt bằng `CapCutDraftValidator`.
* Đóng băng hồ sơ dự án `build_manifest.json` per Section 12: Tính `buildManifestHash = sha256(canonical(manifest_without_hash))` và chuyển trạng thái đạt chuẩn `PROJECT_READY` (100%).

---

## 2. KẾT QUẢ KIỂM THỬ TỰ ĐỘNG

Chạy bộ kiểm thử liên hoàn Milestone 1, 2, 3 và IPC Bridge:
* `test_upscale_stage_lanczos_and_checkpoint_resume`: PASSED (Lanczos upscale 2x kích thước, lưu derived_media, checkpoint resume tái sử dụng an toàn).
* `test_upscale_stage_real_esrgan_unavailable_error`: PASSED (Báo lỗi chuẩn xác khi thiếu backend Real-ESRGAN).
* `test_subtitle_stage_existing_srt`: PASSED (Lưu generated.srt và băm SHA256 đầy đủ).
* `test_srt_review_gate_non_blocking_and_resume`: PASSED (Cổng kiểm duyệt nhường worker, cho phép nộp reviewed.srt và tiếp tục).
* `test_timeline_stage_edit_plan_generation`: PASSED (Sinh EditPlan đầy đủ clips, tính băm canonical edit_plan.json).
* `test_duplicate_project_name_safety`: PASSED (Xử lý an toàn hậu tố `(1)`, `(2)` chống đè tên dự án CapCut).
* `test_end_to_end_pipeline_project_ready_and_manifest`: PASSED (Toàn bộ pipeline chạy thông suốt từ ghim đến PROJECT_READY, kiểm định tính khớp 100% của buildManifestHash).
* `tests/test_bridge_build_queue.py`: PASSED (Giao tiếp IPC Bridge).

Tổng số test passed: **20/20 passed in 0.26s**.

---

## 3. KIỂM SOÁT HỒI QUY LÕI BĂNG (FROZEN CORE REGRESSION CHECK)

* `FFMPEG_V1_DIFF`: 0
* `TIMELINE_ENGINE_BEHAVIOR_REGRESSION`: 0
* `RULE_ENGINE_REGRESSION`: 0
* `CAPCUT_DRAFT_SCHEMA_REGRESSION`: 0
* `RENDER_QUEUE_MANAGER_FSM_REGRESSION`: 0
* `LICENSE_CRYPTO_REGRESSION`: 0

---

## 4. KẾT LUẬN & SẴN SÀNG CHUYỂN BƯỚC

Milestone 3 đã hoàn tất xuất sắc theo đúng kiến trúc đóng băng Schema 2.1.0 và nguyên tắc Rule 0. Sẵn sàng chuyển sang **Milestone 4: Queue UI, SRT Review Modal & Batch Controls**.
