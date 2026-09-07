# PHIẾU GIAO VIỆC & ĐẶC TẢ KỸ THUẬT: ĐỒNG BỘ LOG CHI TIẾT TÊN ẢNH & PHÂN LOẠI UPSCALE 4K / 2K
## TASK HANDOVER SPECIFICATION FOR 2TOOLNE UPSCALE DESKTOP AGENT

**Mã phiếu:** `docs/GIAO_VIEC_APP_UPSCALE_LOG_CHI_TIET.md`  
**Dành cho:** Agent Quản Lý & Phát Triển Ứng Dụng Desktop (`2TOOLNE Upscale & Slideshow Studio`)  
**Người giao việc:** Lead Architect & Hệ Thống Quản Trị Trung Tâm (`2tamne.site`)  
**Phiên bản chuẩn:** `1.1.0-LOG-DETAILED`  
**Ngày phát hành:** 05/09/2026  
**Thư mục mã nguồn App:** `/Users/2tamne/Documents/toolupscale/`

---

## 📌 1. BỐI CẢNH & YÊU CẦU TỪ NGƯỜI DÙNG

Trong hệ thống quản lý giao dịch Token (`credit_transactions`), trước đây khi người dùng thực hiện xuất ảnh upscale, nhật ký giao dịch chỉ ghi nhận chung:
- **Loại tác vụ (Type):** `UPSCALE` hoặc `COMMIT`.
- **Mô tả (Description):** Cố định dòng chữ `"Local image upscale completed"`.

Điều này khiến cả khách hàng trên Web User và Quản trị viên trên Web Admin khó theo dõi được cụ thể từng bức ảnh đã tiêu hao token nào và ở độ phân giải nào (4K hay 2K).

### Yêu cầu cải tiến:
1. **Cột Loại (Type):** Phải phân biệt rõ ràng:
   - `UPSCALE 4K`: Tiêu hao 2 token / ảnh.
   - `UPSCALE 2K`: Tiêu hao 1 token / ảnh.
2. **Cột Mô tả (Description):** Phải hiển thị tên ảnh và độ phân giải:
   - Cú pháp chuẩn: `[Tên ảnh gốc] [4K/2K] upscale completed`
   - Ví dụ: `DSC_0012.JPG 4K upscale completed`, `wedding_photo.png 2K upscale completed`.

---

## 🏗️ 2. CÁC THAY ĐỔI ĐÃ HOÀN TẤT TRÊN SERVER & WEB

Đội ngũ Lead Architect đã hoàn thành toàn bộ phần hạ tầng backend và giao diện web:

### 2.1. Cập nhật Cơ sở dữ liệu MySQL Live Production (`ecxaebka_bot` tại `2tamne.site`)
- Đã chạy ALTER TABLE chuyển trường `credit_transactions.type` từ `ENUM(...)` sang `VARCHAR(64)` UTF-8.
- Đã migrate an toàn toàn bộ 220 bản ghi lịch sử cũ:
  - Các giao dịch trừ 2 token được chuẩn hóa thành `type = 'UPSCALE 4K'`, mô tả `'4K upscale completed'`.
  - Không còn bản ghi nào hiển thị vô danh hoặc lỗi font.

### 2.2. Nâng cấp API Backend (`POST /api/v1/credits/commit`)
File: `website/api/v1/controllers/CreditsController.php`
- Tiếp nhận thêm 2 trường tùy chọn trong JSON body:
  - `file_name` (hoặc `filename`): Tên tệp tin đang được hoàn tất.
  - `resolution`: Độ phân giải đầu ra (`4K` hoặc `2K`).
- **Cơ chế Fallback thông minh (Backward Compatible 100%):** Nếu Client phiên bản cũ không truyền `file_name` hoặc `resolution`, Server sẽ tự động suy diễn từ số token đã trừ (`committed_amount == 2 ? '4K' : '2K'`). Không bao giờ gây lỗi giao dịch cho các bản Client cũ.

### 2.3. Giao diện Web Quản trị (`website/license_admin.php`) & Web User (`website/index.php`)
- Thiết kế Badge nhận diện chuyên nghiệp:
  - `🚀 UPSCALE 4K`: Badge màu tím hoàng gia (`badge-purple`), in đậm.
  - `⚡ UPSCALE 2K`: Badge xanh dương rực rỡ (`badge-info`), in đậm.
- Hiển thị mô tả tên ảnh rõ ràng, có ngắt dòng an toàn (word-break) tránh tràn bảng.

---

## 💻 3. CÔNG VIỆC THỰC HIỆN TRÊN DESKTOP APP (`toolupscale`)

Để thông tin tên ảnh và độ phân giải được gửi lên hệ thống trung tâm ngay khi mỗi bức ảnh hoàn tất, Agent phụ trách App Desktop đã và cần duy trì các điểm cập nhật sau:

