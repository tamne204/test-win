# 2TOOLNE CLOUD — GIAO DIỆN TRÌNH QUẢN LÝ TỆP TRÊN WEB (WEB FILE MANAGER & ADMIN UI V2)
**Tài liệu:** `docs/CLOUD_WEB_INTEGRATION_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật:** V2 Architecture Update (Space Switcher Dropdown, Shared Team View, Over-Quota Warning Banner, Admin Storage Pool & Quota Adjustment UI)  
**Quy chuẩn cốt lõi:** Tuân thủ 100% Nguyên Tắc Cổng Hợp Nhất (Unified Single Portal) tại `website/index.php` (Khách hàng) và `website/license_admin.php` (Quản trị viên). **Tuyệt đối không sinh thêm các trang HTML mồ côi (No orphan HTML files).**  
**Hệ thống thiết kế:** Dark Mode Raycast / Linear phong cách đồng nhất trong `website/globals.css`.

---

## 1. NGUYÊN TẮC TÍCH HỢP VÀ ĐIỀU HƯỚNG

- Toàn bộ trải nghiệm Trình quản lý tệp tin 2TOOLNE Cloud được tích hợp thành một Tab chính mới tại `index.php`:  
  `<div id="tab-cloud-storage" class="tab-pane" style="display:none">`
- **Menu điều hướng Desktop (Sidebar Navigation):**  
  Thêm nút: `<button class="dash-nav-btn" id="btn-tab-cloud" onclick="switchMainTab('tab-cloud-storage')"><span>☁️</span> 2toolne Cloud</button>`
- **Menu điều hướng Di động (Mobile Drawer):**  
  Thêm liên kết: `<a href="#tab-cloud-storage" class="mobile-nav-link" onclick="mobileSwitchTab('tab-cloud-storage')">☁️ 2toolne Cloud</a>`
- **URL Hash Routing:** Hỗ trợ truy cập trực tiếp qua liên kết `https://www.2tamne.site/#cloud`.

---

## 2. GIAO DIỆN KHÁCH HÀNG VỚI BỘ CHUYỂN ĐỔI KHÔNG GIAN (SPACE SWITCHER & FILE MANAGER)

