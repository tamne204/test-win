# 2TOOLNE AUTOEDIT V2 — GLOBAL UI STATE CONSISTENCY & RELIABILITY AUDIT
**Version**: 2.0.0-rc2 Hardened  
**Audit Date**: 2026-09-09  
**Execution Mode**: FAST-TRACK PRODUCT HARDENING (EVIDENCE-CORRECTED)  
**Status**: HARDENED SOFTWARE READY FOR TARGETED PHYSICAL RETEST  

---

## Executive Summary

An authoritative end-to-end audit of all 17 product functional domains was conducted across backend API, Python core sidecar, Electron main process, and Web frontend renderer. 

This audit was initiated following real Windows physical activation evidence where `POST /api/v1/capcut/activate` succeeded (HTTP 200, DB record persisted) but Desktop UI remained `"Chưa kích hoạt"`.

### Strict Evidence Policy
In accordance with 2TOOLNE strict quality assurance standards:
1. **Never classify as `PASS_WINDOWS_PHYSICAL` without real physical machine execution proof.**
2. **Disqualified Evidence**: Previous 16,797-byte render outputs were synthetic FFmpeg CI mocks and are permanently disqualified as physical evidence.
3. **Fixture vs Real Pipeline**: Prebuilt drafts (`TestBundle/sample_draft`) do not prove the physical dynamic pipeline.
4. **Binary Loader vs GPU Inference**: Executable loader success is not proof of real GPU tensor inference on physical hardware.
5. **Checksum vs Signature**: SHA-256 is an artifact checksum for payload integrity, not an asymmetric digital signature (`SIGNED_MANIFEST = NO`).

---

## 1. Domain-by-Domain Source of Truth Architecture

### 1.1 Authentication & Session
- **Authoritative Source of Truth**: Production MySQL Database `2tamne_site` (`users`, `sessions`, `auth_tokens`).
- **Client Authoritative State**: In-memory session in Electron Main process + OS secure credential vault (DPAPI on Windows, Keychain on macOS) managed by `SecureStorage`.
- **UI Exposure**: IPC channel `auth:get-state`, `auth:changed`, `auth:session-expired`.
- **Consistency Guard**: When access tokens expire, `CloudClient` automatically invokes `/api/v1/auth/refresh`. If refresh fails, `auth:session-expired` is broadcast to reset UI cleanly without contradictory states.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.2 Commercial Machine License
- **Authoritative Source of Truth**: Backend `licenses` & `devices` tables issuing cryptographically signed Ed25519 `signed_entitlement` tokens.
- **Client Authoritative State**: In-memory `LicenseGuard` in Python sidecar holding evaluated cryptographic state (`authorized: bool`, `plan`, `expires_at`, `offline_until`). Backed on disk by OS DPAPI/Keychain encrypted envelope (zero plaintext raw license keys stored).
- **UI Reactivity**:
  - Main IPC `license:activate`: Strictly verifies `sidecarRes.authorized === true` before persisting credentials to storage or returning success.
  - Main IPC event `license:status-changed` broadcasts authoritative state immediately to `mainWindow`.
  - Renderer `onLicenseChanged`: Re-renders `updateLicenseUI` immediately without polling delays.
- **Root Cause & Fix Summary**:
  1. *Database*: Column `device_fingerprint` migrated from `VARCHAR(64)` to `TEXT` with `device_fingerprint_hash CHAR(64)` index (MySQL 5.6 InnoDB 767-byte compliant). Verified live on production server `43.129.165.150`.
  2. *Crypto Polyfill*: Deployed `paragonie/sodium_compat v1.24.2` to production PHP 7.4 runtime, enabling genuine Ed25519 detached signatures validated by `ed25519_verifier.py`. Verified live on production server.
  3. *Main Guard*: Reject activation if sidecar returns `authorized = false`.
  4. *Renderer Event*: Direct event-driven state update eliminates stale "Chưa kích hoạt" badge.
