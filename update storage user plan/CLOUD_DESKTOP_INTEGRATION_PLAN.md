# 2TOOLNE CLOUD — TÍCH HỢP ỨNG DỤNG MÁY KHÁCH DESKTOP (DESKTOP INTEGRATION PLAN V2)
**Tài liệu:** `docs/CLOUD_DESKTOP_INTEGRATION_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật:** V2 Architecture Update (Cloud Space Support, Space Selector, Over-Quota Graceful Pausing, Team App Key / Device Binding)  
**Phạm vi:** Ứng dụng Desktop 2TOOLNE Upscale (`apps/desktop`) & Slideshow Studio  
**Triết lý cốt lõi:** Tách rời hoàn toàn Hàng đợi Xử lý AI khỏi Hàng đợi Sao lưu Đám mây. Không biến ứng dụng desktop thành bản sao cồng kềnh của Google Drive, ưu tiên quy trình sao lưu dự án mượt mà, hỗ trợ chọn Không gian lưu trữ (Personal vs Team) và tự động khôi phục dữ liệu.

---

## 1. TÁCH BIỆT HAI HÀNG ĐỢI ĐỘC LẬP (SEPARATION OF CONCERNS)

> [!IMPORTANT]
> **TIÊU CHUẨN TRẢI NGHIỆM NGƯỜI DÙNG:**
> - Việc tải file lên đám mây **tuyệt đối không được làm nghẽn hoặc làm chậm tiến trình Upscale AI trên GPU**.
> - Ngay khi tiến trình Upscale xử lý xong một ảnh hoặc một batch, tệp tin xuất ra tại thư mục cục bộ (`output_path`) phải **khả dụng ngay lập tức** để người dùng có thể xem và sử dụng ngay.
> - Tác vụ đẩy bản sao lưu lên đám mây được chuyển sang **Hàng đợi Sao lưu Đám mây (Cloud Backup Queue)** chạy ngầm ở luồng riêng.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    TIẾN TRÌNH CHÍNH (MAIN PROCESS)                          │
├──────────────────────────────────────┬──────────────────────────────────────┤
│       1. HÀNG ĐỢI XỬ LÝ GPU          │       2. HÀNG ĐỢI SAO LƯU CLOUD      │
│      (QueueManager - Tác vụ nặng)    │     (CloudBackupQueue - Chạy nền)    │
├──────────────────────────────────────┼──────────────────────────────────────┤
│ • Điều phối GPU AI Model (Real-ESRGAN│ • Gắn chặt theo Cloud Space được chọn│
│   / Compact / Ultra-Sharp).          │ • Gửi yêu cầu xin phiên upload.      │
│ • Quản lý Token (Reserve / Commit).  │ • Truyền luồng tệp tin trực tiếp tới │
│ • Xuất ảnh thành phẩm vào ổ đĩa.     │   Google Resumable Session URI.      │
│ ──> Xong: Báo "Hoàn tất" cục bộ.     │ • Tự động tiếp tục khi rớt mạng.     │
│                                      │ ──> Xong: Cập nhật cờ "Đã sao lưu".  │
└──────────────────────────────────────┴──────────────────────────────────────┘
```

---

## 2. CẤU TRÚC BẢNG HÀNG ĐỢI CLOUD CỤC BỘ (LOCAL SQLITE V2)

Trong cơ sở dữ liệu SQLite cục bộ (`database/local-sqlite/schema.sql`), bảng `cloud_backup_jobs` được thiết kế có trường `cloud_space_id` để định danh chính xác Không gian lưu trữ đích:

