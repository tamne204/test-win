# 2TOOLNE CLOUD — KIẾN TRÚC MỤC TIÊU V2 (TARGET ARCHITECTURE V2)
**Tài liệu:** `docs/CLOUD_TARGET_ARCHITECTURE.md`  
**Phiên bản:** `2.0.0-PROD-PLAN`  
**Cập nhật V2:** Tích hợp tầng trừu tượng hóa Không gian lưu trữ (`Cloud Space`) hỗ trợ Cá nhân và Đội nhóm, Chu trình gỡ bỏ tài khoản vật lý an toàn (`Safe Account Removal`), Bảng điều khiển Bể lưu trữ Google Drive đa năng và Giám sát tỷ lệ cấp phát vượt mức (Overcommit).

---

## 1. MÔ HÌNH KIẾN TRÚC TỔNG THỂ V2 (LOGICAL ARCHITECTURE V2)

```
┌───────────────────────────┐    ┌───────────────────────────┐    ┌───────────────────────────┐
│     2toolne Upscale       │    │     Slideshow Studio      │    │    Future 2toolne Apps    │
│      (Desktop App)        │    │       (Desktop App)       │    │     (Web / Automation)    │
└─────────────┬─────────────┘    └─────────────┬─────────────┘    └─────────────┬─────────────┘
              │                                │                                │
              └────────────────────────────────┼────────────────────────────────┘
                                               │
                                               ▼
                         ┌───────────────────────────────────────────┐
                         │       2TOOLNE CLOUD REST API v1           │
                         │          (https://2tamne.site)            │
                         └─────────────────────┬─────────────────────┘
                                               │
                                               ▼
                         ┌───────────────────────────────────────────┐
                         │         CLOUD SPACE DOMAIN LAYER          │
                         │  ┌───────────────────┬──────────────────┐ │
                         │  │   Personal Space  │    Team Space    │ │
                         │  │(User-owned 25-50G)│(Shared Pool 50G+)│ │
                         │  └───────────────────┴──────────────────┘ │
                         │  • Quota Ledger (Base + Addon + AdminAdj) │
                         │  • Virtual Folders (cloud_folders)        │
                         │  • Virtual Files (cloud_files)            │
                         │  • Atomic Quota Reservations              │
                         └─────────────────────┬─────────────────────┘
                                               │
                                               ▼
                         ┌───────────────────────────────────────────┐
                         │       STORAGE POOL MANAGER (ADMIN)        │
                         │   • Allocator Strategy: MOST_FREE_SPACE   │
                         │   • Safety Buffer Management (10%)        │
                         │   • Overcommit Ratio Monitor              │
                         │   • Safe Removal Guard (Block if Files>0) │
                         └─────────────────────┬─────────────────────┘
                                               │
                                               ▼
                         ┌───────────────────────────────────────────┐
                         │       STORAGE ADAPTER ABSTRACTION         │
                         │        (StorageProvider Interface)        │
                         └─────────────────────┬─────────────────────┘
                                               │
                    ┌──────────────────────────┼──────────────────────────┐
                    ▼                          ▼                          ▼
         ┌─────────────────────┐    ┌─────────────────────┐    ┌─────────────────────┐
         │  GoogleDriveAdapter │    │  GoogleDriveAdapter │    │    Future Adapter   │
         │   (Drive Account 01)│    │   (Drive Account 02)│    │  (S3 / R2 / B2 ...) │
         └─────────────────────┘    └─────────────────────┘    └─────────────────────┘
```

---

## 2. VÒNG ĐỜI GỠ BỎ TÀI KHOẢN GOOGLE DRIVE AN TOÀN (SAFE REMOVAL LIFECYCLE)

Để bảo vệ dữ liệu khách hàng không bao giờ bị mất mát hoặc mồ côi (Orphaned Files), tài khoản lưu trữ vật lý tuân thủ quy trình 4 trạng thái:

```
[ACTIVE] ──(Dung lượng > 95% hoặc Admin chủ động)──> [DRAINING]
                                                           │
                                                           │ (Tiến trình chuyển file / Xóa tệp)
                                                           ▼
[DISCONNECTED] ◄──(Chỉ cho phép khi active_files = 0)─── [EMPTY / MIGRATED]
```

