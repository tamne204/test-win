# RELEASE_STANDARD_RULE: Mandatory Rules for Every New Version

This rule is a **PERMANENT RELEASE POLICY** for 2TOOLNE. It strictly applies whenever Antigravity is asked to:
- Bump version
- Create a new release
- Build Windows or macOS artifacts
- Update Auto Update packages
- Deploy a release
- Prepare installer, ZIP, or DMG packages
- Publish release metadata
- Modify integrity or security packaging
- Update production download files

---

## Core Philosophical Invariant
```text
ONE VERSION = ONE CLEAN SOURCE COMMIT = ONE COMPLETE RELEASE TREE = ONE SIGNED INTEGRITY MANIFEST = ONE SET OF ARTIFACTS = ONE SERVER RELEASE METADATA RECORD
```
- **Zero Dirty Builds**: `git status` MUST be clean before packaging. `BUILD_SOURCE_COMMIT` must be recorded.
- **Zero Component Version Drift**: `APP_VERSION == MANIFEST_VERSION == INSTALLER_VERSION == UPDATE_VERSION == WEBSITE_VERSION`.
- **Zero Mixed Releases**: Never reuse old app.asar/runtime scaffolds or mix version files.

---

## Key Technical Hard Gates

1. **Python Core Protection**:
   - Production Python core MUST be compiled with Nuitka into a native executable (`2toolne-core.exe` / `2toolne-core`).
   - Packaged production releases must contain ZERO `.py`, `.pyc`, `.pyo`, `__pycache__`, or PyInstaller `_internal` directories (`PYINSTALLER_PRODUCTION_USAGE=0`).
2. **Ed25519 Signing Key Policy**:
   - Production private signing keys MUST NEVER exist in source code, repository, git history, or packaged artifacts.
   - Must be sourced exclusively from secure CI Secrets / vault environments.
   - Production Native Root and IntegrityGuard MUST NOT trust public dev keys (`DEV_KEY_TRUSTED_BY_PRODUCTION=NO`).
   - Any exposed signing key is permanently revoked and rotated.
3. **Strict Native Root Architecture**:
   - Launch chain: `Shortcut -> 2TOOLNE AutoEdit.exe -> verify signed manifest & file SHA256 -> generate bootstrap proof -> start 2toolne-runtime -> Electron IntegrityGuard -> sidecar`.
   - Direct startup of `2toolne-runtime.exe` without valid bootstrap proof MUST be blocked (`DIRECT_RUNTIME_LAUNCH=BLOCKED`, `ROOT_OF_TRUST_BYPASS=NO`).
4. **Production Fallback Elimination**:
   - In packaged mode (`app.isPackaged == true`), file integrity verification must resolve ONLY inside the active installed release root (`PRODUCTION_PATH_FALLBACK_COUNT=0`). Fallback to repo or development paths is strictly forbidden.
5. **Atomic Auto-Update Architecture**:
   - Active release is immutable.
   - Flow: `Download -> SHA256 verify -> Staging -> Verify staged release & manifest -> Close app -> External helper -> Whole release directory swap -> Relaunch Native Root`.
   - Staged launcher must be authenticated before execution (`STAGED_LAUNCHER_AUTHENTICATED=YES`).
   - Rollback capability must be preserved.
6. **Code Signing Truth**:
   - Distinguish `SIGNING_PIPELINE_READY` from `ACTUAL_ARTIFACT_SIGNED`. Never claim Authenticode or Apple Developer ID pass without verified certificates on the actual artifacts.
7. **Secure Token Storage**:
   - Enforce Electron `safeStorage` (Windows DPAPI / macOS Keychain).
   - In packaged mode, unavailability of OS encryption MUST throw `SecurityError` (fail-secure, zero plaintext/base64 fallback).
8. **Release Content Hygiene**:
   - Exclude all tests, reports, diagnostics, `.github/`, `.git/`, dev scripts, build scripts, source `.py`, `.go`, `.cpp`, source maps, and developer documentation from packaged releases.

---

## Acceptance Report Requirement

Every release audit and completion MUST output the exact 35-field acceptance report specified in Section 42 of the Release Standard:
`READY_TO_DEPLOY=YES` is strictly prohibited if any mandatory hard gate fails or if `CRITICAL_FINDINGS > 0`.

If any gate fails:
**STOP IMMEDIATELY. Do not deploy. Do not silently bypass. Report the exact failure to the user.**
