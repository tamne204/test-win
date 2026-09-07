# 📝 PHIẾU BÁO CÁO KẾT QUẢ KIỂM THỬ WINDOWS THỰC TẾ
## EXTERNAL WINDOWS LAB TEST REPORT — 2TOOLNE AUTOEDIT FOR CAPCUT V2

**Người kiểm thử (Tester Name)**: _______________________________  
**Ngày thực hiện (Test Date)**: _______________________________  
**Phiên bản ứng dụng**: 2toolne AutoEdit Setup 2.0.0.exe  

---

### 💻 1. THÔNG TIN CẤU HÌNH THIẾT BỊ KIỂM THỬ

| Thông số | Giá trị thực tế trên máy kiểm thử |
|:---|:---|
| **Phiên bản Windows** | (Ví dụ: Windows 11 Pro 23H2 / Windows 10 Home 22H2) |
| **Hãng & Đời CPU** | (Ví dụ: Intel Core i5-12400F / AMD Ryzen 5 5600X) |
| **Dung lượng RAM** | (Ví dụ: 16 GB DDR4 / 32 GB DDR5) |
| **Card đồ họa (GPU)** | (Ví dụ: NVIDIA GeForce RTX 3060 12GB / Intel UHD 730) |
| **Phiên bản CapCut Desktop** | (Ví dụ: CapCut Desktop 9.3.0 Build 1420) |

---

### ✅ 2. BẢNG CHECKLIST KẾT QUẢ THỬ NGHIỆM
*(Vui lòng đánh dấu `[x]` vào các mục đạt hoặc `[ ]` nếu gặp lỗi)*

#### A. Cài đặt & Khởi động
- [ ] **Application installed**: Bộ cài NSIS cài đặt suôn sẻ, tạo đúng shortcut Desktop.
- [ ] **Application opened**: Ứng dụng mở giao diện đồ họa mượt mà.
- [ ] **No black CMD window**: Tuyệt đối không nhấp nháy cửa sổ đen Command Prompt khi mở app hoặc chạy tác vụ.
- [ ] **CapCut detected**: Giao diện hiển thị đúng nhãn phát hiện phiên bản CapCut Desktop.

#### B. Bản quyền & Lưu trữ an toàn (DPAPI)
- [ ] **Activation succeeded**: Kích hoạt thành công mã bản quyền kiểm thử.
- [ ] **Restart kept activation**: Thoát app hoàn toàn và mở lại, trạng thái bản quyền vẫn giữ nguyên.
- [ ] **Offline mode worked**: Rút mạng Internet, app vẫn chạy bình thường với chế độ ngoại tuyến (Offline grace).

#### C. Tạo dự án & Tương thích CapCut
- [ ] **Script-to-SRT alignment succeeded**: Căn chỉnh kịch bản văn bản với giọng nói thành công, hộp thoại Preview hiển thị đúng 100% chữ kịch bản gốc.
- [ ] **Subtitle editing in Preview succeeded**: Thao tác chỉnh sửa câu phụ đề trong bảng Preview diễn ra mượt mà.
- [ ] **Project generated**: Tạo dự án thành công từ các tệp mẫu trong `TEST_MEDIA/`.
- [ ] **Project appeared in CapCut**: Mở CapCut Desktop, dự án xuất hiện ngay ở đầu danh sách.
- [ ] **Timeline editable**: Dự án mở lên bình thường, có đủ 3 clip, kéo thả di chuyển clip trơn tru.
- [ ] **Native keyframes visible**: Các hiệu ứng Zoom In, Zoom Out, Pan Left hiển thị keyframe native và chuyển động mượt.
- [ ] **Audio correct**: Âm thanh phát đúng, to rõ và đồng bộ với hình ảnh.
- [ ] **Vietnamese subtitle correct**: Phụ đề tiếng Việt hiển thị đẹp, chuẩn font, không lỗi dấu.
- [ ] **Save succeeded**: Chỉnh sửa timeline và bấm `Ctrl + S` lưu dự án thành công.
- [ ] **Reopen succeeded**: Đóng CapCut và mở lại dự án, mọi chỉnh sửa được bảo toàn, không crash.

#### D. Dọn dẹp & Gỡ cài đặt
- [ ] **Deactivate succeeded**: Hủy kích hoạt bản quyền thành công, thông tin DPAPI được xóa sạch.
- [ ] **Uninstall succeeded**: Gỡ cài đặt qua Windows Settings diễn ra sạch sẽ, không sót tiến trình ngầm.

---

### 📷 3. HÌNH ẢNH CHỨNG MINH & CHỤP MÀN HÌNH (SCREENSHOTS)
*(Vui lòng đính kèm ảnh chụp màn hình Timeline CapCut khi mở dự án và ảnh chụp lỗi nếu có)*

- Ảnh 1 (Timeline CapCut mở dự án thành công): `[Đính kèm file hoặc dán link]`
- Ảnh 2 (Thông tin About CapCut Desktop): `[Đính kèm file]`
- Ảnh 3 (Lỗi phát sinh - nếu có): `[Đính kèm file]`

---

### 💬 4. NHẬN XÉT & GHI CHÚ BỔ SUNG (TESTER NOTES)

(Ghi rõ bất kỳ hiện tượng lạ, giật lag, thông báo cảnh báo hoặc đề xuất cải tiến nào bạn nhận thấy):

_______________________________________________________________________________

_______________________________________________________________________________

---

**Xác nhận của Tester**: [ ] Đã hoàn thành toàn bộ bài kiểm tra theo đúng quy trình.
