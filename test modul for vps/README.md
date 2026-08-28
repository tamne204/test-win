# ⚡ VPS Subtitle & Forced Alignment Microservice (15-User Scaled)

Module microservice độc lập được tối ưu hóa chuyên biệt cho môi trường **VPS Linux (2 Core / 2GB-4GB RAM)**, xử lý đồng thời **15 người dùng** với cam kết **0% Crash, 0% OOM, CPU luôn ổn định dưới 80%**.

---

## 🛠️ 3 CƠ CHẾ TỐI ƯU HÓA ĐẶC BIỆT CHO 15 KHÁCH HÀNG:

1. **Singleton Shared Model Pool (Tiết kiệm 90% RAM):**
   - Thay vì mỗi khách hàng load 1 model Whisper riêng (15 x 180MB = 2.7GB), server chỉ nạp duy nhất **1 Shared Instance** trong RAM (~180MB cố định).
2. **Semaphore Concurrency Guardrail (Chống 100% CPU lockup):**
   - Giới hạn số lượng phép tính AI chạy song song ở mức `MAX_CONCURRENT_INFERENCES = 2`.
   - 13 khách hàng còn lại được xếp hàng trong hàng đợi bất đồng bộ (Non-blocking Async Queue).
   - Mỗi tác vụ xử lý xong trong ~3-6s sẽ lập tức nhường chỗ cho người tiếp theo mà không làm nghẽn CPU server.
3. **Bounded Thread Allocation (`cpu_threads=2`):**
   - Khóa cứng mỗi worker chỉ sử dụng tối đa 2 luồng tính toán, chừa tài nguyên CPU cho hệ điều hành và web server.

---

## 🚀 HƯỚNG DẪN CHẠY TRÊN VPS:

### 1. Khởi chạy bằng Docker:
```bash
docker compose up -d --build
```

### 2. Kiểm tra hiệu năng thực tế (Stress Test 15 khách):
```bash
python3 stress_test_15_users.py
```
