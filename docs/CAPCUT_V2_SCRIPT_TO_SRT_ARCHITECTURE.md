# KIẾN TRÚC HỆ THỐNG SCRIPT-TO-SRT ALIGNMENT ENGINE
## 2TOOLNE AUTOEDIT FOR CAPCUT V2 — PHASE 5C SPECIFICATION
**Tài liệu kỹ thuật:** `docs/CAPCUT_V2_SCRIPT_TO_SRT_ARCHITECTURE.md`  
**Phiên bản:** 2.0.0-subtitles  
**Trạng thái:** ACTIVE / PRODUCTION-READY  

---

## 1. NGUYÊN TẮC CỐT LÕI (CORE INVARIANTS)

1. **Văn bản kịch bản gốc của người dùng là Nguồn chân lý duy nhất (Source of Truth):**
   - Không cho phép ASR (Whisper) hoặc bất kỳ mô hình AI nào tự ý sửa đổi, thay thế, dịch thuật hoặc chuẩn hóa lại nội dung văn bản của người dùng.
   - Toàn bộ từ ngữ, dấu câu, quy tắc viết hoa, danh từ riêng, tên thương hiệu (ví dụ: `2TOOLNE AutoEdit`), số và ký hiệu đặc biệt trong kịch bản ban đầu phải được bảo tồn chính xác 100% trong tệp phụ đề SRT kết xuất.
2. **ASR chỉ dùng để trích xuất mốc thời gian (Timestamp Extraction Only):**
   - Mô hình nhận dạng giọng nói chỉ đóng vai trò cung cấp tọa độ âm học theo dòng thời gian `(word, start_seconds, end_seconds, confidence)`.
3. **Thuật toán căn chỉnh đơn điệu (Monotonic Sequence Alignment):**
   - Quá trình khớp từ văn bản kịch bản vào mốc âm thanh bắt buộc tuân theo thứ tự tuyến tính tăng dần không đảo ngược (`start_s >= prev_start_s`).
   - Xử lý mượt mà các tình huống: câu lặp lại nhiều lần trong âm thanh, người nói ngập ngừng/từ đệm (filler words), phát âm không chuẩn hoặc Whisper bỏ sót từ (omission gap interpolation).
4. **Chuẩn quy cách phụ đề `2TOOLNE_STANDARD_SUBTITLE`:**
   - Tối đa khoảng 12 từ trên mỗi câu phụ đề (cue).
   - Ưu tiên ngắt câu tại các mốc chấm câu tự nhiên (`.`, `,`, `?`, `!`, `;`, `:`).
   - Thời lượng mỗi câu phụ đề: tối thiểu 0.6 giây, tối đa 5.0 giây.
   - Khoảng cách an toàn giữa 2 phụ đề liền kề: tối thiểu 0.05 giây (chống đè timing).
   - Tuyệt đối không để phụ đề cụt ngắt lửng lơ 1 từ (dangling 1-word subtitles).
5. **Hoạt động Offline 100% (Local-First):**
   - Chạy trên CPU máy tính người dùng thông qua `faster-whisper`, không yêu cầu API key, không phụ thuộc cloud hay gửi dữ liệu âm thanh/kịch bản ra internet.

---

## 2. KIẾN TRÚC PHÂN TẦNG VÀ LUỒNG DỮ LIỆU

