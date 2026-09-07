# 2TOOLNE AUTOEDIT FOR CAPCUT V2 — PHASE 4.1 LICENSE STORAGE AUDIT REPORT

## 1. BẢNG TRẠNG THÁI TIÊU CHUẨN KIỂM TOÁN LƯU TRỮ (EXACT KEY-VALUE PAIRS)

```ini
LICENSE_KEY_IN_ENTITLEMENT_BEFORE = YES (Present in test envelopes & redundant references)
LICENSE_KEY_IN_ENTITLEMENT_AFTER = NO (Removed; payload contains only license_id, user_id, product_id, device_id, plan, issued_at, expires_at, offline_until, features; UI displays non-sensitive license_key_last4 / masked hint)

ENTITLEMENT_STORAGE_BEFORE = PLAINTEXT_JSON (Written to ~/Library/Application Support/2toolne AutoEdit/entitlement.json by sidecar)
ENTITLEMENT_STORAGE_AFTER = ENCRYPTED_SAFE_STORAGE (Encrypted by Main in secure_store.bin via electron.safeStorage Keychain/DPAPI; In-memory RAM only in Python Sidecar process)

SAFE_STORAGE_ENCRYPTED_AT_REST = YES

SIDECAR_INDEPENDENT_GATE = PASS

RAW_LICENSE_KEY_FOUND_ON_DISK = NO

RAW_LICENSE_KEY_FOUND_IN_LOGS = NO

PRIVATE_KEY_IN_CLIENT = NO

MAC_SECURE_STORAGE_PHYSICAL_TEST = PASS

WINDOWS_DPAPI_IMPLEMENTED = YES
WINDOWS_DPAPI_PHYSICAL_VALIDATION = UNTESTED (macOS environment)

SECURITY_ABSOLUTE_CLAIMS_REMOVED = YES

V1_REGRESSION = PASS
V2_REGRESSION = PASS

FINAL_STATUS = CAPCUT_V2_LICENSE_STORAGE_HARDENED
```

---

## 2. CHI TIẾT KIỂM TOÁN & SỬA ĐỔI KỸ THUẬT

