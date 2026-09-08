# 2TOOLNE AUTOEDIT V2 — PRODUCTIZATION REALITY MATRIX
**Authoritative Pre-Implementation Audit**
**Date:** 2026-09-08
**Frozen Subsystems:** A0 (Hierarchical_v1), A1 (Hierarchical_DP_v1), A2 (Frame_Quantization)

---

## 1. Executive Summary

This matrix audits the current source repository reality across all 8 mandatory product capabilities defined in the **Productization Fast-Track Master Directive**. It distinguishes between client-only UI placeholders, backend implementations, and end-to-end wired runtime capabilities.

| # | Product Capability | Current Classification | Status Summary |
|---|---|---|---|
| 1 | **Project Build Queue UX** | `UI_ONLY` / `NOT_WIRED` | Studio "Thêm vào hàng đợi" saved to client array; backend queue manager missing; queue UI displayed render queue instead. |
| 2 | **Studio Workflow** | `PARTIAL` | Direct single-project generation works; inputs and validation exist; lacks preset integration and build-queue snapshotting. |
| 3 | **Render Queue / Native Automation** | `PARTIAL` / `PHYSICAL_PENDING` | `RenderQueueManager` backend fully implemented & tested; UI table renders jobs; physical export requires live macOS/Windows CapCut. |
| 4 | **Project Management** | `PARTIAL` | Draft library view, search, open in CapCut, safe draft folder deletion implemented; lacks sorting, duration display, external refresh. |
| 5 | **Installer / Updater** | `PARTIAL` | PyInstaller spec & electron-builder config exist; updater is version-check only ("Kiểm tra cập nhật"); packaging exclusions need check. |
| 6 | **Account / Wallet / Credit UX** | `PARTIAL` | Login, license activation, wallet balance check wired; requires clean Vietnamese error messages and auto-refresh on consume. |
| 7 | **Preset / Edit Style** | `PARTIAL` | `PresetManager` backend exists; lacks deterministic `NORMAL`, `CALM`, `FAST` presets and Studio dropdown selector. |
| 8 | **Batch Project Creation** | `MISSING` | No sequential draft build worker in backend; no "Tạo tất cả dự án" action wired to build queue. |

---

## 2. Detailed Capability Breakdown

### Capability 1: Project Build Queue UX
- **Classification:** `UI_ONLY` / `NOT_WIRED`
- **Current UI:**
  - In `apps/capcut-v2/desktop/src/renderer/index.html`, Tab 2 (`#view-queue`) contains a table configured for Render Queue (`RenderQueueManager`).
  - In `app.js` (line 949), `DOM.btnAddToQueue` creates an ad-hoc object and pushes to `state.queue`, saving to `electron-store` under `autoedit_queue`.
  - However, `view-queue` never renders `state.queue`; it calls `renderQueueTableFromState(state.renderQueue)` which only inspects Render Queue jobs.
- **Backend:**
  - `MISSING`. No `ProjectBuildQueueManager` exists in `apps/capcut-v2/core/` or `adapters/capcut/`.
  - Only single-project synchronous generation exists via `GENERATE_CAPCUT_PROJECT` IPC handler.
- **IPC / API Wiring:**
  - `NOT_WIRED`. No IPC handlers for `ENQUEUE_BUILD_JOB`, `GET_BUILD_QUEUE_STATE`, `BUILD_PROJECT_JOB`, `BUILD_ALL_PROJECTS`, `CANCEL_BUILD_JOB`, `RETRY_BUILD_JOB`.
- **Persistence:**
  - `PARTIAL`. Only raw client-side `electron-store`, which lacks transactional backend recovery and FSM resumption across sidecar restarts.
- **Tests:**
  - `MISSING`. No tests exist for build queue state transitions.
- **Real-User Availability:**
  - `NOT_AVAILABLE`. Normal users cannot queue draft creation jobs or view build progress cards.
