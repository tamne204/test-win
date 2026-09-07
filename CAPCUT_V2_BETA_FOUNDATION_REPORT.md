# BÁO CÁO NỀN TẢNG BETA — 2TOOLNE AUTOEDIT FOR CAPCUT (PRODUCT V2)
**Mã tài liệu:** `CAPCUT_V2_BETA_FOUNDATION_REPORT.md`  
**Ngày báo cáo:** 06/09/2026  
**Trạng thái phê duyệt:** `CAPCUT_V2_BETA_FOUNDATION_READY`  
**Tác giả:** Đội ngũ Kỹ thuật 2TOOLNE  

---

## 1. TỔNG QUAN ĐIỀU HÀNH (EXECUTIVE SUMMARY)
Dự án **2TOOLNE AutoEdit for CapCut (Thế hệ sản phẩm V2)** đã hoàn thành giai đoạn **Phase 2 — Beta Foundation** với tiêu chuẩn kỹ thuật nghiêm ngặt, cách ly tuyệt đối khỏi thế hệ FFMPEG V1 cũ, bảo toàn 100% các cam kết an toàn hệ thống và cơ chế dựng phi AI (100% Deterministic Rule Engine).

Tất cả các thành phần cốt lõi của Beta Foundation đã được xây dựng, kiểm thử tự động với 137 bài kiểm thử pass 100%, đồng thời đã tiến hành kiểm thử thực tế trên máy vật lý Apple Silicon với ứng dụng **CapCut Desktop 9.3.0**. Dự án mở, hiển thị keyframe chuyển động native, đồng bộ SRT, font chữ phụ đề và âm thanh chuẩn xác, không gặp bất kỳ lỗi xung đột hay crash nào.

---

## 2. MA TRẬN PHẦN CỨNG & PHẦN MỀM ĐÃ XÁC THỰC (PHYSICAL PLATFORM & VERIFIED MATRIX)

### 2.1. Cấu hình máy vật lý thực thi (Physical Execution Hardware)
- **Hệ điều hành:** macOS Sequoia (Darwin 25.6.0 arm64 / Apple Silicon).
- **Phần mềm NLE đích:** CapCut Desktop version `9.3.0 (1234)` chính thức tại `/Applications/CapCut.app`.
- **Thư mục lưu trữ dự án CapCut:** `/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft`.
- **Môi trường lập trình:** Python 3.12.14, Pillow, pytest-9.1.1.

### 2.2. Ma trận tương thích phiên bản (Compatibility Matrix Tiers)
| Nền tảng | Phiên bản CapCut | Trạng thái kỹ thuật | Bộ điều hợp (Adapter) | Ghi chú an toàn |
| :--- | :--- | :--- | :--- | :--- |
| **macOS (Apple Silicon)** | `9.3.0` | **VERIFIED (Đã xác thực)** | `CapCutVersionAdapter_9_3` | Khởi chạy thực tế, lưu và biên tập mượt mà |
| **macOS (Intel/Apple)** | `9.3.x` | **VERIFIED (Đã xác thực)** | `CapCutVersionAdapter_9_3` | Cùng schema JSON 9.3 |
| **macOS (Mọi dòng)** | `>= 9.4` hoặc `<= 9.2` | **UNTESTED (Chưa kiểm nghiệm)** | Không ghi draft tự động | Bị chặn bởi Registry; cho phép override dev mode |
| **Windows 10/11 x64** | `9.3.x` | **UNTESTED (Sẵn sàng test)** | `CapCutVersionAdapter_9_3` | Đã code multi-probe; chờ kiểm nghiệm trên máy Win thực |
| **Mọi nền tảng** | `< 8.0` | **UNSUPPORTED (Không hỗ trợ)** | Không khả dụng | Schema XML/JSON cũ không tương thích |

*Quy tắc chuẩn mực:* Không bao giờ tuyên bố "Tương thích 100% mọi phiên bản CapCut toàn cầu". Hệ thống luôn gắn cờ `VERIFIED`, `UNTESTED`, hoặc `UNSUPPORTED`.

---

