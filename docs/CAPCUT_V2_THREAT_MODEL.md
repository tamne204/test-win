# 🎯 MÔ HÌNH PHÂN TÍCH HIỂM HỌA 2TOOLNE AUTOEDIT FOR CAPCUT V2
## (CAPCUT V2 THREAT MODEL & MITIGATION MATRIX)

**Tài liệu:** `docs/CAPCUT_V2_THREAT_MODEL.md`  
**Phiên bản:** 1.0.0 (Phase 4 Commercial Security)  
**Phân loại:** Phân tích Hiểm họa & Biện pháp Đối phó (Threat Modeling)

---

## 1. MA TRẬN PHÂN TÍCH HIỂM HỌA & GIẢI PHÁP ĐỐI PHÓ (STRIDE MODEL)

| Mã Hiểm Họa | Loại Hiểm Họa (STRIDE) | Kịch Bản Tấn Công | Hậu Quả Tiềm Tàng | Biện Pháp Đối Phó Trong Phase 4 | Trạng Thái Kiểm Thử |
| :---: | :--- | :--- | :--- | :--- | :---: |
| **T-01** | **Elevation of Privilege** | Kẻ tấn công gọi trực tiếp binary `autoedit-core` qua Terminal để chạy lệnh `GENERATE_CAPCUT_PROJECT` bỏ qua giao diện Electron. | Sử dụng lậu không cần mua bản quyền. | **Lớp B Hard Gate**: Python Core tự kiểm tra `LicenseGuard.require_entitlement()` trước khi thực thi mọi lệnh thương mại. | **PASS**<br>(Kiểm thử tự động & thực tế) |
| **T-02** | **Tampering** | Can thiệp hoặc sửa đổi nội dung payload đã ký để nâng gói từ TRIAL lên ENTERPRISE hoặc lùi ngày `expires_at`. | Kéo dài thời gian sử dụng trái phép. | **Chữ ký số Ed25519**: Mọi thay đổi trong payload làm chữ ký không khớp, lập tức kích hoạt `LICENSE_TOKEN_INVALID`. | **PASS** |
| **T-03** | **Repudiation / Spoofing** | Sử dụng key hoặc token hợp lệ của sản phẩm khác (ví dụ: Tool V1 Slideshow). | Xung đột phân khúc sản phẩm thương mại. | **Phân lập Product ID**: Server và Sidecar thẩm định nghiêm ngặt trường `product_id == "2toolne.capcut.v2"`. | **PASS** |
| **T-04** | **Information Disclosure / Cloning** | Sao chép tệp bản quyền từ Máy A sang Máy B để chạy đồng thời trên nhiều máy. | Vi phạm hạn mức thiết bị, chia sẻ key lậu. | **Ràng buộc Device ID**: Token mã hóa chứa băm phần cứng `device_id`. Máy B so khớp thất bại và trả về `LICENSE_DEVICE_MISMATCH`. | **PASS** |
| **T-05** | **Tampering (Time)** | Người dùng chỉnh lùi đồng hồ hệ điều hành (Clock Rollback) về quá khứ để lách hạn hết hạn. | Dùng vĩnh viễn key ngắn hạn. | **Clock Rollback Defense**: Giám sát `last_trusted_server_time`, nếu lùi quá 12h sẽ lập tức khóa tính năng (`LICENSE_ONLINE_CHECK_REQUIRED`). | **PASS** |
| **T-06** | **Repudiation** | Kích hoạt 1 lần rồi ngắt mạng vĩnh viễn để dùng offline mãi mãi. | Mất khả năng thu hồi bản quyền. | **Offline Grace 72 Giờ**: Token ngoại tuyến chỉ có hiệu lực tối đa 72 giờ (`offline_until`). Sau đó bắt buộc kết nối mạng để làm mới. | **PASS** |
| **T-07** | **Information Disclosure** | Đọc trộm token hoặc key lưu dạng plaintext trong tệp cấu hình máy tính. | Rò rỉ thông tin đăng nhập và bản quyền. | **OS Secure Storage**: Mã hóa bằng Apple Keychain (macOS) và Windows DPAPI. Renderer không thể đọc trực tiếp. Tuyệt đối không lưu raw key hay plaintext entitlement trên đĩa. | **PASS** |
| **T-08** | **Tampering (IPC)** | Chèn mã độc qua kênh stdin hoặc giả mạo gói tin JSON-RPC. | Thực thi mã trái phép. | Schema validation nghiêm ngặt, từ chối tham số lạ, không sử dụng `eval()`. | **PASS** |
| **T-09** | **Information Disclosure (MITM)** | Tấn công nghe lén đường truyền mạng giữa client và server. | Chiếm đoạt key khi đang kích hoạt. | **Strict TLS**: Kết nối HTTPS bảo mật, cấm vô hiệu hóa chứng chỉ (`rejectUnauthorized: true`). | **PASS** |
| **T-10** | **Information Disclosure (Build)** | Dịch ngược mã nguồn app bundle để tìm mật khẩu DB hoặc Private Key. | Rò rỉ toàn bộ hệ sinh thái. | **Kiểm toán Bundle (Zero Secret)**: Không có bất kỳ Private Key, DB pass hay OAuth secret nào trong ứng dụng khách. | **PASS** |

---

## 2. KẾT LUẬN ĐÁNH GIÁ MÔ HÌNH BẢO MẬT

Kiến trúc Phase 4 & Phase 4.1 cung cấp mức độ an toàn thương mại toàn diện, cân bằng giữa **trải nghiệm người dùng ngoại tuyến (Offline Resilience)** và **khả năng chống can thiệp, chống sao chép trái phép (Tamper-Resistant Defense)**. Mô hình thừa nhận các giới hạn cố hữu của việc thực thi mã máy khách trong môi trường thiết bị bị xâm nhập toàn diện (Fully Compromised Host), đồng thời thiết lập hệ thống phòng thủ theo chiều sâu (Defense-in-Depth) bảo vệ hiệu quả tài sản trí tuệ và quyền lợi của nhà phát hành.