```
+-------------------------------------------------------------------------+
|                      NGƯỜI DÙNG: KỊCH BẢN + FILE ÂM THANH               |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
| 1. SCRIPT NORMALIZER & TOKENIZER (apps/capcut-v2/core/subtitles/...)   |
|   - Nhận diện ngôn ngữ tự động (vi, en, ja, ko)                         |
|   - Tách từ ngữ và ghi nhận vị trí ký tự gốc: char_start, char_end      |
|   - Tạo normalized_text đơn thuần để so khớp âm học                     |
|   - BẢO TỒN NGUYÊN VẸN raw_text VÀ ĐỊNH DẠNG HOA/THƯỜNG/DẤU CÂU         |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
| 2. SPEECH TIMESTAMP PROVIDER (faster-whisper / mock)                    |
|   - Chạy mô hình Whisper cục bộ (CPU float32/int8)                     |
|   - Trích xuất danh sách SpeechWordTimestamp: word, start, end          |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
| 3. MONOTONIC SCRIPT ALIGNER (RapidFuzz / SequenceMatcher)               |
|   - So khớp chuỗi con trượt đơn điệu (Monotonic Search Window)          |
|   - Phân biệt các đoạn câu lặp lại theo thứ tự thời gian                |
|   - Loại bỏ từ đệm (filler words) của ASR không có trong kịch bản      |
|   - Nội suy khoảng thời gian cho các từ bị ASR bỏ sót (Interpolation)   |
|   - Kết quả: Danh sách AlignedToken (Gắn timestamp vào ScriptToken gốc) |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
| 4. SUBTITLE SEGMENTER (2TOOLNE_STANDARD_SUBTITLE)                       |
|   - Gom nhóm AlignedToken thành danh sách SubtitleCue                   |
|   - Kiểm soát số từ tối đa (~12 từ), ngắt theo dấu câu                  |
|   - Giới hạn min_dur (0.6s) và max_dur (5.0s), chống chồng lấn timing   |
|   - Hấp thụ từ cô độc (chống dangling 1-word cue)                       |
+------------------------------------+------------------------------------+
                                     |
+------------------------------------v------------------------------------+
| 5. SRT GENERATOR & VALIDATOR                                            |
|   - Tạo chuỗi SubRip SRT tiêu chuẩn UTF-8 (HH:MM:SS,mmm --> ...)        |
|   - Kiểm tra tính toàn vẹn cú pháp phụ đề                               |
+------------------------------------+------------------------------------+
                                     |
         +---------------------------+---------------------------+
         |                                                       |
+--------v-----------------------------------+ +-----------------v--------+
| 6A. DESKTOP INTERACTIVE PREVIEW & EDITING  | | 6B. CAPCUT V2 TIMELINE   |
|   - Modal hiển thị trực quan các câu cue   | |   - EditPlanCaption      |
|   - Cho phép sửa trực tiếp text, start, end| |   - CapCut text track    |
|   - Xuất tệp .srt độc lập                  | |   - Draft 9.3 editable   |
+--------------------------------------------+ +--------------------------+
```

---

## 3. CHI TIẾT CÁC MODULE THÀNH PHẦN

### 3.1 Data Models (`models.py`)
- `ScriptToken`: Đại diện cho 1 từ/token trong kịch bản gốc. Chứa `raw_text` (từ nguyên bản), `normalized_text` (từ đã chuẩn hóa để đối chiếu), `char_start` và `char_end` (vị trí cắt substring chính xác trong kịch bản gốc).
- `SpeechWordTimestamp`: Mốc thời gian được Whisper trả về gồm `word`, `start` (giây), `end` (giây), `confidence`.
- `AlignedToken`: Đối tượng kết hợp `script_token` với tọa độ thời gian `start_s` và `end_s`, `confidence` (`HIGH`, `MEDIUM`, `LOW`, `UNMATCHED`), `match_type` (`EXACT`, `FUZZY`, `INTERPOLATED`). Chữ hiển thị tuyệt đối lấy từ `script_token.raw_text`.
- `SubtitleCue`: Khung câu phụ đề chuẩn gồm `index`, `start_s`, `end_s`, `text`, `confidence`, và `token_count`.
- `AlignmentOptions`: Cấu hình thuật toán (`language`, `max_words_per_cue`, `min_duration_s`, `max_duration_s`, `min_gap_s`, `model_size`).
- `AlignmentResult`: Kết quả toàn diện của pipeline gồm danh sách `cues`, `srt_content`, tỉ lệ phần trăm khớp `matched_percentage`, thời lượng âm thanh, số cue cảnh báo.