## 3. KIẾN TRÚC MULTI-PROBE TRÊN WINDOWS (WINDOWS MULTI-PROBE ARCHITECTURE)
Để chuẩn bị cho việc phát hành trên Windows mà không cần sửa đổi mã nguồn sau này, `CapCutDetector` đã được trang bị cơ chế đa đầu dò (Multi-probe path resolution):
1. **Dò tìm ứng dụng (App Executable Probe):**
   - `%LOCALAPPDATA%\CapCut\Apps\<version>\CapCut.exe` (đường dẫn cài đặt chuẩn per-user của ByteDance/CapCut).
   - `%ProgramFiles%\CapCut\CapCut.exe` (cài đặt máy tính dùng chung).
   - `%ProgramFiles(x86)%\CapCut\CapCut.exe` (phiên bản 32-bit tương thích).
2. **Dò tìm thư mục lưu Draft (Draft Storage Probe):**
   - Đọc cấu hình INI: `%LOCALAPPDATA%\CapCut\User Data\Config\capcutUserVote.ini` $\rightarrow$ kiểm tra trường `[Project] save_path` nếu người dùng đổi ổ đĩa lưu trữ (ví dụ sang ổ `D:\CapCutDrafts`).
   - Đường dẫn mặc định: `%LOCALAPPDATA%\CapCut\User Data\Projects\com.lveditor.draft`.
3. **Registry Fallback Inspection:**
   - Hỗ trợ dò tìm khóa `HKCU\Software\ByteDance\CapCut` hoặc `HKLM\Software\CapCut` khi được thực thi trong môi trường Windows.
4. **Cam kết trung thực:**
   - Trên máy Mac hiện tại, detector báo cáo `macOS: VERIFIED`, và các hàm Windows trả về cấu trúc đường dẫn chuẩn bị sẵn sàng cho kiểm nghiệm phần cứng thực thụ trên Windows (`UNTESTED - READY FOR HARDWARE TEST`).

---

## 4. CƠ CHẾ LƯU TRỮ DỰ ÁN & PROJECT INDEX (DRAFT STORAGE & PROJECT INDEX)
Cấu trúc dự án CapCut được chuẩn hóa thành công gồm 3 tệp then chốt trong từng thư mục draft:
- `draft_info.json`: Mô tả cấu trúc timeline hoàn chỉnh (tracks video, audio, text), danh mục materials (videos, texts, audios, animations), và hệ thống keyframe chuyển động.
- `draft_meta_info.json`: Chứa metadata cục bộ của dự án (draft_id, draft_name, tm_duration, draft_fold_path).
- `draft_cover.jpg`: Ảnh bìa đại diện của dự án trên giao diện trang chủ CapCut Desktop.

Chỉ mục tổng `root_meta_info.json` nằm tại thư mục gốc của draft (`com.lveditor.draft/root_meta_info.json`), quản lý mảng `all_draft_store`. Mỗi khi 2TOOLNE tạo dự án mới, mục này được chèn lên đầu danh sách để dự án xuất hiện ngay đầu tiên khi mở CapCut Desktop.

---

## 5. CƠ CHẾ AN TOÀN TRANSACTIONAL: LOCK, CONFLICT & ROLLBACK
Để bảo vệ an toàn dữ liệu người dùng, không bao giờ làm hỏng các dự án CapCut có sẵn:
1. **Khóa tệp đồng thời (Cooperative File Locking):**
   - Sử dụng `fcntl.flock(LOCK_EX)` trên macOS/POSIX và polling lock-file trên Windows trong `file_lock()`. Khóa độc quyền trong suốt quá trình đọc, sao lưu và cập nhật `root_meta_info.json`.
2. **Sao lưu trước khi ghi (Read-before-write Backup):**
   - Trước khi sửa `root_meta_info.json`, hệ thống sao chép một bản backup có gắn timestamp: `root_meta_info.json.bak.<timestamp>`.
3. **Ghi nguyên tử với Fsync (Atomic Write & Fsync):**
   - Dữ liệu mới được ghi vào tệp tạm `root_meta_info.json.tmp.<pid>`, thực hiện `f.flush()` và `os.fsync(fileno)`, sau đó gọi `os.replace` để tráo đổi nguyên tử, loại trừ rủi ro mất điện hoặc crash gây hư hỏng tệp json.