### Các Trạng Thái Vận Hành Của Tài Khoản Drive:
- **`ACTIVE`:** Đang hoạt động, tiếp nhận các phiên tải lên mới và phục vụ tải xuống.
- **`NEAR_FULL`:** Dung lượng đạt $\ge 85\%$. Chỉ phân bổ các tệp nhỏ ($< 100\text{ MB}$).
- **`DRAINING`:** Không tiếp nhận thêm tệp mới. Tệp cũ vẫn đọc và tải xuống bình thường. Đang chờ di chuyển dữ liệu.
- **`AUTH_REQUIRED`:** Refresh Token bị Google thu hồi hoặc hết hạn. Khóa tải lên, cần Admin bấm "Cấp lại quyền".
- **`OFFLINE` / `ERROR`:** Tạm dừng phục vụ để kiểm tra kỹ thuật.
- **`DISCONNECTED`:** Ngắt kết nối vĩnh viễn (Chỉ thực hiện được khi số tệp còn lại trên Drive bằng đúng 0).

---

## 3. TRUY CẬP VÀ PHÂN QUYỀN TRÊN CLOUD SPACE (ACCESS CONTROL FLOW)

Mọi yêu cầu gửi đến API đều được kiểm tra phân quyền qua 2 tầng:

```
                            Yêu Cầu Tới Cloud API
                                      │
                                      ▼
                        Xác Thực Người Dùng (JWT / Session)
                                      │
                         [Thất bại ──> 401 Unauthorized]
                                      │ Thành công
                                      ▼
                      Phân Giải cloud_space_id Yêu Cầu
                                      │
                         ┌────────────┴────────────┐
                         ▼                         ▼
                 [PERSONAL SPACE]             [TEAM SPACE]
                         │                         │
            owner_id === user_id?      user_id có trong team_members
                         │             với status = 'ACTIVE'?
                [Không ──┼─────────────────────────┼──> 403 Forbidden]
                         │                         │
                         └────────────┬────────────┘
                                      │ Hợp lệ
                                      ▼
             Kiểm Tra Trạng Thái Space (ACTIVE hay OVER_QUOTA)
                                      │
              • Thao tác Đọc / Tải / Xóa: Luôn cho phép.
              • Thao tác Upload mới: Bị chặn nếu OVER_QUOTA.
```

---

## 4. CHIẾN LƯỢC TRUYỀN TẢI TỆP TIN TRỰC TIẾP (DIRECT RESUMABLE UPLOAD)

Tiếp tục bảo toàn nguyên tắc **Zero-Host-Storage (0 bytes qua đĩa hosting)**:
1. Client gửi: `POST /api/v1/cloud/spaces/{space_id}/uploads/create`.
2. Máy chủ kiểm tra hạn mức `cloud_space_quotas` (`used_bytes + reserved_bytes + size <= effective_quota`).
3. Máy chủ khóa dòng nguyên tử (`SELECT ... FOR UPDATE`), cộng `size` vào `reserved_bytes`.
4. Storage Allocator chọn Drive tốt nhất theo `MOST_FREE_SPACE`.
5. Tạo Resumable Session URL trực tiếp với Google Drive API và trả về cho Client.
6. Client (Web/Desktop) truyền dữ liệu nhị phân thẳng tới Google Drive.
7. Sau khi hoàn tất, Client gửi `finalize`: Máy chủ xác minh tệp thực tế trên Google Drive, chuyển số byte từ `reserved_bytes` sang `used_bytes`, kích hoạt `ACTIVE`.

---

## 5. BẢO TỒN TỆP TIN ĐỘI NHÓM KHI THÀNH VIÊN RỜI ĐỘI

- Khi Thành viên B tải lên 100 bức ảnh vào Team "Studio ABC", các bản ghi trong `cloud_files` mang:
  - `cloud_space_id`: `cs_team_abc`.
  - `created_by_user_id`: `user_member_b`.
- Nếu Thành viên B rời đội hoặc bị quản trị viên xóa khỏi đội:
  - Bản ghi trong `team_members` chuyển sang `status = 'REMOVED'`.
  - Thành viên B mất hoàn toàn quyền truy cập vào `cs_team_abc`.
  - **100 bức ảnh trên vẫn thuộc quyền sở hữu của `cs_team_abc`**, các thành viên còn lại trong đội vẫn xem và sử dụng bình thường.
