# 🧭 MA TRẬN TƯƠNG THÍCH CAPCUT V2 (CAPCUT COMPATIBILITY MATRIX)
## ĐỘC LẬP THEO NỀN TẢNG (PLATFORM-INDEPENDENT BASELINE)

**Document Version**: 2.1.0 (Independent Dual-Platform Architecture)  
**Effective Date**: 2026-09-07  
**Policy**: Tương thích thực chứng (Empirical Verification Only). Tuyệt đối không giả định macOS và Windows dùng chung phiên bản CapCut. Hai hệ điều hành là hai mục tiêu tương thích độc lập hoàn toàn.

---

## 1. NGUYÊN TẮC CỐT LÕI (CORE PRINCIPLES)

1. **Khóa Phiên Bản Theo Nền Tảng (Locked Compatibility Targets):**
   * **macOS Target:** `CAPCUT_MAC_SUPPORTED_VERSION = 9.4.0` (Apple Silicon arm64)
   * **Windows Target:** `CAPCUT_WINDOWS_SUPPORTED_VERSION = 9.3.0` (Exact verified x64 build)
2. **Tách Biệt Năng Lực (Capability Separation):**
   * Một phiên bản có thể được hỗ trợ tạo bản nháp (`DRAFT_GENERATION_SUPPORTED = YES`) nhưng chưa hỗ trợ tự động hóa render (`RENDER_AUTOMATION_SUPPORTED = UNVERIFIED`).
   * Không bao giờ đánh đồng năng lực tạo timeline JSON với năng lực điều khiển GUI Automation.
3. **Định Danh Bản Build Vật Lý (Build Identity):**
   * Khi dữ liệu kiểm thử lab thực tế có sẵn, hệ thống định danh bằng bộ ngũ:
     * `displayed_version` (Phiên bản hiển thị trên UI, e.g. "9.3.0")
     * `executable_file_version` (Phiên bản file nhị phân PE/Mach-O)
     * `build_number` (Số build nội bộ của CapCut)
     * `architecture` (`x86_64` hoặc `arm64`)
     * `executable_sha256` (Mã băm SHA256 của file thực thi chính để truy vết tuyệt đối)

---

## 2. MA TRẬN PHÂN TÁCH NĂNG LỰC (CAPABILITY MATRIX)

| Hệ điều hành | Kiến trúc | Phiên bản CapCut | DRAFT_GENERATION | PROJECT_OPEN | EDIT_SAVE_REOPEN | RENDER_AUTOMATION | Ghi chú & Trạng thái kiểm chứng |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **macOS 15** | Apple Silicon (arm64) | **`9.4.0`** | **YES** | **PASS** | **VERIFIED** | **UNVERIFIED** | **Mục tiêu chính hiện tại của macOS**: Khởi chạy Desktop, Draft link tài nguyên, Font, Keyframes hoạt động trơn tru. Render Queue macOS là mục tiêu tương lai (Profile: `macos_capcut_9_4_0`). |
| **macOS 14/15** | Apple Silicon (arm64) | `9.3.0` | **YES** | **PASS** | **VERIFIED (Historical)** | **UNVERIFIED** | *Historical Baseline*: Đã nghiệm thu thành công trong Phase 3/4; hiện được kế thừa và nâng cấp lên chuẩn 9.4.0. |
| **macOS** | Mọi kiến trúc | `< 8.0.0` | **NO** | **NO** | **UNSUPPORTED** | **UNSUPPORTED** | Không hỗ trợ: Định dạng schema cũ thiếu `common_keyframes`. |
| **macOS** | Mọi kiến trúc | `10.x+` (Tương lai) | **NO** | **NO** | **UNTESTED** | **UNSUPPORTED** | Cần audit lại schema khi ByteDance phát hành. |
| **Windows 10/11** | x86_64 | **`9.3.0` (Exact)** | **YES** (Static) | **UNTESTED** | **UNTESTED** | **PHASE_5E_TARGET** | **Mục tiêu số 1 cho Render Automation (Phase 5E)**. Profile concept: `windows_capcut_9_3_0`. Chỉ bật cho đúng bản build 9.3.0 đã verify trên máy thật. |
| **Windows 10/11** | x86_64 | `9.3.x` (Wildcard) | **YES** (Dev Mode) | **UNTESTED** | **UNTESTED** | **DISABLED** | Tuyệt đối không tự động kích hoạt Render Queue cho các bản phụ cho đến khi được verify. |
| **Windows 10/11** | x86_64 | `9.4.x` / Latest | **YES** (Dev Mode) | **UNTESTED** | **UNTESTED** | **DISABLED** | Không cho phép tự động kích hoạt Render Queue. Chờ mở rộng profile sau khi hoàn tất 9.3.0. |
| **Windows 10/11** | ARM64 | Mọi bản | **UNTESTED** | **UNTESTED** | **UNTESTED** | **DISABLED** | Chờ phần cứng Snapdragon X vật lý. |
| **Windows 10/11** | Mọi kiến trúc | `< 8.0.0` | **NO** | **NO** | **UNSUPPORTED** | **UNSUPPORTED** | Không tương thích schema. |

---

## 3. QUY TẮC VERSION GUARD CHO RENDER QUEUE

Cơ chế bảo vệ phiên bản cho phân hệ Render Queue được định tuyến riêng biệt theo từng hệ điều hành:

```python
# Pseudo-logic kiểm tra Version Guard cho Render Queue
def is_render_queue_enabled(platform: str, capcut_build_info: dict) -> bool:
    if platform == "windows":
        # Windows Phase 5E: Chỉ kích hoạt cho duy nhất bản 9.3.0 đã kiểm chứng vật lý
        return (
            capcut_build_info.get("version") == "9.3.0"
            and capcut_build_info.get("is_exact_build_verified") is True
        )
    elif platform == "macos":
        # macOS: CapCut 9.4.0 hỗ trợ Draft Workflow; Render Queue chưa mở cho đến khi có automation profile
        return False
    return False
```

* **Hành vi khi không thỏa mãn Version Guard:**
  * Nút "Render Queue" và "Render Now" tự động chuyển về trạng thái `DISABLED`.
  * Hiển thị thông báo minh bạch cho người dùng:  
    `"Phiên bản CapCut hiện tại chưa được xác minh cho tính năng Render Queue tự động. Bạn vẫn có thể tạo dự án và bấm 'Mở trong CapCut' để Export thủ công."`

---

## 4. QUY TRÌNH NÂNG CẤP VÀ XÁC MINH PHIÊN BẢN MỚI

Một phiên bản CapCut mới chỉ được phép chuyển trạng thái từ `UNTESTED` sang `VERIFIED` khi thỏa mãn:

1. **Đối với năng lực DRAFT_GENERATION:**
   * Mở được dự án không hiện thông báo lỗi hay popup "Thiếu tệp tin" (Missing media).
   * Thêm/bớt clip, thay đổi vị trí trên timeline, bấm lưu và đóng CapCut.
   * Mở lại dự án một lần nữa thành công mà không làm hỏng cấu trúc `draft_info.json`.
2. **Đối với năng lực RENDER_AUTOMATION:**
   * Thu thập đầy đủ dữ liệu từ `CapCutUiProbe.exe` (UIA tree, control patterns, export dialog).
   * Tạo tệp `RenderProfile` chuyên biệt cho bản build đó.
   * Vượt qua bài kiểm tra Single Export và bài kiểm tra Batch Queue 5 dự án trên thiết bị thật.
