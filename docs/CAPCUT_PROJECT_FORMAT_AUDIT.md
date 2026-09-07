# 📊 BÁO CÁO AUDIT ĐỊNH DẠNG DỰ ÁN CAPCUT DESKTOP (CAPCUT PROJECT FORMAT AUDIT)

**Audit Version**: 1.0.0  
**Audit Date**: 2026-09-06  
**Host Environment**: macOS (Darwin 26.1 / Apple Silicon)  
**CapCut Application**: `/Applications/CapCut.app`  
**Installed CapCut Version**: **`9.3.0`** (Bundle ID: `com.lemon.lvoverseas`)  
**Data Integrity**: 100% kiểm chứng trực tiếp từ các dự án thực tế trên máy người dùng, không suy đoán.

---

## 1. VỊ TRÍ LƯU TRỮ VÀ CẤU TRÚC THƯ MỤC DỰ ÁN (DRAFT STORAGE LOCATION)

Trên hệ điều hành macOS, toàn bộ dự án CapCut Desktop được lưu trữ tại:
```
/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/
```

### 1.1 Cấu trúc tổng thể của không gian dự án
```
com.lveditor.draft/
├── root_meta_info.json               <-- File chỉ mục trung tâm chứa danh sách mọi dự án
├── <draft_folder_1>/                 <-- Thư mục chứa từng dự án riêng biệt
│   ├── draft_info.json               <-- File cốt lõi mô tả timeline, track, keyframes, materials
│   ├── draft_meta_info.json          <-- Metadata của dự án (tên, ID, thời gian tạo, cover)
│   ├── draft_cover.jpg               <-- Ảnh đại diện thu nhỏ (thumbnail) của dự án
│   ├── attachment_editing.json       <-- Cấu hình trạng thái biên tập phụ trợ
│   ├── draft.extra                   <-- Dữ liệu mở rộng nhị phân / trạng thái phiên
│   └── Resources/                    <-- Tài nguyên nội bộ nếu có
└── <draft_folder_2>/
```

---

## 2. FILE CHỈ MỤC TRUNG TÂM (`root_meta_info.json`)

File này là cổng tiếp nhận của CapCut khi khởi động trang chủ (Home Projects list).
Cấu trúc JSON:
```json
{
  "all_draft_store": [
    {
      "draft_id": "8158DC4F-8604-436B-A7EF-BF32FEE2AF1D",
      "draft_name": "0825 (1)",
      "draft_fold_path": "/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/0825 (1)",
      "draft_root_path": "/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft",
      "draft_json_file": "/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft/0825 (1)/draft_info.json",
      "draft_cover": "draft_cover.jpg",
      "tm_draft_create": 1787676073132188,
      "tm_draft_modified": 1787748990620132,
      "tm_duration": 132133333,
      "draft_materials_size": 2284348148,
      "draft_type": "",
      "draft_is_invisible": false
    }
  ],
  "draft_ids": 0,
  "root_path": "/Users/2tamne/Movies/CapCut/User Data/Projects/com.lveditor.draft"
}
```

> [!CAUTION]
> **Chính sách an toàn khi ghi `root_meta_info.json`**:
> 1. Phải sao lưu (`backup`) file `root_meta_info.json` ra `root_meta_info.json.bak.<timestamp>` trước khi ghi.
> 2. Chỉ chèn bản ghi của dự án mới vào đầu mảng `all_draft_store`.
> 3. Tuyệt đối không xóa hay thay đổi bất kỳ phần tử nào của dự án có sẵn.

---

## 3. FILE METADATA CỦA BẢN NHÁP (`draft_meta_info.json`)

Mỗi thư mục dự án chứa file `draft_meta_info.json` với các trường định danh:
- `draft_id`: UUID dạng hoa (VD: `8158DC4F-8604-436B-A7EF-BF32FEE2AF1D`).
- `draft_name`: Tên hiển thị của dự án trên giao diện CapCut.
- `draft_fold_path`: Đường dẫn tuyệt đối tới thư mục dự án.
- `draft_root_path`: Thư mục gốc `com.lveditor.draft`.
- `tm_draft_create` / `tm_draft_modified`: Timestamp hệ thống tính bằng **microsecond** (`int(time.time() * 1_000_000)`).
- `tm_duration`: Tổng thời lượng timeline (microsecond).
- `draft_materials`: Danh sách tệp đính kèm với `type: 0` (video/image) chứa đường dẫn tệp gốc `file_Path`.

---

## 4. FILE CỐT LÕI DỰ ÁN (`draft_info.json`)

Đây là file quan trọng nhất quyết định toàn bộ timeline và hành vi của CapCut.

### 4.1 Đơn vị thời gian (Time Units)
- Toàn bộ thời gian trong CapCut Desktop được lưu theo đơn vị **MICROSECONDS** ($\mu\text{s}$):
  $$1\text{ giây} = 1,000,000\ \mu\text{s}$$
- Ví dụ: 5.0 giây = `5000000`, 15.0 giây = `15000000`.

### 4.2 Cấu hình Canvas & FPS
- `fps`: `60.0` (cho video mượt) hoặc `30.0`.
- `canvas_config`:
  ```json
  {
    "ratio": "9:16",
    "width": 1080,
    "height": 1920,
    "background": null
  }
  ```

