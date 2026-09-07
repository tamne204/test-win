# 📋 BÁO CÁO NGHIỆM THU ĐỘNG CƠ CĂN CHỈNH KỊCH BẢN - PHỤ ĐỀ (PHASE 5C)
## 2TOOLNE AUTOEDIT FOR CAPCUT V2 — SCRIPT-TO-SRT ALIGNMENT ENGINE REPORT
**Tài liệu:** `reports/script_to_srt/CAPCUT_V2_SCRIPT_TO_SRT_REPORT.md`  
**Mốc phát triển:** Phase 5C — Script-to-SRT Alignment Engine  
**Phiên bản:** 2.0.0-subtitles.1  
**Ngày báo cáo:** 2026-09-07  
**Trạng thái:** HOÀN TẤT / SẴN SÀNG TRIỂN KHAI (CAPCUT_V2_SCRIPT_TO_SRT_READY)  

---

## 1. BẢNG TIÊU CHUẨN VÀ BẤT BIẾN SẢN PHẨM (PRODUCT INVARIANTS)

| Tiêu chí bất biến | Trạng thái | Minh chứng kỹ thuật |
|:---|:---|:---|
| **`FFMPEG_V1_INTACT`** | **`PASS`** | Toàn bộ tệp V1 (`app.py`, `version.py`, v.v.) độc lập 100% |
| **`V1_MOTION_CORE_MODIFIED`** | **`NO`** | `subpixel_affine_engine.py` giữ nguyên hàm toán học đóng băng |
| **`LICENSE_ARCHITECTURE_FROZEN`** | **`YES`** | Không thay đổi kiến trúc Ed25519, chỉ kích hoạt quyền `script_to_srt` |
| **`ORIGINAL_SCRIPT_IS_SOURCE_OF_TRUTH`** | **`YES`** | 100% chữ kịch bản gốc được giữ nguyên, bảo tồn dấu câu và casing |
| **`ASR_REWRITE_USER_TEXT`** | **`NO`** | ASR chỉ cung cấp tọa độ âm học, tuyệt đối không viết đè chữ người dùng |
| **`PRESET_2TOOLNE_STANDARD_SUBTITLE`** | **`ACTIVE`** | Giới hạn ~12 từ/cue, ngắt theo dấu câu, min 0.6s / max 5.0s, chống cụt từ |
| **`LOCAL_FIRST_OFFLINE`** | **`YES`** | Chạy CPU cục bộ qua Faster-Whisper, không cần API key, không cloud |
| **`MACOS_PHYSICAL_VALIDATION`** | **`VERIFIED`** | Đã tạo draft CapCut thực tế, xác thực bởi `CapCutDraftValidator` |
| **`WINDOWS_AUTOMATED_STATIC_TESTS`**| **`PASS`** | 100% kiểm thử tĩnh và luồng CI tự động đạt yêu cầu |
| **`WINDOWS_PHYSICAL_VALIDATION`** | **`UNTESTED`** | Không khẳng định kiểm thử vật lý Windows do không có phần cứng |

---

## 2. KIẾN TRÚC & CÁC MODULE ĐÃ TRIỂN KHAI (`apps/capcut-v2/core/subtitles/`)

1. **`models.py`**:
   - Khởi tạo các cấu trúc dữ liệu chặt chẽ: `ScriptToken`, `SpeechWordTimestamp`, `AlignedToken`, `SubtitleCue`, `AlignmentOptions`, `AlignmentResult`.
   - Định nghĩa các mức độ tin cậy `ConfidenceLevel` (`HIGH`, `MEDIUM`, `LOW`, `UNMATCHED`) và kiểu khớp `MatchType` (`EXACT`, `FUZZY`, `INTERPOLATED`).
2. **`script_normalizer.py`**:
   - Nhận diện ngôn ngữ tự động (`vi`, `en`, `ja`, `ko`).
   - Tách từ ngữ chính xác, bảo toàn tuyệt đối vị trí ký tự gốc `char_start` và `char_end` (`script[char_start:char_end] == raw_text`).
   - Hỗ trợ từ viết tắt tiếng Anh (`don't`, `it's`) và chữ tượng hình CJK.
   - Chuẩn hóa chuỗi chỉ phục vụ so khớp âm học, không bao giờ tác động lên văn bản đầu ra.
3. **`speech_timestamp_provider.py`**:
   - Lớp trừu tượng `SpeechTimestampProvider`.
   - `FasterWhisperTimestampProvider`: Chạy model Whisper cục bộ trên CPU với `word_timestamps=True` và `vad_filter=True`.
   - `MockSpeechTimestampProvider`: Cung cấp mốc thời gian giả lập phục vụ unit test và CI không phụ thuộc tài nguyên máy.