- **Remaining Blocker:**
  - Implement `apps/capcut-v2/core/build_queue_manager.py` with states:
    `QUEUED`, `VALIDATING`, `PINNING_INPUTS`, `UPSCALING`, `SUBTITLE`, `WAITING_SRT_REVIEW`, `TIMELINE`, `BUILDING_DRAFT`, `VERIFYING`, `PROJECT_READY`, `FAILED`, `CANCELLED`.
  - Expose IPC in `bridge.py`, `main/index.js`, and `preload/preload.js`.
  - Split Tab 2 (`#view-queue`) into sub-tabs: `[📋 Hàng Đợi Tạo Dự Án]` and `[🎬 Hàng Đợi Xuất Video]`.
  - Add job cards with Vietnamese status badges, progress bars, and action buttons (`Tạo dự án`, `Hủy`, `Thử lại`, `Mở CapCut`, `+ Render Queue`).

---

### Capability 2: Studio Workflow
- **Classification:** `PARTIAL`
- **Current UI:**
  - Complete form in `#view-studio`: Audio file picker, image files/folders selector, aspect ratio selector (`9:16`, `16:9`, `1:1`), FPS selector, Subtitle mode (AutoSub / Forced Alignment / Existing SRT), Auto Upscale toggle, Ken Burns weights, Project name generator.
- **Backend:**
  - `IMPLEMENTED`. `PipelineReplayHarness`, `TimelineBuilder`, `VisualPipelineAdapter`, `CapCutProjectManager`.
- **IPC / API Wiring:**
  - `IMPLEMENTED`. `GENERATE_CAPCUT_PROJECT`, `VALIDATE_INPUTS`, `GENERATE_SRT_FROM_SCRIPT`.
- **Persistence:**
  - `IMPLEMENTED`. Creates draft directory with `draft_content.json`, `draft_meta_info.json`, and updates `root_meta_info.json`.
- **Tests:**
  - `IMPLEMENTED`. 429 repository tests pass, covering A0, A1, A2, and draft generation.
- **Real-User Availability:**
  - `PARTIAL`. Users can build a project directly with "Tạo dự án ngay" (via modal progress dialog), but clicking "Thêm vào hàng đợi" does not queue it into an active build pipeline.
- **Remaining Blocker:**
  - Connect "Thêm vào hàng đợi" to snapshot the Studio configuration into `ProjectBuildQueueManager`.
  - Connect deterministic preset selector to auto-fill motion parameters.

---

### Capability 3: Render Queue / Native CapCut Render Automation
- **Classification:** `PARTIAL` / `PHYSICAL_VALIDATION_PENDING`
- **Current UI:**
  - `view-queue` has controls for `btnStartQueue`, `btnPauseQueue`, `btnStopAfterCurrent`, `btnClearQueue`, and renders table of `renderQueue` jobs from Python backend.
- **Backend:**
  - `IMPLEMENTED`. `apps/capcut-v2/adapters/capcut/render_queue_manager.py` provides complete FSM (`IDLE`, `QUEUED`, `RUNNING`, `PAUSED`, `STOPPING`, `DONE`, `FAILED`, `CANCELLED`).
  - Native UI automation: `native_exporter.py` with platform guards; `output_verifier.py` validates file size, container validity, and duration.
- **IPC / API Wiring:**
  - `IMPLEMENTED`. `RENDER_NOW`, `ENQUEUE_RENDER`, `GET_RENDER_QUEUE_STATE`, `CONTROL_RENDER_QUEUE`, `GET_RENDER_PROFILE`.
- **Persistence:**
  - `IMPLEMENTED`. Persists queue state to JSON in app data workspace.
- **Tests:**
  - `IMPLEMENTED`. `test_capcut_render_queue.py`, `test_capcut_output_verifier.py`, `test_capcut_render_bridge.py`.
- **Real-User Availability:**
  - `PHYSICAL_VALIDATION_PENDING`. Native export requires live physical CapCut Desktop (v9.4.0 on macOS).
