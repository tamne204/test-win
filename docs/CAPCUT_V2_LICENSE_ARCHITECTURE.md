# 🏛️ KIẾN TRÚC BẢN QUYỀN THƯƠNG MẠI 2TOOLNE AUTOEDIT FOR CAPCUT V2
## (CAPCUT V2 LICENSE ARCHITECTURE)

**Tài liệu:** `docs/CAPCUT_V2_LICENSE_ARCHITECTURE.md`  
**Phiên bản:** 1.0.0 (Phase 4 Commercial Security)  
**Phân loại:** Bảo mật & Bản quyền Thương mại

---

## 1. TỔNG QUAN KIẾN TRÚC BẢO MẬT BẢN QUYỀN

Khác biệt hoàn toàn với mô hình khóa cứng đơn lớp hoặc kiểm tra token đối xứng (HMAC) trước đây, **2TOOLNE AutoEdit for CapCut (Product V2)** thiết lập kiến trúc bản quyền **Chốt chặn kép (Dual-Layer Defense)** kết hợp với **Chữ ký số bất đối xứng Ed25519**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 1. AUTHORITATIVE SERVER (https://www.2tamne.site/)                         │
│                                                                             │
│  - Cơ sở dữ liệu: users, licenses, devices                                  │
│  - Ed25519 Private Signing Key (Bảo mật máy chủ, không phân phối client)    │
│  - Kiểm soát hạn mức thiết bị (Device Limits: 3 máy / license)              │
│  - Kiểm soát thu hồi (Revocation) & Hạn dùng (Expiry)                        │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ HTTPS (TLS Verified)
                                       │ /api/v1/capcut/activate
                                       │ /api/v1/capcut/verify
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 2. LỚP A — ELECTRON DESKTOP UX GATE                                         │
│                                                                             │
│  - Preload Isolation: contextIsolation=true, nodeIntegration=false          │
│  - Lưu trữ mã hóa cấp HĐH: safeStorage (macOS Keychain / Windows DPAPI)     │
│  - Mã hóa an toàn tại chỗ (Encrypted at Rest): secure_store.bin             │
│  - Màn hình kích hoạt lần đầu (Activation Modal) & Cập nhật License Badge   │
│  - Bàn giao signed entitlement qua luồng stdin IPC an toàn vào RAM sidecar  │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ stdin / stdout
                                       │ JSON-RPC v1
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 3. LỚP B — PYTHON CORE SIDECAR HARD GATE (autoedit-core)                     │
│                                                                             │
│  - LicenseGuard & require_entitlement()                                     │
│  - Thẩm định chữ ký số Ed25519 bằng Public Key nhúng sẵn                    │
│  - Lưu trữ phiên bản quyền duy nhất trong RAM (In-Memory Process Lifetime)   │
│  - Tuyệt đối không ghi entitlement.json dạng plaintext ra đĩa               │
│  - So khớp Device ID (bảo vệ quyền riêng tư qua SHA-256)                    │
│  - Phòng thủ lùi đồng hồ hệ thống (Clock Rollback Defense)                  │
│  - Quản lý hạn ngoại tuyến (Offline Grace: 72 giờ)                          │
│  - Từ chối thực thi chức năng thương mại qua CLI khi chưa có entitlement    │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. PHÂN LẬP SẢN PHẨM & ĐỊNH DANH BẢN QUYỀN (PRODUCT ENTITLEMENT)

1. **Product ID chuẩn**: `2toolne.capcut.v2`
2. **Quy tắc phân lập sản phẩm (Product Boundary)**:
   - Khách hàng sở hữu key cho `SLIDESHOW` (FFmpeg V1) hoặc `LABS_EXTENSION` **không được tự động cấp quyền** sử dụng CapCut V2.
   - Nếu client gửi token của sản phẩm khác, hệ thống từ chối ngay lập tức với mã lỗi: `LICENSE_WRONG_PRODUCT`.
3. **Định dạng License Key**:
   - `2TL-CAP-XXXX-XXXX-XXXX-XXXX` (16 ký tự ngẫu nhiên entropy cao, chia thành 4 nhóm).
   - Không sử dụng ID tuần tự có thể đoán trước.
   - Sau khi kích hoạt thành công, máy khách **không lưu trữ hoặc tái tạo mã kích hoạt nguyên bản** (raw license key). Chỉ lưu chỉ dấu hiển thị an toàn (`license_key_last4` hoặc `2TL-CAP-****-****-XXXX`).

---

## 3. CẤU TRÚC GÓI BẢN QUYỀN NGOẠI TUYẾN (SIGNED ENTITLEMENT ENVELOPE)

Gói bản quyền được ký số bằng Ed25519 phía máy chủ và được lưu trữ mã hóa an toàn tại chỗ (Encrypted at Rest) thông qua `electron.safeStorage` trong tệp nhị phân `secure_store.bin` cấp hệ điều hành (Apple Keychain trên macOS, Windows DPAPI trên Windows).

> [!NOTE]
> Python Sidecar nhận gói bản quyền này qua kênh IPC `stdin` và lưu giữ **duy nhất trong bộ nhớ RAM** trong suốt vòng đời của tiến trình. Tuyệt đối không có tệp `entitlement.json` dạng plaintext được lưu trữ trên đĩa.

```json
{
  "token_version": 1,
  "kid": "kid_2026_01",
  "payload": {
    "license_id": "lic_9f83a8bc...",
    "user_id": "usr_vip_888",
    "product_id": "2toolne.capcut.v2",
    "device_id": "dev_4b8f3e2a1c...",
    "plan": "PRO",
    "issued_at": 1772812800,
    "expires_at": 1804348800,
    "offline_until": 1773072000,
    "features": [
      "capcut_autoedit",
      "unlimited_export",
      "all_presets"
    ]
  },
  "signature": "base64-encoded-64-byte-ed25519-signature"
}
```

### Quy chuẩn bảo mật thông tin:
- Payload đã ký **không bao gồm**: `license_key`, `email`, `password` hay bất kỳ secret máy chủ nào.
- Chỉ lưu giữ các claims xác thực bản quyền bắt buộc về mặt kỹ thuật.
- Hiển thị trên UI dựa trên `license_key_last4` hoặc `masked_key`.

### Quy chuẩn tuần tự hóa (Canonicalization):
Trước khi ký và khi thẩm định, chuỗi JSON được chuẩn hóa với các khóa sắp xếp theo thứ tự bảng chữ cái (`ksort` trong PHP, `sort_keys=True` trong Python) và không có khoảng trắng thừa (`separators=(',', ':')`).

---

## 4. MÔ HÌNH CHUYỂN ĐỔI TRẠNG THÁI (LICENSE STATE MACHINE)

```
                            ┌────────────────────────────────────┐
                            │      Khởi Động Ứng Dụng Desktop    │
                            └─────────────────┬──────────────────┘
                                              │
                                              ▼
                             [Giải Mã Session Từ safeStorage]
                                              │
                       ┌──────────────────────┴──────────────────────┐
                       │ Không có session                            │ Có session hợp lệ
                       ▼                                             ▼
            [LICENSE_NOT_ACTIVATED]                     [Thẩm Định Chữ Ký Ed25519]
                       │                                             │
                       │                                ┌────────────┴────────────┐
                       │                                │ Chữ ký sai / Tampered   │ Chữ ký đúng
                       │                                ▼                         ▼
                       │                    [LICENSE_TOKEN_INVALID]      [So Khớp Product ID]
                       │                                                          │
                       │                                                 ┌────────┴────────┐
                       │                                                 │ Sai             │ Đúng
                       │                                                 ▼                 ▼
                       │                                     [LICENSE_WRONG_PRODUCT] [So Khớp Device ID]
                       │                                                                   │
                       │                                                          ┌────────┴────────┐
                       │                                                          │ Không khớp      │ Khớp
                       │                                                          ▼                 ▼
                       │                                              [LICENSE_DEVICE_MISMATCH] [Kiểm Tra Lùi Giờ]
                       │                                                                            │
                       │                                                                   ┌────────┴────────┐
                       │                                                                   │ Bị lùi > 12h    │ Hợp lệ
                       │                                                                   ▼                 ▼
                       │                                                    [LICENSE_ONLINE_CHECK_REQ] [Kiểm Tra Hạn]
                       │                                                                                     │
                       │                                                                            ┌────────┴────────┐
                       │                                                                            │ Quá expires_at  │ Còn hạn
                       │                                                                            ▼                 ▼
                       │                                                                    [LICENSE_EXPIRED] [Kiểm Tra Grace]
                       │                                                                                              │
                       │                                                                                     ┌────────┴────────┐
                       │                                                                                     │ Quá 72h         │ Trong 72h
                       │                                                                                     ▼                 ▼
                       ▼                                                                         [LICENSE_ONLINE_CHECK_REQ]   │
            [HIỆN MODAL KÍCH HOẠT]                                                                                            │
            • Khóa nút Tạo Dự Án                                                                          ┌───────────────────┴───────────────────┐
            • Chặn tất cả 8 lệnh                                                                          ▼                                       ▼
              thương mại trong Sidecar                                                              [LICENSE_ACTIVE]                    [LICENSE_OFFLINE_GRACE]
                                                                                                    (Mới ký trong 24h)                  (Chạy offline hợp lệ)
```

---

## 5. PHÒNG THỦ LÙI ĐỒNG HỒ HỆ THỐNG (CLOCK ROLLBACK DEFENSE)

- Tệp giám sát: `clock_guard.json` ghi nhận:
  - `last_trusted_server_time`: Mốc thời gian đáng tin cậy cao nhất nhận được từ máy chủ.
  - `last_local_check_time`: Mốc thời gian kiểm tra gần nhất của máy khách.
- **Quy tắc an ninh**: Nếu thời gian hiện tại của máy tính người dùng nhỏ hơn `last_trusted_server_time - 43,200s` (12 giờ), hệ thống phát hiện hành vi cố tình chỉnh giờ lùi để dùng lậu, lập tức khóa chức năng thương mại và yêu cầu kết nối mạng (`LICENSE_ONLINE_CHECK_REQUIRED`).

---

## 6. PHÂN LOẠI LỆNH SIDECAR (COMMAND CLASSIFICATION)

| Phân loại | Tên Lệnh IPC | Trạng thái Bản quyền Yêu cầu |
| :--- | :--- | :---: |
| **Công khai** | `PING` | Không yêu cầu |
| **Công khai** | `GET_APP_INFO` | Không yêu cầu |
| **Công khai** | `DETECT_CAPCUT` | Không yêu cầu |
| **Quản lý License** | `GET_LICENSE_STATUS` | Không yêu cầu |
| **Quản lý License** | `INSTALL_SIGNED_ENTITLEMENT` | Không yêu cầu |
| **Quản lý License** | `CLEAR_LICENSE` | Không yêu cầu |
| **Thương mại** | `GET_PRESETS` | **Bắt buộc: LICENSE_ACTIVE hoặc OFFLINE_GRACE** |
| **Thương mại** | `SAVE_CUSTOM_PRESET` | **Bắt buộc** |
| **Thương mại** | `DELETE_CUSTOM_PRESET` | **Bắt buộc** |
| **Thương mại** | `VALIDATE_INPUTS` | **Bắt buộc** |
| **Thương mại** | `BUILD_EDIT_PLAN` | **Bắt buộc** |
| **Thương mại** | `GENERATE_CAPCUT_PROJECT` | **Bắt buộc** |
| **Thương mại** | `OPEN_CAPCUT` | **Bắt buộc** |
| **Thương mại** | `GET_PROJECT_STATUS` | **Bắt buộc** |

---

## 7. BẢO TOÀN DÒNG SẢN PHẨM FFMPEG V1

- Mọi thay đổi trong Phase 4 chỉ nằm trong `apps/capcut-v2/` và các router mới `/api/v1/capcut/*` của máy chủ.
- File `license_manager.py` của V1 và cơ chế cấp phép cho FFmpeg V1 (`tests/test_mandatory_license_gate.py`, `tests/test_v1_isolation.py`) được duy trì 100% không bị ảnh hưởng.
