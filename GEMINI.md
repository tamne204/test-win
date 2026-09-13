# 2TOOLNE — Architectural Invariants & Engineering Truths

This document is the **Single Source of Architectural Truth** for the 2TOOLNE codebase. Every AI agent and developer working in this repository MUST strictly abide by these invariants.

---

## 1. Core Architectural Invariant

```text
1 Input Bundle = 1 Video = 1 PipelineJob = 1 CapCut Project
```

- Each video generation request is encapsulated into a single **PipelineJob** driven by one **Input Bundle**.
- The pipeline produces exactly one **CapCut Project**.

---

## 2. Queue Hierarchy & Orchestration (Strict Separation)

- **`PipelineQueueV2` — Sole Product-Level Processing Queue**:
  - `PipelineQueueV2` is the **ONLY user-facing and product-level** queue in the 2TOOLNE application.
  - Manages AI video pipeline generation strictly sequentially (FIFO).
  - There is **NO user-facing Render Queue** (the old "Hàng Đợi Xuất Video" UI is permanently eliminated).
  - Normal pipeline execution terminates automatically at **`PROJECT_READY`** upon successful CapCut project draft build and verification.
  - **Zero Auto Video Export**: 2TOOLNE does NOT automatically export final MP4 videos. Final video rendering/export is managed directly inside CapCut or upon explicit manual user command.
  - **DO NOT** create a "Queue C", a "Desktop TTS Batch Queue", or any parallel video orchestrators.
- **Internal / Legacy `RenderQueue` Status**:
  - If internal/legacy `RenderQueue` code remains in the repository, it functions strictly as an internal timeline/media utility or optional manual exporter; it is **NOT** a video orchestrator and is **NOT** part of the normal product flow.
  - Do NOT delete existing internal `RenderQueue` backend code without a comprehensive usage audit.
- **Remote Sub-Jobs: Central TTS Backend (`tts_jobs`)**:
  - Managed by the remote PHP backend (`POST /api/v1/tts/jobs`).
  - It is an asynchronous sub-task within a single `PipelineJob`, **NOT** a desktop video queue.

---

## 3. Google Flow Integration Contract

- **Live Runtime**: The current live Google Flow UI is built with **Angular + ProseMirror + Chrome DevTools Protocol (CDP)**.
- **No Obsolete Assumptions**: Do **NOT** use Slate / React Fiber assumptions from legacy browser extension audits (e.g. TobyFlow). They are invalid on live Flow.
- **Key Selectors & CDP Actions**:
  - Prompt Editor: ProseMirror `contenteditable` container.
  - Submit Button: `button[aria-label="Create"]`.
  - Keystroke Injection: Native CDP `Input.dispatchKeyEvent` or ProseMirror transaction dispatch.
- **Production Guardrail**:
  - `SILENT_FLOW_MOCK_FALLBACK=DISABLED`.
  - Silent simulation fallbacks on missing WebContents or profile failures are strictly forbidden in production.

---

## 4. Character Reference & Approval Gate

- Character references MUST be generated and locked before scene image/video generation:
  `Preview -> Approve / Regenerate -> Lock References -> Generate Scenes`.
- The pipeline supports both interactive human review (approve per image / approve all) and optional auto-approve mode.

---

## 5. Native TTS Pipeline Integration

- TTS is an **OPTIONAL STAGE** inside `PipelineJob`:
  `VALIDATING_BUNDLE -> PREPARING_INPUTS -> GENERATING_TTS_AUDIO [OPTIONAL] -> ...`
- Invokes Central PHP Backend: `POST /api/v1/tts/jobs`.
- **Canonical Payload**:
  ```json
  {
    "text": "...",
    "voice_id": "...",
    "language": "...",
    "output_format": "wav",
    "settings": {
      "speed": 1.0
    },
    "idempotency_key": "pipeline:<pipeline_job_id>:tts",
    "cloud_space_id": "...",
    "folder_id": "..."
  }
  ```
- If no cloud destination is configured, audio remains local.

---

## 6. Mandatory Agent Behaviors & Discipline

