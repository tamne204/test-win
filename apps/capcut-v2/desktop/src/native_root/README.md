# 2TOOLNE Desktop — Native Root of Trust Bootstrap Verifier

## Overview
The Native Root of Trust verifier is the primary native executable entrypoint (`2TOOLNE AutoEdit.exe` on Windows; `Contents/MacOS/2TOOLNE AutoEdit` on macOS) situated outside `app.asar`. It establishes an unbroken cryptographic chain of trust prior to launching the Electron runtime engine (`2toolne-runtime.exe` / `2toolne-runtime`).

## Architecture & Security Properties
1. **Root of Trust Outside `app.asar`**:
   Addresses the fundamental vulnerability where verification logic packed inside `app.asar` cannot safely verify `app.asar` itself (`SELF_VERIFICATION_BOOTSTRAP_SAFE = NO`).
2. **Hardened Public Key Anchoring**:
   Embeds the authentic 32-byte Ed25519 public verification key (`+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=`) in compiled read-only static memory. Tampering with the binary corrupts PE/Mach-O headers and invalidates code signatures.
3. **Multi-Binary Streaming SHA-256 Verification**:
   Streams SHA-256 digests using 1MB buffers for all 7 critical application binaries:
   - `resources/app.asar`
   - `resources/autoedit-core/win-x64/2toolne-core.exe`
   - `resources/bin/win-x64/ffmpeg.exe`
   - `resources/bin/win-x64/ffprobe.exe`
   - `resources/bin/win-x64/CapCutUiProbe.exe`
   - `resources/engine/win-x64/realesrgan-ncnn-vulkan.exe`
   - `resources/engine/win-x64/vcomp140.dll`
4. **Strict Fail-Secure Policy**:
   If the manifest is missing, signature is invalid, any SHA-256 digest mismatches, or any critical binary is absent:
   - Displays a native OS modal error dialog (`MessageBoxW` on Windows; native alert on macOS).
   - Terminates immediately with `ExitProcess(1)` / `os.Exit(1)`.
   - The Electron runtime is **NEVER** executed.
5. **Ephemeral Bootstrap Handshake**:
   Upon 100% verification pass, generates an unforgeable, ephemeral handshake token (`_2TOOLNE_BOOTSTRAP_TOKEN` with a 60-second TTL) bound to `manifest.signature` and passed via process environment to the Electron runtime.

## Windows PE Icon Resource (`rsrc_windows_amd64.syso`)
To ensure the compiled Windows launcher (`2TOOLNE AutoEdit.exe`) displays the branded application icon on Desktop shortcuts, Start Menu, taskbar, and Add/Remove Programs:
- The resource object `rsrc_windows_amd64.syso` is compiled from `apps/capcut-v2/desktop/assets/icon.ico` using `rsrc`:
  ```bash
  go install github.com/akavel/rsrc@latest
  rsrc -ico ../../assets/icon.ico -arch amd64 -o rsrc_windows_amd64.syso
  ```
- The Go linker automatically links `rsrc_windows_amd64.syso` when building with `GOOS=windows GOARCH=amd64`. When building for macOS, the file is ignored.

## Compilation
```bash
# Build both Windows PE32+ and macOS Mach-O
./build.sh --all

# Or via Makefile
make all
```