- **Remaining Blocker:**
  - Move render queue table into sub-tab `[🎬 Hàng Đợi Xuất Video]`.
  - Wire `[+ Render Queue]` and `[Render ngay]` directly from `PROJECT_READY` cards in Build Queue and Project Management.
  - Perform live physical export smoke test in CapCut 9.4.0.

---

### Capability 4: Project Management
- **Classification:** `PARTIAL`
- **Current UI:**
  - `#view-projects` grid renders project cards from `state.projects`.
  - Card actions: "Mở CapCut", "Nạp vào Studio", "Render Ngay", "Thêm Hàng Đợi", "Thư mục", "Xóa".
  - Search input filters by name/path. Delete confirmation modal exists with optional draft folder deletion.
- **Backend:**
  - `IMPLEMENTED`. `CapCutProjectManager` creates drafts and manages `root_meta_info.json`. `fs:delete-draft` in `main/index.js` safely deletes only recognized draft directories without touching source media.
- **IPC / API Wiring:**
  - `PARTIAL`. `sidecar:open-capcut` and `fs:delete-draft` are wired; backend draft scan IPC for discovering externally created/modified drafts is missing.
- **Persistence:**
  - `IMPLEMENTED`. CapCut draft directory + `root_meta_info.json` + `electron-store`.
- **Tests:**
  - `PARTIAL`. `tests/test_stage_c_project_management.py`.
- **Real-User Availability:**
  - `PARTIAL`. Users can browse projects, open them in CapCut, reload them into Studio, or safely delete them.
- **Remaining Blocker:**
  - Add sorting options (newest first, name, duration).
  - Calculate and display duration and media counts accurately.
  - Ensure deletion never touches original user assets.

---

### Capability 5: Installer / Updater
- **Classification:** `PARTIAL`
- **Current UI:**
  - Version displayed in header and account view. "Kiểm tra cập nhật" button exists in account view.
- **Backend:**
  - `IMPLEMENTED`. `autoedit-core.spec` PyInstaller spec bundles sidecar. `updater/github_release_client.py` and `version_manager.py` check GitHub releases.
- **IPC / API Wiring:**
  - `IMPLEMENTED`. `updater:check-update`.
- **Persistence:**
  - N/A.
- **Tests:**
  - `PARTIAL`. `tests/test_stage_d_packaging_and_updater.py`, `tests/test_updater.py`.
- **Real-User Availability:**
  - `PARTIAL`. Updater functions honestly as a version check ("Kiểm tra cập nhật"), not a full silent auto-updater.
- **Remaining Blocker:**
  - Ensure release packaging excludes test artifacts, caches, and golden fixtures.
  - Maintain honest user-facing wording: "Kiểm tra cập nhật".

---

### Capability 6: Account / Wallet / Credit UX
- **Classification:** `PARTIAL`
- **Current UI:**
  - `#view-account` has login form, token balance card, license key activation form, device ID, plan badge.
- **Backend:**
  - `IMPLEMENTED`. `core/security/license_guard.py` and `license_manager.py` manage commercial entitlements, device fingerprints, and offline grace periods.
- **IPC / API Wiring:**
  - `IMPLEMENTED`. `auth:login`, `auth:logout`, `wallet:get-balance`, `license:activate`, `license:deactivate`.
- **Persistence:**
  - `IMPLEMENTED`. Encrypted/hashed tokens stored in `electron-store`.
- **Tests:**
  - `IMPLEMENTED`. `tests/test_mandatory_license_gate.py`, `tests/test_stage_e_auth_and_wallet.py`.
- **Real-User Availability:**
  - `PARTIAL`. Users can log in, see balance, activate license keys.
- **Remaining Blocker:**
  - Refresh wallet balance automatically after project creation or upscale operations.
  - Ensure zero cryptographic/HMAC terminology is shown in error dialogs.

---

### Capability 7: Preset / Edit Style
- **Classification:** `PARTIAL`
- **Current UI:**
  - Studio currently exposes individual Ken Burns sliders (Zoom In, Zoom Out, Pan, Tilt) and duration, but lacks a 1-click Preset dropdown for standard styles.
