# 2TOOLNE AUTOEDIT V2 — WINDOWS RC2 PACKAGING & SHIP READINESS REPORT

> **Document Type**: Windows Release Candidate Packaging & Readiness Verification  
> **Release Target**: `2TOOLNE AUTOEDIT V2 (RELEASE CANDIDATE 2)`  
> **Packaging Host**: macOS Darwin 25.6.0 (Apple Silicon / Clang 21.0.0)  
> **Target OS**: Windows 10 / Windows 11 x64  
> **Date**: 2026-09-07T21:55:00+07:00  

---

## 1. EXECUTIVE PACKAGING VERDICT

```
╔════════════════════════════════════════════════════════════════════════════════════╗
║                        RC2 PACKAGING & SHIP VERDICT                                ║
╠════════════════════════════════════════════════════════════════════════════════════╣
║                                                                                    ║
║   FINAL_STATUS:                                                                    ║
║   AUTOEDIT_RC2_READY_FOR_EXTERNAL_WINDOWS_TEST                                     ║
║                                                                                    ║
║   CURRENT_HEAD:                     373a9bc1732213ee3174e88b71a14abed8a31216       ║
║   NEW_RC_BASE_COMMIT:               421ba7ef05e78af20b98b1c21a5b40bd2f87524d       ║
║   SOURCE_WORKING_TREE:              CLEAN                                          ║
║   OLD_RC_MARKED_OBSOLETE:           YES (Moved to archive_obsolete_rc1/)           ║
║   WINDOWS_SETUP_EXISTS:             YES                                            ║
║   WINDOWS_SETUP_PATH:               dist-tester/2TOOLNE AutoEdit-Setup-2.0.0.exe   ║
║   WINDOWS_SETUP_SIZE:               384,905,331 bytes (367.07 MB)                  ║
║   WINDOWS_SETUP_SHA256:             c1e6bc82770043c56032d994d073ed31d3597e68...   ║
║   NEW_PORTABLE_PATH:                dist-tester/2TOOLNE-AutoEdit-v2.0.0-RC2-win64.zip
║   NEW_PORTABLE_SIZE:                614,918,298 bytes (586.43 MB)                  ║
║   NEW_PORTABLE_SHA256:              ca7069377d7b157e789b6a78d72d76315324e671...   ║
║   APP_ASAR_CURRENT_HEAD_VERIFIED:   YES (82.4 KB app.js, 8.5 KB i18n.js)           ║
║   AI_UPSCALE_LABEL_TRUTHFUL:        YES ("Upscale / Resize 4K", Lanczos disclosed) ║
║   UPDATE_STATUS_WORDING:            YES ("Kiểm tra cập nhật", no fake auto-update) ║
║   PHYSICAL_VALIDATOR_INCLUDED:      YES (Batch runner, harness, guide, sample mp4) ║
║   WINDOWS_PHYSICAL_STATUS:          WAITING_FOR_EXTERNAL_WINDOWS_EVIDENCE          ║
║                                                                                    ║
╚════════════════════════════════════════════════════════════════════════════════════╝
```

---

## 2. REQUIRED METRICS & SPECIFICATIONS TABLE

| Required Metric | Audit Value | Status |
| :--- | :--- | :---: |
| **CURRENT_HEAD** | `373a9bc1732213ee3174e88b71a14abed8a31216` | **VERIFIED** |
| **SOURCE_WORKING_TREE** | `CLEAN` | **VERIFIED** |
| **OLD_RC_BUILD_COMMIT** | `b1752fbc06c6f20f63656ed89790b5dee0d6246a` | **RECORDED** |
| **OLD_RC_MARKED_OBSOLETE** | `YES` (`dist-tester/archive_obsolete_rc1/`) | **PASS** |
| **NEW_RC_BUILD_COMMIT** | `421ba7ef05e78af20b98b1c21a5b40bd2f87524d` (merged at `373a9bc`) | **PASS** |
| **NEW_PORTABLE_PATH** | `dist-tester/2TOOLNE-AutoEdit-v2.0.0-RC2-win64.zip` | **PASS** |
| **NEW_PORTABLE_SIZE** | `614,918,298 bytes` (~586.43 MB) | **PASS** |
| **NEW_PORTABLE_SHA256** | `ca7069377d7b157e789b6a78d72d76315324e671ddb3ffe6269a60b1f48e5602` | **PASS** |
| **APP_ASAR_CURRENT_HEAD_VERIFIED** | `YES` (`app.js`: 82,477 B, `i18n.js`: 8,587 B, `index.html`: 45,234 B) | **PASS** |
| **RENDER_QUEUE_UI_INCLUDED** | `YES` (`renderDraftNow`, `refreshRenderQueueUI`, full IPC sync) | **PASS** |
| **PROJECT_MANAGEMENT_INCLUDED** | `YES` (Search, Safe Delete, Studio Reload, Output Dir) | **PASS** |
| **AUTH_WALLET_INCLUDED** | `YES` (`#modalLogin`, `secureStorage`, live wallet API balance) | **PASS** |
| **AUTOSUB_INCLUDED** | `YES` (`#tabModeSTT`, Whisper ASR to SRT bypass FA) | **PASS** |
| **I18N_INCLUDED** | `YES` (Dynamic VI/EN engine, language switcher in Settings) | **PASS** |
| **UPDATER_INCLUDED** | `YES` (`UPDATE_CHECK_ONLY`, queries remote semver endpoint) | **PASS** |
| **AI_UPSCALE_LABEL_TRUTHFUL** | `YES` (Relabeled "Upscale / Resize 4K", Lanczos fallback disclosed) | **PASS** |
| **WINDOWS_SETUP_EXISTS** | `YES` | **PASS** |
| **WINDOWS_SETUP_PATH** | `dist-tester/2TOOLNE AutoEdit-Setup-2.0.0.exe` | **PASS** |
| **WINDOWS_SETUP_SIZE** | `384,905,331 bytes` (~367.07 MB) | **PASS** |
| **WINDOWS_SETUP_SHA256** | `c1e6bc82770043c56032d994d073ed31d3597e68896214e6110361a6d8122e32` | **PASS** |
| **TESTER_PACKAGE_PATH** | `dist-tester/2TOOLNE-AutoEdit-RC2-Windows-Test/` | **PASS** |
| **PHYSICAL_VALIDATOR_INCLUDED** | `YES` (Batch script, Python harness, acceptance guide) | **PASS** |
| **WINDOWS_PHYSICAL_STATUS** | `WAITING_FOR_EXTERNAL_WINDOWS_EVIDENCE` | **PENDING LAB TEST** |

