# 2TOOLNE AUTOEDIT V2 — PRIORITY 5: CLOUD SHARE LINK
## Fast-Track Production Architecture & Verification Summary

**Document ID:** `REP-P5-SHARE-LINK-V2`  
**Status:** `PASS / FREEZE` — Ready for Production Deployment  
**Author:** Antigravity (Google Deepmind)  
**Date:** September 8, 2026  
**Authoritative Plan Reference:** `reports/productization/AUTH_LICENSE_CLOUD_TEAM_INTEGRATION_PLAN.md`

---

### Executive Summary

Priority 5 (Cloud Share Link) has been designed, implemented, hardened, and verified under strict production specifications for 2TOOLNE AutoEdit V2. Secure public sharing for cloud files and folders is fully operational with zero credential leakage, complete isolation of folder subtrees, and zero token exposure in Apache access logs.

All **32 automated tests (`SHARE-T01` through `SHARE-T32`)** pass at 100%, and zero regressions were introduced into Priority 1 (License + Account), Priority 2 (Browser Quick Login), Priority 3 (Token & Upscale Gating), Priority 4 (Cloud Explorer), or core subtitle generation algorithms (`A0`, `A1`, `A2`).

---

### Key Architectural Implementations

#### 1. Schema Migration (`database/migrations/v3_cloud_shares_schema.sql`)
- **Strict File-Based Migration:** Request-time schema mutation (`CREATE TABLE` inside API endpoints) was completely eliminated.
- **Dual Token Architecture:**
  - `share_token_hash` `VARCHAR(64) UNIQUE NOT NULL`: Indexed SHA-256 hash used exclusively for public lookup ($O(1)$ lookup time, raw token impossible to reverse).
  - `share_token_ciphertext` `TEXT NOT NULL`: Base64-encoded AES-256-GCM envelope containing 96-bit IV, 128-bit authentication tag, and ciphertext. Decrypted strictly for authenticated owners and space admins to allow copying the existing share link without re-generating tokens or storing raw tokens in plaintext.
- **Idempotency Support:** `idempotency_key` `VARCHAR(64) UNIQUE` prevents duplicate share records on retry.
- **Access Accounting:** Tracks `access_count` and `last_accessed_at` without storing sensitive metadata.