### 2.1. Kiểm Toán Payload Ký Số (Entitlement Payload Audit)
- **Trước chỉnh sửa**: Trong các bộ sinh token thử nghiệm và tài liệu cũ, `license_key` vẫn được đưa vào payload của signed envelope.
- **Sau chỉnh sửa**:
  - `license_key` bị **LOẠI BỎ HOÀN TOÀN** khỏi payload ký số trên cả Server Controller ([`CapCutLicenseController.php`](file:///Users/2tamne/tool%20ffmpeg/website/api/v1/controllers/CapCutLicenseController.php)) lẫn Python Core ([`license_guard.py`](file:///Users/2tamne/tool%20ffmpeg/apps/capcut-v2/core/security/license_guard.py)).
  - Payload ký số chỉ gồm:
    ```json
    {
      "license_id": "lic_...",
      "user_id": "usr_...",
      "product_id": "2toolne.capcut.v2",
      "device_id": "dev_...",
      "plan": "PRO",
      "issued_at": 1788712800,
      "expires_at": 1804348800,
      "offline_until": 1788972000,
      "features": ["capcut_autoedit", "unlimited_export", "all_presets"]
    }
    ```
  - Không chứa: `license_key`, `email`, `password`, hay bất kỳ secret máy chủ nào.

### 2.2. Chỉ Dấu Hiển Thị Bản Quyền (License Display Hint)
- Khi kích hoạt thành công, server trả về `license_key_last4` và `masked_key` (ví dụ: `2TL-CAP-****-****-7890`).
- Máy khách **không bao giờ lưu trữ hoặc tái tạo lại raw license key**.
- Giao diện UI chỉ hiển thị chuỗi đã che mặt số, ô nhập liệu kích hoạt được xóa sạch (`value = ''`) ngay sau khi kích hoạt thành công.

### 2.3. Kiểm Toán Lưu Trữ Thực Tế & Mã Hóa Tại Chỗ (Encrypted at Rest)
- **Khảo sát thực tế đường dẫn macOS**:
  - `~/Library/Application Support/2toolne AutoEdit/`
  - Đã xóa bỏ cơ chế ghi `entitlement.json` dạng plaintext của Python sidecar.
  - Tệp `clock_guard.json` được phân loại là **NON-SENSITIVE** (chỉ chứa 2 timestamp số nguyên `last_trusted_server_time` và `last_local_check_time` phục vụ chống lùi giờ, không chứa thông tin đăng nhập hay mã bản quyền).
- **Cơ chế lưu trữ an toàn OS SafeStorage**:
  - Desktop Main Process sở hữu `secureStorage` thông qua `electron.safeStorage`:
    - macOS: Apple Keychain Services.
    - Windows: Windows DPAPI.
  - Tệp lưu trữ `secure_store.bin` chứa nhị phân đã được mã hóa. Đọc bằng text editor thông thường hoàn toàn không thấy chuỗi JSON hay token đọc được.
  - Renderer Process không thể đọc tệp này.

### 2.4. Mô Hình Bàn Giao An Toàn Cho Sidecar (In-Memory Session)
- Electron Main giải mã `entitlement_envelope` từ `secure_store.bin` khi khởi động ứng dụng.
- Truyền gói bản quyền đã giải mã qua luồng chuẩn `stdin` IPC cho tiến trình Python Sidecar qua lệnh `INSTALL_SIGNED_ENTITLEMENT`.
- Python Sidecar:
  - Thẩm định chữ ký số Ed25519 với public key nhúng sẵn.
  - Lưu giữ session **DUY NHẤT TRONG BỘ NHỚ RAM** (`_in_memory_envelope`) trong suốt vòng đời của tiến trình.
  - Tuyệt đối không ghi bản sao `entitlement.json` thứ hai ra đĩa.
  - Khi tiến trình Sidecar tắt, bộ nhớ tự động giải phóng.

### 2.5. Bảo Toàn Chốt Chặn Cứng Layer B (Direct CLI Hard Gate)
- Gọi trực tiếp binary độc lập `apps/capcut-v2/packaging/dist/autoedit-core/autoedit-core` mà không có Electron cấp entitlement:
  ```json
  {"id": "test_direct", "protocol": 1, "ok": false, "error": {"code": "LICENSE_NOT_ACTIVATED", "message": "Chưa kích hoạt mã bản quyền trên thiết bị này."}}
  ```
  Lập tức bị từ chối với `LICENSE_NOT_ACTIVATED`.

### 2.6. Quét Tìm Khóa Nguyên Bản (Raw Key Scan)
- Kích hoạt thử nghiệm bằng khóa dùng 1 lần `2TL-CAP-DISP-OSK1-TEST-9999`.
- Quét đệ quy toàn bộ thư mục app data của V2:
  `RAW_LICENSE_KEY_FOUND_ON_DISK = NO`
- Quét nhật ký console và chẩn đoán:
  `RAW_LICENSE_KEY_FOUND_IN_LOGS = NO`

### 2.7. Loại Bỏ Các Tuyên Bố Tuyệt Đối Hóa (Terminology Correction)
- Đã loại bỏ các từ ngữ tuyệt đối hóa ("unbypassable", "uncrackable", "absolutely secure", "an toàn tuyệt đối") trong toàn bộ tài liệu:
  - `docs/CAPCUT_V2_LICENSE_ARCHITECTURE.md`
  - `docs/CAPCUT_V2_DESKTOP_SECURITY_MODEL.md`
  - `docs/CAPCUT_V2_THREAT_MODEL.md`
  - `CAPCUT_V2_COMMERCIAL_SECURITY_REPORT.md`
- Sử dụng các thuật ngữ kỹ thuật chính xác: "tamper-resistant", "server-authoritative", "cryptographically signed", "defense-in-depth", "resistant to casual copying/forgery".
- Ghi nhận rõ giới hạn: Nếu máy khách bị can thiệp bởi người dùng có toàn quyền quản trị (Root/Admin) dùng kernel debugger can thiệp vào bộ nhớ RAM, họ có thể sửa đổi logic kiểm tra phía client.

---

## 3. KẾT QUẢ KIỂM CHỨNG VẬT LÝ TRÊN MACOS

Kịch bản vật lý [`tests/validate_real_desktop_security.py`](file:///Users/2tamne/tool%20ffmpeg/tests/validate_real_desktop_security.py) trên macOS Apple Silicon:
1. **Activate**: Kích hoạt thành công với Ed25519 token nạp vào RAM. Xác nhận không có `entitlement.json` trên đĩa.
2. **Close App**: Đóng ứng dụng hoàn toàn.
3. **Reopen**: Mở lại ứng dụng, khôi phục session đã giải mã từ safeStorage qua stdin IPC $\rightarrow$ `LICENSE_ACTIVE`.
4. **Offline Verification**: Kiểm tra ngoại tuyến trong grace window 72h $\rightarrow$ Hợp lệ.
5. **Generate CapCut Project**: Sinh dự án CapCut Draft đầy đủ media, keyframe, subtitle.
6. **Thẩm Định Draft**: `CapCutDraftValidator` xác nhận 0 lỗi.
7. **Launch CapCut**: CapCut Desktop 9.3.0 mở dự án thành công.
8. **Deactivate**: Hủy kích hoạt $\rightarrow$ Khóa tính năng thương mại ngay lập tức.
9. **Reopen After Deactivate**: Mở lại ứng dụng $\rightarrow$ Giữ nguyên trạng thái khóa `LICENSE_NOT_ACTIVATED`.

---

## 4. KẾT LUẬN & PHÁN QUYẾT

```
============================================================
PHÁN QUYẾT: CAPCUT_V2_LICENSE_STORAGE_HARDENED
============================================================
```
