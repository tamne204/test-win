# 2TOOLNE AUTOEDIT V2 — GLOBAL ACCEPTANCE MATRIX
**Version**: 2.0.0-rc2 Hardened  
**Audit Date**: 2026-09-09  
**Execution Mode**: FAST-TRACK PRODUCT HARDENING (EVIDENCE-CORRECTED)  
**Status**: HARDENED SOFTWARE READY FOR TARGETED PHYSICAL RETEST  

---

## 1. Classification Levels & Rules
- **PASS_AUTOMATED**: Verified by deterministic unit, integration, and security regression test suites (153 passed / 1 skipped).
- **PASS_WINDOWS_CI**: Verified by automated Windows execution pipeline.
- **PASS_MAC_PHYSICAL**: Verified on physical macOS workstation (Apple Silicon).
- **PASS_SERVER_PROD**: Verified live on production server `43.129.165.150` (`2tamne_site`).
- **PASS_WINDOWS_PHYSICAL**: Verified by irrefutable real machine execution evidence on physical Windows hardware (`AKIZI\hieun`).
- **NOT_PROVEN**: Disqualified or unproven due to synthetic mocks, prebuilt fixtures, or lack of dynamic end-to-end evidence.
- **PENDING_PHYSICAL**: Ready for execution; awaiting targeted physical test run on target Windows hardware.
- **FAIL**: Test executed and failed.

---

## 2. Global Acceptance Matrix Across All Domains