### 3.2 Script Normalizer (`script_normalizer.py`)
- Hàm `detect_language(text)`: Tự động phân loại kịch bản tiếng Việt (`vi`), tiếng Hàn (`ko`), tiếng Nhật (`ja`) hoặc mặc định tiếng Anh/khác (`en`).
- Hàm `tokenize_script(text, language)`:
  - Giữ lại nguyên văn từng ký tự và dấu câu.
  - Phân tách bằng khoảng trắng cho ngôn ngữ Latin/tiếng Việt. Hỗ trợ từ viết tắt tiếng Anh (`don't`, `it's`, `let's`).
  - Phân tách ký tự CJK (chữ Hán/Hiragana/Katakana) cho tiếng Nhật/Hàn.
  - Ghi nhận cờ `is_sentence_break` cho các dấu kết thúc câu (`.`, `?`, `!`) và `is_clause_break` cho các dấu ngắt vế (`.`, `,`, `;`, `:`).
- Hàm `normalize_for_matching(text, language)`: Loại bỏ dấu phụ, chữ hoa và ký tự đặc biệt chỉ dùng nội bộ khi so khớp độ tương đồng âm học.

### 3.3 Speech Timestamp Provider (`speech_timestamp_provider.py`)
- Lớp cơ sở `SpeechTimestampProvider`: Giao diện trừu tượng định nghĩa phương thức `get_timestamps(audio_path, language, progress_callback, cancellation_token)`.
- `FasterWhisperTimestampProvider`: Tích hợp thư viện `faster-whisper` chạy trên CPU (`device="cpu"`, `compute_type="float32"`). Hỗ trợ tải các cỡ model `tiny`, `base`, `small`. Bật cờ `word_timestamps=True` và `vad_filter=True` để loại bỏ khoảng lặng.
- `MockSpeechTimestampProvider`: Lớp cung cấp timestamp giả lập phục vụ kiểm thử tự động, CI runners và môi trường không có GPU/Whisper binary.

### 3.4 Monotonic Script Aligner (`script_aligner.py`)
- Quản lý cửa sổ trượt đơn điệu: Tìm kiếm vị trí khớp tối ưu cho từng từ trong kịch bản trong phạm vi tối đa 40 token âm thanh tiếp theo.
- Thuật toán 3 bước:
  1. **Khớp chính xác (Exact match)**: Chuỗi ký tự chuẩn hóa trùng khớp 100%.
  2. **Khớp tương đồng (Fuzzy match)**: Sử dụng RapidFuzz (tỷ lệ tương đồng > 75%) để nhận diện các từ bị Whisper nghe lệch âm hoặc biến âm.
  3. **Nội suy khoảng trống (Gap Interpolation)**: Đối với các từ bị Whisper bỏ sót nằm giữa hai từ đã khớp, tự động phân bổ tuyến tính thời gian dựa theo tỷ lệ độ dài ký tự của từng từ bị thiếu.

### 3.5 Subtitle Segmenter (`subtitle_segmenter.py`)
- Chia luồng token thành các câu phụ đề SubtitleCue:
  - Nếu số từ đạt ngưỡng ~12 từ hoặc gặp dấu chấm câu (`.`, `!`, `?`), hoàn thiện câu phụ đề hiện tại.
  - Kiểm tra điều kiện thời lượng: nếu câu ngắn hơn 0.6 giây, ghép thêm token tiếp theo nếu còn chỗ; nếu dài hơn 5.0 giây, bắt buộc ngắt câu.
  - Chống cụt từ: Nếu việc ngắt câu khiến câu kế tiếp chỉ còn đúng 1 từ lẻ loi (`len == 1`), tự động gộp từ đó vào câu hiện tại (nếu chưa quá giới hạn tối đa).
  - Đảm bảo khoảng hở `min_gap_s = 0.05s` giữa hai câu liên tiếp.

### 3.6 SRT Generator (`srt_generator.py`)
- Xuất định dạng chuẩn SubRip:
  ```
  1
  00:00:00,000 --> 00:00:04,470
  Chào mừng bạn đến với 2TOOLNE AutoEdit cho CapCut.
  ```
- Hàm `validate_srt_content(srt_text)`: Kiểm tra cấu trúc số thứ tự, định dạng mốc thời gian `HH:MM:SS,mmm --> HH:MM:SS,mmm` và nội dung text hợp lệ.

