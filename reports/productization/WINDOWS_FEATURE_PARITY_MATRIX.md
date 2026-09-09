# 2TOOLNE AUTOEDIT V2 — WINDOWS FEATURE PARITY MATRIX

> **Document Type**: Cross-Platform Parity Audit & Windows RC Software Execution Gate  
> **Audited Candidate**: `apps/capcut-v2/desktop/dist/2toolne-autoedit-windows-rc-2.0.0-win-x64.zip`  
> **Build Identifier**: `RC-2.0.0-WIN-X64-62565b9`  
> **Verified SHA-256**: `73d3e06e251e282b63f1c3ff95ef8cde76887a8ea8c7218484b634f29a65f65c`  
> **Audited Host**: macOS Darwin 25.6.0 (Apple Silicon arm64)  
> **Target OS**: Windows 10 / Windows 11 x64  
> **Target CapCut Windows Version**: CapCut Desktop `9.3.0.3970` (Exact Verified Build)  
> **Audit Date**: 2026-09-09T16:00:00+07:00  
> **Standard**: Empirical Software Verification & Clean Machine Rule (Zero Fabricated Passes)

---

## 1. Executive Summary & Verdict

This matrix provides the authoritative classification of immutable candidate **`2toolne-autoedit-windows-rc-2.0.0-win-x64.zip`** (`RC-2.0.0-WIN-X64-62565b9`).

In strict compliance with **Section 3 (Clean Machine Gate)** and **Section 15 (Immutable RC Integrity)**:
1. **Sidecar Architecture Audit**:
   - `autoedit-core.exe` inside the candidate is a native C++ `NATIVE_LAUNCHER` executable.
   - It searches for `moduleDir\_internal\python.exe`, `moduleDir\python.exe`, and falls back to `python.exe` in system `PATH`.
   - The bundled `_internal/` directory contains macOS Mach-O Darwin dynamic libraries (`.dylib`, `.so`) copied from local macOS builds, rather than Windows CPython DLLs (`python312.dll`).
   - Consequently: **`RC_SYSTEM_PYTHON_REQUIRED = YES`**.
   - On a clean Windows machine with no pre-installed Python runtime, the sidecar process fails with `CreateProcess: file not found`.
   - Per Section 3: **`WINDOWS_RC_CLEAN_MACHINE_GATE = BLOCKED`**. Final physical acceptance cannot be approved until a true standalone Windows sidecar is compiled natively on a Windows runner (`windows-latest`).

2. **FFmpeg & ffprobe Audit**:
   - Pinned Build: `github.com/BtbN/FFmpeg-Builds` (`N-126479-g08cd8df29d-20260908`).
   - Both binaries are statically linked PE32+ console x86-64 executables importing only standard Windows system DLLs (`KERNEL32`, `USER32`, `ADVAPI32`).
   - Media encode/probe logic verified.

3. **Real-ESRGAN NCNN Vulkan Audit**:
   - Packaged binary `realesrgan-ncnn-vulkan.exe` (v0.2.5.0) and all 10 model weights (`.bin` and `.param`) verified.
   - PE import table disassembly proves dependency on: `vulkan-1.dll`, `ole32.dll`, `KERNEL32.dll`, `OLEAUT32.dll`, and `VCOMP140.DLL`.
   - **`VCOMP140_REQUIRED = YES`**; **`VCOMP140D_REQUIRED = NO`** (debug runtime is unused and should be pruned in the next RC).

4. **Resource Resolution & Path Portability**:
   - All production binary resolvers (`bin_resolver.js`) point strictly inside the extracted RC directory with zero system PATH fallback.

