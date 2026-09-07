# 2TOOLNE CLOUD V2 — TÀI LIỆU BÀN GIAO KỸ THUẬT CHO AGENT PHÁT TRIỂN DESKTOP APP
## CLOUD INTEGRATION SPECIFICATION FOR 2TOOLNE UPSCALE AGENT

**Mã tài liệu:** `docs/CLOUD_APP_AGENT_INTEGRATION_GUIDE.md`  
**Dành cho:** Agent Quản Lý & Phát Triển Ứng Dụng Desktop (2TOOLNE Upscale & Slideshow Studio)  
**Đơn vị ban hành:** Lead Systems Architect & Web Infrastructure Team (`2tamne.site`)  
**Phiên bản chuẩn:** `2.0.0-APP-HANDOVER`  
**Ngày phát hành:** 05/09/2026  
**Thư mục mã nguồn App:** `/Users/2tamne/Documents/toolupscale/`

---

## 💎 1. TỔNG QUAN & NGUYÊN TẮC BẤT DI BẤT DỊCH (CORE PRINCIPLES)

Chào bạn, Agent phụ trách ứng dụng Desktop (2TOOLNE Upscale)!  
Tài liệu này cung cấp toàn bộ đặc tả kỹ thuật, cấu trúc cơ sở dữ liệu SQLite cục bộ, giao thức mạng và quy chuẩn giao diện để bạn tích hợp tính năng **2TOOLNE Cloud V2** (Lưu trữ đám mây đa tài khoản Google Drive) vào ứng dụng Desktop.

> [!CAUTION]
> **3 NGUYÊN TẮC VÀNG DÀNH CHO DESKTOP APP:**
> 1. **TUYỆT ĐỐI KHÔNG LÀM NGHẼN TIẾN TRÌNH GPU (ZERO GPU BLOCKING):**  
>    Việc tải tệp tin lên đám mây **tuyệt đối không được làm chậm hoặc tạm dừng tiến trình Upscale AI trên GPU**. Ngay khi AI xử lý xong 1 ảnh trong batch và lưu vào thư mục cục bộ (`output_path`), tệp tin đó phải **khả dụng ngay lập tức** cho người dùng. Tác vụ đẩy lên Cloud được chuyển sang một hàng đợi chạy nền độc lập (`CloudBackupQueue`).
> 2. **DỮ LIỆU CỤC BỘ LUÔN AN TOÀN TRƯỚC HẾT (LOCAL FIRST):**  
>    Dù mạng rớt, Google Drive lỗi, hay Cloud bị vượt hạn mức (`OVER_QUOTA`), **toàn bộ ảnh thành phẩm đã upscale luôn được bảo toàn 100% trên ổ cứng máy tính**. Không bao giờ làm mất ảnh của khách hàng vì lỗi mạng.
> 3. **TRUYỀN TRỰC TIẾP KHÔNG QUA HOSTING (DIRECT RESUMABLE UPLOAD):**  
>    Desktop App chỉ gọi 2TOOLNE API để xin cấp `resumable_session_url`, sau đó gửi luồng nhị phân (binary chunks) **trực tiếp 100% lên máy chủ Google Drive**. Tuyệt đối không gửi file nhị phân qua máy chủ hosting `2tamne.site` (hosting chỉ có 200MB, sẽ sập ngay nếu nhận file).

---