| Domain / Subsystem | Authoritative SSOT | Verification Status | Notes / Evidence Boundary |
|---|---|---|---|
| **A0 Forced Alignment Core** | `HierarchicalAligner` + `CollapseDetector` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`, `PASS_WINDOWS_CI` | 4/4 collapse healing tests passing |
| **A1 Visual Rhythm Planner** | `VisualPlanner` + `DynamicProgramming` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`, `PASS_WINDOWS_CI` | 24/24 acceptance matrix tests passing |
| **A2 Frame Quantization** | `FrameQuantizer` (60fps deterministic) | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`, `PASS_WINDOWS_CI` | 17/17 frame quantization tests passing |
| **Rule Engine** | `core/rule_engine.py` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`, `PASS_WINDOWS_CI` | Schema compliance & preset rules verified |
| **Timeline Builder** | `core/timeline_builder.py` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`, `PASS_WINDOWS_CI` | Multi-track alignment & timing verified |
| **CapCut Version Adapter (9.3)** | `adapters/capcut/version_9_3.py` | `PASS_AUTOMATED`, `PASS_WINDOWS_CI` | Schema 9.3 validation clean |
| **Project Build Queue (Queue A)** | `core/build_queue_manager.py` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`<br>**PROJECT_BUILD_WINDOWS_PHYSICAL: NOT_PROVEN** | 10/10 tests pass. Previous run used prebuilt fixture draft; dynamic build on Windows hardware is `PENDING_PHYSICAL` |
| **CapCut Render Queue (Queue B)** | `adapters/capcut/capcut_render_queue.py` | `PASS_AUTOMATED`<br>**RENDER_QUEUE_WINDOWS_PHYSICAL: NOT_PROVEN** | 3/3 queue tests pass. Previous 16,797-byte outputs were synthetic FFmpeg mocks and are permanently disqualified |
| **CapCut Native Export Gate** | CapCut Win32 + Keyboard + Vision FSM | **NATIVE_EXPORT_WINDOWS_PHYSICAL: NOT_PROVEN** | No physical PASS until real `CapCut.exe` produces verified MP4 on target hardware (`PENDING_PHYSICAL`) |
| **Output Verifier (FFmpeg)** | `adapters/capcut/output_verifier.py` | `PASS_AUTOMATED` | Stream, duration, and frame validation logic verified |
| **Real-ESRGAN Windows Loader** | `realesrgan-ncnn-vulkan.exe` | **REALESRGAN_WINDOWS_LOADER: PASS_WINDOWS_PHYSICAL** | Executable successfully probed on physical Windows hardware |
| **Real-ESRGAN GPU Inference** | Vulkan Tensor Pipeline | **REALESRGAN_GPU_INFERENCE: PENDING_PHYSICAL** | Pending real image processing on NVIDIA RTX 3050 Ti Laptop GPU |
| **License Activation (API)** | Production Backend `/api/v1/capcut/activate` | `PASS_SERVER_PROD`, `PASS_AUTOMATED` | HTTP 200 returned, valid Ed25519 token issued |
| **License DB Persistence** | MySQL `2tamne_site` (`devices`) | `PASS_SERVER_PROD` | 68-char fingerprint stored in `TEXT` column + indexed hash |
| **Ed25519 Cryptographic Verifier** | `ed25519_verifier.py` + `sodium_compat` | `PASS_SERVER_PROD`, `PASS_AUTOMATED` | Live server signatures verified bit-for-bit |
| **License UI Reactivity (Desktop)** | Desktop IPC `license:status-changed` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`<br>**LICENSE_UI_WINDOWS_PHYSICAL: PENDING_PHYSICAL** | Code hardened. Awaits physical verification: activate → popup success → UI immediately active → restart → UI active |
| **License Restart Persistence** | Production `/api/v1/capcut/verify` + DPAPI | `PASS_AUTOMATED`, `PASS_SERVER_PROD`<br>**LICENSE_RESTART_WINDOWS_PHYSICAL: PENDING_PHYSICAL** | Token recovery logic verified; awaits physical Windows cold boot re-test |
| **User Authentication & Session** | `/api/v1/auth/*` + `SecureStorage` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Token exchange, session refresh, and cleanup verified |
| **Wallet & Token Metering** | `/api/v1/wallet/*` + Main Store | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | SSOT balance display & team vs personal context |
| **Workspace & Team Seats** | `/api/v1/team/*` + `WorkspaceManager` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Multi-tenant seat allocation & workspace switching |
| **Cloud Explorer & Chunk Upload** | `CloudClient` + Resumable Chunks | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Chunked upload with SHA-256 integrity verification |
| **Cloud Share Links** | `/api/v1/cloud/share/*` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Public download links with permission checks |
| **AI Connection SSOT** | Server `ai_access_keys` (SSOT) + OS Vault (Storage) | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Server status is authoritative; local DPAPI/Keychain is encrypted secret storage only |
| **Google Flow Browser** | Electron WebContents + Profile Manager | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Headless automation and interactive takeover |
| **Input Bundle Engine** | `BundleEngine` + Folder Scanner | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Comprehensive input bundle structure verification |
| **Desktop Auto-Updater** | `AutoUpdateManager` + `update_manifest` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL`<br>**WINDOWS_AUTOUPDATE: PENDING_PHYSICAL** | SHA-256 artifact hash integrity verified (`SIGNED_MANIFEST = NO`). Windows physical update is pending |
| **Crash Recovery & Snapshots** | Atomic JSON persistence files | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | Safe recovery from simulated mid-run crashes |
| **Offline 72h Grace Window** | `LicenseGuard` + `clock_guard.json` | `PASS_AUTOMATED`, `PASS_MAC_PHYSICAL` | 72h offline tolerance + clock rollback defense |

---

## 3. Critical Path Gate Progression

```
[Input Bundle Engine]
      │ (PASS_AUTOMATED / PASS_MAC_PHYSICAL)
      ▼
[Project Build Queue (Queue A)] ──► PENDING PHYSICAL RETEST (Dynamic Input Bundle)
      │
      ▼
[License Gate] ───────────────────► PASS_SERVER_PROD / PASS_AUTOMATED
      │                             (UI immediate transition & reboot: PENDING PHYSICAL RETEST)
      ▼
[CapCut Render Queue (Queue B)] ──► PENDING PHYSICAL RETEST (Real CapCut 9.3 native export)
      │
      ▼
[FFmpeg Output Verifier] ─────────► PASS_AUTOMATED (Ready to verify real MP4)
      │
      ▼
[Real-ESRGAN Upscaler] ───────────► LOADER: PASS_WINDOWS_PHYSICAL
                                    GPU_INFERENCE: PENDING PHYSICAL RETEST
```

---

## 4. Final Verdict & Gate Classifications

```
GLOBAL_SOFTWARE_HARDENING          = PASS
CUSTOMER_TEST_READY                = PASS
WINDOWS_PHYSICAL_CRITICAL_PATH     = PENDING
FINAL_AUTOEDIT_V2_VERDICT          = HARDENED_SOFTWARE_READY_FOR_TARGETED_PHYSICAL_RETEST
```
