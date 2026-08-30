# 📖 ĐẶC TẢ SẢN XUẤT: RENDERER G & HỆ THỐNG DUAL-ENGINE

> **Mã tài liệu:** `docs/RENDERER_G_PRODUCTION.md`  
> **Phiên bản:** `v2.2.3.18-PROD` (Checkpoint `renderer-g-rc1`)  
> **Trạng thái:** **RELEASE READY**

---

## 1. NGUYÊN LÝ HOẠT ĐỘNG (CORE ARCHITECTURE)

Renderer G là động cơ xử lý chuyển động subpixel trên GPU (lấy cảm hứng từ `Loomos-hub/glide-ffmpeg`), loại bỏ hoàn toàn hiện tượng khựng/nhảy frame (Integer Crop Truncation) vốn có trong bộ lọc `zoompan` truyền thống của FFmpeg.

```
[Ảnh Đầu Vào]
      │
      ▼
[RGB Tensor (float32)]
      │
      ▼
[GPU Meshgrid Tọa Độ Thực [-1.0, 1.0]]
      │
      ▼
[Phép Biến Đổi Affine Phân Số (Continuous UV Transform)]
      │
      ▼
[torch.nn.functional.grid_sample (mode='bilinear', padding_mode='reflection')]
      │
      ▼
[Direct Stdin Pipe Streaming (Không ghi file tạm)]
      │
      ▼
[FFmpeg Hardware Encoder (VideoToolbox / NVENC / libx264)]
      │
      ▼
[Video Hoàn Chỉnh 60 FPS]
```

---

## 2. MA TRẬN PHẦN CỨNG & ĐỘNG CƠ (PLATFORM & HARDWARE MATRIX)

| Hệ điều hành | Phần cứng GPU | Backend PyTorch | Động cơ Ưu tiên | Động cơ Dự phòng |
| :--- | :--- | :---: | :---: | :---: |
| **macOS** | Apple Silicon (M1 / M2 / M3 / M4) | Metal (`mps`) | **Renderer G (124 FPS)** | Renderer D (Golden Baseline) |
| **Windows** | NVIDIA GeForce / RTX / Quadro | CUDA (`cuda`) | **Renderer G (NVENC)** | Renderer D (Golden Baseline) |
| **Windows** | AMD Radeon / Intel ARC / iGPU | DirectML / QSV | **Renderer D** (Auto-fallback) | Renderer D (Golden Baseline) |
| **Bất kỳ** | CPU Thuần (Không có GPU rời) | CPU Multi-core | **Renderer D (Golden Baseline)** | Renderer D (Golden Baseline) |

---

## 3. CƠ CHẾ ĐỊNH TUYẾN AN TOÀN (ROUTING & CANARY FEATURE FLAGS)

Hệ thống cung cấp cờ điều hướng `camera_renderer` trong cấu hình và project file:

```python
# Cấu hình trong settings / project JSON:
# "camera_renderer": "auto" | "g" | "legacy"

def render_camera_clip_with_fallback(...):
    # 1. auto: Tự động kiểm tra runtime GPU. Nếu khả dụng -> dùng Renderer G.
    #    Nếu xảy ra bất kỳ lỗi ngoại lệ nào -> tự động chuyển sang Renderer D.
    # 2. g: Bắt buộc chạy Renderer G.
    # 3. legacy: Bắt buộc chạy Renderer D (Golden Baseline 4X Canvas).
```

---

## 4. CHUẨN ĐO LƯỜNG VÀ LOGGING (STRUCTURED LOGGING)

Mỗi phân đoạn video được ghi nhận đầy đủ telemetry để phục vụ giám sát và hỗ trợ kỹ thuật:

```text
🚀 [Renderer G] Device: mps | Source: 1920x1080 (2x) | Output: 1920x1080 @ 60fps | Effect: zoom_in (mag=0.20) | Batch: 8 | Encoder: h264_videotoolbox
✅ [Renderer G] Completed 300 frames in 2.42s (123.9 FPS) -> /path/to/output.mp4
```

---

## 5. QUY TRÌNH ROLLBACK KHẨN CẤP (EMERGENCY ROLLBACK)

Trong trường hợp phát hiện sự cố không tương thích trên một cấu hình máy tính cá biệt của khách hàng:
1. Đổi cờ `camera_renderer: "legacy"` trong file cấu hình hoặc project.
2. Ứng dụng sẽ lập tức kích hoạt **Renderer D (Golden Baseline Windows 2.2.3.15)** mà không cần biên dịch lại phần mềm.