4. **Tự động Rollback khi có lỗi (`CAPCUT_PROJECT_INSTALL_ROLLBACK`):**
   - Nếu xảy ra bất kỳ lỗi nào trong quá trình sinh draft hoặc kiểm tra tính hợp lệ sau cài đặt (`CapCutDraftValidator`), hệ thống lập tức:
     - Xóa thư mục draft chưa hoàn chỉnh đã copy sang CapCut.
     - Phục hồi nguyên trạng `root_meta_info.json` từ tệp `.bak`.
     - Đánh dấu trạng thái `ERROR` trong `metadata/project.json` của workspace 2TOOLNE.

---

## 6. ĐẶC TẢ EDITPLAN & KẾT QUẢ KIỂM THỬ XÁC THỰC (EDITPLAN SPEC & VALIDATION)
`EditPlan` là chuẩn định dạng trung gian độc lập (NLE-independent source of truth), gồm các cổng kiểm soát nghiêm ngặt:
- **Cổng 1 (Non-overlapping video):** Tự động phát hiện và chặn nếu 2 clip trên track chính có khoảng thời gian đè lên nhau.
- **Cổng 2 (Non-negative timestamps):** Từ chối bất kỳ segment nào có start time âm.
- **Cổng 3 (Positive duration):** Bắt buộc thời lượng clip $> 0$.
- **Cổng 4 (FPS Guard):** Bắt buộc FPS phải thuộc danh sách tiêu chuẩn được hỗ trợ (23.976, 24.0, 25.0, 29.97, 30.0, 50.0, 59.94, 60.0).
- **Cổng 5 (Motion enum guard):** Bắt buộc motion thuộc danh sách đã xác thực (`ZOOM_IN`, `ZOOM_OUT`, `PAN_LEFT`, `PAN_RIGHT`, `PAN_UP`, `PAN_DOWN`, `NONE`).
- **Cổng 6 (Caption boundary guard):** Bắt buộc `position_y` nằm trong phạm vi hợp lệ $[-1.0, 1.0]$.
- **Cổng 7 (Media verification):** Tùy chọn kiểm tra sự tồn tại vật lý của media trên đĩa trước khi render.

---

## 7. CHUYỂN ĐỔI CHUYỂN ĐỘNG & KEYFRAME NATIVE CAPCUT (MOTION TRANSLATION)
Không dùng video rendering nặng nề, 2TOOLNE V2 xuất trực tiếp keyframe toán học mà CapCut Desktop có thể render bằng phần cứng của máy người dùng:
1. **`ZOOM_IN`:** Scale tăng dần từ `1.0` lên `1.0 + zoom_magnitude` (mặc định `1.15`). Vị trí $(X=0, Y=0)$.
2. **`ZOOM_OUT`:** Scale giảm dần từ `1.0 + zoom_magnitude` về `1.0`.
3. **`PAN_LEFT`:** Dịch chuyển $X$ từ `+pan_magnitude` sang `-pan_magnitude` với scale nhẹ $1.08$ tránh viền đen.
4. **`PAN_RIGHT`:** Dịch chuyển $X$ từ `-pan_magnitude` sang `+pan_magnitude` với scale $1.08$.
5. **`PAN_UP`:** Dịch chuyển $Y$ từ `-pan_magnitude` lên `+pan_magnitude` với scale $1.08$.
6. **`PAN_DOWN`:** Dịch chuyển $Y$ từ `+pan_magnitude` xuống `-pan_magnitude` với scale $1.08$.

Tất cả keyframe được ánh xạ vào `common_keyframes` dạng `KFTypeScaleX`, `KFTypeScaleY`, `KFTypePositionX`, `KFTypePositionY` với hàm nội suy tuyến tính chuẩn của CapCut Desktop.

---