1. **Context First**: Always query persistent memory (`agent-memory` / `memory-brain`) and read `GEMINI.md` before planning or writing code.
2. **Audit Before Modifying**: Audit live code paths before making claims or structural assumptions.
3. **Preserve Architecture**: Never redesign existing queues or pipeline stages unless explicitly commanded by the user.
4. **Scope Control**: Touch only the files directly required for the task. Zero unauthorized refactoring.
5. **Record Durable Decisions**: Persist all architectural conclusions and verified findings to `agent-memory` upon task completion.

---

## 7. Google Flow Product UI Invariants (Overlay & Runner Layer)

- **Single Canonical Module (`FlowOverlayLayer`)**:
  - Injected directly into the Google Flow WebContents.
  - Lifecycle: `mount()`, `ensureMounted()`, `unmount()`, `setAutomationState()`, `setTaskState()`, `setStatus()`, `setProgress()`.
  - Single Instance Guarantee: SettingsButton $\le 1$, MiniRunner $\le 1$, Launcher $\le 1$, StatusPill $\le 1$, AutoFrame $\le 1$.
- **CSS Selector Syntax Rule for Numbered IDs**:
  - DOM IDs starting with digits (such as `2toolne-flow-auto-frame`) MUST use attribute selectors `[id="2toolne-flow-..."]` in both CSS rules and `querySelectorAll` to prevent browser `DOMException: SyntaxError: '#2...' is not a valid selector`.
- **Visual Polish Invariants**:
  - Frame border: crisp 2px border in brand orange (`#FF7A00`) with canonical 20px border radius across all 4 corners.
  - Ambient glow: 4-stop inward inset box-shadow (`16px, 32px, 48px, 64px`) ensuring completely uniform perimeter glow with zero corner hotspots.
  - Breathing animation: `twoToolneAutoGlowBreath` (opacity 0.70 $\leftrightarrow$ 1.00, 2.8s ease-in-out infinite).
- **SPA Remount & State Rehydration**:
  - A debounced (150ms) MutationObserver watches the DOM and auto-invokes `ensureMounted()` when Angular SPA navigates or wipes DOM nodes.
  - Re-hydrated immediately on tab switch (`FlowBrowserManager.show()`), profile switch, and pipeline queue state changes (`job:progress`, `job:state_changed`).

---

## 8. Desktop Dark-Only Invariant (`2TOOLNE_DESKTOP_THEME=DARK_ONLY`)

- **Product Invariant**: 2TOOLNE Desktop is permanently **DARK MODE ONLY**. Light mode, system theme auto-switching, and theme toggle controls are permanently removed.
- **Canonical Design Tokens**:
  - App Background: `--bg-app: #0E0F11`
  - Sidebar: `--bg-sidebar: #111214`
  - Surfaces: `--surface-1: #15171A`, `--surface-2: #1A1C20`, `--surface-3: #202228`
  - Border: `--border: #292C31`
  - Brand Accent: `--brand: #FF7A00`
  - Text: `--text-main: #FFFFFF`, `--text-muted: #8E929B`
- **Native Chrome & Anti-Flash**:
  - Main Process: `nativeTheme.themeSource = 'dark'` and `BrowserWindow({ backgroundColor: '#0E0F11' })`.
  - Renderer Root: `<html lang="vi" data-theme="dark">` with `color-scheme: dark;` on `:root` and `html`.
- **Silent Migration**:
  - Legacy `localStorage` keys (`2toolne_theme='light'|'system'`, `appearance='light'`) are silently migrated to `'dark'` and removed on initialization with zero user interruption.

---

## 9. Dynamic Cards & Delegated Action Invariant

- **Core Rule**: All dynamic cards rendered in:
  - **Queue V2 / Build Queue** (`#pipelineJobsList`)
  - **Created Projects** (`#projectsGrid`)
  MUST use stable delegated event routing (`event.target.closest('[data-...-action]')`) bound exactly ONCE to the persistent container.
- **Strict Prohibition of Inline Handlers**:
  - `onclick="..."` attributes are strictly forbidden due to Content Security Policy (`script-src 'self'`).
  - Direct `btn.addEventListener` per card is forbidden because list re-renders (`innerHTML = ''`) discard listeners.