- **Evidence Level**:
  - Backend DB & Crypto Signing: `PASS_SERVER_PROD`, `PASS_AUTOMATED`
  - Main & Renderer Code Fixes: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`
  - Windows Physical End-to-End (`AKIZI\hieun`): `PENDING_PHYSICAL` (must be confirmed on real hardware through activation -> success popup -> UI immediately active -> restart -> UI active).

### 1.3 Wallet & Credit Metering
- **Authoritative Source of Truth**: Server `user_wallets` and `credit_transactions` tables.
- **Client Authoritative State**: `currentWalletState` in Electron Main (`apps/capcut-v2/desktop/src/main/index.js:1212-1236`).
- **UI Reactivity**: IPC `wallet:state-updated` and `wallet:balance-updated`. Renderer displays balance formatted with locale commas, handles loading, and shows unambiguous context ("Số dư Team" vs "Số dư cá nhân").
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.4 Workspace & Team Governance
- **Authoritative Source of Truth**: Server tables `workspaces`, `teams`, `team_members`, `team_seats`.
- **Client Authoritative State**: `WorkspaceManager` (`apps/capcut-v2/desktop/src/main/workspace_manager.js`).
- **UI Reactivity**: IPC `workspace:sync`, `workspace:get-active`, `workspace:switch`. Workspace dropdown updates team context and seat allowances seamlessly.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.5 Cloud Explorer & Shared Links
- **Authoritative Source of Truth**: Server `cloud_files`, `cloud_shares`, and S3/B2 storage blobs.
- **Client Authoritative State**: `CloudClient` (`apps/capcut-v2/desktop/src/main/cloud_client.js`) with on-disk chunk cache at `appData/cache/cloud_assets`.
- **UI Reactivity**: `cloud:upload-progress`, `cloud:download-progress`, `cloud:list-files`. Resumable chunk uploads with SHA-256 validation.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.6 Scoped AI Connection & Keyring
- **Authoritative Source of Truth**: SERVER `ai_access_keys` table represents the authoritative lifecycle status (`ACTIVE`, `REVOKED`, `EXPIRED`).
- **Local Storage Role**: OS DPAPI (Windows) / macOS Keychain represents encrypted secret storage only. A locally stored key cannot be treated as `ACTIVE` if the server returns `REVOKED`.
- **Client Authoritative State**: `AICredentialStore` (`apps/capcut-v2/desktop/src/main/ai_credential_store.js`) query synchronized with server key status.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.7 Google Flow Automation
- **Authoritative Source of Truth**: Isolated Electron WebContents / Chrome session in `FlowBrowserManager`.
- **Client Authoritative State**: `GoogleFlowAdapter` and `FlowProfileManager`.
- **UI Reactivity**: IPC `flow:status-updated` and `flow:mode-changed` with takeover / resume automation toggles.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.8 Input Bundle Engine & Studio
- **Authoritative Source of Truth**: Local on-disk input directory containing valid structured assets (`images/`, `audio/`, `subtitles/`, `metadata.json`).
- **Client Authoritative State**: `BundleEngine` (`apps/capcut-v2/desktop/src/main/bundle_engine.js`).
- **Integrity Guarantee**: Deterministic asset indexing, missing asset detection, and auto-generated manifest schemas.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.9 Project Build Queue (Queue A — Phase 5E Wave 1)
- **Authoritative Source of Truth**: `ProjectBuildQueueManager` in Python sidecar, backed by atomic on-disk JSON snapshot `build_queue.json`.
- **State Progression**: `QUEUED` -> `BUILDING` -> `PROJECT_READY` (or `FAILED` with retry capability).
- **Separation Constraint**: Strictly decoupled from Render Queue (Queue B). Produces valid CapCut draft folders ready for editing or rendering.
- **Evidence Level**:
  - Automated Suite: `PASS_AUTOMATED` (10/10 tests passing)
  - macOS Physical: `PASS_MAC_PHYSICAL`
  - Windows Physical: `NOT_PROVEN` (previous run used prebuilt `TestBundle/sample_draft` fixture; real bundle generation on Windows machine is `PENDING_PHYSICAL`).

### 1.10 Project Library & Draft Registration
- **Authoritative Source of Truth**: CapCut native user drafts folder (`com.lveditor.draft`) and `root_meta_info.json`.
- **Client Authoritative State**: `CapCutProjectManager` with cooperative lockfile fallback (`root_meta_info.json.lock`) for safe multi-process writes on Windows.
- **UI Reactivity**: Immediate draft detection and direct "Mở trong CapCut" launcher integration.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.11 CapCut Project Schema & Version Adapter
- **Authoritative Source of Truth**: CapCut 9.3 schema specification (`draft_content.json`, `draft_meta_info.json`).
- **Client Authoritative State**: `CapCutVersionAdapter_9_3` and `CapCutDraftValidator`.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_WINDOWS_CI`.

### 1.12 Render Queue (Queue B — Phase 5E Wave 2)
- **Authoritative Source of Truth**: `CapCutRenderQueueManager` in Python sidecar, backed by `render_queue.json`.
- **State Progression**: `IDLE` -> `QUEUED` -> `RENDERING` -> `VERIFYING` -> `COMPLETED` (or `FAILED`).
- **Evidence Level**:
  - Automated Suite: `PASS_AUTOMATED` (3/3 tests passing)
  - Windows Physical: `NOT_PROVEN` (previous 16,797-byte files were synthetic FFmpeg CI mocks; physical CapCut native export is `PENDING_PHYSICAL`).

