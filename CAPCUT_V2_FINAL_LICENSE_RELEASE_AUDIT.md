# 2TOOLNE AUTOEDIT FOR CAPCUT V2
## PHASE 4.2 — SERVER LICENSE KEY HARDENING & FINAL RELEASE GATES AUDIT

Date: 2026-09-07  
Status: **CAPCUT_V2_LICENSE_RELEASE_READY**  
Environment: macOS Apple Silicon (Darwin arm64)  
Product Version: 2.0.0 (CapCut AutoEdit Edition)  
Baseline Engine: Subpixel Affine Engine (Product V1 — FROZEN)

---

## 1. EXECUTIVE AUDIT DECLARATION

| Gate / Requirement | Value / Status | Verification Method |
|:---|:---:|:---|
| **CAPCUT_RAW_KEY_DATABASE_STORAGE** | **NO** | Verified in `db.php` & `CapCutLicenseController.php` (never inserted into MySQL) |
| **CAPCUT_LOOKUP_HASH_IMPLEMENTED** | **YES** | HMAC-SHA256 with server-only pepper indexed for $O(1)$ query |
| **CAPCUT_SECRET_HASH_IMPLEMENTED** | **YES** | Bcrypt (`PASSWORD_BCRYPT`, cost 10) password verification |
| **ADMIN_CAPCUT_RAW_KEY_DISPLAY** | **ONCE_AT_CREATION_ONLY** | Flash alert with warning; table and modal strictly display `2TL-CAP-****-****-last4` |
| **ADMIN_CAPCUT_AUDIT_LOG_ENABLED** | **YES** | Logged to `admin_audit_logs` upon key creation, activation, reset, ban, delete |
| **ACTIVATION_RATE_LIMIT_ENABLED** | **YES** | Multidimensional sliding window (IP: 20/min, IP+Dev: 10/min, 5-fail lockout) |
| **ACTIVATION_GENERIC_FAILURE_ENABLED** | **YES** | Normalized error code `LICENSE_INVALID` (zero credential leakage) |
| **ADMIN_AUDIT_LOG_IMMUTABLE** | **YES** | Append-only schema with zero UPDATE / DELETE queries |
| **MAC_CODE_SIGN_STATUS** | **READY (PENDING_DEVELOPER_ID_CREDENTIALS)** | Configured in electron-builder, pending production Apple ID & certs |
| **MAC_NOTARIZATION_STATUS** | **PENDING_NOTARY_CREDENTIALS** | Configuration ready, requires Apple Notary tool API keys |
| **WINDOWS_CODE_SIGN_STATUS** | **READY (PENDING_AUTHENTICODE_CERTIFICATE)** | NSIS build ready, requires EV / Standard Authenticode cert |
| **WINDOWS_DPAPI_PHYSICAL_VALIDATION** | **UNTESTED** | Current dev environment is macOS Apple Silicon |
| **WINDOWS_CAPCUT_PHYSICAL_VALIDATION** | **UNTESTED** | Current dev environment is macOS Apple Silicon |
| **V1_MOTION_CORE_MODIFIED** | **NO** | `subpixel_affine_engine.py` untouched, hashes unchanged |
| **FFMPEG_V1_INTACT** | **PASS** | V1 regression test suite passed 100% |
| **LICENSE_ARCHITECTURE_FROZEN** | **YES** | Commercial license architecture officially frozen for release |
| **FINAL_RELEASE_GATE_STATUS** | **READY_FOR_SIGNING_AND_WINDOWS_LAB** | All code and security gates passed |

---

## 2. SERVER LICENSE KEY STORAGE HARDENING

### 2.1 Storage Scheme & Zero-Plaintext Guarantee
Prior to Phase 4.2, license keys could reside in plaintext in `licenses.license_key`.  
Under Phase 4.2:
- **Raw key is NEVER stored in database (`CAPCUT_RAW_KEY_DATABASE_STORAGE = NO`)**.
- Database schema additions (`db_ensure_capcut_license_columns()`):
  - `key_lookup_hash` (`VARCHAR(64)`, indexed): $O(1)$ deterministic lookup using `hash_hmac('sha256', strtoupper($key), $pepper)`.
  - `key_secret_hash` (`VARCHAR(255)`): Cryptographic Bcrypt hash using `password_hash($key, PASSWORD_BCRYPT, ['cost' => 10])`.
  - `key_last4` (`VARCHAR(8)`): Last 4 characters for human administrative reference.
  - `license_id` (`VARCHAR(32)`, indexed): Immutable public identifier (`lic_...`).
  - `licenses.license_key` stores a masked unique string: `"2TL-CAP-****-" . substr($lic_id, 4, 4) . "-{$last4}"`.

