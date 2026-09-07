# 📋 THÔNG SỐ TÍCH HỢP DÀNH CHO AGENT QUẢN LÝ HỆ THỐNG WEB (WEB TEAM)
## HỆ THỐNG KẾT NỐI: WEB SERVER (`2tamne.site`) & DESKTOP APP (`2toolne AutoEdit for CapCut V2`)

---

**Người gửi:** Đội Ngũ Phát Triển Desktop App & Kiến Trúc Hệ Thống  
**Người nhận:** Agent Quản Lý Hệ Thống Web (Web Lead Agent & Web Team)  
**Mục đích:** Ghi nhận và cam kết duy trì 04 quy chuẩn kỹ thuật cốt lõi để đảm bảo tương thích 100% giữa Web Backend và Desktop App CapCut V2.  
**Ngày ban hành:** 07/09/2026  
**Trạng thái:** **ACTIVE & ENFORCED / ĐANG HIỆU LỰC TRÊN TOÀN HỆ THỐNG**  

---

## 📌 BẢNG 04 QUY CHUẨN KỸ THUẬT BẮT BUỘC

| STT | Quy Chuẩn Kỹ Thuật | Chi Tiết Cấu Hình & Vị Trí Code | Mục Đích & Cam Kết Duy Trì |
| :---: | :--- | :--- | :--- |
| **1** | **Cấu hình Buffered Query cho PDO** | • `website/storage/db.php`<br>• `website/api/v1/Database.php`<br>`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true` | Tránh lỗi `General error: 2014 Cannot execute queries while other unbuffered queries are active` khi app client gọi đồng thời nhiều API. |
| **2** | **Định dạng License Key chuẩn** | `2TL-CAP-XXXX-YYYY-ZZZZ-WWWW`<br>• Bảng mã Crockford Base32 (32 ký tự)<br>• Tuyệt đối không chứa `0`, `O`, `1`, `I`<br>• 16 ký tự mã hóa chia 4 block | Loại trừ 100% nhầm lẫn khi người dùng nhập tay. Key lưu trữ an toàn kèm mã băm HMAC-SHA256, hiển thị masked key cho admin. |
| **3** | **Cổng thanh toán tự động SePay** | • `website/sepay_ipn.php`<br>• Nhận diện tag `2toolne.capcut.v2` hoặc `CAPCUT_V2`<br>• Tạo key qua `generate_capcut_v2_key()` | Tự động sinh key bản quyền chuẩn CapCut V2 và kích hoạt ngay vào ví tài khoản user khi khách hàng quét mã QR thanh toán. |
| **4** | **Bộ 3 API Kích hoạt Bản quyền chính thức** | • `POST https://www.2tamne.site/api/v1/capcut/activate`<br>• `POST https://www.2tamne.site/api/v1/capcut/verify`<br>• `POST https://www.2tamne.site/api/v1/capcut/deactivate` | Cấp token Ed25519, kiểm tra tính hợp lệ HWID/thời hạn, và thu hồi giấy phép khi người dùng đổi máy (deactivate/reset). |

---

## 🔍 CHI TIẾT ĐẶC TẢ KỸ THUẬT

### 1. Cấu hình PDO Connection (`website/storage/db.php` & `website/api/v1/Database.php`)
```php
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // BẮT BUỘC DUY TRÌ
];
```

### 2. Định dạng Key & Sinh Key (`website/storage/db.php`)
```php
function generate_capcut_v2_key(): string {
    // 32-character Crockford Base32 alphabet (no 0, O, 1, I to avoid ambiguity)
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $alpha_len = strlen($alphabet);
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, $alpha_len - 1)];
        }
        $groups[] = $chunk;
    }
    return '2TL-CAP-' . implode('-', $groups);
}
```

### 3. Tự động hóa Webhook SePay IPN (`website/sepay_ipn.php`)
```php
$is_capcut = ($prod === '2toolne.capcut.v2' || $prod === 'CAPCUT_V2' || stripos($pkg_n, 'CapCut') !== false || stripos($pkg_n, 'AutoEdit') !== false);
if ($is_capcut) {
    $prod_tag = '2toolne.capcut.v2';
    $new_key  = generate_capcut_v2_key();
    db_create_capcut_v2_license($new_key, $tier, $days, $owner_user, "Đơn tự động: {$ord_id}");
}
```

### 4. Hợp đồng API Endpoint (API Contract)

#### A. Kích hoạt thiết bị (`POST /api/v1/capcut/activate`)
- **Headers**: `Content-Type: application/json`
- Trả về Token có chữ ký số Ed25519 và thời hạn bản quyền.

#### B. Xác thực token định kỳ (`POST /api/v1/capcut/verify`)
- **Headers**: `Content-Type: application/json`
- Kiểm tra tính hợp lệ của token và HWID.

#### C. Hủy liên kết máy / Đổi thiết bị (`POST /api/v1/capcut/deactivate`)
- **Headers**: `Content-Type: application/json`
- Giải phóng HWID trên database, cho phép khách hàng chuyển bản quyền sang máy tính mới.

---

## 🔒 CAM KẾT ĐỒNG BỘ
- Web Team cam kết duy trì nguyên vẹn 04 thông số trên trong mọi lần triển khai và bảo trì hệ thống.
- Mọi thay đổi liên quan đến cấu trúc bảng `licenses`, bảng mã key hoặc route `/api/v1/capcut/*` bắt buộc phải thông qua thỏa thuận kỹ thuật với Desktop App Team trước khi thực hiện.
