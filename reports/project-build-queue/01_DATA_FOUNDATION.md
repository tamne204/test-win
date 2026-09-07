# BÁO CÁO NGHIỆM THU MILESTONE 1: DATA MODEL, PERSISTENCE & MIGRATION
**PHÂN HỆ HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**

> **Tài liệu**: Milestone 1 Implementation Acceptance Report  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Commit kiểm toán**: `8cc34b3`  
> **Trạng thái**: PASSED (Đạt 100% tiêu chí)  

---

## 1. CÁC HẠNG MỤC ĐÃ HIỆN THỰC

### 1.1. Lược Đồ Dữ Liệu `ProjectJob` (Schema Version 2.1.0)
* Tệp: `apps/capcut-v2/adapters/capcut/project_build_job.py`
* Đầy đủ 15 trạng thái FSM cấp cao chuẩn hóa:
  `NEEDS_REVIEW`, `QUEUED`, `VALIDATING`, `PINNING_INPUTS`, `PREPARING_MEDIA`, `UPSCALING`, `PREPARING_SUBTITLE`, `WAITING_SRT_REVIEW`, `BUILDING_TIMELINE`, `GENERATING_CAPCUT_DRAFT`, `VERIFYING_DRAFT`, `PROJECT_READY`, `PAUSED`, `CANCELLED`, `FAILED`.
* Không tạo các mã trạng thái lai như `FAILED_UPSCALE`; chuẩn hóa về `status = "FAILED"` kèm đối tượng `lastError = { stage, code, message, failedAsset, timestamp }`.
* Tách bạch tiến trình chi tiết từng bước: `steps = { validation, pinning, upscale, subtitles, timeline, draftGeneration, draftVerification }`.
* Hàm tính `calculate_fast_fingerprint`: Kích thước + mtime_ns + SHA256 64KB đầu + SHA256 64KB cuối.

### 1.2. Bộ Quản Lý Hàng Đợi Thẩm Quyền Duy Nhất `ProjectBuildQueueManager`
* Tệp: `apps/capcut-v2/adapters/capcut/project_build_queue_manager.py`
* Thuộc quyền sở hữu độc quyền của Python Sidecar.
* Lưu trữ bền vững nguyên tử trên Windows: `~/.2toolne-autoedit/project_build_queue.json` qua cơ chế `.tmp` -> `flush()` -> `os.fsync()` -> `os.replace()`.
* Quản lý số hiệu phiên bản tăng đơn điệu: `queue_revision`. Mọi đột biến trạng thái đều kích hoạt tăng revision.
* Cơ chế tự động dung hòa sau crash (Crash Reconciliation): Khi ứng dụng khởi động lại, các job ở trạng thái đang chạy dở (`PINNING_INPUTS`, `UPSCALING`...) tự động đưa về `PAUSED` kèm thông báo tiếng Việt để người dùng sẵn sàng tiếp tục.

### 1.3. Di Trú Hàng Đợi Cũ Đúng Một Lần (Exactly-Once Legacy Migration)
* Nguồn: `localStorage.autoedit_queue` của renderer.
* Trình tự: Renderer đọc mảng cũ -> gửi IPC `sidecar:migrate-legacy-queue` -> Backend kiểm tra `legacy_queue_migration.completed` và deduplicate -> Các mục đủ ảnh chuyển thành `QUEUED`, các mục thiếu tệp chuyển thành `NEEDS_REVIEW` -> Ghi nguyên tử vào đĩa -> Gửi ACK -> Renderer chỉ xóa localStorage sau khi nhận được ACK.

### 1.4. Tích Hợp IPC Bridge, Electron Main, Preload & Renderer
* `apps/capcut-v2/desktop_bridge/bridge.py`: Bổ sung các lệnh `ENQUEUE_PROJECT_BUILD`, `GET_BUILD_QUEUE_STATE`, `CONTROL_BUILD_QUEUE`, `MIGRATE_LEGACY_QUEUE`, `UPDATE_BUILD_JOB_CONFIG`, `CONFIRM_NEEDS_REVIEW`. Bổ sung listener tự động bắn event `build_queue_update`.
* `apps/capcut-v2/desktop/src/main/index.js`: Đăng ký đầy đủ IPC handlers tương ứng.
* `apps/capcut-v2/desktop/src/preload/preload.js`: Expose các hàm gọi và listener `onBuildQueueUpdate`.
* `apps/capcut-v2/desktop/src/renderer/app.js`:
  * Thay thế nút `DOM.btnAddToQueue` sang gọi `window.autoedit.enqueueProjectBuild(payload)`.
  * Cơ chế an toàn IPC Revision: Renderer lưu `lastRenderedBuildRevision`, tự động bỏ qua event nếu `queue_revision <= lastRenderedBuildRevision`.
  * Cập nhật `updateQueueBadge()` hiển thị tổng số job đang chờ từ cả Build Queue và Render Queue.

---

## 2. KẾT QUẢ KIỂM THỬ TỰ ĐỘNG

Bộ kiểm thử `tests/test_project_build_queue_m1.py` và `tests/test_bridge_build_queue.py`:
* `test_project_job_data_model`: PASSED (Khởi tạo, chuyển trạng thái FAILED, serialize/deserialize roundtrip).
* `test_fast_fingerprint`: PASSED (Tính fingerprint 64KB đầu/cuối và SHA256 full).
* `test_queue_persistence_and_revision`: PASSED (Ghi nguyên tử, tăng queue_revision tuần tự, nạp lại state).
* `test_legacy_phantom_queue_migration`: PASSED (Phân loại `QUEUED` vs `NEEDS_REVIEW`, chống trùng lặp exactly-once).
* `test_needs_review_confirmation`: PASSED (Kiểm tra chặn xác nhận khi còn thiếu tệp, cho phép xác nhận sau khi cập nhật).
* `test_crash_recovery_reconciles_active_states`: PASSED (Tự động đưa job dở dang về PAUSED khi nạp snapshot).
* `test_bridge_build_queue_dispatch`: PASSED (Gửi lệnh IPC qua Bridge và nhận event notification).

---

## 3. KIỂM SOÁT HỒI QUY LÕI BĂNG (FROZEN CORE REGRESSION CHECK)

* `FFMPEG_V1_DIFF`: 0
* `TIMELINE_ENGINE_BEHAVIOR_REGRESSION`: 0
* `RULE_ENGINE_REGRESSION`: 0
* `CAPCUT_DRAFT_SCHEMA_REGRESSION`: 0
* `LICENSE_CRYPTO_REGRESSION`: 0