## 🏗️ 2. KIẾN TRÚC HAI HÀNG ĐỢI ĐỘC LẬP TRÊN DESKTOP

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    TIẾN TRÌNH CHÍNH (ELECTRON MAIN PROCESS)                 │
├──────────────────────────────────────┬──────────────────────────────────────┤
│       1. HÀNG ĐỢI XỬ LÝ GPU          │       2. HÀNG ĐỢI SAO LƯU CLOUD      │
│      (QueueManager - Tác vụ nặng)    │     (CloudBackupQueue - Chạy nền)    │
├──────────────────────────────────────┼──────────────────────────────────────┤
│ • Điều phối GPU AI Model (Real-ESRGAN│ • Lắng nghe sự kiện `item:completed`.│
│   / Compact / Ultra-Sharp).          │ • Gắn chặt theo `cloud_space_id`.    │
│ • Quản lý Token (Reserve / Commit).  │ • Xin phiên upload từ 2TOOLNE API.   │
│ • Lưu ảnh thành phẩm vào ổ đĩa.      │ • Đẩy binary trực tiếp lên Google.   │
│ ──> Xong ảnh: Báo "Hoàn tất" cục bộ. │ • Tự động tiếp tục (Resume) rớt mạng.│
│                                      │ ──> Xong: Cập nhật cờ "Đã sao lưu".  │
└──────────────────────────────────────┴──────────────────────────────────────┘
```

---

## 🗄️ 3. ĐẶC TẢ CƠ SỞ DỮ LIỆU SQLITE CỤC BỘ (LOCAL SQLITE SCHEMA)

Tại file `/Users/2tamne/Documents/toolupscale/database/local-sqlite/schema.sql`:

### 3.1. Tạo Bảng Mới: `cloud_backup_jobs`
Bảng này lưu trữ toàn bộ các tác vụ đẩy tệp tin lên đám mây chạy ngầm:

```sql
CREATE TABLE IF NOT EXISTS cloud_backup_jobs (
  id TEXT PRIMARY KEY,                       -- UUID v4 (ví dụ: cbj_1029384756)
  project_id TEXT NOT NULL,                  -- Khóa ngoại liên kết projects(id)
  project_item_id TEXT,                      -- Khóa ngoại liên kết project_items(id)
  cloud_space_id TEXT NOT NULL,              -- Personal Space hoặc Team Space đích
  local_file_path TEXT NOT NULL,             -- Đường dẫn file ảnh/video thành phẩm trên ổ đĩa
  target_folder_path TEXT NOT NULL,          -- Thư mục ảo trên Cloud (ví dụ: /Upscale/2026_09_Wedding)
  file_name TEXT NOT NULL,                   -- Tên file (ví dụ: 2T_4K_IMG_001.png)
  file_size_bytes INTEGER NOT NULL,          -- Kích thước tệp (bytes)
  file_hash_sha256 TEXT,                     -- Mã hash SHA-256 để kiểm tra toàn vẹn
  status TEXT NOT NULL DEFAULT 'PENDING',    -- PENDING, PREPARING, UPLOADING, PAUSED_OVER_QUOTA, PAUSED_MANUAL, COMPLETED, FAILED
  upload_reservation_id TEXT,                -- Mã giữ quota từ 2TOOLNE API (res_...)
  resumable_session_url TEXT,                -- Link phiên tải lên trực tiếp của Google Drive
  bytes_uploaded INTEGER NOT NULL DEFAULT 0, -- Số byte đã truyền thành công
  retry_count INTEGER NOT NULL DEFAULT 0,    -- Số lần đã thử lại khi rớt mạng
  last_error_code TEXT,                      -- Mã lỗi (ví dụ: QUOTA_EXCEEDED, NETWORK_TIMEOUT)
  last_error_message TEXT,                   -- Chi tiết lỗi
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  started_at TEXT,
  completed_at TEXT,
  FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cloud_jobs_status ON cloud_backup_jobs(status);
CREATE INDEX IF NOT EXISTS idx_cloud_jobs_space ON cloud_backup_jobs(cloud_space_id);
CREATE INDEX IF NOT EXISTS idx_cloud_jobs_project ON cloud_backup_jobs(project_id);
```

### 3.2. Cập Nhật Bảng `projects`
Bổ sung 2 cột vào bảng `projects`:
- `cloud_space_id TEXT DEFAULT NULL`: Lưu ID của Không gian lưu trữ mà người dùng chọn cho dự án này (nếu bật sao lưu).
- `auto_cloud_backup INTEGER NOT NULL DEFAULT 0`: `1` là tự động sao lưu sau khi upscale xong, `0` là tắt.

### 3.3. Bảng Cache Danh Sách Không Gian: `cloud_spaces_cache`
Để giao diện mở nhanh tức thì không phụ thuộc mạng:

```sql
CREATE TABLE IF NOT EXISTS cloud_spaces_cache (
  space_id TEXT PRIMARY KEY,
  space_name TEXT NOT NULL,
  space_type TEXT NOT NULL,                  -- 'PERSONAL' hoặc 'TEAM'
  role TEXT NOT NULL,                        -- 'OWNER', 'ADMIN', 'MEMBER'
  effective_quota_bytes INTEGER NOT NULL,
  used_bytes INTEGER NOT NULL,
  reserved_bytes INTEGER NOT NULL,
  is_over_quota INTEGER NOT NULL DEFAULT 0,
  cached_at TEXT NOT NULL
);
```

---

## ⚙️ 4. XÂY DỰNG MODULE `CloudBackupQueue` (`apps/desktop/src/main/cloud_backup_queue.ts`)

### 4.1. Vòng Đời Tác Vụ Sao Lưu (Job Lifecycle)
```
[Ảnh xử lý GPU xong & lưu cục bộ]
                │
                ▼
          CLOUD_PENDING ────────► CLOUD_PAUSED_MANUAL (Người dùng bấm tạm dừng)
                │
                ▼
         CLOUD_PREPARING ──(Gọi POST /uploads/create xin phiên & khóa quota)
                │
                ├───► CLOUD_PAUSED_OVER_QUOTA (Nếu Space bị đầy / HTTP 409)
                │
                ▼
         CLOUD_UPLOADING ──(Truyền binary theo khối 4MB tới Google Drive)
                │
                ├────────► CLOUD_FAILED (Rớt mạng quá 5 lần ──> Bounded Backoff)
                │
                ▼
         CLOUD_VERIFYING ──(Gọi POST /uploads/{id}/finalize xác thực khớp size)
                │
                ▼
         CLOUD_COMPLETE  ──(Đánh dấu hoàn tất thành công)
```

### 4.2. Khởi Động Job Từ `queue_manager.ts`
Trong `apps/desktop/src/main/queue_manager.ts`, tại điểm hoàn tất xử lý một ảnh:
```typescript
// Ngay sau khi lưu file thành công tại output_path:
await repository.updateProjectItemStatus(item.id, 'COMPLETED', outputPath);

// Kiểm tra dự án có bật Cloud Backup không:
const project = await repository.getProjectById(item.project_id);
if (project && project.auto_cloud_backup === 1 && project.cloud_space_id) {
  await cloudBackupQueue.enqueue({
    projectId: project.id,
    projectItemId: item.id,
    cloudSpaceId: project.cloud_space_id,
    localFilePath: outputPath,
    targetFolderPath: `/Upscale/${sanitizeFolderName(project.name)}`,
    fileName: path.basename(outputPath),
    fileSizeBytes: fs.statSync(outputPath).size
  });
}
```

---

## 🌐 5. QUY TRÌNH TẢI LÊN RESUMABLE TRỰC TIẾP (DIRECT RESUMABLE ENGINE)

Module `CloudBackupQueue` thực thi quy trình 3 bước chuẩn hóa:

```
[Desktop App]                  [2TOOLNE API (2tamne.site)]            [Google Drive API]
     │                                     │                                  │
     │── 1. POST /spaces/{id}/uploads/create ─>│                                  │
     │      (name, size, folder)           │                                  │
     │                                     │── Xin Resumable Session URI ────>│
     │                                     │<── Trả về session_url ───────────│
     │<── 2. Trả về reservation_id ────────┘                                  │
     │       & session_url                                                    │
     │                                                                        │
     │── 3. PUT binary chunks (4MB) trực tiếp ───────────────────────────────>│
     │      Content-Range: bytes 0-4194303/15000000                           │
     │<──   HTTP 308 Resume Incomplete (Range: 0-4194303) ────────────────────│
     │                                                                        │
     │── 4. PUT chunk cuối cùng ─────────────────────────────────────────────>│
     │<──   HTTP 200 OK (Google File ID) ─────────────────────────────────────│
     │                                                                        │
     │── 5. POST /spaces/{id}/uploads/{res_id}/finalize ──>│                  │
     │      (google_file_id, sha256)                       │                  │
     │<── 6. Trả về cloud_file_id (ACTIVE) ───────────────┘                  │
```

### 5.1. Thuật Toán Chunked Upload Bằng Node.js:
- **Kích thước khối chuẩn (Chunk Size):** `4 * 1024 * 1024` (4 MB). Bắt buộc phải là bội số của 256 KB theo chuẩn Google Drive.
- **Tiêu đề HTTP gửi đi mỗi khối:**
  ```http
  PUT {resumable_session_url}
  Content-Length: {chunk_bytes_length}
  Content-Range: bytes {start_byte}-{end_byte}/{total_file_size}
  Content-Type: image/png
  ```
- **Xử lý phản hồi từ Google:**
  - `HTTP 308 Resume Incomplete`: Đã nhận xong khối. Đọc header `Range: bytes=0-{last_byte}` $\rightarrow$ Cập nhật `bytes_uploaded = last_byte + 1` vào SQLite $\rightarrow$ Bắn tiến độ lên Renderer UI $\rightarrow$ Gửi tiếp khối tiếp theo.
  - `HTTP 200` hoặc `HTTP 201`: Đã truyền xong khối cuối cùng! Đọc JSON phản hồi từ Google lấy `id` của file trên Google Drive (`provider_file_id`).

### 5.2. Cơ Chế Tự Động Tiếp Tục (Resume Engine) Khi Mất Mạng Hoặc Tắt App
Khi máy tính mất mạng hoặc ứng dụng bị tắt đột ngột:
1. Khi app mở lại hoặc mạng có lại, worker tìm các job có `status = 'UPLOADING'` và có `resumable_session_url`.
2. Gửi yêu cầu kiểm tra trạng thái tới Google bằng một PUT rỗng:
   ```http
   PUT {resumable_session_url}
   Content-Length: 0
   Content-Range: bytes */{total_file_size}
   ```
3. Google Drive trả về mã `HTTP 308` kèm header: `Range: bytes=0-8388607`.
4. Worker chỉ việc mở file cục bộ, đọc tiếp từ byte `8388608` và truyền tiếp. **Tuyệt đối không phải upload lại từ byte 0!**

---

## 🛡️ 6. CƠ CHẾ ỨNG PHÓ KHI VƯỢT HẠN MỨC (OVER-QUOTA GRACEFUL HANDLING)

Khi Không gian lưu trữ bị quá hạn mức (`used_bytes + file_size > effective_quota_bytes`):
1. Khi gọi `POST /api/v1/cloud/spaces/{spaceId}/uploads/create`, 2TOOLNE API sẽ trả về lỗi:
   ```json
   {
     "success": false,
     "error": "QUOTA_EXCEEDED",
     "message": "Không gian lưu trữ đã đầy. Hạn mức khả dụng không đủ.",
     "effective_quota": 26843545600,
     "used_bytes": 26800000000
   }
   ```
2. **Hành vi bắt buộc của Desktop App:**
   - Đánh dấu job trong SQLite: `status = 'PAUSED_OVER_QUOTA'`, `last_error_code = 'QUOTA_EXCEEDED'`.
   - **TIẾN TRÌNH UPSCALE TRÊN GPU VẪN CHẠY TIẾP BÌNH THƯỜNG 100% ĐẾN HẾT BATCH.**
   - Hiển thị thông báo Toast nhẹ nhàng trên giao diện:  
     *“⚠️ Không thể sao lưu lên Cloud: Không gian lưu trữ đã đầy. Ảnh đã được lưu an toàn trên máy tính của bạn.”*
   - Cập nhật huy hiệu dung lượng trên Header sang trạng thái cảnh báo màu cam/đỏ.
   - Khi người dùng giải phóng dung lượng trên Web hoặc mua thêm gói, bấm nút **"Tiếp tục sao lưu"** trên Desktop thì job sẽ tự động chạy tiếp.

---

## 👥 7. MÔ HÌNH BẢN QUYỀN VÀ GHÉP NỐI THIẾT BỊ CHO TEAM (APP KEYS & DEVICES)

Khi một khách hàng/studio ảnh cưới sử dụng gói Đội nhóm (ví dụ: Team Starter có 2 thành viên, 50GB dung lượng, 2 App Keys Desktop):
1. **Xác thực và kích hoạt thiết bị:**
   - Thành viên mở app $\rightarrow$ Đăng nhập bằng tài khoản thành viên của Team (hoặc nhập License Key của Team).
   - App gọi `/api/v1/devices/activate` gửi kèm HWID (`SHA256_HWID_FINGERPRINT`).
   - Server kiểm tra và gán thiết bị vào bảng `devices` dưới bản quyền của Team, tiêu tốn 1 slot thiết bị (`max_devices`).
   - **`MIGRATION_REQUIRED = NO`**: Sử dụng nguyên vẹn cấu trúc bảng `devices` hiện có, không có rủi ro hồi quy.
2. **Lấy danh sách Không gian lưu trữ:**
   - Sau khi đăng nhập thành công, Desktop App gọi: `GET /api/v1/cloud/spaces`.
   - Server trả về danh sách các Không gian mà tài khoản có quyền (bao gồm Personal Space cá nhân và Team Space dùng chung).
   - App lưu vào bảng `cloud_spaces_cache` để người dùng chọn.

---

## 🎨 8. ĐẶC TẢ TÍCH HỢP GIAO DIỆN DESKTOP APP (UI / UX)

### 8.1. Hộp Thoại Thiết Lập Dự Án (`NewProjectModal.tsx` & `ProjectDetailModal.tsx`)
Bổ sung phần thiết lập Cloud trực quan, thân thiện:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ☁️ Tự Động Sao Lưu Đám Mây (2toolne Cloud)                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│ [✓] Tự động tải kết quả lên đám mây sau khi Upscale thành công              │
│                                                                             │
│ Không gian lưu trữ đích:                                                    │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ 👥 Team Wedding Pro (35.2 GB / 50.0 GB khả dụng)                      ▼ │ │
│ ├─────────────────────────────────────────────────────────────────────────┤ │
│ │   👤 Cá nhân - Personal Space (12.4 GB / 25.0 GB)                       │ │
│ │ ✓ 👥 Team Wedding Pro (35.2 GB / 50.0 GB)                               │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                             │
│ Thư mục lưu trữ: 📁 /Upscale/{Tên_Dự_Án}                                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 8.2. Huy Hiệu Trên Header (`Header.tsx`)
- Hiển thị huy hiệu thu nhỏ: `☁️ Team Wedding Pro: 35.2 / 50.0 GB`.
- Khi bấm vào: Mở Popover hiển thị:
  - Tiến độ tải lên hiện tại (`Đang sao lưu: 3/10 ảnh · 45%`).
  - Nút Tạm dừng / Tiếp tục hàng đợi Cloud.
  - Nút dẫn nhanh tới link Web File Manager: `https://www.2tamne.site/#cloud`.

### 8.3. Khôi Phục Dữ Liệu 1 Bấm (`ProjectsView.tsx`)
- Nếu người dùng vô tình dọn ổ đĩa và xóa mất thư mục xuất ảnh cục bộ, nút **"☁️ Khôi Phục Từ Cloud"** sẽ sáng lên.
- Khi người dùng bấm vào: App gọi API lấy danh sách ảnh của dự án này trên Cloud Space tương ứng và tải lại toàn bộ về máy tính tự động.

---

## 📡 9. TỔNG HỢP DANH MỤC API DÀNH RIÊNG CHO DESKTOP APP

Base URL: `https://www.2tamne.site/api/v1/cloud`  
Headers bắt buộc:
```http
Authorization: Bearer <JWT_TOKEN>
X-Device-Id: <DEVICE_HWID_SHA256>
Content-Type: application/json
Accept: application/json
```

### 1. Lấy danh sách Không gian người dùng có quyền
- **Endpoint:** `GET /spaces`
- **Response (200):**
  ```json
  {
    "success": true,
    "spaces": [
      {
        "id": "spc_pers_user15",
        "name": "Personal Space",
        "space_type": "PERSONAL",
        "role": "OWNER",
        "effective_quota_bytes": 26843545600,
        "used_bytes": 12884901888,
        "reserved_bytes": 0,
        "is_over_quota": false
      },
      {
        "id": "spc_team_wedding01",
        "name": "Team Wedding Studio",
        "space_type": "TEAM",
        "role": "MEMBER",
        "effective_quota_bytes": 53687091200,
        "used_bytes": 37580963840,
        "reserved_bytes": 209715200,
        "is_over_quota": false
      }
    ]
  }
  ```

### 2. Lấy thông số hạn mức của một Không gian
- **Endpoint:** `GET /spaces/{spaceId}/quota`
- **Response (200):**
  ```json
  {
    "success": true,
    "space_id": "spc_team_wedding01",
    "effective_quota_bytes": 53687091200,
    "used_bytes": 37580963840,
    "reserved_bytes": 209715200,
    "free_bytes": 15896412160,
    "is_over_quota": false
  }
  ```

### 3. Tạo phiên tải lên & Tạm giữ Quota
- **Endpoint:** `POST /spaces/{spaceId}/uploads/create`
- **Request Body:**
  ```json
  {
    "file_name": "2T_4K_IMG_001.png",
    "file_size_bytes": 15728640,
    "folder_path": "/Upscale/Wedding_Batch_01",
    "mime_type": "image/png",
    "project_id": "proj_20260905_01",
    "checksum_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  }
  ```
- **Response thành công (201):**
  ```json
  {
    "success": true,
    "upload_reservation_id": "res_987654321",
    "resumable_session_url": "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=ADPycdss...",
    "expires_at": "2026-09-06 06:30:00"
  }
  ```
- **Response khi quá hạn mức (409):**
  ```json
  {
    "success": false,
    "error": "QUOTA_EXCEEDED",
    "message": "Không gian lưu trữ đã vượt quá hạn mức cho phép."
  }
  ```

### 4. Xác nhận hoàn tất tải lên (Commit)
- **Endpoint:** `POST /spaces/{spaceId}/uploads/{upload_reservation_id}/finalize`
- **Request Body:**
  ```json
  {
    "provider_file_id": "1A2B3C4D5E6F7G8H9I0J",
    "checksum_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  }
  ```
- **Response (200):**
  ```json
  {
    "success": true,
    "cloud_file_id": "cf_1029384756",
    "status": "ACTIVE",
    "file_name": "2T_4K_IMG_001.png",
    "file_size_bytes": 15728640
  }
  ```

### 5. Hủy phiên tải lên & Giải phóng Quota đã giữ
- **Endpoint:** `POST /spaces/{spaceId}/uploads/{upload_reservation_id}/abort`
- **Request Body:**
  ```json
  {
    "reason": "Upload cancelled by user or network aborted"
  }
  ```
- **Response (200):**
  ```json
  {
    "success": true,
    "message": "Quota reservation released successfully."
  }
  ```

### 6. Khôi phục tệp từ Cloud về máy tính
- **Endpoint:** `GET /files/{cloud_file_id}/download`
- Hỗ trợ tiêu đề `Range: bytes=START-END` cho phép tải theo phân đoạn mượt mà.

---

## 📋 10. CHECKLIST TRIỂN KHAI VÀ KIỂM THỬ DÀNH CHO APP AGENT

Trước khi bàn giao bản build Desktop mới, hãy thực hiện kiểm thử theo checklist sau:

- [ ] **1. Cơ sở dữ liệu SQLite:** Chạy script cập nhật bảng `cloud_backup_jobs`, bổ sung cột `cloud_space_id` và `auto_cloud_backup` vào bảng `projects`.
- [ ] **2. Tách biệt GPU:** Chạy thử batch 5 ảnh $\rightarrow$ Xác nhận tiến trình upscale GPU hoàn tất và lưu file ra ổ đĩa bình thường, không bị dừng hay chậm lại bởi luồng upload mạng.
- [ ] **3. Upload trực tiếp Google Drive:** Xác nhận file được truyền trực tiếp từ Desktop lên Google qua `resumable_session_url` theo từng chunk 4MB, không gửi dữ liệu nhị phân qua hosting `2tamne.site`.
- [ ] **4. Kiểm thử mất mạng (Resume Engine):** Khi đang upload tệp 100MB ở 50% $\rightarrow$ Ngắt kết nối WiFi $\rightarrow$ Bật lại WiFi $\rightarrow$ Xác nhận app gửi PUT rỗng truy vấn byte offset và truyền tiếp từ 50%, không bị upload lại từ đầu.
- [ ] **5. Kiểm thử Over-Quota:** Thử nghiệm với Không gian bị quá hạn mức $\rightarrow$ Xác nhận API trả về `409 QUOTA_EXCEEDED` $\rightarrow$ Job chuyển `PAUSED_OVER_QUOTA` $\rightarrow$ GPU vẫn upscale ảnh xong an toàn $\rightarrow$ App hiển thị thông báo Toast lịch sự, không crash.
- [ ] **6. Bộ chọn Space:** Kiểm tra dropdown chọn Space trong Modal thiết lập dự án hiển thị đúng danh sách Personal và Team Space.
- [ ] **7. Khôi phục 1 chạm:** Xóa thử file ảnh thành phẩm trên máy $\rightarrow$ Bấm "Khôi phục từ Cloud" $\rightarrow$ Xác nhận ảnh được tải về đúng vị trí thư mục cũ.