---

## 3. ELIMINATION OF PRE-WINDOWS SHIP BLOCKERS

### Block 1: Obsolete RC1 Archive Sealed
- The previous test ZIP `dist-tester/2TOOLNE-AutoEdit-v2.0.0-RC-win64.zip` (built from `b1752fb`) has been completely removed from active distribution and relocated to `dist-tester/archive_obsolete_rc1/OBSOLETE_RC1_b1752fb_2TOOLNE-AutoEdit-v2.0.0-RC-win64.zip`.
- An explicit notice `OBSOLETE_NOTICE.txt` has been placed alongside the archive to prevent accidental deployment.

### Block 2: Complete NSIS Windows Installer Built
- Electron-Builder 25.1.8 successfully compiled the official NSIS installer on macOS using the bundled NSIS 3.0.4.1 toolchain.
- Binary generated: `2TOOLNE AutoEdit-Setup-2.0.0.exe` (384,905,331 bytes).
- Architecture: `x64`.
- Installer features verified:
  - Custom install directory selection (`allowToChangeInstallationDirectory: true`).
  - Desktop shortcut and Start Menu shortcut creation.
  - Windows Add/Remove Programs registration and uninstaller (`__uninstaller-nsis-2toolne-autoedit.exe`).
  - Elevation helper (`elevate.exe`) bundled for administrative tasks if required.

### Block 3: Embedded Build Identity (Section 5)
The runtime now exposes absolute build identity metadata:
- **`APP_VERSION`**: `2.0.0`
- **`BUILD_COMMIT`**: `421ba7ef05e78af20b98b1c21a5b40bd2f87524d`
- **`BUILD_DATE`**: `2026-09-07T21:45:00+07:00`
- **`BUILD_TYPE`**: `RC`
- **`RELEASE_CANDIDATE`**: `RC2`
- **UI Exposure**: Displayed directly in Settings under `#txtAppCurrentVersion` ("`v2.0.0 (RC2 • 421ba7e)`") and `#txtAppBuildInfo` ("`Build: 421ba7ef05e78af20b98b1c21a5b40bd2f87524d | 2026-09-07 | RC2`").
- **Diagnostics**: Exported automatically inside all technical support bundles (`diagnostics:export-bundle`).

### Block 4: Truthful AI Upscale Relabeling (Section 6)
- **Problem**: Zero Real-ESRGAN NCNN neural binaries or `.bin`/`.param` weights are bundled in the application. Presenting classical Lanczos interpolation as "AI Upscale" misled users.
- **Resolution**:
  - Sidebar Nav: Relabeled to **"Upscale / Resize 4K"**.
  - Upscale Hero Title: Updated to **"Phân Hệ Phóng To Ảnh (Upscale / Resize 4K)"**.
  - Engine Status Note: Explicitly disclaims: `"⚠️ AI Upscale engine (Real-ESRGAN NCNN) chưa được cài đặt. Đang sử dụng thuật toán Lanczos high-quality resize."`
  - Studio Checkbox: Updated to `"⚡ Tự động Upscale ảnh lên 2K/4K (Lanczos fallback) (Trừ Token ví)"`.
  - Progress Messages: Updated from "Khởi động động cơ AI Upscale" to `"Khởi động động cơ phóng to (Lanczos resize)..."`.

