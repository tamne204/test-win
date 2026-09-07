# PHIẾU GIAO VIỆC TỔNG HỢP & BÀN GIAO KỸ THUẬT TOÀN DIỆN CHO AGENT QUẢN LÝ APP DESKTOP
## MASTER TASK ASSIGNMENT & SYSTEM INTEGRATION SPECIFICATION (2TOOLNE UPSCALE STUDIO)

**Mã tài liệu:** `docs/GIAO_VIEC_TONG_HOP_APP_UPSCALE_CLOUD_VA_LOG.md`  
**Dành cho:** Agent Quản Lý & Phát Triển Ứng Dụng Desktop (`2TOOLNE Upscale & Slideshow Studio`)  
**Người giao việc:** Lead Systems Architect & Trưởng Nhóm Dự Án Trung Tâm (`2tamne.site`)  
**Phiên bản chuẩn:** `2.1.0-MASTER-HANDOVER`  
**Ngày phát hành:** 05/09/2026  
**Đường dẫn thư mục App Desktop:** `/Users/2tamne/Documents/toolupscale/`

---

## 🎯 TỔNG QUAN NHIỆM VỤ DÀNH CHO DESKTOP AGENT

Để đảm bảo sản phẩm Desktop App vận hành trơn tru, đồng bộ tuyệt đối với Hệ thống Máy chủ & Web Trung tâm (`2tamne.site`), Agent Quản lý Desktop App cần nắm vững và duy trì **02 Trụ Cột Kỹ Thuật Trọng Tâm**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│               02 TRỤ CỘT KỸ THUẬT DESKTOP APP CẦN ĐỒNG BỘ                   │
├──────────────────────────────────────┬──────────────────────────────────────┤
│    TRỤ CỘT 1: CLOUD STORAGE V2       │    TRỤ CỘT 2: LOG TOKEN CHI TIẾT    │
│  (Lưu Trữ Đám Mây Google Drive)      │   (Tên Ảnh + Upscale 4K / 2K)        │
├──────────────────────────────────────┼──────────────────────────────────────┤
│ • Hàng đợi nền `CloudBackupQueue`.   │ • Trích xuất `fileName` & `res`.     │
│ • Không làm nghẽn tiến trình GPU.   │ • Truyền vào `commitTokens(...)`.    │
│ • Direct Resumable Upload Google.   │ • Tự động suy diễn nếu thiếu tham số.│
│ • Quản lý hạn mức Quota & UI Space.  │ • Đồng bộ Badge 🚀 4K và ⚡ 2K.     │
└──────────────────────────────────────┴──────────────────────────────────────┘
```

---

## ☁️ PHẦN A: ĐẶC TẢ TÍCH HỢP CLOUD STORAGE V2 (GOOGLE DRIVE MULTI-POOL)

### 1. Nguyên Tắc Cốt Lõi Bất Di Bất Dịch (Core Principles)
1. **Zero GPU Blocking (Tuyệt đối không chặn luồng GPU AI):**
   - Quá trình tải ảnh lên Google Drive chạy trên hàng đợi nền riêng biệt (`CloudBackupQueue`).
   - Khi GPU hoàn thành ảnh cục bộ (`output_path`), ảnh đó phải khả dụng ngay cho người dùng. Không được đợi upload Cloud xong mới báo hoàn thành.
2. **Local First (Dữ liệu cục bộ an toàn trên hết):**
   - Mọi ảnh hoàn tất luôn nằm an toàn trên ổ cứng máy tính khách hàng. Lỗi mạng hay lỗi Cloud tuyệt đối không làm mất ảnh.
3. **Direct Resumable Upload (Truyền nhị phân trực tiếp không qua Hosting):**
   - Desktop App gọi 2TOOLNE API để lấy `resumable_session_url` của Google Drive.
   - Gửi các khối nhị phân 4MB (chunks) trực tiếp 100% lên máy chủ Google. Tuyệt đối không đẩy luồng nhị phân qua hosting `2tamne.site`.

### 2. Cơ Sở Dữ Liệu SQLite Cục Bộ (`database/local-sqlite/`)
Cần đảm bảo 2 bảng SQLite cục bộ luôn sẵn sàng:
* **`cloud_backup_jobs`:**
  - `id` (TEXT PRIMARY KEY): Mã job sao lưu (UUID v4).
  - `project_id`, `project_item_id`, `cloud_space_id`.
  - `local_file_path`, `target_folder_path`, `file_name`, `file_size_bytes`.
  - `status`: `PENDING`, `UPLOADING`, `COMPLETED`, `PAUSED_OVER_QUOTA`, `FAILED`.
  - `resumable_session_url`, `bytes_uploaded`, `retry_count`.
* **`cloud_spaces_cache`:**
  - `space_id`, `space_name`, `effective_quota_bytes`, `used_bytes`, `is_over_quota`.

### 3. Quy Trình Vận Hành 3 Bước Của `CloudBackupQueue`
**File nguồn:** `apps/desktop/src/main/cloud_backup_queue.ts`
1. **Bước 1: Khởi tạo phiên Upload:**
   - Gửi `POST https://www.2tamne.site/api/v1/cloud/spaces/{spaceId}/uploads/create`
   - Nhận về `upload_reservation_id` và `resumable_session_url`.