- **Canonical Created Projects Action Contract**:
  - **Chỉnh sửa dự án (`edit-menu` dropdown)**:
    - Đổi tên: `data-project-action="rename-project"`
    - Tạo lại ảnh: `data-project-action="regen-images"`
    - Tạo lại Voice: `data-project-action="regen-voice"`
    - Thêm vào Hàng Đợi Xử Lý: `data-project-action="queue-pipeline"`
    - Mở Project CapCut: `data-project-action="open-capcut"`
  - **External / Utility Actions**:
    - Upload Cloud: `data-project-action="upload-cloud"`
    - Mở thư mục: `data-project-action="open-folder"`
    - Xóa: `data-project-action="delete"`
  - **Removed Stale Actions**: Legacy `export-video`, `queue-export`, and `load-studio` actions are removed from the product action contract.
- **Strict Project ID Routing**:
  - Action dispatch MUST resolve strictly via `button.dataset.projectId` to locate the exact record.
  - Never route by array index, DOM order, or first element.

---

## 10. Persistent Flow WebContents & Background Lifecycle Invariant

```text
ONE Flow Profile = ONE long-lived WebContents = ONE long-lived WebContentsView
```

- **Zero Tab-Switch Recreation**:
  - Tab switching between Flow, Queue, Studio, Cloud, Projects, and Settings must NEVER recreate or reload the Flow WebContents.
  - Leaving Flow tab: detach native View only (`mainWindow.contentView.removeChildView(view)`). WebContents continues running full-speed in background.
  - Returning to Flow tab: reattach same View (`addChildView(existingView)` $\rightarrow$ `setBounds()`) after 1 RAF frame without blocking on async profile/status checks.
  - Destruction occurs exclusively on application exit (`app.on('before-quit')`).
- **Dynamic Background Throttling**:
  - `wc.setBackgroundThrottling(false)` during active automation tasks so background timers, DOM observers, and downloads run at full capability without 1000ms clamps.
  - `wc.setBackgroundThrottling(true)` restored when Flow is idle to conserve system resources.
- **Long-Lived CDP Attachment**:
  - Chrome DevTools Protocol (CDP) session is initialized once on WebContents creation and maintained across tab transitions.
- **Reload Protection**:
  - User reload action (`DOM.btnFlowReload` / `flow:reload`) is guarded against active automation tasks and requires confirmation before interrupting in-progress generations.

---

## 11. App-Shell Popover & Native Overlay Invariant (`GlobalPopoverManager`)

- **Core Invariant**: Global header popovers (Workspace dropdown, account, wallet, menus) overlay native `WebContentsViews` (such as Google Flow) as a native top-level layer.
- **Strict Prohibition of Flow Bounds Adjustment**:
  - Opening, navigating, or closing a popover must NEVER reposition (`y`), resize (`height`), or reload Google Flow.
  - `FLOW_BOUNDS_BEFORE == FLOW_BOUNDS_DURING_POPOVER == FLOW_BOUNDS_AFTER`.
- **Single Reusable Native View (`GlobalPopoverView`)**:
  - Managed by `GlobalPopoverManager` as a single, long-lived `WebContentsView` with transparent background (`#00000000`).
  - View bounds cover ONLY the popover card rectangle plus a 10px shadow margin.
  - No fullscreen transparent click interceptor: the surrounding application remains completely interactive.
- **Native Z-Order Guarantee**:
  - Native z-order is enforced strictly via `BrowserWindow.contentView.children` ordering:
    `App Shell DOM < Google Flow WebContentsView < GlobalPopoverView (TOPMOST)`.
  - Even during active Google Flow AUTO mode, the glowing outline, breathing animation, and MiniRunner render seamlessly behind the popover without visual clipping or border bleed.

---

## 12. SePay Payment Gateway & Hosted Checkout Invariant

```text
1 Commercial Checkout Request -> 1 Pending Order in DB -> 1 Hosted Checkout URL (GET /sepay_checkout.php?order_id=...) -> Server-Side Cryptographic Sign -> Auto-POST to SePay Gateway (https://pay.sepay.vn/v1/checkout/init) -> VietQR Payment Interface
```