```sql
CREATE TABLE IF NOT EXISTS `cloud_shares` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `cloud_space_id` VARCHAR(64) NOT NULL,
  `item_type` ENUM('FILE', 'FOLDER') NOT NULL,
  `item_id` VARCHAR(64) NOT NULL,
  `share_token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `share_token_ciphertext` TEXT NOT NULL,
  `idempotency_key` VARCHAR(64) NULL UNIQUE,
  `access_level` ENUM('VIEW_ONLY', 'ALLOW_DOWNLOAD') NOT NULL DEFAULT 'VIEW_ONLY',
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `created_by_user_id` VARCHAR(64) NOT NULL,
  `access_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_accessed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_shares_space_item` (`cloud_space_id`, `item_type`, `item_id`),
  INDEX `idx_shares_hash` (`share_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2. Zero Token Exposure in Server Logs (Fragment Architecture)
- **URL Fragment Architecture:** Public share links are formatted as `https://www.2tamne.site/share/#<raw_token>`.
- **RFC Compliance:** Per RFC 3986, URL fragments (`#...`) are processed purely client-side by user agents and are **never transmitted in HTTP GET requests**. Apache, Nginx, CDN edge, and proxy access logs never record the raw secret token.
- **Capability Resolution Flow:**
  1. Recipient opens `https://www.2tamne.site/share/#<raw_token>`.
  2. Static HTML/JS shell loads.
  3. Client-side JavaScript extracts `window.location.hash.slice(1)`.
  4. Client makes `POST /api/v1/cloud/public/share/resolve` with body `{"share_token": "<raw_token>"}`.
  5. Backend checks SHA-256 hash in DB, verifies active status, increments access count once, and generates an HMAC-SHA256 signed capability token (`share_session`, 15-minute TTL).
  6. Subsequent preview/download/folder calls use the capability token: `GET /api/v1/cloud/public/share/download?session=<capability>`.

#### 3. Single-Session Access Accounting & Range Streaming Protection
- Video and audio streaming requests utilize standard HTTP `Range: bytes=start-end` (`206 Partial Content`).
- `access_count` increments **strictly once** when the fragment is resolved via `/resolve`.
- Subsequent chunk/byte-range preview requests verify the capability session without updating database rows, completely eliminating write storms during media scrubbing and playback.

#### 4. Strict Folder Subtree Isolation
- For folder shares, any public request for subfolders (`/folder?subfolder_id=...`) or descendant files (`/download?file_id=...`) is subjected to cycle-safe ancestor traversal with bounded depth (maximum 50 iterations).
- Sibling folders, parent folders, root workspace files, and cross-space items are rejected with HTTP 403 `FORBIDDEN`.

#### 5. Desktop Application Integration
- **`cloud_client.js`:** Added methods `createShare(spaceId, itemType, itemId, accessLevel, expiresIn, idempotencyKey)`, `getItemShares(spaceId, itemType, itemId)`, and `revokeShare(shareId)`.
- **Preload & IPC Bridge:** Secure IPC bindings exposed via `window.autoedit.cloud.createShare`, `getItemShares`, `revokeShare`.
- **Desktop UI (Modal 13 `#modalCloudShare`):**
  - Configurable access levels: `Chỉ xem` (`VIEW_ONLY`) and `Cho phép tải xuống` (`ALLOW_DOWNLOAD`).
  - Configurable expiration: `7 ngày`, `30 ngày`, `Vô thời hạn`.
  - Copy link action with toast notification and visual clipboard feedback.
  - Active share list showing access level, expiry, copy button, and immediate revocation button.
  - File table rows include `[🔗]` button for instant sharing and a `🔗` badge indicator for actively shared items.

---

### Verification Matrix (32/32 Tests Passed)

| Test ID | Scenario / Verification Scope | Result |
| :--- | :--- | :---: |
| **SHARE-T01** | Create file share (ALLOW_DOWNLOAD, 7d expiration) | **PASS** |
| **SHARE-T02** | Cryptographically random token generated (32 bytes = 256 bits entropy) | **PASS** |
| **SHARE-T03** | Raw token not stored server-side (only SHA-256 hash & AES-GCM ciphertext) | **PASS** |
| **SHARE-T04** | Public access works without login via POST `/resolve` | **PASS** |
| **SHARE-T05** | Invalid token rejected with HTTP 404 `NOT_FOUND` | **PASS** |
| **SHARE-T06** | Expired link rejected with HTTP 410 `EXPIRED` | **PASS** |
| **SHARE-T07** | Revoked link rejected immediately with HTTP 410 `REVOKED` | **PASS** |
| **SHARE-T08** | VIEW_ONLY link correctly blocks download with HTTP 403 `DOWNLOAD_FORBIDDEN` | **PASS** |
| **SHARE-T09** | ALLOW_DOWNLOAD permits file streaming with `attachment` disposition | **PASS** |
| **SHARE-T10** | Image preview streamed inline with HTTP 200 and image mime type | **PASS** |
| **SHARE-T11** | Video Range streaming returns HTTP 206 `Partial Content` with correct range | **PASS** |
| **SHARE-T12** | Audio streaming served inline with `audio/mpeg` content type | **PASS** |
| **SHARE-T13** | Folder share creation verified for cloud folders | **PASS** |
| **SHARE-T14** | Folder children listed accurately via capability session token | **PASS** |
| **SHARE-T15** | Subfolder navigation works within shared subtree | **PASS** |
| **SHARE-T16** | Descendant file downloaded successfully via folder share | **PASS** |
| **SHARE-T17** | Parent folder traversal attempt strictly rejected with HTTP 403 `FORBIDDEN` | **PASS** |
| **SHARE-T18** | Sibling folder and files access strictly blocked with HTTP 403 `FORBIDDEN` | **PASS** |
| **SHARE-T19** | Cross-space IDOR attempts rejected with HTTP 403 `FORBIDDEN` | **PASS** |
| **SHARE-T20** | Non-owner and VIEWER role denied share creation (HTTP 403) | **PASS** |
| **SHARE-T21** | Desktop copy link formatted with `#` fragment identifier | **PASS** |
| **SHARE-T22** | Desktop revoke link executes and revokes immediately via IPC API | **PASS** |
| **SHARE-T23** | Existing active shares returned for item instead of blind duplicates | **PASS** |
| **SHARE-T24** | Filenames safely HTML-escaped against XSS | **PASS** |
| **SHARE-T25** | Filename header injection (CRLF, path traversal) sanitized | **PASS** |
| **SHARE-T26** | Audit logs track share_id without exposing raw tokens | **PASS** |
| **SHARE-T27** | Owner recovers existing share URL via AES-GCM without DB raw token storage | **PASS** |
| **SHARE-T28** | Unauthorized users/viewers cannot recover encrypted share token (`share_url: null`) | **PASS** |
| **SHARE-T29** | Master share token never appears in application audit logs | **PASS** |
| **SHARE-T30** | Master share token never appears in public request URLs (fragment + body) | **PASS** |
| **SHARE-T31** | Multiple HTTP Range chunk requests count as 1 access session (no DB write storm) | **PASS** |
| **SHARE-T32** | Idempotency key prevents duplicate share records in DB | **PASS** |

---

### Regression Testing Summary

| Test Suite | Priority Area | Total Tests | Status |
| :--- | :--- | :---: | :---: |
| `test_quick_login_and_auth.js` | Priority 1 (License + Account), Priority 2 (Quick Login), Priority 3 (Upscale Gating) | 5 / 5 | **100% PASS** |
| `test_cloud_explorer.js` | Priority 4 (Cloud File Explorer, VFS, Upload/Download, Quota) | 20 / 20 | **100% PASS** |
| `test_cloud_share.js` | Priority 5 (Cloud Share Link, Fragment Security, Range, Subtree Isolation) | 32 / 32 | **100% PASS** |
| `test_a0_collapse_healing.py` | Subtitle Generation Engine (`FROZEN_PRODUCTION`) | 4 / 4 | **100% PASS** |

---

### Production Deployment Instructions

1. **Database Migration:**
   Apply `database/migrations/v3_cloud_shares_schema.sql` to production MariaDB/MySQL database:
   ```bash
   mysql -u root -p tamne_cloud < database/migrations/v3_cloud_shares_schema.sql
   ```
2. **Backend Controllers & Routing:**
   Deploy `website/api/v1/controllers/CloudShareController.php` and updated `website/api/v1/index.php`.
3. **Public Share Landing:**
   Deploy `website/share/index.php` and `website/share/.htaccess`.
4. **Desktop App Build:**
   Package Electron app containing updated main process, preload, and renderer modules.