2. **Bước 2: Upload từng khối nhị phân 4MB trực tiếp tới Google:**
   - Chunk Size: `4 * 1024 * 1024` bytes (bội số của 256 KB).
   - Gửi `PUT {resumable_session_url}` với `Content-Range: bytes {start}-{end}/{total}`.
   - Nhận `HTTP 308 Resume Incomplete` -> tiếp tục khối kế tiếp.
   - Nhận `HTTP 200/201` -> hoàn tất tải file lên Google, lấy `google_file_id`.
3. **Bước 3: Hoàn tất & Kích hoạt trên Server:**
   - Gửi `POST https://www.2tamne.site/api/v1/cloud/spaces/{spaceId}/uploads/{reservationId}/finalize`
   - Kèm `google_file_id` và `sha256` để máy chủ kích hoạt tệp tin sang trạng thái `ACTIVE`.

### 4. Xử Lý Khi Quá Dung Lượng (Over-Quota Handling)
- Khi API trả về `QUOTA_EXCEEDED`, Desktop App đánh dấu job `PAUSED_OVER_QUOTA`.
- **GPU Upscale tiếp tục chạy bình thường đến hết danh sách ảnh.**
- Hiển thị thông báo nhẹ (Toast) nhắc nhở người dùng giải phóng bộ nhớ hoặc nâng cấp gói trên Web.

---

## 🪙 PHẦN B: ĐẶC TẢ ĐỒNG BỘ LOG CHI TIẾT TÊN ẢNH & UPSCALE 4K / 2K

### 1. Bối Cảnh & Mục Tiêu Nghiệp Vụ
- Trước đây: Cột Loại ghi chung `UPSCALE`, Cột Mô tả ghi cứng `"Local image upscale completed"`.
- Mục tiêu mới:
  - Cột Loại: Hiển thị badge riêng biệt **`🚀 UPSCALE 4K`** (trừ 2 token) hoặc **`⚡ UPSCALE 2K`** (trừ 1 token).
  - Cột Mô tả: Ghi rõ cú pháp: `[Tên tệp tin gốc] [4K/2K] upscale completed` (ví dụ: `DSC_0012.JPG 4K upscale completed`).

### 2. Các Thay Đổi Đã Triển Khai Trên Hệ Thống Trung Tâm
- **MySQL Database Production:** Cột `credit_transactions.type` đã đổi thành `VARCHAR(64)` và toàn bộ 220 bản ghi cũ đã được chuẩn hóa.
- **Backend API (`POST /api/v1/credits/commit`):**
  - Đã hỗ trợ tiếp nhận `file_name` (hoặc `filename`) và `resolution` (`4K` hoặc `2K`).
  - Có fallback thông minh: Tự động nhận diện từ số token đã trừ (`committed_amount == 2 ? '4K' : '2K'`) nếu thiếu tham số.
