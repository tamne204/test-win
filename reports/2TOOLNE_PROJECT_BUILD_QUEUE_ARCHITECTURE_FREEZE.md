# TÀI LIỆU ĐÓNG BĂNG KIẾN TRÚC HOÀN CHỈNH: AUTOEDIT PROJECT BUILD QUEUE
**HỆ THỐNG HÀNG ĐỢI TẠO DỰ ÁN CAPCUT TỰ ĐỘNG - 2TOOLNE AUTOEDIT V2**
*(Phiên bản hiệu chỉnh dứt điểm các điểm bất nhất - Final Freeze Correction)*

> **Tài liệu**: Authoritative Architecture Freeze Specification (Chuẩn Hóa Kiến Trúc Bất Biến)  
> **Chế độ**: FINAL FREEZE CORRECTION (DO NOT IMPLEMENT PRODUCT CODE YET)  
> **Căn cứ**: Chỉ thị hiệu chỉnh kiến trúc tối hậu của OWNER  
> **Phiên bản lược đồ**: `schema_version = "2.1.0"`  
> **Quyền sở hữu duy nhất**: `ProjectBuildQueueManager` (Python Sidecar)  
> **Ngày phê duyệt**: 07/09/2026  
> **Trạng thái**: ARCHITECTURE FROZEN = YES | IMPLEMENTATION_READY = YES  

---

## MỤC LỤC