```sql
CREATE TABLE IF NOT EXISTS cloud_backup_jobs (
    id TEXT PRIMARY KEY,                       -- UUID v4 (cbj_xxx)
    project_id TEXT NOT NULL,                  -- Liên kết dự án upscale
    cloud_space_id TEXT NOT NULL,              -- Personal Space hoặc Team Space đích
    local_file_path TEXT NOT NULL,             -- Đường dẫn file ảnh/video trên máy tính
    target_folder_path TEXT NOT NULL,          -- Thư mục ảo trên Cloud (ví dụ /Upscale/Project_A)
    file_name TEXT NOT NULL,
    file_size_bytes INTEGER NOT NULL,
    file_hash_sha256 TEXT,
    status TEXT NOT NULL DEFAULT 'PENDING',    -- PENDING, PREPARING, UPLOADING, PAUSED_OVER_QUOTA, COMPLETED, FAILED
    upload_reservation_id TEXT,
    resumable_session_url TEXT,
    bytes_uploaded INTEGER NOT NULL DEFAULT 0,
    retry_count INTEGER NOT NULL DEFAULT 0,
    last_error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cloud_jobs_status ON cloud_backup_jobs(status);
CREATE INDEX IF NOT EXISTS idx_cloud_jobs_space ON cloud_backup_jobs(cloud_space_id);
```

---

## 3. VÒNG ĐỜI VÀ TRẠNG THÁI HÀNG ĐỢI CLOUD (CLOUD QUEUE LIFECYCLE V2)

```
[Ảnh xuất xong cục bộ]
          │
          ▼
    CLOUD_PENDING ─────────► CLOUD_PAUSED (Người dùng tạm dừng thủ công)
          │
          ▼
    CLOUD_PREPARING ──(Tạo phiên upload với API theo cloud_space_id)
          │
          ├───► CLOUD_PAUSED_OVER_QUOTA (Nếu Space bị đầy / 409 QUOTA_EXCEEDED)
          │
          ▼
    CLOUD_UPLOADING ──(Truyền dữ liệu nhị phân trực tiếp theo khối 4MB)
          │
          ├────────► CLOUD_FAILED (Rớt mạng quá 5 lần ──> Bounded Backoff)
          │
          ▼
    CLOUD_VERIFYING ──(Gọi Finalize kiểm tra khớp kích thước)
          │
          ▼
    CLOUD_COMPLETE  ──(Đã sao lưu an toàn trên đám mây)
```

### 3.1. Xử Lý Tình Huống Vượt Hạn Mức Dung Lượng (Over-Quota Graceful Handling)
- Khi Cloud Space đích bị quá hạn mức (`OVER_QUOTA`):
  - API trả về mã lỗi HTTP `409 QUOTA_EXCEEDED`.
  - Hàng đợi chuyển trạng thái job thành `CLOUD_PAUSED_OVER_QUOTA`.
  - **Tiến trình AI GPU vẫn hoàn tất 100%, tệp thành phẩm vẫn nằm an toàn trên ổ cứng máy tính.**
  - Giao diện Desktop hiển thị thông báo nhẹ nhàng (Toast / Non-intrusive alert):  
    *“⚠️ Không thể sao lưu lên Cloud: Không gian lưu trữ đã đầy. Tệp ảnh đã được lưu an toàn trên máy tính của bạn. Hãy dọn dẹp dung lượng Cloud hoặc liên hệ quản trị viên.”*
  - Người dùng vẫn có thể xem trước, tải xuống và khôi phục các tệp cũ bình thường.

---

## 4. CƠ CHẾ TỰ ĐỘNG TIẾP TỤC TẢI LÊN KHI GẶP SỰ CỐ (RESUME ENGINE)

1. **Lưu trữ trạng thái bền vững:** Mỗi khi truyền thành công một khối (ví dụ 4 MB), worker cập nhật ngay số byte đã chuyển (`bytes_uploaded`) vào bảng `cloud_backup_jobs` trong SQLite cục bộ.
2. **Khởi động lại ứng dụng:** Khi ứng dụng Desktop mở lại, hàm `recoverInterruptedCloudJobs()` tự động kiểm tra các bản ghi đang ở trạng thái `CLOUD_UPLOADING`.
3. **Thăm dò vị trí byte (Google Query Status):**
   - Gửi yêu cầu HTTP `PUT` với header rỗng `Content-Range: bytes */{total_bytes}` tới `resumable_session_url`.
   - Google Drive API trả về mã `HTTP 308 Resume Incomplete` kèm header `Range: bytes=0-{last_byte}`.
   - Ứng dụng chỉ cần đọc file từ byte `{last_byte + 1}` và truyền tiếp. **Không cần tải lại từ đầu.**