4. **`script_aligner.py`**:
   - Thuật toán căn chỉnh đơn điệu (Monotonic Sequence Alignment) sử dụng RapidFuzz.
   - Cửa sổ tìm kiếm trượt xử lý chuẩn xác các đoạn kịch bản lặp từ trong âm thanh.
   - Loại bỏ các từ đệm của ASR không có trong kịch bản.
   - Tự động nội suy tuyến tính (Gap Interpolation) cho các từ bị Whisper bỏ sót.
5. **`subtitle_segmenter.py`**:
   - Hiện thực hóa quy chuẩn `2TOOLNE_STANDARD_SUBTITLE`.
   - Tối đa ~12 từ/cue, ưu tiên ngắt tại mốc dấu câu tự nhiên (`. , ? ! ; :`).
   - Ràng buộc thời lượng: tối thiểu 0.6 giây, tối đa 5.0 giây.
   - Đảm bảo khoảng cách an toàn giữa 2 cue: tối thiểu 0.05 giây (chống chồng lấn).
   - Ngăn chặn câu phụ đề cụt ngắt lửng lơ 1 từ (dangling 1-word subtitle).
6. **`srt_generator.py`**:
   - Định dạng chuẩn UTF-8 SubRip Subtitle (`HH:MM:SS,mmm --> HH:MM:SS,mmm`).
   - Hàm `validate_srt_content` thẩm định tính toàn vẹn cú pháp phụ đề.
7. **`pipeline.py`**:
   - Điều phối toàn bộ quy trình: Audio + Script -> Normalize -> Transcribe -> Align -> Segment -> SRT.
   - Máy trạng thái 6 giai đoạn phát thông báo tiến độ chi tiết.
   - Hỗ trợ cơ chế hủy tác vụ người dùng (`cancellation_token`).

---

## 3. TÍCH HỢP IPC & GIAO DIỆN DESKTOP ELECTRON

1. **Protocol & Bridge (`apps/capcut-v2/desktop_bridge/`)**:
   - Bổ sung 4 phương thức JSON-RPC mới:
     * `GENERATE_SRT_FROM_SCRIPT`: Tạo SRT từ kịch bản và tệp âm thanh.
     * `GET_SUBTITLE_ALIGNMENT_STATUS`: Truy vấn tiến độ căn chỉnh thời gian thực.
     * `CANCEL_SUBTITLE_ALIGNMENT`: Hủy tác vụ đang chạy ngầm.
     * `EXPORT_SRT`: Xuất phụ đề ra tệp `.srt` độc lập.
   - Kiểm soát bản quyền: Phương thức thương mại được bảo vệ qua `LicenseGuard.require_entitlement("script_to_srt")`.
2. **Desktop Shell & Renderer UI (`apps/capcut-v2/desktop/`)**:
   - Thêm bộ chọn 3 chế độ phụ đề: `Không dùng phụ đề`, `Nhập tệp SRT có sẵn (.srt)`, `Từ kịch bản + giọng nói (Script to SRT)`.
   - Khung nhập liệu kịch bản hỗ trợ gõ trực tiếp hoặc nạp tệp `.txt`.
   - Hộp thoại tương tác **Subtitle Preview Modal**:
     * Xem danh sách các câu phụ đề kèm mốc thời gian và cảnh báo độ tin cậy.
     * Chỉnh sửa trực tiếp nội dung chữ hoặc mốc thời gian bắt đầu/kết thúc.
     * Hỗ trợ nút gộp câu (Merge) và xóa câu (Delete).
     * Nút xuất file `.srt` và nút áp dụng vào dự án CapCut.

---

## 4. KẾT QUẢ ĐO KIỂM HIỆU NĂNG THỰC TẾ TRÊN MACOS APPLE SILICON

Thực hiện kiểm thử vật lý với âm thanh và kịch bản thực tế tại `tests/validate_real_script_to_srt_macos.py`:

```
============================================================
  PHASE 5C — REAL MACOS SCRIPT-TO-SRT PHYSICAL VALIDATION
============================================================

--- [1/3] VIETNAMESE SCRIPT + AUDIO BENCHMARK ---
Original Script:
   "Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut. Đây là công cụ biên tập video tự động hàng đầu."
Audio file: tests/scratch/vi_test_speech.mp3

✓ Vietnamese Transcription & Alignment Complete:
   Audio Duration:    7.80 s
   Processing Time:   1.85 s
   Real-Time Factor:  0.24 (RTF < 1.0 is faster than real-time)
   Matched Script:    80.0%
   Subtitle Cues:     2

Generated SRT:
   1
   00:00:00,000 --> 00:00:04,470
   Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut.
   
   2
   00:00:04,520 --> 00:00:06,980
   Đây là công cụ biên tập video tự động hàng đầu.

✓ SRT format integrity: VALID
✓ SOURCE OF TRUTH VERIFIED: 100% original text & brand names preserved.

--- [2/3] ENGLISH SCRIPT + AUDIO BENCHMARK ---
Original Script:
   "Welcome to 2TOOLNE AutoEdit V2 for CapCut. This is an automated timeline alignment demonstration."
Audio file: tests/scratch/en_test_speech.mp3

✓ English Transcription & Alignment Complete:
   Audio Duration:    7.51 s
   Processing Time:   0.22 s
   Real-Time Factor:  0.03
   Matched Script:    85.7%
   Subtitle Cues:     2
✓ English alignment and source of truth verified.

--- [3/3] CAPCUT DRAFT GENERATION TEST ---
✓ EditPlan generated with 2 clips and 2 caption elements.
✓ Draft created at: /Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/2toolne_...
✓ CapCutDraftValidator: errors=[]
✓ CapCut Text Track verified: 2 editable subtitle segments created.
============================================================
  MACOS PHYSICAL VALIDATION & PERFORMANCE BENCHMARK: PASS
============================================================
```