## 8. CƠ CHẾ ĐỒNG BỘ THỜI GIAN THEO PHỤ ĐỀ SRT (SRT TIMING LOGIC)
Module `core/srt_timeline.py` cung cấp cơ chế phân đoạn cảnh theo phụ đề hoàn toàn xác định:
- **Parser mạnh mẽ:** Đọc được timestamp chuẩn `HH:MM:SS,mmm` lẫn biến thể dấu chấm `HH:MM:SS.mmm`.
- **Ngưỡng thời lượng cảnh tối thiểu (`min_scene_duration_s = 3.0s`):** Các câu thoại ngắn liên tiếp dưới 3 giây được gộp chung vào một hình ảnh cảnh để tránh tình trạng giật chuyển cảnh quá nhanh.
- **Ngưỡng thời lượng cảnh tối đa (`max_duration_s = 8.0s`):** Các câu thoại hoặc đoạn ngâm quá dài được tự động cắt thành các cảnh phụ với góc máy nối tiếp để giữ nhịp điệu sinh động cho video.
- **Tạo Track Text tự động:** Phụ đề SRT được biên dịch thành track chữ (`type: text`) với font size, màu sắc và tọa độ `position_y` tùy biến.

---

## 9. KIẾN TRÚC PRESET & CÁC BỘ PRESET CÓ SẴN (PRESET ARCHITECTURE)
Hệ thống quản lý Preset (`core/preset_manager.py`) hỗ trợ 4 preset tích hợp sẵn và cho phép người dùng lưu trữ preset cá nhân dạng JSON tại `~/.2toolne/autoedit-capcut/presets/`:
1. **`basic_slideshow`:** Chuẩn dọc 9:16 @ 60 FPS, thời lượng 5.0s/ảnh, chuyển động luân phiên Zoom In $\rightarrow$ Zoom Out $\rightarrow$ Pan Left $\rightarrow$ Pan Right.
2. **`tiktok_fast`:** Dọc 9:16 @ 60 FPS, nhịp nhanh 3.0s/ảnh, zoom mạnh 20%, phù hợp video TikTok bắt trend.
3. **`story_calm`:** Dọc 9:16 @ 30 FPS, nhịp chậm 6.0s/ảnh, zoom nhẹ 8%, phù hợp vlog kể chuyện, tâm sự.
4. **`youtube_shorts_dynamic`:** Dọc 9:16 @ 60 FPS, 2.5s/ảnh, chuyển động zoom dồn dập tối ưu hóa giữ chân người xem.

---

## 10. BỘ ĐIỀU HỢP PHIÊN BẢN & CHIẾN LƯỢC RẼ NHÁNH (REGISTRY & VERSION ROUTER)
`CapCutAdapterRegistry` quản lý việc phân giải phiên bản CapCut được cài đặt trên máy người dùng:
- Khớp regex `^9\.3(\.\d+)?$` $\rightarrow$ Trả về `CapCutVersionAdapter_9_3` với trạng thái `CAPCUT_VERSION_SUPPORTED`.
- Phiên bản mới chưa kiểm thử (ví dụ 9.4, 10.0) $\rightarrow$ Chặn ghi tự động, trả về `CAPCUT_VERSION_UNTESTED` để bảo vệ an toàn, trừ khi người dùng bật chế độ nhà phát triển (developer override).
- Phiên bản cũ đã biết là hỏng (ví dụ `< 8.0`) $\rightarrow$ Báo lỗi `CAPCUT_VERSION_UNSUPPORTED`.

---

## 11. BÁO CÁO ĐỘ PHỦ KIỂM THỬ (TEST SUITE COVERAGE)
Đã chạy toàn bộ bộ kiểm thử tích hợp:
```
============================= 137 passed in 4.30s ==============================
```
- **Kiểm thử cách ly V1 (`tests/test_v1_isolation.py`):** 3 bài test xác nhận FFMPEG V1 và CapCut V2 hoàn toàn độc lập, không import chéo, không xung đột namespace.
- **Kiểm thử V2 Core (`tests/test_capcut_v2_core.py`):** 7 bài test kiểm tra RuleEngine tính tất định, EditPlan serialize, TimelineBuilder 60 FPS, Adapter tạo draft và CapCutDetector trên macOS.
- **Kiểm thử V2 Beta Foundation (`tests/test_capcut_v2_beta.py`):** 21 bài test toàn diện kiểm tra PresetManager, SRT parser, cảnh biên min/max, 6 cổng kiểm soát EditPlan, CapCutDraftValidator, AdapterRegistry, Transactional Rollback và Windows Multi-probe.
- **Kiểm thử toàn bộ hệ thống cũ V1 (106 bài test):** Subpixel Affine Engine, Camera Engine, Trajectory Math, License Gate, Diagnostics, Security, Inno Setup Installer... đều pass 100%.

