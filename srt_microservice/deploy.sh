#!/usr/bin/env bash
# Quick VPS Deployment Script for SRT Microservice (Ubuntu/Debian)

set -e

echo "=========================================================="
echo "🚀 Deploying SRT Forced Alignment Microservice on VPS"
echo "=========================================================="

# 1. Update system & install FFmpeg
sudo apt-get update
sudo apt-get install -y ffmpeg python3 python3-pip python3-venv git curl

# 2. Setup Virtual Environment
if [ ! -d ".venv" ]; then
    echo "[*] Creating Python virtual environment..."
    python3 -m venv .venv
fi

source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt

# 3. Test installation
echo "[*] Checking service health..."
python3 -c "import faster_whisper, fastapi, uvicorn; print('✅ All dependencies loaded successfully!')"

echo "=========================================================="
echo "✅ Setup Complete!"
echo "To start the microservice in the background:"
echo "   nohup .venv/bin/uvicorn app:app --host 0.0.0.0 --port 8000 --workers 2 > srt_service.log 2>&1 &"
echo "To benchmark the service:"
echo "   python3 benchmark_client.py --server http://127.0.0.1:8000 --audio sample.wav --script sample.txt"
echo "=========================================================="
