# 🛡️ MÔ HÌNH BẢO MẬT ỨNG DỤNG DESKTOP 2TOOLNE AUTOEDIT FOR CAPCUT V2
## (CAPCUT V2 DESKTOP SECURITY MODEL)

**Tài liệu:** `docs/CAPCUT_V2_DESKTOP_SECURITY_MODEL.md`  
**Phiên bản:** 1.0.0 (Phase 4 Commercial Security)  
**Phân loại:** Kiến trúc An toàn & Phòng thủ Desktop

---

## 1. NGUYÊN TẮC THIẾT KẾ AN NINH (SECURITY PRINCIPLES)

2TOOLNE AutoEdit for CapCut vận hành theo nguyên tắc **Zero Trust trong ứng dụng khách**:
1. **Renderer Process hoàn toàn không có đặc quyền (Unprivileged Sandboxed UI)**: Không có quyền gọi trực tiếp Node.js, không truy cập hệ thống tệp thô, không truy cập mạng ngoài trừ các kênh được cho phép.
2. **Main Process là chốt chặn tin cậy (Trusted Broker)**: Toàn bộ thao tác truy cập tệp nhạy cảm, lưu trữ an toàn và gọi mạng HTTPS đều do Main Process quản lý và thẩm định.
3. **Core Sidecar là chốt chặn bản quyền độc lập**: Không tin cậy mù quáng vào Electron; tự động kiểm tra chữ ký số Ed25519 cho mọi lệnh thương mại.

---

## 2. CẤU HÌNH AN NINH CỬA SỔ ELECTRON (BROWSERWINDOW HARDENING)

Trong `apps/capcut-v2/desktop/src/main/index.js`, cửa sổ ứng dụng được cấu hình nghiêm ngặt:

```javascript
webPreferences: {
  contextIsolation: true,       // BẮT BUỘC: Cô lập hoàn toàn context giữa Preload và Renderer
  nodeIntegration: false,        // BẮT BUỘC: Vô hiệu hóa Node.js trong Renderer UI
  sandbox: false,                // Preload script truy cập an toàn IPC
  devTools: process.env.AUTOEDIT_DEV === '1', // Tắt DevTools trong bản Production
  preload: path.join(__dirname, '../preload/preload.js'),
}
```

### 2.1. Chính sách An ninh Nội dung (Strict Content Security Policy - CSP)
Ứng dụng thiết lập CSP qua hook `onHeadersReceived`:
```http
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: file:; connect-src 'self' https://www.2tamne.site;
```
- **Chặn toàn bộ script từ xa**: Không cho phép nạp JavaScript từ bên ngoài (`script-src 'self'`).
- **Giới hạn kết nối mạng**: Chỉ cho phép kết nối đến máy chủ xác thực chính thức `https://www.2tamne.site`.

### 2.2. Chặn Điều Hướng Tự Do & Popups
- `mainWindow.webContents.on('will-navigate', (e) => e.preventDefault())`: Ngăn chặn mọi hành vi điều hướng cửa sổ ứng dụng tới trang web lạ.
- `mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }))`: Chặn hoàn toàn việc mở cửa sổ popup hoặc liên kết ngoài nguy hiểm.

---

## 3. LƯU TRỮ BẢO MẬT CẤP HỆ ĐIỀU HÀNH (OS SECURE STORAGE)

Mã bản quyền và token xác thực phiên không bao giờ được lưu dưới dạng văn bản thô (plaintext) trong `settings.json`, `project.json` hay `localStorage`.

Triển khai qua `apps/capcut-v2/desktop/src/main/secure_storage.js`:
- **Trên macOS**: Sử dụng **Apple Keychain Services** thông qua API `electron.safeStorage`. Khóa mã hóa được phần cứng T2/Apple Silicon Secure Enclave bảo vệ.
- **Trên Windows**: Sử dụng **Windows DPAPI (Data Protection API)** thông qua `electron.safeStorage`. Khóa mã hóa gắn liền với thông tin đăng nhập Windows của người dùng cục bộ.
- **Renderer Shield**: Renderer không thể đọc tệp lưu trữ này trực tiếp.

---

## 4. BẢO VỆ DỮ LIỆU NHẠY CẢM TRONG NHẬT KÝ (LOG REDACTION)

Hệ thống triển khai module `apps/capcut-v2/core/security/redactor.py` trên toàn bộ các kênh ghi log (stdout, stderr, diagnostics):
- **Che mờ License Key**:
  ```text
  2TL-CAP-A1B2-C3D4-E5F6-7890 ➔ 2TL-CAP-****-****-7890
  2TAMNE-VIP-ABCD-1234        ➔ 2TAMNE-VIP-****-****-1234
  ```
- **Che mờ Session Token / Signatures**:
  ```text
  sess_token_very_secret_1234567890 ➔ sess****7890
  ```
- Hàm `sanitize_diagnostics()` tự động quét đệ quy các trường JSON chẩn đoán và loại bỏ mọi thông tin nhạy cảm trước khi hiển thị cho người dùng hoặc gửi hỗ trợ kỹ thuật.

---

## 5. AN TOÀN TRUYỀN DẪN MẠNG (TRANSPORT SECURITY - TLS)

- Toàn bộ giao tiếp giữa Desktop App và máy chủ 2TOOLNE đều diễn ra qua giao thức **HTTPS (TLS 1.2 / 1.3)**.
- **Bắt buộc xác thực chứng chỉ TLS**:
  - Không sử dụng cờ `rejectUnauthorized: false`.
  - Không sử dụng `verify=False` trong Python.
  - Các chứng chỉ TLS tự ký (self-signed) bị từ chối tự động.

---

## 6. PHẠM VI AN TOÀN & GIỚI HẠN THỰC TẾ (REALISTIC SECURITY BOUNDARIES)

Hệ thống được thiết kế theo mô hình phòng thủ theo chiều sâu (Defense-in-Depth), có khả năng chống can thiệp (Tamper-Resistant) và chống sao chép trái phép (Resistant to casual copying/forgery). Tuy nhiên, theo các nguyên lý an ninh máy khách (Client-Side Security Model):
1. **Thiết bị bị chiếm quyền kiểm soát (Fully Compromised Host)**: Nếu máy khách bị can thiệp bởi người dùng có đặc quyền quản trị viên cao nhất (Root/Admin), can thiệp trực tiếp qua bộ gỡ lỗi (Memory / Kernel Debugger) hoặc chỉnh sửa nhị phân đã biên dịch, các chốt chặn phía client có thể bị ảnh hưởng cục bộ trên máy đó.
2. **Nguyên tắc thẩm định**: Hệ thống không sử dụng các tuyên bố tuyệt đối hóa phi thực tế ("unbypassable", "uncrackable", "absolutely secure"), mà áp dụng các biện pháp an ninh vững chắc: chữ ký số Ed25519, ràng buộc thiết bị, mã hóa tại chỗ bằng OS Keychain/DPAPI, và máy chủ giữ vai trò trọng tài tối cao (Server-Authoritative).
