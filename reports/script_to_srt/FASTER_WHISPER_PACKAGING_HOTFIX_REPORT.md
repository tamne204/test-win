# 📋 BÁO CÁO KHẮC PHỤC NÓNG: ĐÓNG GÓI TÀI NGUYÊN FASTER-WHISPER SILERO VAD (HOTFIX)
## 2TOOLNE AUTOEDIT FOR CAPCUT V2 — PACKAGING HOTFIX REPORT
**Tài liệu:** `reports/script_to_srt/FASTER_WHISPER_PACKAGING_HOTFIX_REPORT.md`  
**Mốc xử lý:** Hotfix — Faster-Whisper Silero VAD Asset Packaging  
**Phiên bản:** 2.0.0-subtitles.hotfix1  
**Ngày báo cáo:** 2026-09-07  
**Trạng thái:** HOÀN TẤT / ĐÃ KIỂM CHỨNG TOÀN DIỆN (FASTER_WHISPER_PACKAGING_HOTFIX_PASS)  

---

## 1. NGUYÊN NHÂN GỐC RỄ (ROOT CAUSE AUDIT)

- **Hiện tượng lỗi**: Khi người dùng sử dụng ứng dụng Desktop đã đóng gói (`2toolne AutoEdit.app`) để tạo phụ đề từ kịch bản + giọng nói, hệ thống thông báo lỗi:
  ```
  [ONNXRuntimeError] : 3 : NO_SUCHFILE : Load model from .../autoedit-core/_internal/faster_whisper/assets/silero_vad_v6.onnx failed: File doesn't exist
  ```
- **Nguyên nhân kỹ thuật**:
  - `faster-whisper` sử dụng bộ lọc tiếng nói VAD (Voice Activity Detection) mặc định dựa trên mô hình ONNX `silero_vad_v6.onnx`.
  - Đường dẫn mô hình được thư viện `faster-whisper` xác định động thông qua:
    `os.path.join(os.path.dirname(__file__), "assets", "silero_vad_v6.onnx")`.
  - Trong tệp build PyInstaller (`apps/capcut-v2/packaging/build_sidecar.py` và `autoedit-core.spec`), cấu hình `datas` chưa khai báo thu thập tài nguyên gói `faster_whisper`, dẫn đến PyInstaller chỉ gom các tệp mã nguồn `.py` vào kho lưu trữ mã nhị phân mà bỏ sót thư mục dữ liệu `assets/silero_vad_v6.onnx`.
  - Không sửa đổi thuật toán căn chỉnh Script-to-SRT vì đây hoàn toàn là lỗi đóng gói (packaging bug).

---

## 2. CÁC BIỆN PHÁP KHẮC PHỤC ĐÃ TRIỂN KHAI

### A. Thu thập tài nguyên PyInstaller (`build_sidecar.py` & `autoedit-core.spec`)
- Cập nhật lệnh build PyInstaller với cờ tự động thu thập tài nguyên:
  `--collect-data faster_whisper`
- Cập nhật `autoedit-core.spec`:
  `from PyInstaller.utils.hooks import collect_data_files`
  `datas = collect_data_files("faster_whisper")`
- Bổ sung xác nhận vật lý nghiêm ngặt (Physical Assertion) ngay trong `build_sidecar.py`:
  Kiểm tra sự tồn tại và dung lượng file (`size > 0`) của `autoedit-core/_internal/faster_whisper/assets/silero_vad_v6.onnx`. Nếu thiếu tài nguyên, tiến trình build sẽ lập tức báo lỗi và dừng lại.

### B. Nâng cấp trải nghiệm xử lý lỗi người dùng (Error UX)
- Trong `apps/capcut-v2/desktop_bridge/protocol.py`: Định nghĩa mã lỗi chuẩn `ERR_ASR_RUNTIME_INCOMPLETE = "ASR_RUNTIME_INCOMPLETE"`.
- Trong `apps/capcut-v2/core/subtitles/speech_timestamp_provider.py`: Bổ sung ngoại lệ `ASRRuntimeIncompleteError(ASRError)`.
- Trong `apps/capcut-v2/core/subtitles/pipeline.py` & `bridge.py`: Bắt các lỗi liên quan đến thiếu tài nguyên ONNX / VAD (`NO_SUCHFILE`, `ONNXRuntimeError`, `silero_vad`, v.v.) và ánh xạ sang thông báo thân thiện với người dùng:
  > *"Thành phần nhận dạng giọng nói bị thiếu hoặc chưa được cài đặt đầy đủ. Vui lòng cài lại hoặc cập nhật 2TOOLNE AutoEdit."*
- Toàn bộ thông tin kỹ thuật chi tiết và stack trace chỉ lưu trong hệ thống chẩn đoán (Diagnostics), không hiển thị thô ráp ra màn hình người dùng.
- Trong giao diện Desktop Renderer (`app.js`): Lọc bỏ tiền tố kỹ thuật `Error invoking remote method...` khi hiển thị modal.

### C. Đồng bộ quy trình kiểm thử Windows CI
- Trong `.github/workflows/capcut-v2-windows-rc.yml`: Bổ sung bước kiểm tra xác nhận tệp `silero_vad_v6.onnx` tồn tại trong gói build Windows.
- Trong `tests/ci_windows_validation.py`: Bổ sung kiểm tra `WINDOWS_CI_ASR_ASSET_PACKAGING` đạt `PASS`.

