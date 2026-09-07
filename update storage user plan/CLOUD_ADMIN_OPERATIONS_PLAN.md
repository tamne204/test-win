# 2TOOLNE CLOUD — QUY TRÌNH VẬN HÀNH QUẢN TRỊ VIÊN (ADMIN OPERATIONS PLAN)
**Tài liệu:** `docs/CLOUD_ADMIN_OPERATIONS_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Mục tiêu:** Cung cấp tài liệu cẩm nang vận hành chi tiết cho Quản trị viên hệ thống trên trang `license_admin.php`, bao gồm quản lý Bể lưu trữ Google Drive, quy trình gỡ bỏ ổ đĩa an toàn (Safe Account Removal), điều chỉnh hạn mức lưu trữ có kiểm toán và giám sát tỷ lệ cấp phát vượt mức (Overcommit).

---

## 1. BẢNG ĐIỀU KHIỂN BỂ LƯU TRỮ (STORAGE POOL OPERATIONAL DASHBOARD)

Giao diện quản trị Storage Pool trên `license_admin.php` được thiết kế theo phong cách trực quan, tối ưu cho việc theo dõi sức khỏe hệ thống:

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│ ☁️ 2TOOLNE CLOUD STORAGE POOL OVERVIEW                               [ 🔄 Làm Mới Tất Cả ]│
├───────────────────┬───────────────────┬───────────────────┬─────────────────────────────┤
│ TỔNG DUNG LƯỢNG   │ ĐÃ SỬ DỤNG VẬT LÝ │ DUNG LƯỢNG ĐỆM    │ KHẢ DỤNG CẤP PHÁT           │
│ 15.0 TB           │ 7.4 TB (49.3%)    │ 1.5 TB (10%)      │ 6.0 TB                      │
├───────────────────┼───────────────────┼───────────────────┼─────────────────────────────┤
│ HẠN MỨC ĐÃ CẤP    │ THỰC TẾ DÙNG      │ HỆ SỐ CẤP VƯỢT    │ SỐ Ổ ĐANG ACTIVE            │
│ 10.2 TB (Logical) │ 7.4 TB            │ 0.68 (An toàn)    │ 2 Active / 1 Draining       │
└───────────────────┴───────────────────┴───────────────────┴─────────────────────────────┘

DANH SÁCH CÁC TÀI KHOẢN GOOGLE DRIVE VẬT LÝ:
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│ 🟢 Drive Node 01 (Storage Master Alpha)                         [ACTIVE] · 🟢 HEALTHY   │
│ Tổng: 5.0 TB  │ Đã dùng: 2.1 TB (42%) │ Trống: 2.9 TB │ Khả dụng cấp: 2.4 TB            │
│ Đệm an toàn: 10% (500 GB) │ Ưu tiên: 100 │ Lần kiểm tra cuối: 10 phút trước             │
│ [ 🔄 Kiểm Tra ]   [ ⚙️ Đổi Ưu Tiên ]   [ 🟡 Rút Dần (Drain) ]   [ 🔴 Vô Hiệu Hóa ]     │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ 🟡 Drive Node 02 (Storage Node Beta)                           [DRAINING] · 🟢 HEALTHY │
│ Tổng: 5.0 TB  │ Đã dùng: 4.8 TB (96%) │ Trống: 0.2 TB │ Khả dụng cấp: 0 GB              │
│ Trạng thái: Kho đầy > 95% - Đang cho phép tải xuống, KHÔNG nhận thêm tệp mới            │
│ [ 🔄 Kiểm Tra ]   [ 🔄 Cấp Lại Quyền OAuth ]   [ ⛔ Ngắt Kết Nối An Toàn (Bị Khóa) ]   │
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ [ + Kết Nối Thêm Tài Khoản Google Drive Mới ]                                           │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. QUY TRÌNH GỠ BỎ TÀI KHOẢN GOOGLE DRIVE AN TOÀN (SAFE ACCOUNT REMOVAL)

> [!CAUTION]
> **NGHIÊM CẤM HÀNH ĐỘNG "XÓA NGAY LẬP TỨC" (NO IMMEDIATE HARD DELETE):**
> Một tài khoản Google Drive đang chứa tệp tin của khách hàng **tuyệt đối không được phép xóa bỏ ngay khỏi cơ sở dữ liệu**. Việc xóa đột ngột sẽ biến hàng nghìn tệp tin của khách hàng thành tệp mồ côi (Orphaned Files) và gây lỗi 404 hàng loạt.

### Vòng Đời Gỡ Bỏ 4 Bước Chuẩn Hóa:
```
   [ACTIVE] ────────> 1. DRAIN (Rút dần)
                           │
                           ├─ Dừng phân bổ tệp mới.
                           ├─ Tệp cũ vẫn đọc/tải xuống bình thường.
                           │
                      2. MIGRATE (Di chuyển tệp sang Drive khác)
                           │
                           ├─ Tải tệp từ Drive cũ sang Drive mới.
                           ├─ Khớp mã SHA-256.
                           ├─ Cập nhật provider_file_id trong cloud_files.
                           │
                      3. EMPTY / VERIFIED (Xác nhận sạch dữ liệu)
                           │
                           ├─ ACTIVE_FILE_COUNT = 0.
                           │
                      4. DISCONNECT (Ngắt kết nối an toàn)
                           │
                           └─ Xóa bản ghi và thu hồi Token.