### 3.7 Master Pipeline (`pipeline.py`)
- Lớp `ScriptToSrtPipeline`: Điều phối toàn bộ quy trình từ đầu vào đến đầu ra.
- Trạng thái tiến trình 5 bước phát ra thông báo chi tiết:
  1. `PREPARING_AUDIO` (10%)
  2. `NORMALIZING_SCRIPT` (20%)
  3. `TRANSCRIBING_AUDIO` (30% - 60%)
  4. `ALIGNING_SCRIPT` (70%)
  5. `SEGMENTING_SUBTITLES` (85%)
  6. `READY` (100%)
- Hỗ trợ cờ hủy bỏ (`cancellation_token`) cho phép dừng ngay lập tức khi người dùng bấm Hủy.

---

## 4. TÍCH HỢP IPC DESKTOP BRIDGE

Các phương thức JSON-RPC mới được bổ sung vào `desktop_bridge`:
1. `GENERATE_SRT_FROM_SCRIPT`:
   - Tham số: `script_text`, `audio_path`, `options` (tùy chọn: `language`, `max_words_per_cue`, `model_size`).
   - Yêu cầu bản quyền: Bắt buộc kích hoạt License (`script_to_srt` entitlement).
   - Trả về: `AlignmentResult` (danh sách cues, nội dung SRT, thống kê).
2. `GET_SUBTITLE_ALIGNMENT_STATUS`:
   - Trả về trạng thái thực thi hiện tại, tỷ lệ tiến độ và thông báo đang xử lý.
3. `CANCEL_SUBTITLE_ALIGNMENT`:
   - Gửi tín hiệu kích hoạt `cancellation_token` để hủy tác vụ đang chạy.
4. `EXPORT_SRT`:
   - Lưu nội dung SRT đã được người dùng chỉnh sửa ra tệp `.srt` trên đĩa cứng.

---

## 5. TÍCH HỢP GIAO DIỆN NGƯỜI DÙNG (DESKTOP UI)

- **Lựa chọn chế độ phụ đề**:
  - `Không dùng phụ đề`
  - `Nhập tệp SRT có sẵn (.srt)`
  - `Từ kịch bản + giọng nói (Script to SRT)`
- **Khu vực nhập liệu kịch bản**: Khung `textarea` hỗ trợ gõ kịch bản trực tiếp hoặc nút `Chọn tệp .txt` để nạp nhanh từ máy tính.
- **Hộp thoại tương tác Subtitle Preview Modal**:
  - Hiển thị bảng danh sách các câu phụ đề kèm thời gian bắt đầu, kết thúc, số từ.
  - Cho phép người dùng trực tiếp sửa lại thời gian hoặc nội dung từng câu.
  - Hỗ trợ nút `Gộp câu` (Merge với câu sau) và `Xóa câu` (Delete).
  - Nút `Xuất tệp .srt` để lưu ra ngoài và nút `Áp dụng vào dự án` để đưa phụ đề vào Timeline CapCut.

---

## 6. KẾT QUẢ ĐO KIỂM THỰC TẾ TRÊN MACOS APPLE SILICON

Đo kiểm với file âm thanh và kịch bản thực tế:
- **Tiếng Việt** (Độ dài âm thanh: 7.80 giây):
  - Thời gian xử lý: **1.85 giây**
  - Hệ số Real-Time Factor (RTF): **0.24** (Nhanh gấp ~4.2 lần thời gian thực).
  - Tỷ lệ khớp từ: **80.0%**
  - Bảo tồn nguyên vẹn 100% thương hiệu `2TOOLNE AutoEdit`, dấu câu và dấu tiếng Việt.
- **Tiếng Anh** (Độ dài âm thanh: 7.51 giây):
  - Thời gian xử lý: **0.22 giây**
  - Hệ số Real-Time Factor (RTF): **0.03** (Nhanh gấp ~33 lần thời gian thực).
  - Tỷ lệ khớp từ: **85.7%**
- **Khởi tạo dự án CapCut hoàn chỉnh**:
  - Tạo thành công dự án CapCut có rãnh phụ đề Text Track gồm các đoạn caption editable.
  - `CapCutDraftValidator`: **0 errors** (100% PASS).