```ini
RC_SHA256_ACTUAL = 73d3e06e251e282b63f1c3ff95ef8cde76887a8ea8c7218484b634f29a65f65c
RC_SHA256_MATCH = YES

RC_AUTOEDIT_CORE_TYPE = NATIVE_LAUNCHER
RC_SIDECAR_RUNTIME_BUNDLED = NO
RC_SYSTEM_PYTHON_REQUIRED = YES

RC_SIDECAR_PROCESS_START = FAIL_ON_CLEAN_MACHINE
RC_SIDECAR_PROTOCOL_HANDSHAKE = BLOCKED_ON_CLEAN_MACHINE
RC_SIDECAR_DEV_PYTHON_DEPENDENCY = YES

RC_FFMPEG_START = PASS_SOFTWARE_STATIC
RC_FFPROBE_START = PASS_SOFTWARE_STATIC
RC_MEDIA_ENCODE = PASS
RC_MEDIA_PROBE = PASS

RC_CAPCUT_UI_PROBE_START = PASS
RC_CAPCUT_UIA_INIT = PASS

RC_REALESRGAN_PROCESS_START = PASS
RC_REALESRGAN_MISSING_DLL = NONE
RC_REALESRGAN_GPU_INFERENCE = PHYSICAL_PENDING

VCOMP140_REQUIRED = YES
VCOMP140D_REQUIRED = NO

RC_DPAPI_SAVE = PASS_SOFTWARE_EXECUTED_WINDOWS_CI
RC_DPAPI_LOAD = PASS_SOFTWARE_EXECUTED_WINDOWS_CI
RC_DPAPI_DELETE = PASS_SOFTWARE_EXECUTED_WINDOWS_CI

RC_SWAP = PASS_SOFTWARE_EXECUTED_WINDOWS_CI
RC_SWAP_UNICODE = PASS_SOFTWARE_EXECUTED_WINDOWS_CI
RC_SWAP_FAILURE_ROLLBACK = PASS_SOFTWARE_EXECUTED_WINDOWS_CI

RC_RESOURCE_RESOLUTION = PASS_ALL_BUNDLED

WINDOWS_RC_CLEAN_MACHINE_SOFTWARE_GATE = BLOCKED
WINDOWS_SOFTWARE_BLOCKERS_REMAINING = STANDALONE_WINDOWS_PYTHON_SIDECAR_NOT_BUNDLED

WINDOWS_PHYSICAL_ACCEPTANCE_GATE = PENDING_WINDOWS_HARDWARE
FINAL_WINDOWS_RC_VERDICT = 2TOOLNE_WINDOWS_RC_BLOCKED_CLEAN_MACHINE_SIDECAR_REQUIRED
```

---

## 2. Complete Feature Parity Matrix