### 2.1. Thiết Kế Khung Giao Diện Chính
```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ ☁️ 2toolne Cloud   [ 👥 Team Wedding Pro (35.2 / 50.0 GB) ▼ ]  Dung lượng: [====--]│
├──────────────────────────────────────────────────────────────────────────────────┤
│ ⚠️ [BANNER CẢNH BÁO NẾU VƯỢT HẠN MỨC]: Không gian lưu trữ đã vượt hạn mức.       │
│    Bạn vẫn có thể tải xuống và xóa tệp, tính năng tải lên tạm dừng.              │
├──────────────────────────────────────────────────────────────────────────────────┤
│ [ 📁 Team Wedding Pro > 📂 Upscale > 📂 Batch_01 ]                               │
│                                                                                  │
│ [ + Tải Tệp Lên ]  [ + Tạo Thư Mục ]   [ 🔍 Tìm kiếm... ]   [ ⊞ Lưới | ☰ Danh sách]│
├──────────────────────────────────────────────────────────────────────────────────┤
│ THƯ MỤC CON:                                                                     │
│ 📁 Chụp Ngoại Cảnh (45 tệp)        📁 Trong Nhà (28 tệp)                        │
│                                                                                  │
│ TỆP TIN CHIA SẺ TRONG TEAM:                                                      │
│ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐     │
│ │ [Preview Image]│ │ [Preview Image]│ │ [Preview Image]│ │ [Video Icon]   │     │
│ │ wedding_01.png │ │ wedding_02.png │ │ wedding_03.png │ │ teaser.mp4     │     │
│ │ 14.2 MB        │ │ 15.8 MB        │ │ 16.1 MB        │ │ 210.5 MB       │     │
│ │ 👤 Tuấn Anh    │ │ 👤 Hoàng Minh  │ │ 👤 Tuấn Anh    │ │ 👤 Mai Linh    │     │
│ │ [👁][⬇][✏][🗑] │ │ [👁][⬇][✏][🗑] │ │ [👁][⬇][✏][🗑] │ │ [👁][⬇][✏][🗑] │     │
│ └────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘     │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 🗑️ Thùng rác (3 tệp đang chờ xóa sau 30 ngày)                  [ Dọn sạch thùng rác ]│
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 2.2. Bộ Chuyển Đổi Không Gian Lưu Trữ (Space Switcher Dropdown)
- Nằm nổi bật tại tiêu đề của Tab Cloud Storage.
- Hiển thị danh sách các Không gian mà tài khoản hiện tại có quyền truy cập:
  - `👤 Không Gian Cá Nhân (Personal Space) — 12.4 GB / 25.0 GB`
  - `👥 Team Wedding Studio (Team Space) — 35.2 GB / 50.0 GB`
  - `👥 Team Media Sài Gòn (Team Space) — 8.1 GB / 50.0 GB`
- **Hành vi chuyển đổi:** Khi người dùng chọn một Không gian khác trong danh sách, JavaScript thực hiện:
  1. Cập nhật `activeSpaceId` trong state trình duyệt.
  2. Tự động gọi API lấy lại cây thư mục và danh sách tệp: `GET /api/v1/cloud/spaces/{spaceId}/files`.
  3. Cập nhật thanh đo hạn mức tương ứng của Không gian đó: `GET /api/v1/cloud/spaces/{spaceId}/quota`.
  4. Nếu Space đó đang ở trạng thái `OVER_QUOTA`, hiển thị ngay Banner cảnh báo và vô hiệu hóa nút `+ Tải Tệp Lên`.

### 2.3. Hiển Thị Tệp Chia Sẻ Theo Đội Nhóm (Shared Team View)
- Khi ở chế độ Team Space:
  - Mọi thành viên trong Team đều nhìn thấy cùng một cấu trúc thư mục và tệp tin.
  - Mỗi thẻ tệp tin hiển thị rõ huy hiệu người đã tải lên: `👤 Tên thành viên` (được ánh xạ từ `created_by_user_id`).
  - Thanh đo hạn mức phản ánh tổng dung lượng dùng chung của toàn bộ Team (`used_bytes` / `effective_quota_bytes`).

### 2.4. Banner Cảnh Báo Quá Hạn Mức (Over-Quota Banner)
- Khi `used_bytes > effective_quota_bytes`:
  - Xuất hiện Banner màu cam hổ phách (`--warning-banner`) nổi bật ở đầu trang:  
    *“⚠️ Không gian lưu trữ đã vượt hạn mức (32.5 GB / 30.0 GB). Bạn vẫn có thể xem, tải xuống và xóa tệp, nhưng tính năng tải tệp mới đang tạm dừng cho đến khi giải phóng dung lượng hoặc liên hệ quản trị viên.”*
  - Nút **`[ + Tải Tệp Lên ]`** bị làm mờ (disabled) kèm tooltip giải thích lý do.
  - Các thao tác xem trước, tải xuống và xóa tệp vào thùng rác **vẫn hoạt động 100% bình thường**.
  - Không có bất kỳ tệp tin nào của người dùng bị xóa tự động.

---

## 3. CÁC TÍNH NĂNG TƯƠNG TÁC PHÍA KHÁCH HÀNG

### 3.1. Cửa Sổ Tải Lên Trực Tiếp Phía Trình Duyệt (Web Direct Upload Modal)
- Bấm **"+ Tải Tệp Lên"** mở modal `#modal-cloud-upload`.
- Hỗ trợ **Kéo & Thả (Drag & Drop)** nhiều tệp cùng lúc.
- **Tiến trình xử lý JavaScript:**
  1. JS đọc tên tệp và kích thước tệp từ đối tượng `File` của trình duyệt.
  2. Gửi yêu cầu `POST /api/v1/cloud/spaces/{spaceId}/uploads/create` xin phiên upload.
  3. Nhận về `upload_session_url` của Google Drive.
  4. Trình duyệt gửi trực tiếp luồng nhị phân (Binary chunks) tới `upload_session_url` bằng `XMLHttpRequest` hoặc `fetch()`. **0 bytes đi qua đĩa máy chủ hosting.**
  5. Cập nhật thanh tiến độ phần trăm thời gian thực (`Progress: 45% · 12 MB/s`).
  6. Sau khi Google báo thành công, JS gửi yêu cầu `finalize` để kích hoạt tệp và tự động làm mới danh sách thư mục.

### 3.2. Trình Xem Trước Đa Phương Tiện (In-Browser Preview Lightbox)
- Nhấn nút xem trước (`👁️`): Mở khung Lightbox tối màu hiển thị ảnh phân giải cao thông qua endpoint `/api/v1/cloud/files/{id}/preview`.
- Hiển thị đầy đủ thông số kỹ thuật: Kích thước tệp, loại định dạng MIME, thời gian tạo, người tải lên, dự án liên kết.

### 3.3. Quản Lý Thùng Rác Ảo (Virtual Trash Management)
- Bấm vào biểu tượng Thùng rác mở giao diện xem các tệp đã xóa của Space hiện tại.
- Mỗi dòng hiển thị: Tên tệp, người xóa, ngày xóa, số ngày còn lại trước khi bị xóa vĩnh viễn (đếm lùi 30 ngày).
- Hai nút thao tác: **"Khôi phục"** (đưa tệp về đúng vị trí thư mục cũ) và **"Xóa vĩnh viễn"**.

---

## 4. GIAO DIỆN QUẢN TRỊ VIÊN TRÊN `license_admin.php` (ADMIN PORTAL V2)

Trong `website/license_admin.php`, bổ sung 2 phân hệ chuyên biệt:

### 4.1. Phân Hệ 1: Bảng Điều Khiển Cụm Lưu Trữ Đa Tài Khoản (Storage Pool Dashboard)
1. **Khối Thẻ Thống Kê Tổng Thể (Metric Cards Grid):**
   - **Tổng Dung Lượng Vật Lý:** `POOL_TOTAL_PHYSICAL` (Ví dụ: 15.0 TB).
   - **Đã Dùng Thực Tế:** `POOL_USED_PHYSICAL` (Ví dụ: 8.2 TB).
   - **Dung Lượng Trống Còn Lại:** `POOL_FREE_PHYSICAL` (Ví dụ: 6.8 TB).
   - **Đang Khóa Tạm Thời:** `POOL_RESERVED` (Ví dụ: 12.5 GB).
   - **Bộ Đệm An Toàn 10%:** `POOL_SAFETY_BUFFER` (Ví dụ: 1.5 TB).
   - **Khả Dụng Cấp Phát Mới:** `POOL_ALLOCATABLE_FREE` (Ví dụ: 5.3 TB).
   - **Tổng Hạn Mức Đã Bán (Logical Allocated):** `TOTAL_LOGICAL_QUOTA_ALLOCATED` (Ví dụ: 18.0 TB).
   - **Tỷ Lệ Cam Kết Vượt:** `OVERCOMMIT_RATIO` (Ví dụ: **1.2x** — Huy hiệu xanh lá `SAFE`).
2. **Bảng Danh Sách Tài Khoản Google Drive Vật Lý:**
   - Cột: Tên gợi nhớ, Email tài khoản, Trạng thái (`ACTIVE`, `DRAINING`, `EMPTY / MIGRATED`, `DISCONNECTED`), Sức khỏe (`HEALTHY`, `ERROR`), Tổng dung lượng, Đã dùng, Số tệp hoạt động (`active_file_count`), Hành động.
   - **Cơ chế Khóa nút Ngắt kết nối (Disconnect Guard UI):**  
     Nếu `active_file_count > 0`, nút "Ngắt kết nối" bị làm mờ (disabled) kèm ghi chú:  
     *“Vẫn còn {N} tệp trên tài khoản này. Vui lòng bấm 'Bắt đầu rút cạn' và di chuyển tệp trước khi ngắt kết nối.”*

### 4.2. Phân Hệ 2: Quản Trị & Điều Chỉnh Hạn Mức Dung Lượng (Quota Adjustments Ledger)
1. **Tìm kiếm & Tra cứu Không gian:**
   - Hỗ trợ tìm kiếm nhanh theo Email người dùng hoặc Tên Team.
   - Hiển thị bảng tóm tắt: Tên Space, Loại Space (`PERSONAL` / `TEAM`), Gói bản quyền cơ sở (`base_plan_quota`), Dung lượng điều chỉnh (`adjusted_bytes`), Hạn mức hiệu lực (`effective_quota`), Đã dùng (`used_bytes`), Tình trạng (`NORMAL` / `OVER_QUOTA`).
2. **Hộp Thoại Điều Chỉnh Dung Lượng (+/- Storage Modal):**
   - Chọn Space đích.
   - Nhập số GB muốn tăng (+) hoặc giảm (-).
   - **Lý do điều chỉnh (Reason) — Bắt buộc:** Không được để trống (ví dụ: *"Tặng thêm khách hàng thân thiết dự án cưới tháng 9"* hoặc *"Giảm theo yêu cầu hạ gói dịch vụ"*).
   - Nút xác nhận thực thi: Gửi `POST /api/v1/cloud/admin/spaces/{spaceId}/adjust-quota`.
3. **Bảng Nhật Ký Lịch Sử Điều Chỉnh (Immutable Ledger):**
   - Đọc trực tiếp từ bảng `cloud_quota_adjustments`.
   - Hiển thị: Thời gian, Quản trị viên thực hiện, Space đích, Dung lượng biến động (+/- GB), Lý do, Hạn mức mới sau điều chỉnh.

---

## 5. TỐI ƯU HÓA GIAO DIỆN DI ĐỘNG & CHỐNG TRÀN MÀN HÌNH (RESPONSIVE RULES)

- Kế thừa toàn bộ cấu trúc chống tràn đã thiết lập trong `globals.css`:
  - `overflow-x: hidden;` trên toàn bộ container.
  - Lưới thẻ thống kê trên Admin Dashboard tự động chuyển thành lưới 2x2 trên Tablet và dạng trượt vuốt dọc 1 cột trên Mobile.
  - Bộ chuyển đổi Space Switcher co giãn linh hoạt thành dropdown toàn chiều ngang trên điện thoại.
  - Bảng dữ liệu Storage Accounts và Quota Ledger được bọc trong thẻ `<div class="table-responsive">` có thanh cuộn mượt và gợi ý vuốt ngang.
  - Các nút hành động trên tệp tin chuyển thành menu ba chấm (`...`) dạng Popup Action Sheet trên thiết bị di động.