---

## 5. TÍCH HỢP GIAO DIỆN NGƯỜI DÙNG DESKTOP (DESKTOP UI INTEGRATION V2)

### 5.1. Thiết Lập Dự Án & Chọn Không Gian Lưu Trữ (Project Settings Modal)
Trong `NewProjectModal.tsx` và `ProjectDetailModal.tsx`, bổ sung bộ chọn Không gian lưu trữ Cloud Space:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ☁️ Tự Động Sao Lưu Đám Mây (2toolne Cloud)                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│ [✓] Bật tự động sao lưu kết quả sau khi xử lý thành công                    │
│ [ ] Tải cả tệp tin nguồn gốc (Raw Input Files)                              │
│                                                                             │
│ Không gian lưu trữ đích:                                                    │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ 👥 Team Wedding Pro (35.2 GB / 50.0 GB khả dụng)                      ▼ │ │
│ ├─────────────────────────────────────────────────────────────────────────┤ │
│ │   👤 Cá nhân - Personal Space (12.4 GB / 25.0 GB)                       │ │
│ │ ✓ 👥 Team Wedding Pro (35.2 GB / 50.0 GB)                               │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                             │
│ Thư mục lưu trữ: 📁 /Upscale/2026_09_Wedding_Batch_01                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2. Thanh Trạng Thái & Huy Hiệu Không Gian (Header Quota Badge)
- Trong `Header.tsx`: Hiển thị huy hiệu dung lượng theo Không gian đang chọn:  
  `☁️ Team Wedding Pro: 35.2 / 50.0 GB`.
- Khi bấm vào: Mở cửa sổ nhỏ (Popover) hiển thị:
  - Tiến độ tải lên hiện tại (`Đang sao lưu: 7 / 20 ảnh · 45%`).
  - Nút Tạm dừng / Tiếp tục.
  - Cảnh báo Over-Quota nếu không gian bị vượt mức.

### 5.3. Khôi Phục Dữ Liệu 1 Bấm (One-Click Restore)
- Trong `ProjectsView.tsx`: Nếu người dùng vô tình xóa thư mục xuất ảnh trên ổ cứng máy tính, nút **"☁️ Khôi Phục Từ Cloud"** sẽ xuất hiện cho phép tải lại toàn bộ ảnh thành phẩm đã lưu trữ về máy tính chỉ với 1 cú click chuột từ đúng Không gian lưu trữ của dự án.

---

## 6. MÔ HÌNH BẢN QUYỀN VÀ GHÉP NỐI THIẾT BỊ CHO TEAM (APP KEY & DEVICE BINDING)

Khi khách hàng sử dụng gói Team (ví dụ Team Starter với 2 App Keys / 2 Ghế làm việc):
1. **Kích hoạt máy tính thành viên:**
   - Thành viên mở 2TOOLNE Desktop App $\rightarrow$ Nhập App Key của Team (hoặc đăng nhập tài khoản thuộc Team).
   - Desktop App gửi HWID phần cứng tới `/api/v1/licenses/activate`.
   - Backend kiểm tra số thiết bị đang kích hoạt trong bảng `devices` của bản quyền Team (`COUNT(devices) < max_devices`).
   - Nếu còn ghế trống: Hệ thống đăng ký HWID vào bảng `devices`, cấp chữ ký HMAC 72 giờ và trả về thông tin các Cloud Space mà người dùng có quyền truy cập (bao gồm Personal Space của họ và Team Space).
2. **Không phát sinh hệ thống cấp phép mới (`MIGRATION_REQUIRED = NO`):**
   - Giữ nguyên cấu trúc `devices` và `license_entitlements`.
   - Mỗi "App Key" của Team tương đương với 1 thiết bị kích hoạt hợp lệ (`max_devices`) trong bảng `devices`.
   - Không cần chạy migration thay đổi cấu trúc bảng kích hoạt thiết bị hiện hữu.
