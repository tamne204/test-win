#!/usr/bin/env bash
# ==============================================================================
# apps/capcut-v2/desktop/src/native_root/build.sh
# 
# 2TOOLNE Desktop — Native Root of Trust Bootstrap Verifier Build Script
# 
# Builds the native pre-launch bootstrap launcher:
# 1. Windows PE32+ GUI binary: '2TOOLNE AutoEdit.exe' (via Go cross-compiler or MinGW GCC)
# 2. macOS 64-bit binary: '2TOOLNE AutoEdit' (via Go compiler or Clang)
# 
# Usage:
#   ./build.sh [options]
#   Options:
#     --all       Build for both Windows and macOS (default)
#     --windows   Build for Windows PE32+ only
#     --darwin    Build for macOS only
#     --clean     Clean artifacts before building
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

DIST_DIR="$SCRIPT_DIR/../../dist/native_root"
TARGET_MODE="${1:---all}"

if [[ "$TARGET_MODE" == "--clean" ]]; then
  echo "[Clean] Removing build artifacts in $DIST_DIR..."
  rm -rf "$DIST_DIR"
  TARGET_MODE="${2:---all}"
fi

mkdir -p "$DIST_DIR"

echo "=========================================================================="
echo " 2TOOLNE DESKTOP: NATIVE ROOT OF TRUST BOOTSTRAP VERIFIER BUILDER        "
echo "=========================================================================="

build_windows() {
  echo "[+] Compiling Windows PE32+ GUI Executable (2TOOLNE AutoEdit.exe)..."
  local OUT_FILE="$DIST_DIR/2TOOLNE AutoEdit.exe"
  
  if command -v go >/dev/null 2>&1; then
    echo "    Using Go toolchain: GOOS=windows GOARCH=amd64 (subsystem: windowsgui)"
    GOOS=windows GOARCH=amd64 go build -ldflags "-s -w -H=windowsgui" -o "$OUT_FILE" .
  else
    echo "    Error: 'go' toolchain is required for cross-compiling the native verifier."
    exit 1
  fi

  if [[ -f "$OUT_FILE" ]]; then
    echo "    [OK] Successfully built: $OUT_FILE"
    file "$OUT_FILE"
  else
    echo "    [ERROR] Output file not found: $OUT_FILE"
    exit 1
  fi
}

build_darwin() {
  echo "[+] Compiling macOS Mach-O Executable (2TOOLNE AutoEdit)..."
  local OUT_FILE="$DIST_DIR/2TOOLNE AutoEdit"

  if command -v go >/dev/null 2>&1; then
    echo "    Using Go toolchain: GOOS=darwin (host: $(uname -m))"
    go build -ldflags "-s -w" -o "$OUT_FILE" .
    chmod +x "$OUT_FILE"
  else
    echo "    Error: 'go' toolchain is required for compiling the native verifier."
    exit 1
  fi

  if [[ -f "$OUT_FILE" ]]; then
    echo "    [OK] Successfully built: $OUT_FILE"
    file "$OUT_FILE"
  else
    echo "    [ERROR] Output file not found: $OUT_FILE"
    exit 1
  fi
}

case "$TARGET_MODE" in
  --windows)
    build_windows
    ;;
  --darwin)
    build_darwin
    ;;
  --all|*)
    build_windows
    build_darwin
    ;;
esac

echo "=========================================================================="
echo " BUILD SUCCESSFUL — Artifacts available in: $DIST_DIR"
echo "=========================================================================="