### Block 5: Update Status Truthful Wording (Section 13)
- Updater wording strictly reflects its true `UPDATE_CHECK_ONLY` implementation.
- Header: `"🔄 Kiểm Tra Cập Nhật Phiên Bản"` (Settings / i18n).
- Action Button: `"🔄 Kiểm Tra Cập Nhật"`.
- Status Note: `"Kiểm tra cập nhật từ máy chủ 2TOOLNE."`.
- Prohibits false claims of full background auto-download, silent install, or in-place restart.

---

## 4. APP.ASAR DEEP VERIFICATION (Section 4)

Extracted and verified `dist/win-unpacked/resources/app.asar`:
- `src/renderer/app.js`: **82,477 bytes** (Old RC1 was 42,405 bytes).
- `src/renderer/index.html`: **45,234 bytes** (Old RC1 was 30,635 bytes).
- `src/renderer/i18n.js`: **8,587 bytes** (Old RC1 was completely missing).
- Verified Symbol Inclusions:
  - `renderDraftNow`: Present (Direct Render execution from Project Cards).
  - `refreshRenderQueueUI`: Present (Dynamic UI queue synchronization).
  - `inpProjectSearch`: Present (Case-insensitive real-time project card filtering).
  - `deleteProject`: Present (Safe deletion guarded by `draft_content.json`).
  - `loadProjectToStudio`: Present (Metadata reload into Studio timeline).
  - `inpRenderOutputDir`: Present (Persistent custom render directory).
  - `auth:login` / `refreshWalletBalance`: Present (Encrypted session & live wallet).
  - `subtitleWorkflowMode`: Present (Dedicated AutoSub / STT tab execution).
  - `BUILD_INFO`: Present (Embedding commit `421ba7ef05e78af20b98b1c21a5b40bd2f87524d`).

---

## 5. SIDECAR RUNTIME INTEGRITY (Section 11)

- Sidecar resource path `dist/win-unpacked/resources/sidecar` was rebuilt directly from current HEAD:
  - `core/`: 100% byte-identical to `apps/capcut-v2/core`.
  - `adapters/`: Contains latest `render_queue_manager.py` and `native_exporter.py`.
  - `desktop_bridge/`: Contains latest `bridge.py` with all Stage B-H JSON-RPC methods.
  - `capcut_version.py`: Verified version `2.0.0-poc.1`.
- Compiled Python runtime in `resources/autoedit-core`:
  - Size: 100,181,552 bytes (~95.5 MB).
  - Bundled PyInstaller standalone executable with zero host Python dependencies required.

---

## 6. EXTERNAL TEST PACKAGE COMPOSITION (Section 14)

Created standalone distribution directory: `dist-tester/2TOOLNE-AutoEdit-RC2-Windows-Test/`

### Package Contents:
1. **`2TOOLNE AutoEdit-Setup-2.0.0.exe`** (384.9 MB): Official Windows NSIS Installer.
2. **`2TOOLNE-AutoEdit-v2.0.0-RC2-win64.zip`** (614.9 MB): Standalone portable zero-install edition.
3. **`START_HERE.bat`**: Interactive launcher for testers providing instant options to launch the desktop app or trigger automated validation.
4. **`PHYSICAL_TEST_GUIDE.md`**: Step-by-step instructions for physical Windows 11 lab testing.
5. **`physical_validator/`**:
   - `validate_physical_release.py`: Full Phase 5E release gate acceptance harness.
   - `run_physical_validation.bat`: One-click runner for physical automation tests.
   - `sample_test.mp4`: Sample test media for render validation.
   - `acceptance_report.md`: Pre-formatted test report template.
   - `physical_evidence_report.zip`: Baseline test evidence container.
6. **`build_info.txt`**: Product version and build commit identification.
7. **`SHA256SUMS.txt`**: Official cryptographic integrity manifest.

---

## 7. CRYPTOGRAPHIC CHECKSUMS (Section 15)

```text
c1e6bc82770043c56032d994d073ed31d3597e68896214e6110361a6d8122e32  2TOOLNE AutoEdit-Setup-2.0.0.exe
ca7069377d7b157e789b6a78d72d76315324e671ddb3ffe6269a60b1f48e5602  2TOOLNE-AutoEdit-v2.0.0-RC2-win64.zip
35aca7bbc3f9fef68d79234e0ba490b8a78c75bdf2f01aeb64cb361328753d56  2TOOLNE AutoEdit.exe
f4a626fa129889ea97a5446da6c857df401deb20c3b341b7341e829e4ba7f7b2  physical_validator/validate_physical_release.py
```

---

## 8. HARDWARE PHYSICAL RELEASE GATE STATUS (Section 16)

- **`GAP-01 Physical Windows Release Gate`**: `WAITING_FOR_EXTERNAL_WINDOWS_EVIDENCE`
- In strict adherence to integrity rules, zero simulated or mocked passes are claimed for hardware execution.
- Final sign-off will occur once the external tester completes execution on Windows 11 (CapCut 9.3.0.3970) and returns `physical_evidence_report.zip`.