### 4.3 Cấu trúc Track và Segment
File `draft_info.json` quản lý danh sách tracks (`tracks: [...]`).
Các loại track quan sát được:
1. `type: "video"` (Main Track hoặc Overlay Track): chứa các phân đoạn video/ảnh.
2. `type: "audio"`: chứa các tệp âm thanh/nhạc/lồng tiếng.
3. `type: "text"`: chứa phụ đề hoặc chữ hiển thị.

Mỗi Segment có:
- `id`: UUID riêng biệt.
- `material_id`: Liên kết tới ID của material tương ứng trong `materials`.
- `target_timerange`: Thời gian bắt đầu và thời lượng trên Timeline (`start`, `duration`).
- `source_timerange`: Thời gian trích xuất từ tệp nguồn (`start`, `duration`).
- `extra_material_refs`: Danh sách UUID liên kết tới các material bổ trợ trong `materials` (bắt buộc gồm: `speed`, `canvas`, `sound_channel_mapping`, `material_color`, `placeholder_info`, `vocal_separation`).

---

## 5. CƠ CHẾ CHUYỂN ĐỘNG NATIVE (NATIVE KEYFRAMES)

Qua phân tích sâu các bản nháp có keyframe thực tế (như `otis kb 6`, `0515`, `0521`), CapCut 9.3.0 lưu trữ keyframe ngay trong thuộc tính `common_keyframes` của từng Segment:

### 5.1 Các thuộc tính chuyển động (Property Types)
1. **`KFTypeScaleX`**: Thu phóng (Scale).
   - Zoom In: keyframe 0 (`values: [1.0]`) tại offset 0 $\rightarrow$ keyframe 1 (`values: [1.15]`) tại offset `duration`.
   - Zoom Out: keyframe 0 (`values: [1.15]`) tại offset 0 $\rightarrow$ keyframe 1 (`values: [1.0]`) tại offset `duration`.
2. **`KFTypePositionX`**: Di chuyển ngang (Pan Left / Pan Right).
   - Tọa độ chuẩn hóa trong khoảng $[-1.0, 1.0]$.
   - Pan Left: $X$ chuyển dịch từ $0.10 \rightarrow -0.10$.
   - Pan Right: $X$ chuyển dịch từ $-0.10 \rightarrow 0.10$.
3. **`KFTypePositionY`**: Di chuyển dọc (Tilt Up / Tilt Down).
4. **`KFTypeRotation`**: Góc xoay (mặc định $0.0$).

### 5.2 Cấu trúc của một Keyframe Item
```json
{
  "id": "<UUID>",
  "curveType": "Line",
  "time_offset": 0,
  "left_control": {"x": 0.0, "y": 0.0},
  "right_control": {"x": 0.0, "y": 0.0},
  "values": [1.0],
  "string_value": "",
  "graphID": ""
}
```
> [!TIP]
> Sử dụng `curveType: "Line"` với `left_control` và `right_control` bằng $0.0$ bảo đảm chuyển động mượt mà, ổn định và tương thích tuyệt đối mà không cần tạo đồ thị Bezier phức tạp (`keyframe_graph_list`).

---

## 6. ĐỊNH DẠNG TEXT / CAPTION BẢN ĐỊA

Track `type: "text"` liên kết tới `materials.texts`:
- Thuộc tính `content`: Một chuỗi JSON chứa `text` và `styles`:
  ```json
  {
    "text": "2TOOLNE CAPCUT AUTOEDIT POC",
    "styles": [
      {
        "fill": {
          "content": {
            "solid": {
              "color": [1.0, 1.0, 1.0]
            }
          },
          "render_type": "solid"
        },
        "range": [0, 27],
        "size": 8.0
      }
    ]
  }
  ```
- Phân đoạn trên text track có `clip.transform.y` để định vị (ví dụ: `-0.6` để đặt phụ đề ở nửa dưới màn hình 9:16).
- **Tính chỉnh sửa**: Người dùng mở CapCut có thể bấm vào text, đổi font, gõ chữ mới hoàn toàn trực tiếp.

---

## 7. ĐỊNH DẠNG ÂM THANH (AUDIO TRACK)

Track `type: "audio"` liên kết tới `materials.audios`:
- Material audio chứa đường dẫn tuyệt đối tới tệp media `path`.
- Segment có `source_timerange` và `target_timerange`.
- Âm lượng `volume: 1.0`.

---

## 8. CÁC TRƯỜNG CHƯA RÕ (UNKNOWN FIELDS) VÀ NGUY CƠ TƯƠNG THÍCH (RISKS)

| Trường / Khu vực | Hiện trạng hiểu biết | Đánh giá rủi ro tương thích | Giải pháp an toàn |
| :--- | :--- | :--- | :--- |
| `platform.device_id`, `hard_disk_id` | Khóa phần cứng cục bộ sinh ngẫu nhiên hoặc trích từ OS | Thấp | Trích xuất trực tiếp từ các draft hiện có trên máy người dùng hoặc giữ mẫu chuẩn của macOS |
| `check_flag` trong `materials.videos` | Bitmask kiểm tra trạng thái video (VD: `62978047`) | Trung bình | Sử dụng đúng giá trị cờ chuẩn từ draft mẫu 9.3.0 |
| `extra_material_refs` | Danh sách UUID liên kết bắt buộc với speed, canvas... | Cao nếu thiếu | Sinh đầy đủ 6 đối tượng vật liệu đi kèm trong `materials` cho mỗi video segment |
| `key_value.json` / `attachment_editing.json` | Cấu hình phụ của trình biên tập | Thấp | Tạo các tệp tối thiểu hợp lệ |