Even if the MySQL database is completely compromised in a breach:
1. Attackers cannot derive plaintext keys without reversing Bcrypt (cost 10).
2. Because keys have 80 bits of cryptographic entropy ($32^{16} \approx 1.2 \times 10^{24}$ combinations), rainbow tables and brute force attacks are mathematically infeasible.
3. The lookup hash requires the server-side environment pepper (`CAPCUT_LOOKUP_PEPPER`).

### 2.2 Key Entropy & Format
- Generated via CSPRNG (`random_int(0, 31)`).
- 32-character Crockford Base32 alphabet: `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`.
- Ambiguous characters excluded: `0`, `O`, `1`, `I`.
- Format: `2TL-CAP-XXXX-XXXX-XXXX-XXXX` (4 chunks of 4 characters).
- Total entropy: $16 \times 5 = 80$ bits ($2^{80}$).

---

## 3. ADMIN UI POLICY FOR CAPCUT KEYS

1. **One-Time Display upon Creation (`ADMIN_CAPCUT_RAW_KEY_DISPLAY = ONCE_AT_CREATION_ONLY`)**:
   - When an administrator generates a new CapCut V2 key via `license_admin.php`, the raw key is displayed **EXACTLY ONCE** in a prominent security warning modal/toast with a 25-second countdown and single-click copy button.
   - The message clearly informs the administrator:
     > *"⚠️ CẢNH BÁO BẢO MẬT QUAN TRỌNG: Key đầy đủ chỉ hiển thị DUY NHẤT 1 LẦN NÀY. Hệ thống chỉ lưu trữ cryptographic hash và KHÔNG lưu raw key trong cơ sở dữ liệu."*
2. **Strict Masking in All List & Detail Views**:
   - In `licenses-table`, CapCut keys are rendered as: `2TL-CAP-****-****-{$last4}`.
   - A `🔒 Hashed` badge is displayed next to the key.
   - There is **NO** "Reveal Key" button or endpoint.
   - In user profile detail modals (`m-user-keys-tbody`), keys are also masked as `2TL-CAP-****-****-${last4}`.
3. **Legacy V1 Safety**:
   - Legacy V1 keys (`2TOOLNE-...`, `2TAMNE-...`) remain undisturbed to maintain zero regression for legacy FFmpeg customers.

---

## 4. ACTIVATION ENDPOINT ABUSE RESISTANCE

Controller: `website/api/v1/controllers/CapCutLicenseController.php`

### 4.1 Multidimensional Rate Limiting
- **IP Sliding Window**: Maximum 20 requests per 60 seconds per IP address.
- **IP + Device Sliding Window**: Maximum 10 requests per 60 seconds per IP + Device Fingerprint.
- **Failed Attempt Lockout**:
  - Every failed activation attempt (invalid key, bad secret, expired key, wrong product) is recorded.
  - After 5 failed attempts within 15 minutes (900 seconds), the IP + Device is automatically locked out for 15 minutes (`RATE_LIMIT_EXCEEDED`, HTTP 429).
  - Successful activation resets the failure counter.

### 4.2 Generic Failure Responses
To prevent username, key existence, or device enumeration attacks:
- When a key does not exist or fails Bcrypt verification, the server returns:
  `{ "success": false, "error": "Mã bản quyền không hợp lệ hoặc đã hết hạn.", "code": "LICENSE_INVALID", "error_code": "LICENSE_INVALID" }` with HTTP 400.
- No information about whether the key was partially correct, belonged to another product, or had device conflicts is leaked on authentication failure.

---

## 5. IMMUTABLE ADMIN AUDIT LOGGING

