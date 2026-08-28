#!/usr/bin/env bash
# aaPanel (Baota Panel) Automated Environment Setup for SRT Service

set -e

echo "========================================================================="
echo "🚀 [aaPanel Setup] Installing Dependencies for SRT Microservice"
echo "========================================================================="

# 1. Detect Package Manager & Install FFmpeg
if command -v apt-get >/dev/null 2>&1; then
    echo "[*] Detected Ubuntu/Debian system..."
    sudo apt-get update -y
    sudo apt-get install -y ffmpeg curl
elif command -v yum >/dev/null 2>&1; then
    echo "[*] Detected CentOS/RHEL/Alibaba Cloud Linux system..."
    sudo yum install -y epel-release || true
    sudo yum install -y ffmpeg ffmpeg-devel curl || true
elif command -v dnf >/dev/null 2>&1; then
    echo "[*] Detected Rocky/Fedora system..."
    sudo dnf install -y ffmpeg curl || true
fi

# 2. Check FFmpeg
if command -v ffmpeg >/dev/null 2>&1; then
    echo "✅ FFmpeg binary detected: $(which ffmpeg)"
else
    echo "⚠️ Warning: FFmpeg could not be installed automatically. Please install FFmpeg on your VPS."
fi

# 3. Virtualenv pip install (if virtualenv exists)
if [ -d ".venv" ]; then
    echo "[*] Installing Python packages in .venv..."
    .venv/bin/pip install --upgrade pip
    .venv/bin/pip install -r requirements.txt
elif [ -d "venv" ]; then
    echo "[*] Installing Python packages in venv..."
    venv/bin/pip install --upgrade pip
    venv/bin/pip install -r requirements.txt
else
    echo "[*] Installing Python packages globally or in active Python..."
    pip3 install --upgrade pip || pip install --upgrade pip
    pip3 install -r requirements.txt || pip install -r requirements.txt
fi

echo "========================================================================="
echo "✅ aaPanel Environment Setup Completed!"
echo "You can now start the project in aaPanel Python Project Manager!"
echo "========================================================================="