1. [Đóng Băng Kiến Trúc Hai Hàng Đợi & Quyền Sở Hữu (Core Dual-Queue Architecture)](#1-đóng-băng-kiến-trúc-hai-hàng-đợi--quyền-sở-hữu)
2. [Chuẩn Hóa Toàn Diện Máy Trạng Thái FSM & Bổ Sung `NEEDS_REVIEW`](#2-chuẩn-hóa-toàn-diện-máy-trạng-thái-fsm--bổ-sung-needs_review)
3. [Giai Đoạn Ghim Nguyên Liệu Độc Lập & Loại Bỏ Hardlink (Immutable Input Pinning)](#3-giai-đoạn-ghim-nguyên-liệu-độc-lập--loại-bỏ-hardlink)
4. [Mô Hình Băm Hai Tầng: Fast Fingerprint & Authoritative SHA256](#4-mô-hình-băm-hai-tầng-fast-fingerprint--authoritative-sha256)
5. [Cơ Chế Bảo Vệ Tranh Chấp Khi Ghim Tệp (Pinning Race Protection)](#5-cơ-chế-bảo-vệ-tranh-chấp-khi-ghim-tệp)
6. [Đóng Băng Nội Dung Kịch Bản & Phụ Đề Tại Thời Điểm Xếp Hàng (Script/SRT In-Memory Snapshot)](#6-đóng-băng-nội-dung-kịch-bản--phụ-đề-tại-thời-điểm-xếp-hàng)
7. [Cấu Trúc Thư Mục Workspace & Cô Lập Tuyệt Đối Theo Job](#7-cấu-trúc-thư-mục-workspace--cô-lập-tuyệt-đối-theo-job)
8. [Quy Tắc Vô Hiệu Hóa Dòng Thời Gian & Chuyển Động (Corrected Motion Invalidation Model)](#8-quy-tắc-vô-hiệu-hóa-dòng-thời-gian--chuyển-động)
9. [Đồ Thị Vô Hiệu Hóa Phụ Thuộc Hoàn Chỉnh (Final Invalidation Graph)](#9-đồ-thị-vô-hiệu-hóa-phụ-thuộc-hoàn-chỉnh)
10. [Kiểm Soát Phiên Bản Tuần Tự Hàng Đợi (Queue Snapshot Revisioning)](#10-kiểm-soát-phiên-bản-tuần-tự-hàng-đợi)
11. [Quy Trình Di Trú Hàng Đợi Cũ Đúng Một Lần (Exactly-Once Legacy Migration)](#11-quy-trình-di-trú-hàng-đợi-cũ-đúng-một-lần)
12. [Đóng Băng Hồ Sơ Dự Án Đạt Chuẩn (Build Manifest Immutability)](#12-đóng-băng-hồ-sơ-dự-án-đạt-chuẩn)
13. [Hợp Đồng Bàn Giao Render Kèm Khóa Toàn Vẹn (Render Handoff Integrity Contract)](#13-hợp-đồng-bàn-giao-render-kèm-khóa-toàn-vẹn)
14. [Ngữ Nghĩa Khóa Phân Phối Tài Nguyên (Resource Lock Classes & Scheduling)](#14-ngữ-nghĩa-khóa-phân-phối-tài-nguyên)
15. [Mô Hình Tiến Trình Động & Đo Lường Trung Thực (Dynamic Progress Model)](#15-mô-hình-tiến-trình-động--đo-lường-trung-thực)
16. [Bảo Vệ Điểm Thưởng Idempotent Bằng Full Content Hash (Server Token Idempotency)](#16-bảo-vệ-điểm-thưởng-idempotent-bằng-full-content-hash)
17. [Bộ Tiêu Chí Kiểm Thử 31 Kịch Bản (Comprehensive 31-Scenario Test Matrix)](#17-bộ-tiêu-chí-kiểm-thử-31-kịch-bản)

---

## 1. ĐÓNG BĂNG KIẾN TRÚC HAI HÀNG ĐỢI & QUYỀN SỞ HỮU

Hệ thống đóng băng nguyên tắc thiết kế phân định rạch ròi hai phân hệ:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        QUEUE A: PROJECT BUILD QUEUE (HÀNG ĐỢI TẠO DỰ ÁN)               │
│  - Trách nhiệm: Input Snapshot -> Pinning -> Media Prep -> Upscale -> Subtitle         │
│                 -> Timeline/RuleEngine -> CapCut Draft Generation -> Verification.     │
│  - Trạng thái hoàn tất: PROJECT_READY (kèm frozen Build Manifest).                     │
│  - Quyền sở hữu độc quyền: ProjectBuildQueueManager (Python sidecar).                  │
│  - Lưu trữ: ~/.2toolne-autoedit/project_build_queue.json                               │
└───────────────────────────────────────────┬────────────────────────────────────────────┘
                                            │ Immutable Handoff Payload (kèm buildManifestHash)
                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        QUEUE B: CAPCUT RENDER QUEUE (HÀNG ĐỢI XUẤT VIDEO)              │
│  - Trách nhiệm: Điều phối CapCut Desktop GUI Windows -> Xuất MP4 -> OutputVerifier.   │
│  - Trạng thái hoàn tất: DONE (video MP4 đạt chuẩn bitrate, thời lượng).                │
│  - Quyền sở hữu độc quyền: RenderQueueManager (adapters/capcut/render_queue_manager.py)│
│  - Quy tắc: Tuyệt đối không chạy lại bất kỳ bước biên dịch nào của Queue A.            │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

* **Xóa bỏ triệt để Hàng Đợi Ảo**: Mảng `state.queue` trong `app.js` và khóa `localStorage.autoedit_queue` bị khai tử. Renderer chỉ là bộ đệm hiển thị (Display Cache) nhận dữ liệu đẩy từ backend qua IPC.

---

## 2. CHUẨN HÓA TOÀN DIỆN MÁY TRẠNG THÁI FSM & BỔ SUNG `NEEDS_REVIEW`

Khắc phục triệt để sự bất nhất giữa chính sách di trú và mô hình trạng thái. Bổ sung chính thức `NEEDS_REVIEW` vào danh sách trạng thái cấp cao.

### 2.1. Danh Sách Trạng Thái Cấp Cao (High-Level Job Status)
```typescript
export type BuildJobStatus =
  | "NEEDS_REVIEW"           // Công việc từ bản cũ di trú sang bị thiếu dữ liệu, cần người dùng xác nhận
  | "QUEUED"                 // Đã sẵn sàng trong hàng đợi, chờ đến lượt thực thi
  | "VALIDATING"             // Kiểm tra định dạng tệp nguồn sơ bộ
  | "PINNING_INPUTS"         // Sao chép tạo snapshot tệp nguồn độc lập vào workspace
  | "PREPARING_MEDIA"        // Phân tích kích thước ảnh, phân loại canvas
  | "UPSCALING"              // Phóng to ảnh (Lanczos / Real-ESRGAN khi khả dụng)
  | "PREPARING_SUBTITLE"     // Chạy Forced Alignment (FA) hoặc AutoSub (ASR)
  | "WAITING_SRT_REVIEW"     // Điểm dừng kiểm duyệt phụ đề của người dùng (Non-blocking worker)
  | "BUILDING_TIMELINE"      // Dựng EditPlan (nội suy RuleEngine & gán motion/keyframes)
  | "GENERATING_CAPCUT_DRAFT"// Tạo thư mục nháp CapCut và cài đặt vào root_meta_info.json
  | "VERIFYING_DRAFT"        // Chạy CapCutDraftValidator thẩm định tính toàn vẹn của JSON
  | "PROJECT_READY"          // Dự án hoàn chỉnh 100%, sẵn sàng chuyển sang Render Queue
  | "PAUSED"                 // Tạm dừng do người dùng hoặc do ứng dụng khởi động lại sau crash
  | "CANCELLED"              // Người dùng hủy tác vụ
  | "FAILED";                // Sự cố tại một bước xử lý (chi tiết lưu trong lastError)
```

### 2.2. Định Nghĩa & Hành Vi Của `NEEDS_REVIEW`
* **Mục đích**: Dành riêng cho các công việc được nạp từ dữ liệu cũ (hoặc người dùng nhân bản cấu hình thiếu) mà thiếu tệp ảnh, đường dẫn âm thanh không tồn tại, hoặc tham số bị mơ hồ.
* **Quy tắc**: `NEEDS_REVIEW` **hoàn toàn khác** với `WAITING_SRT_REVIEW`. Công việc ở trạng thái này chưa thể chạy và không được worker nhấc vào thực thi.
* **Hành động trên UI**:
  * `[🔍 Kiểm Tra Cấu Hình]`: Mở lại cấu hình trên Studio để người dùng bổ sung ảnh/âm thanh bị thiếu.
  * `[✅ Xác Nhận & Đưa Vào Hàng Đợi]`: Chuyển trạng thái từ `NEEDS_REVIEW` sang `QUEUED`.
  * `[🗑️ Xóa Khỏi Hàng Đợi]`: Hủy bỏ công việc lỗi thời.

### 2.3. Mô Hình Báo Lỗi Chuẩn Hóa
Khi bất kỳ bước nào thất bại, trạng thái tổng **luôn là `status = "FAILED"`**. Thông tin chi tiết được ghi nhận vào `lastError`:
```typescript
lastError: {
  stage: "PINNING_INPUTS",
  code: "INPUT_CHANGED_DURING_PIN",
  message: "Tệp ảnh gốc đã bị sửa đổi trong lúc hệ thống đang sao chép.",
  failedAsset: "D:/Media/photo_12.jpg",
  timestamp: 1725725000000
}
```

---

## 3. GIAI ĐOẠN GHIM NGUYÊN LIỆU ĐỘC LẬP & LOẠI BỎ HARDLINK

### 3.1. Bác Bỏ Hardlink (`os.link`)
* **Lý do kỹ thuật**: Hardlink trỏ chung vào một inode trên hệ thống tệp. Nếu tệp nguồn bị chỉnh sửa nội dung in-place (ví dụ qua Photoshop hoặc ghi đè), tệp hardlink trong workspace sẽ bị thay đổi theo, phá vỡ tính bất biến của snapshot dự án.

### 3.2. Chiến Lược Ghim Nguyên Liệu Độc Lập (Immutable Content Snapshot)
Giai đoạn `PINNING_INPUTS` bắt buộc phải tạo bản sao dữ liệu vật lý độc lập theo thứ tự ưu tiên:
1. **Ưu tiên 1 (Copy-on-Write / Reflink)**: Nếu hệ thống tệp hỗ trợ bản sao tức thời độc lập (như `clonefile` trên macOS APFS, hoặc ReFS Block Cloning trên Windows Server/Win11 Enterprise), sử dụng CoW để đạt tốc độ tức thì và tiết kiệm đĩa.
2. **Ưu tiên 2 (Standard Physical Copy)**: Sử dụng `shutil.copy2()` để sao chép độc lập toàn bộ dữ liệu kèm metadata thời gian.
3. **Cấm tuyệt đối**: Không dùng `os.link` làm giải pháp snapshot bất biến.
4. **An toàn tệp gốc**: Không bao giờ di chuyển hoặc sửa đổi tệp gốc của người dùng.

---

## 4. MÔ HÌNH BĂM HAI TẦNG: FAST FINGERPRINT & AUTHORITATIVE SHA256

Hệ thống tách biệt rõ ràng mục đích của kiểm tra thay đổi nhanh và định danh tài sản:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ 1. TẠI THỜI ĐIỂM XẾP HÀNG (ENQUEUE):                                                   │
│    Tính FAST_FINGERPRINT cho từng tệp nguồn:                                           │
│    fast_fingerprint = {                                                                │
│      file_size: stat.st_size,                                                          │
│      mtime_ns: stat.st_mtime_ns,                                                       │
│      head_chunk_hash: sha256(file[:65536]),                                            │
│      tail_chunk_hash: sha256(file[-65536:]) (nếu file > 128KB)                         │
│    }                                                                                   │
│    Mục đích: Phát hiện siêu nhanh xem tệp có bị sửa đổi trước khi thực thi không.      │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ 2. TẠI BƯỚC PINNING_INPUTS:                                                            │
│    Tính AUTHORITATIVE_ASSET_HASH (SHA256_FULL_CONTENT) trong lúc sao chép tệp:        │
│    - sourceContentHash = sha256_full(source_stream)                                    │
│    - pinnedContentHash = sha256_full(pinned_file)                                      │
│    - BẮT BUỘC ĐIỀU KIỆN TIÊN QUYẾT: sourceContentHash == pinnedContentHash.             │
│    Mục đích: Khóa định danh bất biến, làm cache key cho Upscale và Idempotency billing. │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 5. CƠ CHẾ BẢO VỆ TRANH CHẤP KHI GHIM TỆP (PINNING RACE PROTECTION)

Để đối phó với tình huống người dùng hoặc phần mềm bên ngoài sửa file nguồn **ngay trong quá trình copy**:

### Quy Trình Ghim Tệp Chống Tranh Chấp (Race-Safe Sequence):
1. Đọc `stat_before = os.stat(source_path)`.
2. Sao chép dữ liệu sang `temp_pinned_path` đồng thời tính `sourceContentHash`.
3. Đọc `stat_after = os.stat(source_path)`.
4. Tính `pinnedContentHash` trên tệp đã sao chép.
5. **Kiểm tra va chạm (Race Collision Check)**:
   * Nếu `stat_before.st_size != stat_after.st_size` HOẶC `stat_before.st_mtime_ns != stat_after.st_mtime_ns` HOẶC `sourceContentHash != pinnedContentHash`:
     * Chuyển trạng thái: `status = "FAILED"`.
     * Gán lỗi: `lastError.stage = "PINNING_INPUTS"`, `lastError.code = "INPUT_CHANGED_DURING_PIN"`.
     * **Xóa bỏ ngay lập tức tệp tạm `temp_pinned_path`** để tránh rác đĩa và dữ liệu hỏng.
     * Dừng xử lý công việc, không tiếp tục.
6. Nếu vượt qua kiểm tra: Thực hiện `os.replace(temp_pinned_path, final_pinned_path)`.

---

## 6. ĐÓNG BĂNG NỘI DUNG KỊCH BẢN & PHỤ ĐỀ TẠI THỜI ĐIỂM XẾP HÀNG

* Văn bản kịch bản (`scriptText`) và nội dung phụ đề SRT có sẵn (`srtSource`) là dữ liệu văn bản thuần trong bộ nhớ.
* **Hành vi bất biến**:
  * Lưu trữ trực tiếp 100% nội dung chuỗi văn bản vào `ProjectJob.config.scriptText` và `ProjectJob.config.srtSource`.
  * Tính và lưu đồng thời:
    * `scriptHash = sha256(scriptText.encode('utf-8'))`
    * `existingSrtHash = sha256(srtSource.encode('utf-8'))`
  * Tuyệt đối không phụ thuộc vào việc tệp `.txt` hay `.srt` bên ngoài có còn tồn tại hay không.

---

## 7. CẤU TRÚC THƯ MỤC WORKSPACE & CÔ LẬP TUYỆT ĐỐI THEO JOB

Mỗi công việc sở hữu độc quyền một không gian lưu trữ riêng biệt tại `~/.2toolne-autoedit/workspace/<jobId>/`:

```
workspace/
└── build_a1b2c3d4e5f6/
    ├── manifest.json              <-- Snapshot dữ liệu công việc kèm schema_version
    ├── build_manifest.json        <-- Hồ sơ bất biến khi đạt PROJECT_READY
    ├── pinned_inputs/             <-- Tệp nguồn độc lập đã ghim an toàn
    │   ├── media/                 <-- img_001.png, img_002.jpg
    │   ├── audio/                 <-- voice.mp3
    │   └── metadata.json          <-- Bảng đối soát hash và nguồn gốc
    ├── derived_media/             <-- Chứa ảnh phóng to x2/x4 (nếu có)
    │   └── img_001_upscaled_2K.png
    ├── subtitles/                 <-- Toàn bộ sản phẩm phụ đề theo jobId
    │   ├── raw_whisper.json       <-- Timestamp thô từ Faster-Whisper
    │   ├── generated.srt          <-- SRT thuật toán sinh ra
    │   └── reviewed.srt           <-- SRT người dùng đã duyệt/sửa
    ├── edit_plan.json             <-- Cấu trúc EditPlan do TimelineBuilder sinh ra
    └── staging_draft/             <-- Thư mục nháp CapCut tạm trước khi copy vào CapCut
```

* **Chính sách xóa công việc**:
  * Thao tác `REMOVE_FROM_QUEUE`: Chỉ gỡ bản ghi khỏi hàng đợi, giữ nguyên thư mục workspace để người dùng có thể khôi phục.
  * Thao tác `DELETE_WORKSPACE`: Xóa sạch thư mục `workspace/<jobId>/`. Tuyệt đối **không bao giờ xóa** dự án CapCut chính thức trong thư viện hoặc tệp gốc của người dùng.

---

## 8. QUY TẮC VÔ HIỆU HÓA DÒNG THỜI GIAN & CHUYỂN ĐỘNG (CORRECTED MOTION INVALIDATION)

Kiểm toán mã nguồn đã chứng minh `RuleEngine` là thành phần nội bộ của `TimelineBuilder` nhằm gán các chuyển động máy quay (Ken Burns / Pan / Zoom) và tạo keyframes vào từng `EditPlanClip`. Do đó:

### Hiệu Chỉnh Quy Tắc Thay Đổi Chuyển Động (Motion Invalidation Rule):
Khi người dùng thay đổi: Preset, Trọng số chuyển động (`motion_weights`), hoặc Cấu hình Ken Burns:
* **Ảnh đã phóng to (`derived_media`)**: `VALID` (Không cần phóng to lại).
* **Phụ đề (`subtitles`)**: `VALID` (Giữ nguyên).
* **Thông tin mốc thời gian (`timing information`)**: `REUSABLE` (Tái sử dụng mốc thời gian đã tính khớp với nhịp nói).
* **Dòng thời gian (`EditPlan`)**: **`STALE`** (Bắt buộc phải chạy lại `TimelineBuilder.build()` để sinh lại keyframes và hiệu ứng mới).
* **Dự án CapCut (`CapCut Draft`)**: **`STALE`** (Bắt buộc biên dịch lại nháp CapCut từ EditPlan mới).

---

## 9. ĐỒ THỊ VÔ HIỆU HÓA PHỤ THUỘC HOÀN CHỈNH (FINAL INVALIDATION GRAPH)

Mô hình phụ thuộc phân tầng bất biến:

```
SOURCE MEDIA ──► PINNED MEDIA ──► DERIVED MEDIA ──┐
                                                  ├──► EDIT PLAN ──► CAPCUT DRAFT
AUDIO + SCRIPT ──► SUBTITLE ARTIFACT ──► TIMING ──┤
                                                  │
MOTION / PRESET ──────────────────────────────────┤
                                                  │
ASPECT RATIO ─────────────────────────────────────┴──► CANVAS ────► CAPCUT DRAFT
```

### Bảng Ma Trận Vô Hiệu Hóa:
1. **Âm thanh thay đổi**: Subtitle stale, Timing stale, EditPlan stale, Draft stale. (Derived media giữ nguyên).
2. **Kịch bản FA thay đổi**: Subtitle stale, Timing stale, EditPlan stale, Draft stale. (Derived media & Whisper ASR audio cache giữ nguyên).
3. **Phụ đề SRT sửa đổi**: Timing stale, EditPlan stale, Draft stale. (Derived media giữ nguyên).
4. **Ảnh thêm/xóa/đổi thứ tự**: Các ảnh liên quan re-evaluate upscale, EditPlan stale, Draft stale. (Subtitle giữ nguyên).
5. **Preset / Motion thay đổi**: EditPlan stale, Draft stale. (Subtitle, Derived media, Timing giữ nguyên).
6. **Tỉ lệ khung hình thay đổi**: EditPlan/Canvas stale, Draft stale. (Subtitle, Derived media giữ nguyên).
7. **Cấu hình Upscale thay đổi**: Derived media stale, EditPlan stale, Draft stale. (Subtitle giữ nguyên).

---

## 10. KIỂM SOÁT PHIÊN BẢN TUẦN TỰ HÀNG ĐỢI (QUEUE SNAPSHOT REVISIONING)

* `ProjectBuildQueueManager` là người ghi duy nhất (Sole Writer).
* Tệp snapshot bổ sung số hiệu phiên bản tăng đơn điệu: `queue_revision` (số nguyên dương).
  ```json
  {
    "schema_version": "2.1.0",
    "queue_revision": 1042,
    "updated_at": 1725725200000,
    "queue_status": "RUNNING",
    "jobs": [ ... ]
  }
  ```
* **Quy tắc an toàn Renderer IPC**:
  * Mỗi thông báo cập nhật gửi lên Renderer bắt buộc kèm `queue_revision`.
  * Renderer lưu `lastRenderedRevision`. Nếu nhận được thông báo có `queue_revision <= lastRenderedRevision` (do IPC gửi lệch thứ tự khi hệ thống tải nặng), Renderer lập tức **bỏ qua**. Điều này triệt tiêu hoàn toàn hiện tượng giao diện bị giật lùi trạng thái cũ.

---

## 11. QUY TRÌNH DI TRÚ HÀNG ĐỢI CŨ ĐÚNG MỘT LẦN (EXACTLY-ONCE MIGRATION)

Khắc phục rủi ro trùng lặp công việc hoặc mất dữ liệu khi chuyển giao từ `localStorage.autoedit_queue`:

```
[Khởi động Ứng dụng]
        │
        ▼
[Renderer đọc localStorage.autoedit_queue]
        │
        ├─► Rỗng? ──► Kết thúc.
        │
        └─► Có dữ liệu? ──► Gửi IPC: sidecar:migrate-legacy-queue(items)
                                     │
                                     ▼
        [Backend kiểm tra migration marker: legacy_queue_migration.completed == True?]
                                     │
                                     ├─► Đã di trú rồi? ──► Trả về ACK ngay (Bỏ qua)
                                     │
                                     └─► Chưa di trú:
                                           ├─► Mục hợp lệ ──► Tạo ProjectJob (QUEUED)
                                           ├─► Mục thiếu tệp ──► Tạo ProjectJob (NEEDS_REVIEW)
                                           ├─► Ghi nguyên tử vào project_build_queue.json
                                           ├─► Ghi migration marker vĩnh viễn vào backend store
                                           └─► Trả về IPC ACK thành công
                                                 │
                                                 ▼
                        [Renderer nhận ACK ──► Xóa sạch localStorage.autoedit_queue]
```

* **Khả năng phục hồi khi Crash**: Nếu ứng dụng bị crash trước khi Renderer nhận được ACK, tại lần khởi động sau, Backend dựa vào `legacy_queue_migration` marker hoặc kiểm tra trùng lặp `legacyJobId` để bỏ qua việc tạo lại, đảm bảo **không bao giờ sinh ra công việc trùng lặp**.

---

## 12. ĐÓNG BĂNG HỒ SƠ DỰ ÁN ĐẠT CHUẨN (BUILD MANIFEST IMMUTABILITY)

Khi công việc hoàn tất bước `VERIFYING_DRAFT` và đạt `PROJECT_READY`, hệ thống tạo một tệp hồ sơ đóng băng vĩnh viễn `build_manifest.json` trong thư mục workspace của job:

```json
{
  "jobId": "build_a1b2c3d4e5f6",
  "schemaVersion": "2.1.0",
  "completedAt": 1725725500000,
  "hashes": {
    "pinnedMediaHashes": ["sha256_img1...", "sha256_img2..."],
    "derivedMediaHashes": ["sha256_up1...", "sha256_up2..."],
    "finalSrtHash": "sha256_srt...",
    "editPlanHash": "sha256_editplan...",
    "capcutContentHash": "sha256_draft_content..."
  },
  "capcut": {
    "draftId": "UUID-CAPCUT-DRAFT-1234",
    "installedDraftPath": "C:/Users/.../CapCut/User Data/Projects/.../draft_1234",
    "validatorErrors": []
  },
  "buildManifestHash": "sha256_cua_toan_bo_manifest_nay"
}
```

---

## 13. HỢP ĐỒNG BÀN GIAO RENDER KÈM KHÓA TOÀN VẸN

Gói bàn giao sang `RenderQueueManager` bắt buộc phải kèm `buildManifestHash`:

```typescript
export interface RenderHandoffPayload {
  jobId: string;
  buildManifestHash: string;         // Mã định danh toàn vẹn của bản build
  draftId: string;
  draftPath: string;
  projectName: string;
  renderProfileId: string;
  expectedDurationSec?: number;
  resolution: string;
  fps: number;
  outputDir: string;
}
```

* **Hợp đồng kiểm định (Verification Gate)**: `RenderQueueManager` trước khi đưa vào hàng đợi sẽ đối chiếu `buildManifestHash` với hồ sơ trên đĩa. Nếu phát hiện tệp draft bị can thiệp trái phép sau khi build, `RenderQueue` từ chối nhận việc và thông báo lỗi toàn vẹn.

---

## 14. NGỮ NGHĨA KHÓA PHÂN PHỐI TÀI NGUYÊN (RESOURCE LOCK CLASSES)

Định nghĩa rõ ràng 3 lớp khóa tài nguyên trong hệ thống:
1. **`CAPCUT_AUTOMATION_LOCK`**: Thuộc quyền ưu tiên cao nhất của `RenderQueueManager`. Khi khóa này được kích hoạt, CapCut Desktop đang được tương tác phím/chuột hoặc đang render phần cứng.
2. **`HEAVY_GPU_LOCK`**: Bảo vệ bộ nhớ VRAM của GPU (dành cho Real-ESRGAN Vulkan và phần cứng encode).
3. **`HEAVY_ASR_LOCK`**: Bảo vệ CPU đa luồng và RAM hệ thống (dành cho Faster-Whisper ASR).

### Quy Tắc Điều Phối:
* Khi `CAPCUT_AUTOMATION_LOCK` đang được giữ: Build Queue **không được cấp phát** `HEAVY_GPU_LOCK` hoặc `HEAVY_ASR_LOCK`.
* Nếu Build Queue đang chạy dở bước Upscale/Whisper mà người dùng kích hoạt Render Queue: Bộ lập lịch của Build Queue sẽ hoàn tất nốt tệp hiện tại rồi tạm dừng ở ranh giới an toàn (Safe boundary), nhường quyền cho Render Queue. Không kill đột ngột tiến trình đang tính toán.

---

## 15. MÔ HÌNH TIẾN TRÌNH ĐỘNG & ĐO LƯỜNG TRUNG THỰC

* **Bác bỏ "Step 4/7" cố định**: Vì các bước như `UPSCALING` hay `PREPARING_SUBTITLE` có thể bị bỏ qua tùy theo cấu hình của người dùng.
* **Cấu trúc tiến trình chuẩn hóa**:
  ```typescript
  progress: {
    stage: "PREPARING_SUBTITLE",
    message: "Đang so khớp kịch bản với giọng nói qua Faster-Whisper...",
    itemCount: null,                 // Không đếm được thời lượng
    totalCount: null
  }
  // Hoặc khi ở bước Upscale:
  progress: {
    stage: "UPSCALING",
    message: "Đang phóng to ảnh bằng thuật toán Lanczos...",
    itemCount: 36,
    totalCount: 50
  }
  ```
* **Giao diện Stage Tracker động**:
  * `[✓] Kiểm tra tệp`
  * `[✓] Ghim dữ liệu (50/50)`
  * `[—] Phóng to ảnh (Bỏ qua)`
  * `[●] Nhận diện phụ đề (Đang xử lý)`
  * `[○] Dòng thời gian`
  * `[○] Cài đặt dự án CapCut`
  * `[○] Thẩm định dự án`

---

## 16. BẢO VỆ ĐIỂM THƯỞNG IDEMPOTENT BẰNG FULL CONTENT HASH

* Khóa giao dịch duy nhất gửi lên API server `/api/v1/credits/commit`:
  ```python
  idempotency_key = hashlib.sha256(
      f"{user_id}:{job_id}:{authoritative_full_content_hash}:{engine}:{target_resolution}".encode("utf-8")
  ).hexdigest()
  ```
* **Quy chuẩn**:
  * `authoritative_full_content_hash` bắt buộc là **Full SHA256** của tệp ảnh, tuyệt đối không dùng 64KB fast fingerprint.
  * Cơ sở dữ liệu server áp dụng ràng buộc `UNIQUE(idempotency_key)`.
  * Nếu yêu cầu bị lặp lại trong quá trình retry, server trả về bản ghi giao dịch cũ mà **không trừ thêm điểm**. Mảng `chargedAssets` phía client chỉ là bộ đệm tối ưu hóa.

---

## 17. BỘ TIÊU CHÍ KIỂM THỬ 31 KỊCH BẢN (COMPREHENSIVE TEST MATRIX)

Bổ sung đầy đủ 9 kịch bản kiểm thử nghiêm ngặt theo yêu cầu hiệu chỉnh của OWNER:

| Mã TC | Tên Kịch Bản Kiểm Thử | Tiêu Chuẩn Nghiệm Thu |
| :--- | :--- | :--- |
| **TC-01** | Tạo đơn lẻ 1 dự án cơ bản | Sinh nháp thành công, validator báo 0 lỗi, đạt `PROJECT_READY`. |
| **TC-02** | Xử lý hàng loạt 20 dự án liên tục | Chạy tuần tự không rò rỉ RAM/VRAM, hoàn tất 20/20. |
| **TC-03** | Chế độ Upscale = OFF | Bỏ qua upscale, tiến trình ghi nhận `SKIPPED`, dùng ảnh đã ghim. |
| **TC-04** | Chế độ Upscale = LANCZOS | Phóng to ảnh qua FFmpeg Lanczos, lưu vào `derived_media`. |
| **TC-05** | Chế độ Upscale = REAL_ESRGAN khi thiếu binary | Tự động khóa `UNAVAILABLE`, không cho bấm chạy, không crash. |
| **TC-06** | Phụ đề Forced Alignment (Audio + Script) | Whisper sinh timestamp, ScriptAligner khớp 100% chữ kịch bản. |
| **TC-07** | Phụ đề AutoSub (Audio không có Script) | Whisper nhận dạng tự do, ngắt câu theo nhịp thở. |
| **TC-08** | Phụ đề Existing SRT | Đọc tệp SRT có sẵn, bỏ qua ASR, gán đúng timeline. |
| **TC-09** | Không sử dụng phụ đề (No Subtitles) | Dựng slideshow 5s/ảnh mượt mà, không sinh track phụ đề rỗng. |
| **TC-10** | Cổng SRT Review: Tự động tiếp tục | `waitForSrtReview = false`, chạy thẳng đến `PROJECT_READY`. |
| **TC-11** | Cổng SRT Review: Chờ duyệt không chặn | `waitForSrtReview = true`, job dừng ở `WAITING_SRT_REVIEW`, worker xử lý tiếp job sau. |
| **TC-12** | Lỗi Upscale tại ảnh 37/50 | Báo lỗi đúng ảnh 37, lưu trạng thái 36 ảnh trước đó, job chuyển `FAILED`. |
| **TC-13** | Thử lại (Retry) từ Checkpoint ảnh 37/50 | Bấm Retry, hệ thống tái sử dụng ảnh 1-36, chỉ chạy tiếp từ ảnh 37. |
| **TC-14** | Crash ứng dụng giữa bước Upscale | Khởi động lại app, job chuyển `PAUSED`, chạy tiếp từ checkpoint. |
| **TC-15** | Crash ứng dụng giữa bước Whisper ASR | Khởi động lại app, khôi phục an toàn, không hỏng tệp JSON. |
| **TC-16** | Crash ứng dụng giữa bước tạo CapCut Draft | Khởi động lại app, dọn sạch staging tạm, sẵn sàng tạo lại. |
| **TC-17** | Tệp nguồn bị sửa trước khi Pinning | Fast fingerprint phát hiện sai lệch, báo lỗi `INPUT_CHANGED`. |
| **TC-18** | Tệp nguồn bị xóa trước khi Pinning | Báo lỗi `SOURCE_FILE_NOT_FOUND` kèm đường dẫn cụ thể. |
| **TC-19** | Hai dự án trùng tên hiển thị | Tự động đánh số `"Tên Dự Án (1)"`, không đè dự án CapCut cũ. |
| **TC-20** | Di trú hàng đợi cũ: Mục thiếu tệp | Chuyển thành trạng thái `NEEDS_REVIEW`, hiển thị các nút xác nhận. |
| **TC-21** | Tranh chấp tài nguyên: Render Queue đang chạy | Build Queue tự hoãn Upscale/ASR khi Render Queue đang export. |
| **TC-22** | Bàn giao sang Render Queue | Bấm [Render Ngay] chuyển payload bất biến, Render Queue chạy tốt. |
| **TC-23** | **Kiểm tra hồi quy Hardlink (Mutation Test)** | Sửa tệp nguồn gốc sau khi ghim; tệp pinned **hoàn toàn không bị đổi**. |
| **TC-24** | **Tệp nguồn bị sửa TRONG KHI ghim (Race Test)** | Kích hoạt đổi file giữa lúc copy; hệ thống phát hiện, xóa file rác, báo `INPUT_CHANGED_DURING_PIN`. |
| **TC-25** | **Đối soát Full-Hash sai lệch** | Mô phỏng đĩa lỗi bit khiến sourceContentHash != pinnedContentHash; dừng ngay lập tức. |
| **TC-26** | **Crash trước khi nhận ACK di trú legacy** | Khởi động lại; hệ thống nhận diện migration marker, **không sinh job trùng lặp**. |
| **TC-27** | **Thử lại di trú legacy không trùng lặp** | Bắn lại yêu cầu di trú; backend trả về ACK an toàn, mảng giữ nguyên. |
| **TC-28** | **IPC Events đến lệch thứ tự (Out-of-Order)** | Gửi event có `queue_revision` nhỏ hơn; Renderer bỏ qua, không giật lùi UI. |
| **TC-29** | **Đổi Motion -> Sinh lại EditPlan** | Đổi preset motion; hệ thống tái sử dụng timing, sinh lại EditPlan và CapCut Draft. |
| **TC-30** | **Chống can thiệp Build Manifest** | Sửa đổi nội dung `draft_content.json` sau khi ready; hệ thống phát hiện sai lệch hash manifest. |
| **TC-31** | **Sai lệch buildManifestHash khi bàn giao Render** | Render Queue từ chối thực thi nếu `buildManifestHash` trong payload không khớp với đĩa. |

---

## KẾT LUẬN & ĐÓNG BĂNG KIẾN TRÚC TOÀN DIỆN

Mọi điểm bất nhất đã được hiệu chỉnh dứt điểm, thiết lập tính nhất quán toán học và kỹ thuật 100% giữa mô hình dữ liệu, FSM, hệ thống tệp và bảo vệ giao dịch.

* **PROJECT_BUILD_QUEUE_ARCHITECTURE_FROZEN = YES**  
* **IMPLEMENTATION_READY = YES**
