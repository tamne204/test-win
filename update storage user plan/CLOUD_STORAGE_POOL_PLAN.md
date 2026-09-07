# 2TOOLNE CLOUD — QUẢN TRỊ POOL LƯU TRỮ ĐA TÀI KHOẢN (STORAGE POOL PLAN V2)
**Tài liệu:** `docs/CLOUD_STORAGE_POOL_PLAN.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật:** V2 Architecture Update (Cloud Space domain, Safe Removal Lifecycle, Overcommit Monitoring, Disconnect Guard)  
**Mục tiêu:** Hợp nhất nhiều tài khoản Google Drive của đơn vị vận hành thành một cụm lưu trữ ảo duy nhất (Unified Storage Pool), tự động cân bằng dung lượng, duy trì dự phòng an toàn, quản lý tỷ lệ Overcommit và xử lý ngắt kết nối an toàn tuyệt đối.

---

## 1. KHÁI NIỆM BỂ LƯU TRỮ ĐA TÀI KHOẢN (MULTI-ACCOUNT STORAGE POOL)

Đơn vị vận hành có thể kết nối nhiều tài khoản Google Drive (ví dụ: Tài khoản 01: 5TB, Tài khoản 02: 5TB, Tài khoản 03: 5TB).
- **Phía Quản Trị Viên (Admin View):** Nhìn thấy rõ từng tài khoản vật lý, tỷ lệ lấp đầy, tình trạng kết nối, lỗi ủy quyền, số lượng tệp đang lưu trữ (`active_file_count`), tỷ lệ cam kết vượt (`OVERCOMMIT_RATIO`) và dung lượng tổng cộng (15 TB).
- **Phía Khách Hàng (Customer / Cloud Space View):** Khách hàng chỉ nhìn thấy Không gian lưu trữ của mình (Personal Space hoặc Team Space), không biết bất kỳ thông tin nào về số lượng hay địa chỉ email của các tài khoản Google Drive phía sau.

---

## 2. VÒNG ĐỜI VÀ CÁC TRẠNG THÁI CỦA TÀI KHOẢN LƯU TRỮ (ACCOUNT LIFECYCLE V2)

### 2.1. Sơ Đồ Chuyển Đổi Trạng Thái
```
                          [ Thêm Tài Khoản Mới ]
                                     │
                                     ▼
                               AUTH_REQUIRED
                                     │ (Hoàn tất Google OAuth & Test API)
                                     ▼
                                  ACTIVE ◄─────────────────────┐
                                  (Nhận file)                  │
                                     │                         │ (Giải phóng
                                     │ (Dung lượng > 85%)      │  hoặc nâng cấp)
                                     ▼                         │
                                 NEAR_FULL ────────────────────┘
                                     │
                                     │ (Dung lượng > 95% hoặc Admin chủ động)
                                     ▼
                                 DRAINING ──(Chỉ tải xuống / Không nhận mới)
                                     │
                                     │ [Di chuyển hết dữ liệu hoặc tệp bị xóa]
                                     ▼
                              EMPTY / MIGRATED (active_file_count == 0)
                                     │
                                     │ [Admin xác nhận ngắt kết nối]
                                     ▼
                               DISCONNECTED (Ngắt an toàn)
