# 2TOOLNE CLOUD — KẾ HOẠCH BẢO MẬT V2 (SECURITY PLAN V2)
**Tài liệu:** `docs/CLOUD_SECURITY_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật V2:** Tích hợp cơ chế kiểm soát phân quyền Không gian lưu trữ (`Cloud Space Authorization`), Cách ly dữ liệu Đội nhóm (`Team Isolation`), Bảo vệ dữ liệu khi vượt hạn mức (`Over-Quota State Handling`) và Khóa an toàn gỡ bỏ tài khoản vật lý (`Safe Account Disconnect Guard`).

---

## 1. RANH GIỚI BẢO MẬT KHÔNG GIAN LƯU TRỮ (CLOUD SPACE ACCESS CONTROL)

Mọi yêu cầu gửi lên máy chủ đều phải trải qua quy trình xác minh quyền sở hữu và tư cách thành viên:

```
                      Yêu Cầu Tới Endpoint /spaces/{space_id}/*
                                      │
                                      ▼
                        Xác Thực Người Dùng (JWT / Session)
                                      │
                         [Hợp lệ? Không ──> 401 Unauthorized]
                                      │ Có
                                      ▼
                       Truy vấn bảng cloud_spaces
                                      │
                       [Tồn tại? Không ───> 404 Not Found]
                                      │ Có
                                      ▼
                         Phân Loại Loại Hình Sở Hữu
                                      │
                         ┌────────────┴────────────┐
                         ▼                         ▼
                 [owner_type = USER]       [owner_type = TEAM]
                         │                         │
                 owner_id === user_id?      user_id có trong team_members
                         │                  với status = 'ACTIVE'?
                [Không ──┼─────────────────────────┼──> 403 Forbidden]
                         │                         │
                         └────────────┬────────────┘
                                      │ Hợp lệ
                                      ▼
                      Thực Thi Thao Tác Nghiệp Vụ
```

### Nguyên Tắc Cách Ly Đội Nhóm Tuyệt Đối (Team Isolation):
- Thành viên của Team A tuyệt đối không thể xem, sửa hoặc xóa bất kỳ tệp tin nào của Team B.
- Việc kiểm tra tư cách thành viên được thực thi tại **tầng Database Query bằng Prepared Statements** trên mọi câu lệnh `SELECT`, `INSERT`, `UPDATE`, `DELETE`.
- Ngay khi một thành viên bị xóa khỏi `team_members`, quyền truy cập vào Cloud Space của Team đó bị vô hiệu hóa tức thì trong vòng 0 mili-giây.

---

## 2. QUẢN TRỊ KHÓA BÍ MẬT & MÃ HÓA GOOGLE REFRESH TOKEN (SECRET MANAGEMENT)

- **Mã hóa tại chỗ (Encryption at Rest):** Toàn bộ trường `encrypted_credentials` trong bảng `storage_accounts` bắt buộc phải được mã hóa bằng thuật toán **AES-256-GCM** (IV 12 bytes + Auth Tag 16 bytes).
- **Vị trí lưu trữ Khóa chủ (`CLOUD_MASTER_KEY`):**
  - Lưu tại biến môi trường `getenv('CLOUD_MASTER_KEY')` hoặc tệp ngoài webroot `/public_html/storage/secrets/cloud_master.key` với phân quyền `0600` và cấu hình `.htaccess Deny from all`.
  - Tuyệt đối không lưu khóa chủ trong cùng cơ sở dữ liệu MySQL với bảng `storage_accounts`.
- **Chính sách lọc log nghiêm ngặt (Log Redaction):**
  - Mọi hàm log hệ thống đều qua bộ lọc `redact_secret()` để ẩn Refresh Token, Access Token, Client Secret.

---

## 3. AN TOÀN KHI KHÔNG GIAN BỊ VƯỢT HẠN MỨC (OVER-QUOTA SAFETY)

Khi Admin giảm hạn mức hoặc khi gói cước bị hạ cấp xuống dưới dung lượng đang dùng:
1. **Chế độ Chỉ Đọc An Toàn (Safe Read-Only Mode):**
   - Trạng thái Space chuyển thành `OVER_QUOTA`.
   - Khách hàng **vẫn tải xuống, xem trước và xóa tệp tin bình thường**.
   - Máy chủ chặn đứng các yêu cầu tạo phiên upload mới (`POST /uploads/create`) với mã lỗi `403 FORBIDDEN` (`SPACE_OVER_QUOTA`).
2. **Không bao giờ xóa tệp tự động:**
   - Hệ thống không bao giờ tự ý thanh trừng tệp tin của người dùng vì lý do vượt hạn mức.
   - Trạng thái `OVER_QUOTA` tự động gỡ bỏ khi người dùng xóa bớt tệp hoặc nâng cấp thêm dung lượng.

---

## 4. KHÓA BẢO VỆ GỠ BỎ TÀI KHOẢN VẬT LÝ (SAFE DISCONNECT GUARD)

Để ngăn ngừa hành động bấm nhầm của quản trị viên làm mất tệp khách hàng:
- Endpoint `DELETE /api/v1/admin/cloud/storage-accounts/{id}` bắt buộc thực hiện kiểm tra tiên quyết:
  ```sql
  SELECT COUNT(*) FROM cloud_files 
  WHERE storage_account_id = :id AND status IN ('ACTIVE', 'TRASHED', 'PENDING_UPLOAD');
  ```
- **Nếu kết quả $> 0$:** Thao tác bị từ chối với mã lỗi `409 Conflict`.
- Chỉ cho phép gỡ bỏ tài khoản khi toàn bộ tệp đã được di chuyển (`MIGRATED`) hoặc xóa sạch (`EMPTY`).

---

## 5. BẢO VỆ CHỐNG TẤN CÔNG ĐƯỜNG DẪN & XÁC THỰC TẢI LÊN

- **Chống Path Traversal:** Loại bỏ toàn bộ `../`, `..\`, null bytes `\0`, ký tự điều khiển ASCII `< 32`.
- **Chuẩn hóa Unicode NFC:** Đảm bảo tính tương thích ký tự tiếng Việt giữa Windows và macOS.
- **Xác thực kích thước tệp tại bước `finalize`:** Truy vấn trực tiếp `file.size` từ Google Drive API để đối soát. Nếu kích thước vượt quá số byte đã khóa tạm $\rightarrow$ Xóa tệp ngay lập tức và từ chối kích hoạt.
- **Bảo vệ chống gửi lại trùng lặp:** Kiểm tra `idempotency_key` trên bảng `cloud_upload_reservations`.