```

### Điều Kiện Tiên Quyết Bắt Buộc Khi Bấm "Ngắt Kết Nối" (Disconnect Guard):
- Khi Admin bấm "Ngắt Kết Nối", hệ thống tự động kiểm tra câu truy vấn:
  ```sql
  SELECT COUNT(*) as active_files 
  FROM cloud_files 
  WHERE storage_account_id = :account_id 
    AND status IN ('ACTIVE', 'TRASHED', 'PENDING_UPLOAD');
  ```
- **Nếu `active_files > 0`:** Hệ thống **lập tức từ chối và khóa nút hành động**, hiển thị cảnh báo đỏ:
  > *"Không thể ngắt kết nối tài khoản này! Hiện vẫn còn 1.240 tệp tin của khách hàng đang lưu trữ trên Drive này. Vui lòng thực hiện chuyển tệp (Drain & Migrate) sang Drive khác trước khi ngắt kết nối."*

---

## 3. QUY TRÌNH ĐIỀU CHỈNH HẠN MỨC LƯU TRỮ CÓ KIỂM TOÁN (ADMIN QUOTA ADJUSTMENTS)

Admin có quyền tăng hoặc giảm dung lượng lưu trữ cho bất kỳ Không gian cá nhân (Personal Space) hoặc Không gian đội nhóm (Team Space) nào.

### 3.1. Các Quy Tắc Điều Chỉnh Bắt Buộc:
1. **Lý do điều chỉnh bắt buộc (Mandatory Justification):** Bắt buộc nhập lý do tối thiểu 5 ký tự (ví dụ: *"Bù dung lượng cho sự cố rớt mạng của khách"*, *"Nâng cấp gói tài trợ sự kiện"*).
2. **Sổ cái bất biến (`cloud_quota_adjustments`):** Mọi thay đổi tăng/giảm đều được ghi lại vĩnh viễn thành một dòng giao dịch với các trường: `delta_bytes`, `type`, `reason`, `admin_user_id`, `balance_after_bytes`. Tuyệt đối không ghi đè lịch sử.
3. **Không làm thay đổi cấu hình gói gốc:** Việc cộng thêm 10 GB cho Khách hàng A không làm thay đổi định nghĩa gốc của gói `PRO` đối với các khách hàng khác.

---

## 4. XỬ LÝ KHI DUNG LƯỢNG BỊ GIẢM XUỐNG DƯỚI MỨC ĐANG DÙNG (OVER-QUOTA HANDLING)

Một tình huống vận hành nhạy cảm xảy ra khi Admin giảm hạn mức của một không gian lưu trữ xuống thấp hơn dung lượng khách hàng đang thực tế sử dụng:
- *Ví dụ:* Không gian lưu trữ đang dùng thực tế **47 GB**. Admin thực hiện giảm hạn mức từ 50 GB xuống **40 GB**.

### Các Nguyên Tắc Xử Lý Nhân Văn & An Toàn Dữ Liệu:
1. **Tuyệt đối KHÔNG tự ý xóa tệp tin của khách hàng.**
2. **Tuyệt đối KHÔNG chặn quyền tải xuống hay xem trước.**
3. **Tuyệt đối KHÔNG làm gián đoạn các tệp đang upload dở.**
4. **Kích hoạt trạng thái `OVER_QUOTA` (Vượt Hạn Mức):**
   - Không gian lưu trữ chuyển sang chế độ **Chỉ Đọc (Read-Only)** đối với tác vụ tải mới.
   - Khách hàng vẫn xem, tải xuống và xóa tệp tin bình thường.
   - Mọi nỗ lực tải lên tệp mới sẽ bị từ chối với thông báo:  
     > *"Không gian lưu trữ của bạn đã vượt quá hạn mức (Đang dùng: 47 GB / Hạn mức: 40 GB). Vui lòng xóa bớt tệp tin cũ hoặc nâng cấp thêm dung lượng để tiếp tục tải lên."*
   - Ngay khi người dùng xóa bớt tệp để dung lượng thực tế giảm xuống $\le 40\text{ GB}$, trạng thái `ACTIVE` tự động được phục hồi ngay lập tức.
5. **Cảnh báo xác nhận trên giao diện Admin:**
   Trước khi xác nhận giảm hạn mức, Admin Portal hiển thị hộp thoại cảnh báo:
   > ⚠️ **CẢNH BÁO VƯỢT HẠN MỨC:**  
   > *Không gian này hiện đang dùng 47.0 GB. Hạn mức mới sẽ là 40.0 GB. Thao tác này sẽ đưa không gian lưu trữ vào trạng thái OVER_QUOTA và khóa quyền tải lên mới của khách hàng cho đến khi họ xóa bớt tệp.*  
   > `[ Hủy Bỏ ]` `[ Xác Nhận Giảm Hạn Mức ]`

---

## 5. GIÁM SÁT HỆ SỐ CẤP PHÁT VƯỢT MỨC (OVERCOMMIT RATIO MONITORING)

Trong thực tế vận hành dịch vụ đám mây, tổng dung lượng cam kết bán cho khách hàng (Logical Quotas) thường có thể lớn hơn dung lượng vật lý thực tế vì không phải khách hàng nào cũng dùng hết 100% dung lượng được cấp.

### Công Thức Tính Hệ Số Cấp Vượt (Overcommit Ratio):
$$\text{Overcommit Ratio} = \frac{\text{Tổng hạn mức logic đã cấp phát cho khách (Total Logical Quota)}}{\text{Tổng dung lượng vật lý khả dụng của Bể Drive (Pool Physical Total)}}$$

- **Hệ số an toàn ($< 1.2$):** Hiển thị màu xanh lá cây 🟢.
- **Hệ số cảnh báo ($1.2 - 1.5$):** Hiển thị màu vàng hổ phách 🟡. Admin nhận được nhắc nhở nên chuẩn bị kết nối thêm tài khoản Google Drive mới.
- **Hệ số rủi ro ($> 1.5$):** Hiển thị màu đỏ 🔴. Khuyến nghị Admin kết nối thêm tài khoản ngay lập tức để tránh rủi ro khách hàng đồng loạt tải dữ liệu làm đầy kho.
