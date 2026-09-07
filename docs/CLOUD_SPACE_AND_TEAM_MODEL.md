# 2TOOLNE CLOUD — MÔ HÌNH KHÔNG GIAN LƯU TRỮ VÀ ĐỘI NHÓM (CLOUD SPACE & TEAM MODEL)
**Tài liệu:** `docs/CLOUD_SPACE_AND_TEAM_MODEL.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Mục tiêu:** Định nghĩa kiến trúc Không gian lưu trữ độc lập (`Cloud Space`) làm đơn vị sở hữu hạn mức trung tâm thay cho `user_id`, sẵn sàng cho mô hình Cá Nhân (Personal) và Đội Nhóm (Team), tích hợp hài hòa với hệ thống bản quyền và thiết bị hiện có mà không gây xung đột.

---

## 1. KHÁI NIỆM CỐT LÕI: CLOUD SPACE (KHÔNG GIAN LƯU TRỮ)

> [!IMPORTANT]
> **THAY ĐỔI KIẾN TRÚC TRỌNG YẾU TỪ V1 SANG V2:**
> - Trong bản V1, hạn mức và tệp tin gắn trực tiếp vào `user_id` (`cloud_user_quotas.user_id`, `cloud_files.user_id`). Mô hình này gây bế tắc khi triển khai tính năng Đội Nhóm (Team / Studio) vì nhiều người dùng không thể cùng chia sẻ một kho tệp và một hạn mức dung lượng.
> - Trong bản V2, toàn bộ tệp tin (`cloud_files`), thư mục (`cloud_folders`), hạn mức (`cloud_space_quotas`) và phiên khóa dung lượng (`cloud_upload_reservations`) được gắn chặt vào **`cloud_space_id`**.
> - Người dùng truy cập dữ liệu thông qua tư cách thành viên hoặc chủ sở hữu của Cloud Space đó.

```
                  ┌──────────────────────────────────────────────┐
                  │                 CLOUD SPACE                  │
                  │             (cloud_space_id)                 │
                  ├──────────────────────────────────────────────┤
                  │ • Quota & Hạn Mức (cloud_space_quotas)       │
                  │ • Sổ Cái Điều Chỉnh (cloud_quota_adjustments)│
                  │ • Thư Mục Ảo (cloud_folders)                 │
                  │ • Tệp Tin Ảo (cloud_files)                   │
                  │ • Phiên Tải Lên (cloud_upload_reservations)  │
                  │ • Thùng Rác (cloud_trash)                    │
                  └──────────────────────┬───────────────────────┘
                                         │
                   ┌─────────────────────┴─────────────────────┐
                   ▼                                           ▼
      ┌─────────────────────────┐                 ┌─────────────────────────┐
      │   PERSONAL CLOUD SPACE  │                 │    TEAM CLOUD SPACE     │
      │   (owner_type = 'USER') │                 │   (owner_type = 'TEAM') │
      ├─────────────────────────┤                 ├─────────────────────────┤
      │ • Chủ sở hữu: 1 User    │                 │ • Chủ sở hữu: 1 Team    │
      │ • Hạn mức theo gói cá   │                 │ • Hạn mức chia sẻ chung │
      │   nhân (BASIC/PRO/STUDIO│                 │ • Nhiều thành viên cùng │
      │ • Sử dụng cho Web & App │                 │   truy cập và làm việc  │
      └─────────────────────────┘                 └─────────────────────────┘
```

---

## 2. KHÔNG GIAN CÁ NHÂN (PERSONAL CLOUD SPACE)

- Mỗi khách hàng khi đăng ký hoặc kích hoạt gói bản quyền cá nhân (`BASIC`, `PRO`, `STUDIO`) sẽ được tự động khởi tạo **1 Personal Cloud Space**.
  - `id`: `cs_usr_<user_id>` (hoặc UUID ngẫu nhiên).
  - `owner_type`: `'USER'`.
  - `owner_id`: `<user_id>`.
  - `name`: `My Cloud`.
- Toàn bộ ảnh upscale từ Desktop App (2toolne Upscale), video từ Slideshow Studio, và tệp tải lên từ Web của người dùng đó mặc định lưu vào Personal Space này.
- Hạn mức cơ sở (`base_quota_bytes`) được cấp phát tự động dựa trên gói cước của tài khoản.

---

## 3. KHÔNG GIAN ĐỘI NHÓM (TEAM CLOUD SPACE)

### 3.1. Cờ Tính Năng Kiểm Soát Phát Hành (Feature Flag)
```ini
TEAM_PLANS_ENABLED = false
```
- **Kiến trúc & Cơ sở dữ liệu:** Sẵn sàng 100% trong mã nguồn và schema.
- **Giao diện mua hàng phía khách hàng:** Tạm ẩn hoàn toàn, không hiển thị trên bảng giá web cho đến khi có quyết định mở bán chính thức.
- **Mục đích:** Đảm bảo khi phát hành Team Plan sau này, hệ thống **hoàn toàn không cần chạy migration phá vỡ dữ liệu cũ (Zero Breaking Migration)**.

### 3.2. Khái Niệm Gói Mở Đầu: TEAM STARTER
Gói cước đội nhóm khởi điểm được định nghĩa theo cấu trúc tham số linh hoạt (không hardcode cứng trong mã nguồn):

| Thuộc Tính Gói (Dimension) | Giá Trị Mặc Định Khởi Tạo | Khả Năng Mở Rộng Tương Lai |
| :--- | :---: | :--- |
| **`member_slots` (Số chỗ thành viên)** | **2 Slots** | Mở rộng thành Team 5, Team 10 mà không cần sửa schema. |
| **`cloud_quota_bytes` (Dung lượng chia sẻ)** | **50 GB** | Nâng cấp thành 100 GB, 500 GB, 1 TB qua cấu hình gói. |
| **`app_key_count` (Số máy/ghế bản quyền app)**| **2 App Keys / Seats**| Cấp phép số lượng máy cài app đồng thời của đội nhóm. |

### 3.3. Cơ Chế Lưu Trữ Chia Sẻ (Shared Storage Mechanics)
- Không gian lưu trữ của Team là **một kho dùng chung duy nhất (Shared Pool)**, không chia nhỏ quota thành 25GB cho người A và 25GB cho người B.
- *Ví dụ thực tế:*
  - Team "Studio Wedding ABC" có hạn mức 50 GB.
  - Thành viên A tải lên 20 GB ảnh cưới.
  - Thành viên B mở Desktop App hoặc Web sẽ nhìn thấy ngay:  
    `Dung lượng: 20 GB / 50 GB (Còn trống: 30 GB)`.
  - Cả 2 thành viên đều nhìn thấy và thao tác được trên các thư mục dự án chung.

---

## 4. ĐỐI SOÁT VÀ TÍCH HỢP HỆ THỐNG BẢN QUYỀN HIỆN CÓ (APP KEY & LICENSE AUDIT)

### 4.1. Hiện Trạng Hệ Thống Bản Quyền Đang Chạy (Current License Model)
Qua kiểm toán mã nguồn tại `website/api/v1/controllers/DeviceController.php`, `LicenseController.php` và `storage/db.php`:
1. **Bảng `license_entitlements`:** Quản lý gói cước (`plan`: `BASIC`/`PRO`/`STUDIO`), chế độ trừ phí (`credit_mode`: `METERED`/`UNLIMITED`), và số máy tối đa được kích hoạt (`max_devices`: mặc định 3 máy cho cá nhân).
2. **Bảng `devices`:** Quản lý từng máy tính cài app qua mã băm HWID (`device_fingerprint`). Mỗi khi mở app, client gọi `POST /api/v1/devices/activate`. Nếu số máy `ACTIVE` vượt quá `max_devices` $\rightarrow$ Chặn kích hoạt với mã lỗi `DEVICE_LIMIT_EXCEEDED`.
3. **Bảng `licenses` (Di sản):** Quản lý các chuỗi key `2TAMNE-VIP-...` dùng riêng cho Slideshow Studio.

### 4.2. Khái Niệm "App Key" Cho Team Plan (Proposed Team Key Model)
- **Tuyệt đối không tạo hệ thống cấp phép song song (No duplicate licensing system).**
- Trong mô hình Team:
  - Thuộc tính **`app_key_count = 2`** chính là **2 Ghế Bản Quyền Máy Khách (2 Active App Seats)**.
  - Mỗi thành viên hợp lệ trong Team (`status = 'ACTIVE'`) khi đăng nhập vào Desktop App `2toolne Upscale` sẽ được hệ thống cấp phép sử dụng 1 ghế bản quyền của Team đó.
  - Việc kích hoạt máy tính vẫn sử dụng trực tiếp bảng `devices` và chữ ký số HMAC-SHA256 ngoại tuyến 72 giờ hiện có.
- **Kết luận:** `MIGRATION_REQUIRED = NO` (Không phá vỡ cấu trúc cấp phép hiện tại).

---

## 5. CÁC QUY TẮC VẬN HÀNH QUAN TRỌNG KHI THÀNH VIÊN RỜI ĐỘI

### 5.1. Khi Thành Viên Rời Hoặc Bị Xóa Khỏi Đội (Member Removal)
- **Tài sản tệp tin:** Mọi tệp tin, dự án và thư mục do Thành viên B tải lên đều thuộc quyền sở hữu của `cloud_space_id` (Team Space).
- **Quy tắc bất biến:** Khi Thành viên B bị xóa khỏi đội, **toàn bộ tệp tin của Thành viên B vẫn nằm nguyên vẹn trong Team Space**, không bị xóa và không bị mất đi.
- **Quyền truy cập của Thành viên B:** Ngay lập tức bị thu hồi quyền truy cập vào Team Space. Thành viên B chỉ còn quyền truy cập vào Personal Space của riêng mình.

### 5.2. Khi Chủ Sở Hữu Muốn Rời Đội (Team Owner Rules)
- Một Team luôn luôn phải có ít nhất 1 Chủ sở hữu (`OWNER`).
- Chủ sở hữu **không thể tự ý rời đội hoặc xóa tài khoản** cho đến khi:
  1. Hoàn tất chuyển giao quyền sở hữu (`Transfer Ownership`) sang một thành viên khác trong đội.
  2. Hoặc chủ động thực hiện thủ tục đóng đội nhóm và giải tán dữ liệu (`Disband Team`).

---

## 6. TRẢI NGHIỆM ĐIỀU HƯỚNG VÀ CHUYỂN ĐỔI KHÔNG GIAN (SPACE SELECTOR UX)

Một người dùng có thể sở hữu 1 Personal Space và tham gia vào một hoặc nhiều Team Spaces. Giao diện Web và Desktop cung cấp bộ chuyển đổi không gian (Space Selector):

```
┌─────────────────────────────────────────────────────────────┐
│ ☁️ 2toolne Cloud              [ 🏢 Studio Wedding ABC ▼ ]    │
├─────────────────────────────────────────────────────────────┤
│ DANH SÁCH KHÔNG GIAN LƯU TRỮ:                               │
│  👤 Không Gian Cá Nhân (Personal Cloud - 25 GB)             │
│  ✓ 🏢 Studio Wedding ABC (Team Shared - 50 GB)               │
│    🏢 Production Agency XYZ (Team Shared - 100 GB)          │
└─────────────────────────────────────────────────────────────┘
```

- Khi chuyển đổi Space:
  - Toàn bộ cây thư mục, danh sách tệp tin và thanh đo dung lượng lập tức chuyển sang ngữ cảnh của Space được chọn.
  - Tuyệt đối không gộp chung dung lượng vật lý giữa các Space.
