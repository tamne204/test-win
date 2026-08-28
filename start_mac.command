#!/bin/bash
cd "$(dirname "$0")"
clear
echo "========================================================"
echo "   SLIDESHOW BUILDER AI - PHIÊN BẢN DÀNH CHO MACOS"
echo "========================================================"
echo ""

# Ưu tiên .venv (có đầy đủ PIL, edge_tts, flask...) — nếu không có thì dùng python3 hệ thống
if [ -f ".venv/bin/python3" ]; then
    PYTHON_CMD=".venv/bin/python3"
    echo "✅ Đang dùng môi trường .venv (đầy đủ thư viện)"
elif command -v python3 &>/dev/null; then
    PYTHON_CMD="python3"
    echo "🔍 Đang dùng Python hệ thống..."
    echo "📦 Đang tự động kiểm tra và cài đặt thư viện cần thiết..."
    python3 -m pip install -q -r requirements.txt 2>/dev/null || true
else
    echo "❌ Lỗi: Không tìm thấy Python 3 trên máy Mac của bạn."
    echo "👉 Vui lòng cài đặt Python từ https://www.python.org/downloads/"
    read -p "Nhấn Enter để thoát..."
    exit 1
fi

echo ""
echo "🚀 Đang khởi động Slideshow Builder AI tại http://localhost:8080 ..."
echo ""
(sleep 2 && open "http://localhost:8080") &
"$PYTHON_CMD" app.py
