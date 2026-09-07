# 🔑 QUẢN LÝ KHÓA KÝ SỐ BẢN QUYỀN 2TOOLNE AUTOEDIT FOR CAPCUT V2
## (CAPCUT V2 CRYPTOGRAPHIC KEY MANAGEMENT)

**Tài liệu:** `docs/CAPCUT_V2_KEY_MANAGEMENT.md`  
**Phiên bản:** 1.0.0 (Phase 4 Commercial Security)  
**Phân loại:** Quản trị Khóa Mật mã & Vòng đời Chữ ký số

---

## 1. MÔ HÌNH CẶP KHÓA ASYMMETRIC ED25519

Hệ thống phân chia rạch ròi vai trò của hai nửa cặp khóa mật mã:

| Thành phần | Loại Khóa | Nơi Lưu Trữ | Vai Trò | Mức Độ Bảo Mật |
| :--- | :--- | :--- | :--- | :--- |
| **Server Signing Key** | **Private Key** (32-byte seed) | Duy nhất trên máy chủ xác thực | Ký số gói entitlement offline | **TỐI MẬT (SECRET)**<br>Tuyệt đối không đưa vào Git, client hay API |
| **Client Verification Key** | **Public Key** (32-byte raw) | Nhúng trong Electron & Python Sidecar | Thẩm định tính toàn vẹn chữ ký | **CÔNG KHAI (PUBLIC)**<br>An toàn khi phân phối kèm app bundle |

---

## 2. QUY TRÌNH BẢO VỆ PRIVATE KEY PHÍA MÁY CHỦ

1. **Biến môi trường độc lập**:
   - Khóa ký số được nạp thông qua biến môi trường hệ thống:
     ```bash
     export CAPCUT_ED25519_PRIVATE_KEY="<Base64-32Byte-Seed>"
     ```
   - Nằm ngoài thư mục Web Root và tệp `.env` được cấu hình trong `.gitignore`.
2. **Không lưu trữ trong cơ sở dữ liệu**:
   - Tránh nguy cơ lộ khóa khi sao lưu (database dump) hoặc khi bị tấn công SQL Injection.
3. **Không xuất hiện trong phản hồi API hay Nhật ký**:
   - API chỉ trả về chữ ký số kết quả (`signature`), không bao giờ để lộ khóa riêng.

---

## 3. CƠ CHẾ XOAY VÒNG KHÓA (KEY ROTATION QUA `kid`)

Để hỗ trợ việc định kỳ thay đổi khóa bảo mật hoặc khi một khóa bị nghi ngờ rò rỉ:
- Mỗi gói token có trường `kid` (Key Identifier), ví dụ `kid_2026_01`.
- Phía máy khách (Desktop App) lưu trữ một danh bạ các khóa công khai tin cậy:
  ```python
  TRUSTED_PUBLIC_KEYS = {
      "kid_2026_01": "+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=",
      "kid_2026_02": "...",  # Khóa công khai của đợt xoay vòng mới
  }
  ```

### Quy trình xoay vòng khóa không gián đoạn:
1. **Giai đoạn chuẩn bị (T - 30 ngày)**:
   - Sinh cặp khóa mới `kid_2026_02`.
   - Cập nhật phiên bản Desktop App mới bổ sung `kid_2026_02` vào danh bạ `TRUSTED_PUBLIC_KEYS`. Khóa cũ `kid_2026_01` vẫn được giữ để duy trì các bản nháp offline đã kích hoạt.
2. **Giai đoạn chuyển giao (T = 0)**:
   - Cấu hình máy chủ bắt đầu ký các token mới bằng `kid_2026_02`.
   - Các client cũ khi kết nối mạng sẽ được làm mới token và nhận khóa mới.
3. **Giai đoạn kết thúc (T + 90 ngày)**:
   - Thu hồi hoàn toàn `kid_2026_01` trên máy chủ và loại bỏ khỏi các bản client tiếp theo.

---

## 4. QUY TRÌNH TẠO MỚI CẶP KHÓA (KEY GENERATION PROCEDURE)

Kỹ thuật viên hệ thống có thể tạo cặp khóa mới bằng đoạn mã chuẩn:

```python
from cryptography.hazmat.primitives.asymmetric import ed25519
from cryptography.hazmat.primitives import serialization
import base64

priv = ed25519.Ed25519PrivateKey.generate()
pub = priv.public_key()

priv_b64 = base64.b64encode(priv.private_bytes(
    encoding=serialization.Encoding.Raw,
    format=serialization.PrivateFormat.Raw,
    encryption_algorithm=serialization.NoEncryption()
)).decode('utf-8')

pub_b64 = base64.b64encode(pub.public_bytes(
    encoding=serialization.Encoding.Raw,
    format=serialization.PublicFormat.Raw
)).decode('utf-8')

print("SERVER PRIVATE KEY (SAVE TO ENV):", priv_b64)
print("CLIENT PUBLIC KEY (EMBED IN APP):", pub_b64)
```