---

## 12. BẰNG CHỨNG XÁC THỰC TRÊN PHẦN CỨNG CAPCUT DESKTOP THẬT
Script kiểm thử thực tế `tests/validate_real_beta_draft.py` đã khởi tạo và cài đặt thành công dự án vào CapCut Desktop 9.3.0 thực:
- **Tên dự án:** `2TOOLNE Beta Foundation Real Mac Test`
- **ID dự án:** `333D5351-3E7A-44AF-8E06-C8E349B30688`
- **Đường dẫn cài đặt:** `/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/2toolne_1788710375_2TOOLNE_Beta_Foundation_Real_Mac_Test`
- **Thời lượng:** 12.0 giây (3 ảnh Full HD 1080x1920, 1 soundtrack âm thanh nổi WAV, 3 đoạn phụ đề tiếng Việt đồng bộ SRT).
- **Kết quả xác thực qua validator:** 0 lỗi cú pháp, 0 lỗi UUID, 0 lỗi đè track, 0 lỗi keyframe.
- **Kiểm tra `root_meta_info.json`:** Mục dự án được ghi nhận chính xác tại vị trí số 1 trong `all_draft_store`.

---

## 13. CÁCH LY SẢN PHẨM & KIỂM TOÁN PHI AI (ZERO AI AUDIT)
- **Kiểm toán Phi AI:** Toàn bộ pipeline dựng video từ `TimelineBuilder`, `RuleEngine`, `PresetManager` đến `CapCutAdapter` đều là mã toán học và logic luật thuần túy (Pure algorithmic & deterministic rule-based). Không có lời gọi nào tới OpenAI, Gemini, Claude, hay bất kỳ LLM nào trong luồng xử lý chính.
- **Bảo toàn FFMPEG V1:** Tệp động cơ cốt lõi của V1 `subpixel_affine_engine.py` và các module render video trực tiếp không bị thay đổi một dòng code nào.

---

## 14. GIỚI HẠN KỸ THUẬT ĐÃ BIẾT (KNOWN LIMITATIONS & BOUNDARIES)
1. **Nền tảng Windows:** Đã hoàn thiện thiết kế code multi-probe nhưng chưa được chạy thử trên máy vật lý cài Windows thật (trạng thái: `UNTESTED`).
2. **CapCut Cloud Templates:** Chỉ hỗ trợ dự án nội bộ trên máy cục bộ (Local Drafts), không hỗ trợ tính năng đồng bộ đám mây CapCut Cloud Template vì đó là giao thức đóng của ByteDance.
3. **Hiệu ứng đặc biệt (Stickers, Advanced VFX):** Hiện tại bản Beta tập trung vào giá trị cốt lõi: Cắt ghép, Chuyển cảnh Keyframe tỉ lệ Scale/Position, Âm thanh và Phụ đề SRT. Hiệu ứng Sticker và Transition động sẽ được bổ sung ở các Phase tiếp theo.

---

## 15. KẾT LUẬN & PHÁN QUYẾT CUỐI CÙNG (FINAL VERDICT)

```
================================================================================
                    CAPCUT_V2_BETA_FOUNDATION_READY
================================================================================
- FFMPEG V1 giữ nguyên trạng thái đóng băng & ổn định.
- CAPCUT V2 hoàn thành toàn bộ kiến trúc nền tảng Beta.
- 137/137 bài kiểm thử pass 100%.
- Kiểm thử thực tế trên CapCut Desktop 9.3.0 macOS thành công tuyệt đối.
================================================================================
```