---

## 5. TỔNG HỢP KIỂM THỬ HỒI QUY TỰ ĐỘNG (AUTOMATED REGRESSION SUITE)

- **Subtitle Engine Tests (`pytest tests/test_script_*.py ...`)**:
  - `test_script_normalizer.py`: 5/5 passed.
  - `test_script_aligner.py`: 5/5 passed.
  - `test_subtitle_segmenter.py`: 4/4 passed.
  - `test_srt_generator.py`: 3/3 passed.
  - `test_script_to_srt_pipeline.py`: 4/4 passed.
  - **Tổng**: 21/21 passed (100% trong 0.11s).
- **Core, Desktop & Security Tests (`pytest tests/test_capcut_v2_*.py tests/test_v1_isolation.py`)**:
  - `test_capcut_v2_core.py`: 7/7 passed.
  - `test_capcut_v2_desktop.py`: 11/11 passed (bao gồm test subtitle IPC trực tiếp).
  - `test_capcut_v2_security.py`: 26/26 passed (bao gồm cổng kiểm soát bản quyền `script_to_srt`).
  - `test_v1_isolation.py`: 3/3 passed (FFmpeg V1 độc lập và đóng băng tuyệt đối).
  - **Tổng**: 47/47 passed (100% trong 1.05s).
- **Windows CI Validation Suite (`python tests/ci_windows_validation.py`)**:
  - `WINDOWS_PE_VERIFICATION` = `PASS`
  - `WINDOWS_CI_SIDECAR_SMOKE` = `PASS`
  - `WINDOWS_CI_UNICODE` = `PASS`
  - `WINDOWS_CI_PROCESS_LIFECYCLE` = `PASS`
  - `WINDOWS_DLL_AUDIT` = `PASS`
  - `WINDOWS_CI_CAPCUT_DETECTOR` = `PASS`
  - `WINDOWS_CI_DRAFT_GENERATION` = `PASS`
  - `WINDOWS_CI_SCRIPT_TO_SRT` = `PASS`
  - `WINDOWS_PACKAGED_SECURITY` = `PASS`
  - **Tổng**: 9/9 tiêu chí kiểm thử CI đạt `PASS`.

---

## 6. CẬP NHẬT HỒ SƠ PHÒNG LAB NGOÀI (EXTERNAL LAB PREPARATION)

Đã cập nhật toàn bộ tài liệu kiểm thử vật lý dành cho Tester Windows:
1. `reports/windows_rc/external_lab/README_WINDOWS_TESTER.md`: Thêm tệp mẫu `script_sample.txt` và quy trình kiểm thử Script-to-SRT.
2. `reports/windows_rc/external_lab/WINDOWS_TEST_CHECKLIST.md`: Bổ sung bước kiểm tra 12 (Thử nghiệm Script-to-SRT, xem trước phụ đề, chỉnh sửa cue và áp dụng vào dự án).
3. `reports/windows_rc/external_lab/WINDOWS_TEST_RESULT_TEMPLATE.md`: Bổ sung các tiêu chí đánh giá cho phân hệ phụ đề.
4. `docs/CAPCUT_V2_SCRIPT_TO_SRT_ARCHITECTURE.md`: Tài liệu đặc tả kiến trúc toàn diện.
5. `docs/CAPCUT_V2_SUBTITLE_ALIGNMENT.md`: Hướng dẫn vận hành và khắc phục sự cố.
6. `docs/CAPCUT_V2_DESKTOP_ARCHITECTURE.md`: Cập nhật danh mục lệnh IPC và Section 8.

---

## 7. KẾT LUẬN & PHÁN QUYẾT CUỐI CÙNG (FINAL VERDICT)

```
================================================================================
FINAL VERDICT:
CAPCUT_V2_SCRIPT_TO_SRT_READY
================================================================================
```
Mọi mục tiêu của Phase 5C đã được hoàn thành trọn vẹn, vượt qua tất cả các bài kiểm tra tự động và kiểm thử vật lý thực tế trên macOS Apple Silicon, tuân thủ nghiêm ngặt tính toàn vẹn của kịch bản gốc và bảo vệ vững chắc kiến trúc bản quyền cũng như Product V1.