- **Giao diện Web:** Web Admin (`license_admin.php`) và Web User (`index.php`) đã tích hợp badge màu tím hoàng gia và xanh dương tương ứng.

### 3. Cập Nhật Bắt Buộc Trong Mã Nguồn App Desktop
1. **`apps/desktop/src/main/license_client.ts`:**
   Phương thức `commitTokens` mở rộng nhận thêm `fileName` và `resolution`:
   ```typescript
   public async commitTokens(
     reservationId: string,
     projectId: string,
     tokensToCommit: number,
     idempotencyKey: string,
     fileName?: string,
     resolution?: string
   ): Promise<{ success: boolean; error?: string }> {
     // Đưa fileName và resolution vào payload gửi tới /api/v1/credits/commit
   }
   ```
2. **`apps/desktop/src/main/queue_manager.ts`:**
   Tại sự kiện `DETAIL_IMAGE_COMPLETED`:
   ```typescript
   const targetRes = existingProject?.target_resolution || '4K';
   const tokenPerImage = existingProject ? TOKEN_COSTS[existingProject.target_resolution] : 2;
   const projectItem = this.dbRepo.getProjectItem(event.item_id);
   const fileName = (event as any).current_filename || projectItem?.filename || '';

   await this.licenseClient.commitTokens(
     this.activeReservationId,
     event.project_id,
     tokenPerImage,
     idempotencyKey,
     fileName,
     targetRes
   );
   ```

---

## 📋 PHẦN C: MA TRẬN KIỂM THỬ & TIÊU CHÍ NGHIỆM THU (ACCEPTANCE MATRIX)

Agent quản lý Desktop App thực hiện nghiệm thu theo bảng checklist sau:

| STT | Hạng mục kiểm tra | Tiêu chí đạt chuẩn | Lệnh / Phương pháp xác minh |
| :---: | :--- | :--- | :--- |
| 1 | **Unit Test Cục Bộ** | 100% test files & test cases passed (25/25) | `cd /Users/2tamne/Documents/toolupscale && npm run test:unit` |
| 2 | **Không Nghẽn GPU** | GPU hoàn tất ảnh là xuất file ngay, upload Cloud chạy ngầm | Kiểm tra luồng `DETAIL_IMAGE_COMPLETED` độc lập với `CloudBackupQueue` |
| 3 | **Đầy Đủ Payload Token** | Gửi lên server có đủ `file_name` và `resolution` | Kiểm tra request body tại `license_client.ts` |
| 4 | **Độ Bền Offline** | Rớt mạng không làm crash app, tự sync khi có mạng | Bắt `try/catch` tại `commitTokens` và ghi nhận local ledger |
| 5 | **Đồng Bộ UI Web** | Hiển thị badge 🚀 4K / ⚡ 2K và tên ảnh chuẩn xác | Xem bảng Token History trên `license_admin.php` và `index.php` |

---

## 📌 PHỤ LỤC: DANH SÁCH TẬP TIN LIÊN QUAN

- Hướng dẫn Cloud chi tiết chuyên sâu: `docs/CLOUD_APP_AGENT_INTEGRATION_GUIDE.md`
- Đặc tả kiến trúc tổng thể: `docs/ARCHITECTURE.md`
- Mã nguồn License Client: `apps/desktop/src/main/license_client.ts`
- Mã nguồn Queue Manager: `apps/desktop/src/main/queue_manager.ts`
- Mã nguồn Cloud Backup Queue: `apps/desktop/src/main/cloud_backup_queue.ts`
- SQLite Repository: `database/local-sqlite/repository.ts`