- **Hosted Form Redirector Architecture**:
  - Desktop clients NEVER receive SePay merchant secrets or private keys.
  - Desktop client calls `POST /api/v1/billing/token-checkout` or `POST /api/v1/billing/checkout` to create a pending order.
  - The API returns an authoritative hosted checkout URL: `https://www.2tamne.site/sepay_checkout.php?order_id={$checkoutId}`.
  - Desktop opens this URL in the user's browser via `shell.openExternal()` (HTTP GET).
  - `sepay_checkout.php` validates the order from the database without requiring session cookies, signs the payload server-side using `SepayClient`, and renders an auto-submitting POST form targeting `https://pay.sepay.vn/v1/checkout/init`.
- **Strict Prohibition of Direct SePay GET**:
  - SePay's `/v1/checkout/init` gateway endpoint strictly requires HTTP POST and returns HTTP 404 on GET requests.
  - Never return `https://pay.sepay.vn/v1/checkout/init?params` directly to Desktop or browser.
- **Token Packages Layout Invariant**:
  - Modal 17 (`#modalTokenTopup`) renders 5 canonical server-driven token packages.
  - Grid layout: Flexbox (`display: flex; flex-wrap: wrap; justify-content: center; gap: 16px;`).
  - Row 1: 3 cards (`pkg_starter`, `pkg_creator`, `pkg_studio`).
  - Row 2: 2 cards (`pkg_enterprise`, `pkg_unlimited`) centered horizontally.
  - Sizing: All 5 cards share exact matching width (`flex: 0 1 calc((100% - 32px) / 3); min-width: 220px; max-width: calc((100% - 32px) / 3);`). Bottom row cards must NEVER stretch to 50%.
  - Headers: Title and badge are encapsulated in a flex container (`.token-pkg-header`) with `align-items: flex-start` and `gap: 6px` to prevent text/badge collisions.
  - Actions: CTA button (`.btn-select-token-pkg`) is bottom-aligned across all cards via `margin-top: auto`.

---

## 13. Canonical Authentication & Zero Raw IP Invariant (`2TOOLNE_AUTH_ORIGIN=CANONICAL_2TAMNE_SITE`)

```text
Desktop Client (endpoints.js: CANONICAL_ORIGIN) -> Open System Browser (https://2tamne.site/index.php?app_auth=1) -> Zero-Redirect Direct Response (HTTP 200) -> User Approves -> Loopback HTTP Callback (http://127.0.0.1:<port>/callback) or Deep Link (toolne://auth/callback) -> POST /api/v1/auth/token -> Session Established
```

- **Single Authoritative Origin**:
  - The single canonical production origin for all 2TOOLNE desktop services, browser authentication flows, and API requests is strictly **`https://2tamne.site`**.
  - Secondary `www.2tamne.site` domains MUST 301-redirect to `https://2tamne.site` at the Apache/Cloudflare perimeter (protecting `/api/` endpoints from payload loss).
- **Zero Raw IP Invariant**:
  - Active runtime code, build configurations, and client payloads MUST contain ZERO raw IP address literals (neither old host `103.97.126.29` nor production server origin `43.129.165.150`).
  - Local loopback is restricted strictly to `127.0.0.1` for ephemeral RFC 8252 authorization code receivers.
- **Centralized Endpoint Contract (`src/common/endpoints.js`)**:
  - All Desktop components import endpoints exclusively from `src/common/endpoints.js`.
  - Scattered URL literals across main, preload, and renderer layers are strictly forbidden.
- **Dual Callback Redundancy**:
  - Primary: RFC 8252 ephemeral loopback HTTP server (`http://127.0.0.1:<port>/callback`) bound dynamically to unprivileged ports.
  - Secondary / Fallback: Custom deep link protocol registered across macOS (`open-url`) and Windows (`second-instance` + `app.requestSingleInstanceLock()`) with schemes `toolne://`, `2toolne://`, and `twotoolne://`.

---

## 14. Pipeline Queue V2 Product & Execution Invariant (`2TOOLNE_QUEUE_ARCHITECTURE=PIPELINE_V2_FIFO_ONLY`)

```text
1 Input Bundle = 1 2TOOLNE Project = 1 PipelineJob = 1 CapCut Project (Terminal: PROJECT_READY)
```

