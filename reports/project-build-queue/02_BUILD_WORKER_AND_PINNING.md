# BÁO CÁO NGHIỆM THU MILESTONE 2: BUILD WORKER, INPUT PINNING & RACE PROTECTION
**PHÂN HỆ HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**

> **Tài liệu**: Milestone 2 Implementation Acceptance Report  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Commit kiểm toán**: `8dbb263`  
> **Trạng thái**: PASSED (Đạt 100% tiêu chí)  

---

## 1. CÁC HẠNG MỤC ĐÃ HIỆN THỰC

### 1.1. Động Cơ Ghim Nguyên Liệu Độc Lập `InputPinner`
* Tệp: `apps/capcut-v2/adapters/capcut/input_pinner.py`
* **Loại bỏ hoàn toàn Hardlink**: Nghiêm cấm `os.link` nhằm ngăn chặn rủi ro tệp nguồn bị chỉnh sửa làm hỏng bản sao trong workspace. Đảm bảo 100% tệp đã ghim sở hữu inode độc lập (`src_stat.st_ino != pin_stat.st_ino`).
* **Cơ chế chống tranh chấp (Pinning Race Protection)**:
  * Trình tự an toàn: `stat_before` -> Streaming copy sang file tạm `.tmp_pin_*` kèm tính SHA256 -> `stat_after` -> Đọc lại file tạm tính SHA256 -> Đọc lại file nguồn lần 2 tính SHA256 hậu copy.
  * So khớp 4 chiều (`size_match`, `mtime_match`, `stream_match`, `post_match`). Nếu có bất kỳ sự thay đổi nào xảy ra trong quá trình copy, lập tức xóa tệp tạm `.tmp_pin_*` và ném lỗi `PinningError(code="INPUT_CHANGED_DURING_PIN")`.
  * Sau khi vượt qua kiểm tra, thực hiện `os.replace` nguyên tử sang tên tệp chuẩn hóa `img_0001.ext`, `audio_source.ext`.

### 1.2. Chuẩn Hóa Cấu Trúc Thư Mục Workspace Theo Job
* Mỗi job sở hữu một không gian độc quyền tại `~/.2toolne-autoedit/workspace/<job_id>/`:
  * `pinned_inputs/media/`
  * `pinned_inputs/audio/`
  * `pinned_inputs/metadata.json` (ghi nhận đầy đủ nguồn gốc ban đầu, kích thước, sha256 đã đối soát)
  * `derived_media/`
  * `subtitles/`
  * `staging_draft/`
  * `manifest.json` (lưu trữ snapshot cấu hình job)

### 1.3. Giai Đoạn Tiền Thực Thi `VALIDATING`
* Tệp: `apps/capcut-v2/adapters/capcut/project_build_queue_manager.py`
* Kiểm tra danh sách hình ảnh đầu vào: phát hiện danh sách rỗng (`ERR_VALIDATION_FAILED`) hoặc tệp không tồn tại trên đĩa (`ERR_SOURCE_FILE_NOT_FOUND`).
* Kiểm tra tệp âm thanh (nếu có cấu hình).
* Tính và cập nhật Fast Fingerprint cho toàn bộ tệp nguồn trước khi ghim.
* Chuyển trạng thái sang `STATUS_VALIDATING` và cập nhật bước `validation` trong FSM.

### 1.4. Bộ Điều Phối Luồng Nền `start_worker` / `_run_worker` / `stop_worker`
* Quản lý luồng worker daemon `ProjectBuildQueueWorker`.
* Tuần tự hóa tác vụ: Xử lý từng `QUEUED` job một, bảo đảm tài nguyên không bị tranh chấp.
* Hỗ trợ dừng luồng an toàn bằng `threading.Event`, ngắt nhịp và giải phóng `active_job` chính xác khi hoàn thành hoặc có sự cố.
* Kiến trúc mở rộng cho Milestone 3 qua cơ chế hook `set_pipeline_runner(runner)`.

---

## 2. KẾT QUẢ KIỂM THỬ TỰ ĐỘNG

Chạy bộ kiểm thử toàn diện `tests/test_project_build_queue_m1.py` và `tests/test_project_build_queue_m2.py`:
* `test_input_pinner_physical_copy_and_hardlink_absence`: PASSED (Sao chép vật lý độc lập, khác biệt inode, chỉnh sửa tệp gốc không ảnh hưởng đến tệp đã ghim).
* `test_input_pinner_race_detection_input_changed_during_pin`: PASSED (Phát hiện tranh chấp mtime/content trong lúc copy, ném `ERR_INPUT_CHANGED_DURING_PIN`, dọn sạch file tạm).
* `test_input_pinner_missing_source_file`: PASSED (Ném `ERR_SOURCE_FILE_NOT_FOUND` kèm `failed_asset`).
* `test_workspace_directory_structure_and_metadata`: PASSED (Tạo đầy đủ 5 thư mục con, sinh `metadata.json` và `manifest.json` chuẩn hóa).
* `test_manager_validation_stage`: PASSED (Chặn danh sách rỗng, tệp thiếu, cập nhật Fast Fingerprints).
* `test_worker_lifecycle_and_pinning_execution`: PASSED (Vận hành worker background, chuyển tiếp `QUEUED` -> `VALIDATING` -> `PINNING_INPUTS`, lưu trữ artifacts).

Tổng số test passed: **12/12 passed in 0.19s**.
Bridge IPC test: **1/1 passed in 0.08s**.

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

Milestone 2 đã hoàn tất đúng cam kết kiến trúc và quy trình Git Rule 0. Sẵn sàng triển khai **Milestone 3: Pipeline Integration (Upscale + Subtitle + TimelineBuilder + CapCut Draft Generation)**.
