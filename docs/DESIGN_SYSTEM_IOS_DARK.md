# iOS Dark Mode Design System (Glassmorphism & Clean Minimalist)

**Phiên bản:** 1.0.0  
**Áp dụng:** Web Dashboard & Desktop Tool (VibeCode / Slideshow Builder AI)  
**Namespace OpenViking:** `ui_design_system`  

---

## 1. HỆ THỐNG MÀU SẮC (COLOR PALETTE & DESIGN TOKENS)

### 1.1. Bảng màu nền & Container (Background & Surfaces)
- **Deep Black (OLED Background):** `#000000` — Nền chính cho toàn bộ trang web và ứng dụng.
- **Surface Level 1 (Card / Modal Background):** `#1c1c1e` hoặc `rgba(28, 28, 30, 0.75)` kèm hiệu ứng `backdrop-filter: blur(20px)`.
- **Surface Level 2 (Elevated / Nested Containers):** `#2c2c2e` hoặc `rgba(44, 44, 46, 0.65)`.
- **Surface Level 3 (Inputs & Control Track):** `#3a3a3c` hoặc `rgba(58, 58, 60, 0.5)`.

### 1.2. Màu sắc thương hiệu & Điểm nhấn (Accent & Interactions)
- **Primary Accent Blue:** `#0a84ff` (iOS System Blue - Dark Mode).
- **Accent Hover / Glow:** `#409cff` / `rgba(10, 132, 255, 0.25)`.
- **Accent Active / Pressed:** `#006ee6`.

### 1.3. Màu trạng thái (Semantic Status Colors)
- **Success / Online (Green):** `#30d158` (iOS Green).
- **Warning / Pending (Orange/Yellow):** `#ffd60a` (iOS Yellow) / `#ff9f0a` (iOS Orange).
- **Danger / Error / Expired (Red):** `#ff453a` (iOS Red).
- **Info / Neutral (Cyan/Indigo):** `#64d2ff` / `#5e5ce6`.

### 1.4. Kiểu chữ & Độ mờ phân cấp (Typography & Text Hierarchy)
- **Primary Text:** `#ffffff` (100% Opacity) — Tiêu đề, số liệu chính, nhãn quan trọng.
- **Secondary / Muted Text:** `#ebebf5` với `opacity: 0.60` (hoặc `rgba(235, 235, 245, 0.6)`) — Mô tả phụ, placeholder, timestamp.
- **Tertiary / Disabled Text:** `#ebebf5` với `opacity: 0.30` (hoặc `rgba(235, 235, 245, 0.3)`).

---

## 2. QUY CHUẨN HIỆU ỨNG THỦY TINH (GLASSMORPHISM SPECS)

### 2.1. Thẻ giao diện (Glass Cards & Panels)
```css
.glass-card {
  background: rgba(28, 28, 30, 0.72);
  backdrop-filter: blur(25px) saturate(180%);
  -webkit-backdrop-filter: blur(25px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.10);
  border-radius: 16px;
  box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
}
```

### 2.2. Viền sáng vi mô (Subtle Borders)
- **Thẻ và Hộp chứa:** `border: 1px solid rgba(255, 255, 255, 0.10)`.
- **Đường phân cách (Dividers):** `border-color: rgba(255, 255, 255, 0.08)`.
- **Active / Focused Border:** `border-color: rgba(10, 132, 255, 0.60); box-shadow: 0 0 0 3px rgba(10, 132, 255, 0.20);`.

---

## 3. THÔNG SỐ HÌNH HỌC (BORDER RADIUS & SPACING)

| Thành phần giao diện (Component) | Border Radius | Padding / Kích thước tiêu chuẩn |
| :--- | :---: | :--- |
| **Thẻ lớn (Glass Cards / Containers)** | `16px` | Padding: `20px` hoặc `24px` |
| **Modal / Dialog Boxes** | `20px` | Padding: `24px` hoặc `32px` |
| **Nút bấm (Buttons)** | `10px` | Chiều cao: `40px` (Medium) hoặc `48px` (Large), Padding ngang: `16px` |
| **Ô nhập liệu (Input Fields / Selects)**| `10px` | Chiều cao: `40px`, Padding ngang: `12px` |
| **Hộp thoại nhỏ (Pills / Badges / Tags)**| `9999px` (Pill) | Padding: `4px 10px`, Chiều cao: `24px` |
| **Thanh cuộn & Progress Bar** | `9999px` (Full) | Thanh cuộn rộng `6px`, Progress Bar cao `6px` |

---

## 4. QUY TẮC NÚT BẤM VÀ TƯƠNG TÁC (BUTTONS & CONTROLS)

### 4.1. Nút chính (Primary Button)
```css
.btn-primary {
  background-color: #0a84ff;
  color: #ffffff;
  font-weight: 600;
  border-radius: 10px;
  border: none;
  padding: 10px 18px;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.btn-primary:hover {
  background-color: #409cff;
  box-shadow: 0 4px 14px rgba(10, 132, 255, 0.35);
  transform: translateY(-1px);
}
.btn-primary:active {
  background-color: #006ee6;
  transform: translateY(0);
}
```

### 4.2. Nút phụ kính mờ (Glass Secondary Button)
```css
.btn-secondary {
  background: rgba(255, 255, 255, 0.08);
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 10px;
  backdrop-filter: blur(10px);
  padding: 10px 18px;
  transition: all 0.2s ease;
}
.btn-secondary:hover {
  background: rgba(255, 255, 255, 0.15);
  border-color: rgba(255, 255, 255, 0.20);
}
```

---

## 5. FONT CHỮ HỆ THỐNG (TYPOGRAPHY STACK)
- **Apple Devices (macOS / iOS):** `-apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display"`
- **Windows / Fallback:** `"Segoe UI", -apple-system, Roboto, Helvetica, Arial, sans-serif`
- **Monospace (Code / License Keys / Logs):** `"SF Mono", "Consolas", "Menlo", "Courier New", monospace`

---

## 6. BẢN GHI JSON TỔNG HỢP (DÀNH CHO VECTOR DATABASE OPENVIKING)

```json
{
  "namespace": "ui_design_system",
  "theme": "ios_dark_mode",
  "version": "1.0.0",
  "colors": {
    "background_primary": "#000000",
    "surface_card": "rgba(28, 28, 30, 0.75)",
    "surface_secondary": "rgba(44, 44, 46, 0.65)",
    "surface_control": "rgba(58, 58, 60, 0.50)",
    "accent_primary": "#0a84ff",
    "accent_hover": "#409cff",
    "accent_active": "#006ee6",
    "status_success": "#30d158",
    "status_warning": "#ffd60a",
    "status_danger": "#ff453a",
    "text_primary": "#ffffff",
    "text_muted": "rgba(235, 235, 245, 0.60)",
    "text_disabled": "rgba(235, 235, 245, 0.30)",
    "border_subtle": "rgba(255, 255, 255, 0.10)",
    "border_focus": "rgba(10, 132, 255, 0.60)"
  },
  "glassmorphism": {
    "card_blur": "25px",
    "card_saturation": "180%",
    "border_width": "1px",
    "shadow": "0 8px 32px 0 rgba(0, 0, 0, 0.37)"
  },
  "radii": {
    "card": "16px",
    "modal": "20px",
    "button": "10px",
    "input": "10px",
    "badge": "9999px"
  }
}
```