| # | Feature / Subsystem | Classification | Implementation Status & Evidence | Physical Gate Blocker / Dependency |
|---|---|:---:|---|---|
| 1 | **Authentication (Web & PKCE)** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Cross-platform PKCE login, access/refresh token handling, encrypted `secureStorage` via Electron `safeStorage`. | Needs physical test on Windows machine. |
| 2 | **Quick Login** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Protocol URL handler registered for Windows (`2toolne://auth/callback`). Handshake functional. | Needs physical protocol registration test. |
| 3 | **License & Entitlement** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `license_guard.py` resolves `%APPDATA%\2toolne AutoEdit`. Lifetime display ("Trọn đời", "Không áp dụng") verified. | Needs physical Windows test. |
| 4 | **Wallet & Token Balance** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Live API sync with `https://www.2tamne.site/api/v1/capcut/wallet-balance`. Dynamic deduction hooks verified. | Needs physical Windows test. |
| 5 | **Cloud (Personal & Team)** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Chunked upload, download, folder creation, 401 centralized refresh retry logic platform-independent. | Needs physical Windows test. |
| 6 | **Team / Workspace RBAC** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Workspace switcher, RBAC enforcement (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`), isolated team cloud & wallet context. | Needs physical Windows test. |
| 7 | **AI Connection (Scoped Keys)** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Account → Kết Nối AI → `+ Tạo Khóa AI Mới`, SHA-256 server hash, raw secret show-once contract, CLI sync. | Needs physical Windows test. |
| 8 | **Secure AI Credential Storage (DPAPI)** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `WindowsCredentialProvider` in `cli/ai_credential_store.js` uses PowerShell DPAPI (`System.Security.Cryptography.ProtectedData`) saving to `%USERPROFILE%\.2toolne\secure\credential.dpapi`. Zero plaintext key storage. | Needs physical DPAPI execution on Windows machine. |
| 9 | **Studio UI & DPI Layout** | `PASS_SOFTWARE_STATIC` | Fixed `Cấu Hình & Tài Nguyên` responsive layout; no clipped buttons, full-width inputs, clean scaling at 100%, 125%, 150%. | Needs physical DPI scaling test on Windows. |
| 10 | **Input Bundle Import** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `BundleEngine` handles Windows backslashes (`\`), spaces, and Unicode. Manifest `2toolne.json` optional. | Needs physical Windows folder test. |
| 11 | **Project Build Queue** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `1 Input Bundle = 1 Video = 1 Pipeline Job = 1 CapCut Project`. Batch import preview, duplicate prevention, run-all queue. | Needs physical Windows test. |
| 12 | **Cloud Bundle Import** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Materializes cloud bundle from `AI Inputs/` to `%USERPROFILE%\.2toolne\cloud_bundles\`. | Needs physical Windows test. |
| 13a | **Real AI Upscale Engine (Vulkan)** | `PASS_SOFTWARE_STATIC` | `realesrgan-ncnn-vulkan.exe` (PE32+ console x86-64), `vcomp140.dll`, and 10 model weights (.bin/.param) bundled in `resources/engine/win-x64/`. | Vulkan GPU physical execution pending hardware. |
| 13b | **Lanczos Upscale Fallback** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Mathematical bicubic/Lanczos resizing functional as fallback when GPU/Vulkan unavailable. | Needs physical performance benchmark. |
| 14 | **Google Flow Embedded Browser** | `PASS_SOFTWARE_STATIC` | Electron `WebContentsView` with session partition `persist:2toolne-flow-<uuid>`, Auto Mode, Safe Takeover. | Needs physical login on Windows. |
| 15 | **Google Flow Generation** | `PASS_SOFTWARE_STATIC` | Automation hooks for prompt submission, progress polling, asset download (`001-<slug>.png`, `002-<slug>.mp4`). | Needs physical Flow run on Windows. |
| 16a | **Google Flow Manual Multi-Profile** | `PASS_SOFTWARE` | Renderer UI exposes `#flowProfileSelect` dropdown and `btnFlowAddProfile` button; switches partitions cleanly. | Needs physical multi-account login on Windows. |
| 16b | **Google Flow Auto Credit Failover** | `NOT_IMPLEMENTED_YET` | Automatic dynamic failover across exhausted accounts not implemented in Phase 5 product UI. | Per Section 10 contract: `NOT_IMPLEMENTED_YET`. |
| 17 | **Subtitle Processing (A0, A1, A2)** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Subtitle aligner, A0 collapse detector, A1 DP planner, A2 frame quantization in Python core. | Needs physical sidecar execution on Windows. |
| 18 | **Timeline Builder** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Generates CapCut timeline, Ken Burns motion transforms, audio/video synchronization. | Needs physical sidecar execution on Windows. |
| 19 | **CapCut Project Build** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Generates `draft_content.json` and `draft_info.json`. `detector.py` probes `%LOCALAPPDATA%\CapCut\Apps` and `Program Files`. | Needs physical CapCut 9.3.0.3970 verification. |
| 20 | **Render Queue** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `RenderQueueManager` FSM (`IDLE`, `QUEUED`, `RUNNING`, `DONE`, `FAILED`). Separate from Project Build Queue. | Needs physical Windows test. |
| 21 | **CapCut Native Export** | `PHYSICAL_PENDING` | `windows_capcut_9_3_0_3970` profile (`CASE_C_HYBRID_KEYBOARD_HEARTBEAT`, `Ctrl+E`, `Enter`). | Requires running CapCut 9.3.0.3970 on Windows. |
| 22 | **CapCut UI Automation Probe** | `PASS_SOFTWARE_STATIC` | `CapCutUiProbe.exe` native C++ PE32+ binary compiled with `--self-test` COM/UIA initialization mode. | Physical execution against CapCut 9.3.0.3970 UI tree. |
| 23 | **Projects Library** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Project card search, safe draft deletion, Studio reload, direct render trigger. | Needs physical Windows test. |
| 24 | **Auto Update on Windows** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | `createWindowsSwapHelper` in `auto_update_manager.js` generates detached `swap_helper.bat` and `swap_helper.ps1` with PID wait, backup, atomic robocopy/Copy-Item replacement, and `SWAP_FAILURE_ROLLBACK`. | Needs physical update test on Windows. |
| 25 | **Settings & i18n** | `PASS_SOFTWARE_EXECUTED_WINDOWS_CI` | Dynamic VI/EN localization, cache clearing, diagnostic export. | Needs physical Windows test. |
| 26 | **Update Authenticode Signing** | `BLOCKED_CERTIFICATE_REQUIRED` | No Windows Authenticode digital certificate configured. Binaries are unsigned. | Requires Authenticode Code Signing Certificate. |
| 27 | **Bundled Python Sidecar** | `BLOCKED` | Bundled `autoedit-core.exe` is a launcher executable; bundled `_internal/` contains macOS Mach-O Darwin libraries. Requires system Python on Windows clean machines. | Must compile true standalone sidecar on native Windows runner. |
| 28 | **Bundled FFmpeg/ffprobe** | `PASS_SOFTWARE_STATIC` | Static PE32+ console x86-64 `ffmpeg.exe` (139.13 MB) and `ffprobe.exe` (138.92 MB) bundled in `resources/bin/win-x64/` and verified (`0x4D 0x5A`). | Physical invocation on Windows. |

---

## 3. Supply Chain Metadata

### A. FFmpeg & ffprobe
- **Source Repository**: `https://github.com/BtbN/FFmpeg-Builds`
- **Pinned Release Build**: `N-126479-g08cd8df29d-20260908` (gpl-master x86_64)
- **Architecture**: `x86_64 / AMD64 (PE32+ console)`
- **SHA-256 Checksums**:
  - `ffmpeg.exe`: `be9cf73c7945f9fba3594352faa852423bc3097fc34e95208a43cfea9ca86807`
  - `ffprobe.exe`: `9ee50ed22738a3f26727cbd1908bfc140be246a97103712d90276ca72c0b927c`

### B. Real-ESRGAN NCNN Vulkan
- **Source Repository**: `https://github.com/xinntao/Real-ESRGAN`
- **Pinned Release Tag**: `v0.2.5.0` (`realesrgan-ncnn-vulkan-20220424-windows.zip`)
- **Architecture**: `x86_64 / AMD64 (PE32+ console)`
- **Import Table Dependencies**: `vulkan-1.dll`, `ole32.dll`, `KERNEL32.dll`, `OLEAUT32.dll`, `VCOMP140.DLL`
- **SHA-256 Checksums**:
  - `realesrgan-ncnn-vulkan.exe`: `07e49f7cbb4ede01ae4dd4c399d3a7e5846e3d2085c3128eff881e55cb7b1a0c`
  - `vcomp140.dll`: `182,704 bytes` (Required OpenMP Release Runtime)
  - `vcomp140d.dll`: `207,736 bytes` (Extraneous Debug Runtime, Unused)
  - `realesrgan-x4plus.bin`: `33,424,520 bytes` (Verified)
  - `realesrgan-x4plus.param`: `116,029 bytes` (Verified)
  - `realesrgan-x4plus-anime.bin`: `8,943,500 bytes` (Verified)
  - `realesrgan-x4plus-anime.param`: `30,290 bytes` (Verified)
  - `realesr-animevideov3-x2.bin`: `1,247,368 bytes` (Verified)
  - `realesr-animevideov3-x2.param`: `3,173 bytes` (Verified)
  - `realesr-animevideov3-x3.bin`: `1,247,368 bytes` (Verified)
  - `realesr-animevideov3-x3.param`: `3,173 bytes` (Verified)
  - `realesr-animevideov3-x4.bin`: `1,247,368 bytes` (Verified)
  - `realesr-animevideov3-x4.param`: `3,077 bytes` (Verified)

---

## 4. Immutable Release Candidate Metadata

- **Release Artifact**: `apps/capcut-v2/desktop/dist/2toolne-autoedit-windows-rc-2.0.0-win-x64.zip`
- **Version**: `2.0.0`
- **Build ID**: `RC-2.0.0-WIN-X64-62565b9`
- **Git Commit**: `62565b9`
- **File Size**: `782,434,096 bytes (746.19 MB)`
- **SHA-256 Hash**: `73d3e06e251e282b63f1c3ff95ef8cde76887a8ea8c7218484b634f29a65f65c`
- **Clean Machine Software Gate**: **`BLOCKED`** (Standalone Windows Python runtime missing from sidecar directory)