```

### 2.2. Bảng Mô Tả Ý Nghĩa Từng Trạng Thái:
| Trạng Thái | Chấp Nhận Upload Mới? | Cho Phép Download? | Cho Phép Ngắt Kết Nối? | Hành Động Hệ Thống |
| :--- | :---: | :---: | :---: | :--- |
| **`ACTIVE`** | Có | Có | **KHÔNG** | Được bộ cấp phát (Allocator) ưu tiên lựa chọn để tạo phiên upload mới. |
| **`NEAR_FULL`** | Có (Chỉ file nhỏ) | Có | **KHÔNG** | Đã dùng vượt ngưỡng cảnh báo (85%). Chỉ cấp phát cho các tệp nhỏ (< 100 MB). |
| **`DRAINING`** | **Không** | Có | **KHÔNG** (Nếu tệp > 0) | Kho đã đầy (> 95%) hoặc Admin chuẩn bị ngắt. Giữ nguyên tệp cũ cho khách tải, không nhận thêm tệp mới. |
| **`EMPTY / MIGRATED`** | **Không** | N/A | **CÓ** | Tài khoản DRAINING đã đạt `active_file_count = 0`. An toàn tuyệt đối để ngắt kết nối. |
| **`DISCONNECTED`** | **Không** | **Không** | Đã ngắt | Đã thu hồi OAuth và ngắt khỏi hệ sinh thái lưu trữ. Không còn tham gia bất kỳ hoạt động nào. |
| **`AUTH_REQUIRED`**| **Không** | **Không** | **KHÔNG** | Refresh Token hết hạn hoặc bị Google thu hồi quyền. Cần Admin bấm "Cấp lại quyền". |
| **`OFFLINE`** | **Không** | **Không** | **KHÔNG** | Tạm dừng phục vụ để bảo trì. Khách hàng thấy thông báo tệp tạm thời không khả dụng. |
| **`ERROR`** | **Không** | **Không** | **KHÔNG** | Gặp lỗi liên tục trong các lần Health check định kỳ. Chờ Admin xử lý. |
| **`DISABLED`** | **Không** | **Không** | **KHÔNG** | Tài khoản bị vô hiệu hóa tạm thời bởi Admin. |

---

## 3. CƠ CHẾ BẢO VỆ NGẮT KẾT NỐI AN TOÀN (SAFE DISCONNECT GUARD)

> [!CAUTION]
> **QUY TẮC BẢO VỆ DỮ LIỆU TỐI THƯỢNG (DISCONNECT GUARD RULE):**
> Hệ thống áp dụng quy chuẩn Server-Authoritative chặn tuyệt đối việc xóa hoặc ngắt kết nối một tài khoản lưu trữ vật lý khi vẫn còn tệp tin hoạt động trên đó.

### 3.1. Các Bước Ngắt Tài Khoản An Toàn
1. **Bước 1: Chuyển sang `DRAINING`:**
   - Admin bấm "Bắt đầu rút cạn (Start Draining)".
   - Hệ thống chuyển `status = 'DRAINING'`.
   - Bộ cấp phát (`StorageAllocator`) lập tức loại trừ tài khoản này khỏi danh sách phân bổ upload mới.
   - Các yêu cầu tải xuống tệp cũ vẫn diễn ra bình thường 100%.
2. **Bước 2: Di chuyển tệp hoặc chờ tệp hết hạn:**
   - Tiến trình di chuyển nền (`Background Migration Worker`) chuyển dần các tệp tin sang tài khoản `ACTIVE` khác.
   - Hoặc các tệp tạm / tệp trong thùng rác tự động hết hạn và bị xóa.
3. **Bước 3: Xác minh `active_file_count == 0`:**
   - Khi không còn bản ghi nào trong bảng `cloud_files` gắn với `storage_account_id` này ở trạng thái `ACTIVE` hoặc `TRASHED`:
   - Trạng thái tài khoản tự động chuyển sang `EMPTY / MIGRATED`.
4. **Bước 4: Ngắt kết nối (`DISCONNECTED`):**
   - Nút "Ngắt kết nối tài khoản" trên Admin Dashboard mới được kích hoạt.
   - Admin bấm xác nhận: Hệ thống gọi Google API thu hồi OAuth Token, xóa Refresh Token đã mã hóa, chuyển trạng thái sang `DISCONNECTED` và ghi nhật ký vào `admin_audit_logs`.

### 3.2. Logic Kiểm Tra Khóa Ngắt Kết Nối Tại API (PHP 7.4):
```php
public function disconnectAccount(string $accountId, int $adminUserId): array {
    $stmt = $this->db->prepare("
        SELECT id, display_alias, status,
               (SELECT COUNT(*) FROM cloud_files WHERE storage_account_id = sa.id AND status != 'PURGED') AS active_files
        FROM storage_accounts sa
        WHERE id = :id
    ");
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new NotFoundException("Tài khoản lưu trữ không tồn tại.");
    }

    if ((int)$account['active_files'] > 0) {
        throw new PreconditionFailedException(
            "KHÔNG THỂ NGẮT KẾT NỐI: Tài khoản vẫn còn {$account['active_files']} tệp tin hoạt động. " .
            "Hãy kích hoạt DRAINING và di chuyển toàn bộ tệp sang tài khoản khác trước."
        );
    }

    // Thực hiện ngắt kết nối an toàn
    $upd = $this->db->prepare("
        UPDATE storage_accounts 
        SET status = 'DISCONNECTED', 
            encrypted_refresh_token = NULL,
            updated_at = NOW() 
        WHERE id = :id
    ");
    $upd->execute([':id' => $accountId]);

    $this->auditLog($adminUserId, 'ACCOUNT_DISCONNECTED', "Ngắt kết nối an toàn tài khoản {$account['display_alias']}");
    return ['success' => true, 'message' => 'Tài khoản đã được ngắt kết nối an toàn.'];
}
```

---

## 4. GIÁM SÁT CAM KẾT VƯỢT (OVERCOMMIT MONITORING & METRICS)

Trong mô hình Cloud Storage thương mại, người dùng hiếm khi sử dụng hết 100% dung lượng được cấp. Do đó, hệ thống cho phép đơn vị vận hành bán tổng hạn mức logic lớn hơn dung lượng vật lý thực tế. Tuy nhiên, tỷ lệ Overcommit phải được kiểm soát chặt chẽ để tránh nguy cơ tràn đĩa vật lý tập thể.

### 4.1. Bảng Chỉ Số Đo Lường Vận Hành (Operational Metrics)
| Mã Chỉ Số | Tên Chỉ Số | Công Thức Tính Toán | Mục Đích Giám Sát |
| :--- | :--- | :--- | :--- |
| **`POOL_TOTAL_PHYSICAL`** | Tổng dung lượng vật lý | $\sum \text{total\_capacity\_bytes}$ | Quy mô vật lý toàn bộ cụm Drive |
| **`POOL_USED_PHYSICAL`** | Dung lượng vật lý đã dùng | $\sum \text{used\_capacity\_bytes}$ | Lượng dữ liệu thực tế đang chiếm trên Google Drive |
| **`POOL_FREE_PHYSICAL`** | Dung lượng vật lý còn trống | $\text{TOTAL\_PHYSICAL} - \text{USED\_PHYSICAL}$ | Khoảng trống thực tế chưa bị chiếm |
| **`POOL_RESERVED`** | Dung lượng đang tạm giữ | $\sum \text{reserved\_capacity\_bytes}$ | Dung lượng các phiên upload đang truyền dở |
| **`POOL_SAFETY_BUFFER`** | Bộ đệm an toàn tổng | $\sum (\text{total\_bytes} \times \text{safety\_percent} / 100)$ | Dự phòng chống tràn đĩa (mặc định 10%) |
| **`POOL_ALLOCATABLE_FREE`** | Dung lượng cấp phát khả dụng | $\sum \text{Allocatable Free (các tài khoản ACTIVE)}$ | Sức chứa thực tế còn lại cho các file mới |
| **`TOTAL_LOGICAL_QUOTA_ALLOCATED`** | Tổng hạn mức logic đã cấp | $\sum \text{effective\_quota\_bytes (tất cả Space)}$ | Tổng dung lượng cam kết bán cho khách hàng |
| **`TOTAL_LOGICAL_USED`** | Tổng dung lượng logic đã dùng | $\sum \text{used\_bytes (tất cả Space)}$ | Tổng dung lượng khách hàng đang thực dùng |
| **`OVERCOMMIT_RATIO`** | Tỷ lệ cam kết vượt | $\frac{\text{TOTAL\_LOGICAL\_QUOTA\_ALLOCATED}}{\text{POOL_TOTAL_PHYSICAL}}$ | Hệ số đòn bẩy dung lượng |

### 4.2. Các Ngưỡng Cảnh Báo Overcommit
- **Mức An Toàn (Healthy):** `OVERCOMMIT_RATIO < 1.5x` và `POOL_ALLOCATABLE_FREE > 25%`. Trạng thái hoạt động tối ưu.
- **Mức Cảnh Báo (Warning):** `1.5x <= OVERCOMMIT_RATIO < 2.0x` hoặc `10% <= POOL_ALLOCATABLE_FREE <= 25%`. Dashboard hiển thị cảnh báo màu vàng: *"Cần chuẩn bị bổ sung tài khoản Google Drive mới"*.
- **Mức Nguy Cấp (Critical):** `OVERCOMMIT_RATIO >= 2.0x` hoặc `POOL_ALLOCATABLE_FREE < 10%`. Dashboard chuyển màu đỏ, gửi thông báo khẩn tới Admin: *"Nguy cơ hết dung lượng vật lý! Tạm dừng cấp thêm hạn mức mới"*.

---

## 5. THUẬT TOÁN PHÂN BỔ DUNG LƯỢNG V2 (STORAGE ALLOCATOR V2)

### 5.1. Chiến Lược Cấp Phát: `MOST_FREE_SPACE`
Khi người dùng bắt đầu tải một tệp mới lên một Cloud Space:
1. **Kiểm tra hạn mức Cloud Space:**  
   Xác minh $\text{Space Used} + \text{Space Reserved} + \text{File Size} \le \text{Effective Quota}$. Nếu vượt $\rightarrow$ Báo lỗi `409 QUOTA_EXCEEDED`.
2. **Lọc tài khoản vật lý đủ điều kiện:**
   - Trạng thái bắt buộc: `status = 'ACTIVE'` và `health_status = 'HEALTHY'`.
   - Các tài khoản ở trạng thái `NEAR_FULL`, `DRAINING`, `AUTH_REQUIRED`, `OFFLINE`, `ERROR`, `DISABLED`, `DISCONNECTED` bị **loại trừ 100%**.
3. **Tính toán `allocatable_bytes`:**
   $$\text{allocatable\_bytes} = \text{total\_bytes} \times \left(1 - \frac{\text{safety\_reserve\_percent}}{100}\right) - \text{used\_bytes} - \text{reserved\_bytes}$$
4. **Lựa chọn tài khoản có `allocatable_bytes` lớn nhất:**
   Tài khoản có nhiều khoảng trống nhất sẽ được chọn để phân tán đều dữ liệu và giảm thiểu rủi ro đầy cục bộ.

### 5.2. Câu Truy Vấn Cấp Phát Nguyên Tử (Atomic Allocator SQL):
```sql
SELECT id, display_alias,
       (total_capacity_bytes * (100 - safety_reserve_percent) / 100 
        - used_capacity_bytes - reserved_capacity_bytes) AS allocatable_bytes
FROM storage_accounts
WHERE status = 'ACTIVE' 
  AND health_status = 'HEALTHY'
  AND (total_capacity_bytes * (100 - safety_reserve_percent) / 100 
       - used_capacity_bytes - reserved_capacity_bytes) >= :incoming_file_size
ORDER BY priority DESC, allocatable_bytes DESC
LIMIT 1
FOR UPDATE;
```

---

## 6. QUY TRÌNH KẾT NỐI TÀI KHOẢN MỚI (ONBOARDING WORKFLOW)

```
[Admin Portal (license_admin.php)]                      [Google OAuth Server]
                 │                                                │
                 │── 1. Bấm "Kết Nối Google Drive Mới" ──────────>│
                 │    (Yêu cầu quyền drive.file tối thiểu)        │
                 │<── 2. Admin đăng nhập & chấp thuận ủy quyền ───┘
                 │
                 ▼
[2TOOLNE Backend API]
  3. Nhận Authorization Code qua Callback bảo mật.
  4. Đổi Code lấy Refresh Token & Access Token.
  5. Gọi Drive API kiểm tra tổng dung lượng & dung lượng trống (`about.get`).
  6. Tự động tạo thư mục gốc: "2toolne_cloud_storage_root".
  7. Mã hóa Refresh Token bằng AES-256-GCM với CLOUD_MASTER_KEY.
  8. Ghi dòng dữ liệu mới vào bảng `storage_accounts` (Status: ACTIVE).
  9. Ghi vết vào `admin_audit_logs`.
  10. Trả về thông báo thành công trên giao diện Admin Dashboard.
```

---

## 7. DI CHUYỂN DỮ LIỆU TỰ ĐỘNG KHI RÚT CẠN TÀI KHOẢN (BACKGROUND DRAINING WORKER)

Để đưa tài khoản từ `DRAINING` về `EMPTY / MIGRATED`, hệ thống kích hoạt tiến trình di chuyển tệp nền:
1. Lấy danh sách tệp thuộc tài khoản `DRAINING` theo thứ tự từ tệp cũ nhất.
2. Với mỗi tệp tin:
   - Dùng `StorageAllocator` chọn tài khoản `ACTIVE` đích.
   - Sao chép trực tiếp giữa 2 tài khoản qua Google Drive Server-to-Server Copy (nếu chia sẻ quyền) hoặc Stream Pipe qua worker.
   - Xác minh tính toàn vẹn bằng SHA-256 checksum.
   - Cập nhật bản ghi `cloud_files`: `storage_account_id = target_account_id`, `provider_file_id = new_google_file_id`.
   - Xóa tệp cũ trên tài khoản nguồn.
   - Giảm `active_file_count` của tài khoản nguồn đi 1.
3. Khi `active_file_count == 0`, tự động cập nhật trạng thái tài khoản thành `EMPTY / MIGRATED`.
4. Khách hàng và các Cloud Space liên quan hoàn toàn không bị ảnh hưởng, `cloud_file_id` giữ nguyên vẹn.
