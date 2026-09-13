# 2TOOLNE Windows CI Lab (`tamne204/test-win`)

Specialized automated GitHub Actions continuous integration and runtime validation lab for **2TOOLNE AutoEdit (Windows Edition)**.

This repository serves strictly as the **Windows CI Harness** for developers operating on macOS or Linux environments without access to a physical Windows machine.

---

## 1. Scope & Truth Boundaries

- **Supported CI Verification Scope** (`windows-latest`):
  - Windows PE32+ (AMD64 / x64) binary headers
  - Stdio JSON-RPC line-delimited communication protocol
  - Native Go root verifier launcher (`2TOOLNE AutoEdit.exe`)
  - Ed25519 cryptographic signatures & streaming SHA-256 integrity manifests
  - Packaged & Installed sidecar runtime cold starts (`2toolne-core.exe`)
  - NSIS silent installer (`/S`) and uninstaller routines
  - Disposable-copy anti-tamper stress tests
  - User data path preservation and filesystem isolation
- **Strictly Excluded from CI Claims**:
  - `WINDOWS_REAL_CAPCUT=NOT_RUN` (Automating physical CapCut GUI requires physical desktop user session)
  - `WINDOWS_REAL_FLOW=NOT_RUN` (Google Flow automation requires interactive CDP profile session)
  - `WINDOWS_PHYSICAL_GUI_RUNTIME=NOT_RUN` (GitHub headless runners do not reflect physical graphics/display behavior)

---

## 2. CI Workflows

| Workflow | File | Trigger | Description |
| :--- | :--- | :--- | :--- |
| **Windows Core Smoke** | `.github/workflows/windows-core-smoke.yml` | `workflow_dispatch`, `push` | Downloads release package, audits file tree, runs 5 cold-start JSON-RPC pings on `2toolne-core.exe`, and executes all anti-tamper tests. |
| **Windows Package Audit** | `.github/workflows/windows-package-audit.yml` | `workflow_dispatch` | Downloads NSIS installer & ZIP, runs silent installation, tests **installed** core binary PING, audits registry, and tests uninstallation. |
| **Windows Full Release** | `.github/workflows/windows-full-release.yml` | `workflow_dispatch` | Builds sidecar, Go launcher, and Electron NSIS installer from canonical source on `windows-latest` using secret `SOURCE_REPO_TOKEN`. |

---

## 3. Test Scripts (`scripts/`)

- `scripts/Test-Sidecar.ps1`: Tests PE machine type, audits dependency closure (`_internal/`), executes stdio JSON-RPC PING across multiple cold starts, and outputs latency metrics.
- `scripts/Test-Integrity.ps1`: Validates Ed25519 manifest signature, checks SHA-256 digests of all 8 critical components, runs native verifier `--verify-only`, tests direct runtime bypass blocking, and executes 5 disposable tamper stress tests.
- `scripts/Test-Package.ps1`: Generates full package tree dump, validates release content hygiene (zero `.py`, `.env`, private keys), and verifies ZIP parity.
- `scripts/Test-Installer.ps1`: Tests NSIS silent installation, validates installed directory tree, verifies installed launcher, executes **installed core PING**, and tests silent uninstaller.

---

## 4. Required Secrets (Optional for Source Build)

- `SOURCE_REPO_TOKEN`: GitHub Personal Access Token (PAT) with `repo` scope to allow `windows-full-release.yml` to checkout private canonical source repository (`tamne204/ffmpeg-tool`).
- If `SOURCE_REPO_TOKEN` is not configured, `SOURCE_BUILD` is reported as `NOT_RUN`, while `windows-core-smoke.yml` and `windows-package-audit.yml` continue to function seamlessly via prebuilt release URLs.