---

## 3. BẢNG TIÊU CHUẨN NGHIỆM THU HOTFIX (RELEASE GATES)

| Tiêu chuẩn / Khóa Kiểm Toán | Kết Quả Thực Tế | Minh Chứng Kỹ Thuật |
|:---|:---:|:---|
| **`ROOT_CAUSE`** | **`AUDITED_PACKAGING_DATA_OMISSION`** | Thiếu cờ `--collect-data faster_whisper` trong PyInstaller |
| **`SOURCE_VAD_ASSET_EXISTS`** | **`YES`** | Có sẵn tại `.venv/.../faster_whisper/assets/silero_vad_v6.onnx` (1.2 MB) |
| **`PYINSTALLER_COLLECT_DATA`** | **`APPLIED`** | Thêm `--collect-data faster_whisper` vào `build_sidecar.py` và spec |
| **`PACKAGED_VAD_ASSET_EXISTS`** | **`YES`** | Tệp tồn tại tại `autoedit-core/_internal/.../silero_vad_v6.onnx` (1,245,151 bytes) |
| **`PACKAGED_ASR_RUNTIME_TEST`** | **`PASS`** | Đã chạy binary `autoedit-core` thực tế: VAD nạp thành công, sinh 2 câu phụ đề |
| **`MAC_ELECTRON_REBUILD`** | **`PASS`** | Xóa sạch dist cũ, rebuild `2toolne AutoEdit.app` có VAD asset (1.2 MB) |
| **`MAC_SCRIPT_TO_SRT_E2E`** | **`PASS`** | Đo kiểm thực tế tiếng Việt/tiếng Anh đạt RTF 0.24 - 0.03, sinh draft CapCut |
| **`WINDOWS_CI_ASSET_CHECK`** | **`PASS`** | `WINDOWS_CI_ASR_ASSET_PACKAGING = PASS` trong `ci_windows_validation.py` |
| **`V1_REGRESSION`** | **`PASS`** | V1 Motion Core giữ nguyên, toàn bộ 3 bài test cách ly V1 đạt 100% |
| **`FINAL_STATUS`** | **`FASTER_WHISPER_PACKAGING_HOTFIX_PASS`** | Đã khắc phục triệt để và an toàn |

---

## 4. KẾT QUẢ ĐO KIỂM THỰC TẾ TRÊN BINARY ĐÓNG GÓI

### A. Kiểm thử runtime trên binary `autoedit-core` đóng gói (`tests/validate_packaged_sidecar_vad.py`):
```
============================================================
  TESTING PACKAGED SIDECAR BINARY RUNTIME (FASTER-WHISPER VAD)
Binary: apps/capcut-v2/packaging/dist/autoedit-core/autoedit-core
Audio:  tests/scratch/vi_test_speech.mp3
============================================================
✓ PING: OK
✓ License Entitlement Activated with 'script_to_srt'

-> Sending GENERATE_SRT_FROM_SCRIPT to PACKAGED binary...
  [Notification] stage=PREPARING_AUDIO percent=10% msg='Đang kiểm tra và chuẩn bị dữ liệu âm thanh...'
  [Notification] stage=NORMALIZING_SCRIPT percent=20% msg='Đang xử lý phân tách từ và dấu câu kịch bản gốc...'
  [Notification] stage=TRANSCRIBING_AUDIO percent=35% msg='Đang nhận diện mốc thời gian phát âm qua ASR Engine...'
  [Notification] stage=PREPARING_AUDIO percent=24% msg='Đang phân tích âm học...'
  [Notification] stage=TRANSCRIBING_AUDIO percent=32% msg='Đang phân tích âm học...'
  [Notification] stage=TRANSCRIBING_AUDIO percent=44% msg='Đang phân tích âm học...'
  [Notification] stage=ALIGNING_SCRIPT percent=70% msg='Đang căn chỉnh từ gốc vào mốc thời gian âm thanh...'
  [Notification] stage=BUILDING_SUBTITLES percent=85% msg='Đang phân đoạn câu phụ đề (chuẩn 12 từ 2TOOLNE)...'
  [Notification] stage=VALIDATING_SRT percent=95% msg='Đang kiểm tra tính toàn vẹn của tệp SRT...'
  [Notification] stage=READY percent=100% msg='Hoàn tất tạo phụ đề từ kịch bản!'

Response received in 38.44s:
✓ Cues generated: 2
✓ Matched percentage: 90.0%

Generated SRT:
1
00:00:00,000 --> 00:00:04,250
Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut.

2
00:00:04,300 --> 00:00:06,880
Đây là công cụ biên tập video tự động hàng đầu.
============================================================
  PACKAGED SIDECAR VAD RUNTIME TEST: PASS
============================================================
```

### B. Kiểm thử tự động đơn vị & hồi quy:
- `tests/test_capcut_v2_desktop.py::test_faster_whisper_vad_asset_packaged`: **PASSED**.
- Toàn bộ 36 tests Subtitle, Desktop và V1 Isolation: **36/36 PASSED**.
- `tests/ci_windows_validation.py`: **10/10 PASS**.
- Ứng dụng Desktop `2toolne AutoEdit.app` đã được build lại sạch sẽ và khởi chạy hoạt động trên màn hình macOS của máy chủ.

---

```
================================================================================
FINAL VERDICT:
FASTER_WHISPER_PACKAGING_HOTFIX_PASS
================================================================================
```