- **Single Authoritative Queue UI**:
  - `PipelineQueueV2` is the ONLY user-facing processing queue in the 2TOOLNE Desktop application.
  - The obsolete "Hàng Đợi Xuất Video" (Render Queue) mini-tab and the legacy sidecar build queue table are permanently removed from the user interface.
  - Users interact with exactly one unified list of workflow jobs.
- **Strict FIFO Video Orchestration**:
  - `PipelineQueueV2` schedules and processes jobs strictly sequentially (FIFO) sorted by `created_at` ASC.
  - `PIPELINE_MAX_CONCURRENT_JOBS = 1`. One active video pipeline runs at a time.
  - Sub-tasks (scenes 001..N, characters, audio) are internal stages of a single `PipelineJob`, NOT separate product-level jobs.
- **Terminal Completion Gate (`PROJECT_READY`)**:
  - A `PipelineJob` completes and terminates when 2TOOLNE project resources are finalized and the CapCut project draft is verified (`PROJECT_READY`).
  - **Zero Automatic Video Export**: 2TOOLNE does NOT automatically trigger background final MP4 video export. Final video rendering/exporting is managed directly inside CapCut or upon explicit manual user command.

---

## 15. Release Standard & Mandatory Deployment Hard Gates (`2TOOLNE_RELEASE_STANDARD=ENFORCED`)

```text
ONE VERSION = ONE CLEAN SOURCE COMMIT = ONE COMPLETE RELEASE TREE = ONE SIGNED INTEGRITY MANIFEST = ONE SET OF ARTIFACTS = ONE SERVER RELEASE METADATA RECORD
```

- **Permanent Mandatory Policy**:
  - Applies to every version bump, build, packaging, release, metadata update, and deployment task.
  - Full specification: `.agents/rules/release_standard_rule.md`.
- **Zero Dirty Builds**:
  - `git status` MUST be completely clean before packaging (`WORKING_TREE_CLEAN=YES`).
  - Production builds MUST record and embed the exact full commit SHA (`BUILD_SOURCE_COMMIT=<SHA>`).
- **Hard Version Consistency**:
  - `APP_VERSION == MANIFEST_VERSION == INSTALLER_VERSION == UPDATE_VERSION == WEBSITE_VERSION`.
  - Zero tolerance for mixed releases or stale metadata.
- **Python Core Protection**:
  - Production Python core MUST be compiled with Nuitka into a native binary (`2toolne-core.exe` / `2toolne-core`).
  - Zero plaintext `.py`, `.pyc`, `.pyo`, `__pycache__`, or PyInstaller `_internal` directories (`PYINSTALLER_PRODUCTION_USAGE=0`).
- **Ed25519 Private Key Isolation**:
  - Production private signing keys MUST NEVER exist in source code, repository, git history, or packaged artifacts.
  - Sourced exclusively from secure CI Secrets / vault environments.
  - Production Native Root and IntegrityGuard MUST NOT trust public dev keys (`DEV_KEY_TRUSTED_BY_PRODUCTION=NO`).
  - Exposed keys are permanently compromised, revoked, and rotated.
- **Strict Native Root Architecture**:
  - Direct execution of `2toolne-runtime.exe` without valid bootstrap handshake proof is strictly BLOCKED (`ROOT_OF_TRUST_BYPASS=NO`).
  - In packaged mode (`app.isPackaged`), IntegrityGuard resolves ONLY inside the active installed root (`PRODUCTION_PATH_FALLBACK_COUNT=0`).
- **Atomic Auto-Update & Staged Launcher Trust**:
  - Active release is immutable. Updates use isolated staging and whole-release transactional directory move with rollback.
  - Staged launcher must be authenticated before execution (`STAGED_LAUNCHER_AUTHENTICATED=YES`).
- **Secure Token Storage**:
  - Electron `safeStorage` (Windows DPAPI / macOS Keychain) required. Plaintext/base64 fallback throws `SecurityError` when packaged.
- **Acceptance Hard Stop**:
  - Every release concludes with the 35-field acceptance report (Section 42).
  - `READY_TO_DEPLOY=YES` is strictly forbidden if ANY mandatory gate fails or if `CRITICAL_FINDINGS > 0`.
  - If a gate fails: **STOP IMMEDIATELY, do not deploy, do not bypass, and report the exact failure.**