- **Backend:**
  - `IMPLEMENTED`. `apps/capcut-v2/core/preset_manager.py` with `RulePreset` dataclass, JSON persistence, and built-ins.
- **IPC / API Wiring:**
  - `IMPLEMENTED`. `GET_PRESETS`, `SAVE_CUSTOM_PRESET`, `DELETE_CUSTOM_PRESET`.
- **Persistence:**
  - `IMPLEMENTED`. JSON files in `~/.2toolne/autoedit-capcut/presets`.
- **Tests:**
  - `PARTIAL`. `tests/test_capcut_v2_core.py`.
- **Real-User Availability:**
  - `PARTIAL`. User can tweak motion weights manually, but deterministic presets `NORMAL`, `CALM`, `FAST` are not exposed.
- **Remaining Blocker:**
  - Add explicit built-in presets in `preset_manager.py`:
    - `NORMAL` (Tiêu chuẩn): Pacing 4.0-6.5s, balanced zoom/pan (25/25/25/25).
    - `CALM` (Êm đềm / Trầm lặng): Pacing 5.5-8.5s, zoom dominant (40/40/10/10), gentle velocity.
    - `FAST` (Nhanh / Sôi động): Pacing 2.5-4.5s, dynamic pan/tilt (15/15/35/35), snappy transitions within A1 3.5%/s limit.
  - Connect Studio preset dropdown selector to populate controls deterministically.

---

### Capability 8: Batch Project Creation
- **Classification:** `MISSING`
- **Current UI:**
  - `MISSING`. No "Tạo tất cả dự án" button in queue interface for draft creation.
- **Backend:**
  - `MISSING`. No sequential worker loop in Python backend to build multiple drafts from queued jobs without draft index collision.
- **IPC / API Wiring:**
  - `MISSING`. No `BUILD_ALL_PROJECTS` handler.
- **Persistence:**
  - `MISSING`.
- **Tests:**
  - `MISSING`.
- **Real-User Availability:**
  - `MISSING`.
- **Remaining Blocker:**
  - Implement sequential batch execution in `ProjectBuildQueueManager`.
  - Add "Tạo tất cả dự án" button in Build Queue UI.
  - Implement sequential progress reporting.

---

## 3. Wave Execution Roadmap

Following the directive rules, work proceeds in 4 focused waves:

1. **Wave 1 (`feat/product-wave1-studio-build-queue`):**
   - Implement `ProjectBuildQueueManager` in `core/build_queue_manager.py`.
   - Wire IPC (`ENQUEUE_BUILD_JOB`, `GET_BUILD_QUEUE_STATE`, `BUILD_PROJECT_JOB`, `BUILD_ALL_PROJECTS`, `CANCEL_BUILD_JOB`, `RETRY_BUILD_JOB`).
   - Separate Tab 2 into sub-tabs `[📋 Hàng Đợi Tạo Dự Án]` and `[🎬 Hàng Đợi Xuất Video]`.
   - Freeze Studio configuration on "Thêm vào hàng đợi"; implement "Tạo dự án" & "Tạo tất cả dự án".
   - Acceptance test suite (`tests/test_build_queue_manager.py`).
2. **Wave 2 (`feat/product-wave2-projects-presets`):**
   - Add deterministic `NORMAL`, `CALM`, `FAST` presets with Vietnamese UI labels.
   - Wire Studio preset selector.
   - Enhance Project Management library (sorting, duration, safe refresh).
3. **Wave 3 (`feat/product-wave3-render`):**
   - Connect `[+ Render Queue]` and `[Render ngay]` from `PROJECT_READY` cards to `RenderQueueManager`.
   - Verify native export pipeline with `OutputVerifier`.
   - Physical smoke test on macOS CapCut 9.4.0.
4. **Wave 4 (`feat/product-wave4-account-release`):**
   - Polish Account/Wallet/Credit UX (auto balance refresh, human-readable error messages).
   - Packaging validation (exclude test artifacts/caches).
   - End-to-end user acceptance flow.