### 1.13 Physical Native Export Gate
- **Execution Mechanism**: Physical UI automation (Win32 + Keyboard navigation + Computer Vision template matching) targeting CapCut 9.3 native export modal. Final output validated by FFmpeg/ffprobe.
- **Evidence Level**: `NOT_PROVEN` / `PENDING_PHYSICAL` (No physical PASS until real `CapCut.exe` creates the verified MP4 on target hardware).

### 1.14 AI Upscale (Real-ESRGAN Vulkan)
- **Authoritative Source of Truth**: Native `realesrgan-ncnn-vulkan.exe` binary output.
- **Client Authoritative State**: `UpscaleManager` with Vulkan GPU detection and fallback handling.
- **Evidence Level**:
  - `REALESRGAN_WINDOWS_LOADER`: `PASS_WINDOWS_PHYSICAL` (loader successfully executes and probes on Windows physical environment)
  - `REALESRGAN_GPU_INFERENCE`: `PENDING_PHYSICAL` (requires real image upscaled via RTX 3050 Ti GPU tensors)

### 1.15 Desktop Auto-Updater
- **Authoritative Source of Truth**: Server-hosted `update_manifest.json` with SHA-256 artifact hash integrity.
- **Terminology Rule**: SHA-256 is an artifact checksum for payload integrity, not an asymmetric digital signature. `SIGNED_MANIFEST = NO`.
- **Evidence Level**:
  - Automated & macOS Physical: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`
  - Windows Auto-Update: `PENDING_PHYSICAL` (`WINDOWS_AUTOUPDATE = PENDING_PHYSICAL`)

### 1.16 Settings & Preferences
- **Authoritative Source of Truth**: Persistent configuration store `electron-store` / `config.json`.
- **Client Authoritative State**: Reactive getters/setters via `store:get-data` and `store:set-data`.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

### 1.17 Application Restart & Persistence
- **Authoritative Source of Truth**: Atomic JSON state files + encrypted DPAPI/Keychain secrets.
- **Behavior on Reboot**:
  - `entitlement_envelope` restored directly into sidecar memory before main window loads.
  - Active workspaces and cloud cache restored.
  - In-flight queue jobs in `BUILDING` or `RENDERING` gracefully restored to `QUEUED` with crash-recovery markers.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` (Windows physical reboot test is `PENDING_PHYSICAL`).

### 1.18 Offline Mode & Grace Window
- **Authoritative Source of Truth**: Signed Ed25519 token `offline_until` timestamp.
- **Tolerance**: 72-hour offline operation without internet connectivity.
- **Clock Rollback Defense**: Local encrypted `clock_guard.json` prevents manual OS date rewinds.
- **Evidence Level**: `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`.

---

## 2. Evidence Correction & Resolution Summary

| Domain | Prior Classification | Corrected Evidence Classification | Rationale |
|---|---|---|---|
| **License UI on Windows** | PASS_WINDOWS_PHYSICAL | `PENDING_PHYSICAL` | Software fix verified; awaits real physical activation on customer machine |
| **Project Build Queue on Windows** | PASS_WINDOWS_PHYSICAL | `NOT_PROVEN` / `PENDING_PHYSICAL` | Previous test used prebuilt fixture draft, not dynamic bundle build |
| **Render Queue on Windows** | PASS_WINDOWS_PHYSICAL | `NOT_PROVEN` | Previous 16,797-byte outputs were synthetic FFmpeg CI mocks |
| **Native CapCut Export** | PASS_WINDOWS_PHYSICAL | `NOT_PROVEN` / `PENDING_PHYSICAL` | Must be proven by real CapCut.exe creating target MP4 |
| **Real-ESRGAN Upscaler** | PASS_WINDOWS_PHYSICAL | `LOADER: PASS_WINDOWS_PHYSICAL`<br>`GPU_INFERENCE: PENDING_PHYSICAL` | Executable loader verified; full GPU image inference pending |
| **Auto-Updater on Windows** | PASS_WINDOWS_PHYSICAL | `PENDING_PHYSICAL` | macOS physical update does not imply Windows physical PASS |
| **AI Connection SSOT** | Local DPAPI primary | Server `ai_access_keys` is SSOT; DPAPI is secret store | Revocation on server must supersede local cache |
| **Update Manifest Security** | "Signed manifest" | `SHA-256 Checksum Integrity`<br>`SIGNED_MANIFEST = NO` | Correct terminology applied |

---

## 3. Global Audit Verdict
- **GLOBAL_SOFTWARE_HARDENING**: `PASS`
- **CUSTOMER_TEST_READY**: `PASS`
- **WINDOWS_PHYSICAL_CRITICAL_PATH**: `PENDING`
- **FINAL_AUTOEDIT_V2_VERDICT**: `HARDENED_SOFTWARE_READY_FOR_TARGETED_PHYSICAL_RETEST`