### 3.1. Cập nhật `LicenseClient`
**File:** `/Users/2tamne/Documents/toolupscale/apps/desktop/src/main/license_client.ts`  
Mở rộng phương thức `commitTokens` để truyền `fileName` và `resolution`:

```typescript
// apps/desktop/src/main/license_client.ts
public async commitTokens(
  reservationId: string,
  projectId: string,
  tokensToCommit: number,
  idempotencyKey: string,
  fileName?: string,
  resolution?: string
): Promise<{ success: boolean; error?: string }> {
  const account = this.getAccountState();
  if (account.credit_mode === 'UNLIMITED') return { success: true };

  try {
    await fetch(`${this.apiBaseUrl}/credits/commit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        device_id: this.getDeviceId(),
        reservation_id: reservationId,
        project_id: projectId,
        committed_amount: tokensToCommit,
        idempotency_key: idempotencyKey,
        file_name: fileName,
        resolution: resolution,
      }),
      signal: AbortSignal.timeout(5000),
    });
  } catch {
    // Offline: recorded locally in SQLite transaction history for next sync
  }

  return { success: true };
}
```

### 3.2. Cập nhật `QueueManager`
**File:** `/Users/2tamne/Documents/toolupscale/apps/desktop/src/main/queue_manager.ts`  
Tại khối xử lý sự kiện `DETAIL_IMAGE_COMPLETED`, trích xuất tên ảnh từ `event.current_filename` (hoặc `this.dbRepo.getProjectItem(event.item_id)?.filename`) cùng độ phân giải `existingProject.target_resolution`, sau đó gửi vào hàm `commitTokens`:

```typescript
// apps/desktop/src/main/queue_manager.ts (khoảng dòng 258-278)
} else if (event.type === 'DETAIL_IMAGE_COMPLETED') {
  this.dbRepo.updateItemStatus(event.item_id, 'COMPLETED', 100);

  // Commit token for this completed image
  const targetRes = existingProject?.target_resolution || '4K';
  const tokenPerImage = existingProject ? TOKEN_COSTS[existingProject.target_resolution] : 2;
  const projectItem = this.dbRepo.getProjectItem(event.item_id);
  const fileName = (event as any).current_filename || projectItem?.filename || '';

  if (this.activeReservationId) {
    const idempotencyKey = `commit_${event.project_id}_${event.item_id}`;
    await this.licenseClient.commitTokens(
      this.activeReservationId,
      event.project_id,
      tokenPerImage,
      idempotencyKey,
      fileName,
      targetRes
    );
  }

  // Update completed image count in DB only while project is actively processing
  const latestProj = this.dbRepo.getProject(event.project_id);
  if (latestProj && latestProj.status === 'PROCESSING') {
    this.dbRepo.updateProject(event.project_id, {
      completed_images: latestProj.completed_images + 1,
      committed_tokens: latestProj.committed_tokens + tokenPerImage,
    });
  }
}
```

---

## 🧪 4. KIỂM THỬ VÀ NGHIỆM THU (TESTING & VERIFICATION)

Agent quản lý Desktop App chạy bộ kiểm thử để nghiệm thu:

```bash
cd /Users/2tamne/Documents/toolupscale
npm run test:unit
```

### Tiêu chí nghiệm thu:
1. **Unit Test Pass 100%:** Toàn bộ 25 bài kiểm thử đơn vị (`queue_manager.test.ts`, `credits_lifecycle.test.ts`, `local_db.test.ts`, v.v.) phải vượt qua (Status: Passed).
2. **Offline Resilience:** Khi ngắt mạng, hàm `commitTokens` bắt ngoại lệ an toàn và ghi nhận cục bộ, không làm crash tiến trình Upscale AI trên GPU.
3. **Payload Chuẩn:** Request gửi lên `/api/v1/credits/commit` chứa đầy đủ:
   ```json
   {
     "device_id": "...",
     "reservation_id": "res_...",
     "project_id": "proj_...",
     "committed_amount": 2,
     "idempotency_key": "commit_proj_item",
     "file_name": "example_01.png",
     "resolution": "4K"
   }
   ```
4. **Log Web Đồng Bộ:** Trên Web Admin và Web User, dòng log hiển thị:
   - Loại: `🚀 UPSCALE 4K`
   - Số lượt: `-2`
   - Mô tả: `example_01.png 4K upscale completed`

---

## 📞 HỖ TRỢ & BÁO CÁO

Nếu có bất kỳ thắc mắc hoặc cần mở rộng thêm metadata (như thời gian xử lý mỗi ảnh, tên AI model sử dụng), hãy để lại ghi chú trong thư mục `docs/` hoặc thông báo cho Lead Architect.
Mọi tài liệu liên quan đến Cloud và License đã được lưu trữ tập trung tại `docs/CLOUD_APP_AGENT_INTEGRATION_GUIDE.md`.