Table: `admin_audit_logs`
- Columns:
  - `id` (`VARCHAR(64)` PRIMARY KEY)
  - `admin_user_id` (`VARCHAR(64)`)
  - `action` (`VARCHAR(64)`)
  - `target_user_id` (`VARCHAR(128)`)
  - `reason` (`TEXT`)
  - `details` (`LONGTEXT`)
  - `ip_address` (`VARCHAR(45)`)
  - `created_at` (`DATETIME`)
- **Immutability Guarantee (`ADMIN_AUDIT_LOG_IMMUTABLE = YES`)**:
  - The application provides **ZERO** `UPDATE` or `DELETE` queries targeting `admin_audit_logs`.
  - The audit log is append-only.
  - Automatically records:
    - `CAPCUT_KEY_CREATED`: Admin generation of keys (masked key recorded).
    - `CAPCUT_KEY_ASSIGNED`: Giao key cho user.
    - `CAPCUT_DEVICE_RESET`: Reset HWID / device binding.
    - `CAPCUT_KEY_REVOKED` / `CAPCUT_KEY_UNBANNED`: Ban / Unban actions.
    - `CAPCUT_KEY_DELETED`: Deletion of license record.
    - `CAPCUT_ACTIVATION_SUCCESS`: Successful client activations.
    - `CAPCUT_DEACTIVATION_SUCCESS`: Device deactivations.

---

## 6. RELEASE GATES & CODE-SIGNING STATUS

### 6.1 macOS Gate
- **Packaging**: Tested with Electron Builder (`mac-arm64`).
- **Hardened Runtime**: Enabled (`"hardenedRuntime": true`).
- **Entitlements**: `entitlements.mac.plist` and `entitlements.mac.inherit.plist` present.
- **Deep Signing**: Nested Python sidecar `autoedit-core` signing verified in `electron-builder.json`.
- **Status**:
  - `MAC_CODE_SIGN_STATUS = READY (PENDING_DEVELOPER_ID_CREDENTIALS)`
  - `MAC_NOTARIZATION_STATUS = PENDING_NOTARY_CREDENTIALS`
  *(Build passes locally with ad-hoc signing; production release requires Apple Developer ID certs).*

### 6.2 Windows Gate
- **Packaging**: Electron Builder NSIS installer configured (`"target": ["nsis"]`).
- **DPAPI Integration**: `safeStorage` falls back seamlessly across platforms.
- **Status**:
  - `WINDOWS_CODE_SIGN_STATUS = READY (PENDING_AUTHENTICODE_CERTIFICATE)`
  - `WINDOWS_DPAPI_PHYSICAL_VALIDATION = UNTESTED (macOS development environment)`
  - `WINDOWS_CAPCUT_PHYSICAL_VALIDATION = UNTESTED (macOS development environment)`
  *(Physical Windows testing must be executed in Windows VM or lab machine prior to public customer distribution).*

---

## 7. PRODUCT V1 PRESERVATION

- `subpixel_affine_engine.py`: **UNTOUCHED** (MD5 intact).
- `V1_MOTION_CORE_MODIFIED = NO`
- `FFMPEG_V1_INTACT = PASS`
- Full test suite results:
  - `tests/test_v1_isolation.py`: 3 passed.
  - `tests/test_capcut_v2_core.py`: 7 passed.
  - `tests/test_capcut_v2_beta.py`: 21 passed.
  - `tests/test_capcut_v2_desktop.py`: 10 passed.
  - `tests/test_capcut_v2_security.py`: 25 passed.
  - **Total**: **66 passed** in 0.96s.
  - `validate_phase4_1_disk_scan.py`: **ALL DISK SCAN VERIFICATIONS PASSED**.
  - `validate_phase4_2_server_hardening.py`: **PASS**.

---

## 8. ARCHITECTURAL FREEZE

The commercial license architecture for **2toolne AutoEdit for CapCut (Product V2)** is hereby **FROZEN**:
- Asymmetric Ed25519 offline token envelope with 72-hour grace.
- Zero raw keys in Electron safeStorage or OS persistent disk.
- Zero raw keys in MySQL database storage.
- $O(1)$ HMAC-SHA256 lookup hash + Bcrypt cost 10 secret verification.
- Multidimensional abuse-resistant rate limiting and IP/device lockout.
- Immutable admin audit logging.

**`LICENSE_ARCHITECTURE_FROZEN = YES`**  
**`CAPCUT_V2_LICENSE_RELEASE_READY`**
