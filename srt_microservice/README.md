# ⚡ SRT Forced Alignment & AutoSub Microservice

High-performance, standalone AI microservice designed for Linux VPS deployment to benchmark and offload subtitle alignment from client machines.

---

## 🌟 Features
- **Two-Pass Anchor Alignment Engine**: 100% ground-truth script matching, zero drift on 30-60 min videos.
- **REST API (FastAPI)**: Lightweight, asynchronous, high-throughput endpoints.
- **Performance Metrics & Healthcheck**: Built-in monitoring for CPU %, RAM, and Real-Time Factor (RTF).
- **CLI Benchmark Client**: Stress-test with single or concurrent requests to evaluate VPS capacity.

---

## 🚀 Quick Start on VPS

### Option 1: Docker (Recommended)
```bash
# 1. Start container
docker compose up -d --build

# 2. View live logs
docker compose logs -f
```

### Option 2: Bare-Metal (Python Virtualenv)
```bash
# 1. Run deployment script
bash deploy.sh

# 2. Start server in background
nohup .venv/bin/uvicorn app:app --host 0.0.0.0 --port 8000 --workers 2 > srt_service.log 2>&1 &
```

---

## 📡 API Endpoints

### 1. Forced Alignment (So Khớp Kịch Bản)
- **POST** `/api/v1/align`
- **Body (Multipart Form)**:
  - `audio`: File âm thanh (.wav, .mp3)
  - `script_text`: Chuỗi văn bản kịch bản gốc
  - `language`: `auto`, `vi`, `ko`, `en`, `ja`
  - `gap_buffer`: `0.02` (20ms)

### 2. AutoSub (Nhận diện giọng nói)
- **POST** `/api/v1/autosub`
- **Body**: `audio`, `language`

### 3. Health & System Monitoring
- **GET** `/health`
- **GET** `/metrics`

---

## 📊 Benchmarking VPS Performance

Run the benchmark client to test latency and speedup factor:
```bash
python3 benchmark_client.py \
  --server http://YOUR_VPS_IP:8000 \
  --audio test_audio.wav \
  --script test_script.txt \
  --concurrency 5
```
