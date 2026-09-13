package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

const (
	LauncherVersion = "2.1.1"
	AppName         = "2TOOLNE AutoEdit"
)

func main() {
	var (
		verifyOnly    = false
		printToken    = false
		headless      = false
		resourcesDir  = ""
		manifestPath  = ""
		runtimeBinary = ""
		forwardArgs   = []string{}
	)

	// Environment variable overrides
	if os.Getenv("_2TOOLNE_HEADLESS_TEST") == "1" || os.Getenv("INTEGRITY_TEST_NO_EXIT") == "1" {
		headless = true
	}
	if envRes := os.Getenv("_2TOOLNE_RESOURCES_PATH"); envRes != "" {
		resourcesDir = envRes
	}
	if envMan := os.Getenv("_2TOOLNE_MANIFEST_PATH"); envMan != "" {
		manifestPath = envMan
	}
	if envRun := os.Getenv("_2TOOLNE_RUNTIME_PATH"); envRun != "" {
		runtimeBinary = envRun
	}

	// Manual argument parsing to prevent Go flag errors on unknown Electron arguments
	args := os.Args[1:]
	for i := 0; i < len(args); i++ {
		arg := args[i]
		switch {
		case arg == "--verify-only":
			verifyOnly = true
		case arg == "--print-token":
			printToken = true
		case arg == "--headless":
			headless = true
		case arg == "--version" || arg == "-v":
			fmt.Printf("%s Native Root Launcher v%s (%s/%s)\n", AppName, LauncherVersion, runtime.GOOS, runtime.GOARCH)
			os.Exit(0)
		case arg == "--help" || arg == "-h":
			fmt.Printf("Usage: %s [launcher-options] [-- electron-arguments...]\n", AppName)
			fmt.Println("\nLauncher Options:")
			fmt.Println("  --verify-only         Perform pre-launch integrity checks and exit (code 0/1)")
			fmt.Println("  --print-token         With --verify-only, print generated bootstrap handshake token")
			fmt.Println("  --headless            Suppress modal error dialogs (stderr only)")
			fmt.Println("  --resources-dir <dir> Override resources directory path")
			fmt.Println("  --manifest <path>     Override integrity.manifest.json path")
			fmt.Println("  --runtime-binary <bin> Override Electron runtime executable path")
			os.Exit(0)
		case arg == "--resources-dir" && i+1 < len(args):
			i++
			resourcesDir = args[i]
		case strings.HasPrefix(arg, "--resources-dir="):
			resourcesDir = strings.TrimPrefix(arg, "--resources-dir=")
		case arg == "--manifest" && i+1 < len(args):
			i++
			manifestPath = args[i]
		case strings.HasPrefix(arg, "--manifest="):
			manifestPath = strings.TrimPrefix(arg, "--manifest=")
		case arg == "--runtime-binary" && i+1 < len(args):
			i++
			runtimeBinary = args[i]
		case strings.HasPrefix(arg, "--runtime-binary="):
			runtimeBinary = strings.TrimPrefix(arg, "--runtime-binary=")
		default:
			forwardArgs = append(forwardArgs, arg)
		}
	}

	// Resolve executable directory
	exePath, err := os.Executable()
	if err != nil {
		exePath, _ = filepath.Abs(os.Args[0])
	}
	exeDir := filepath.Dir(exePath)

	// Resolve resources directory if not provided
	if resourcesDir == "" {
		if runtime.GOOS == "darwin" && strings.Contains(exeDir, filepath.Join("Contents", "MacOS")) {
			resourcesDir = filepath.Join(filepath.Dir(exeDir), "Resources")
		} else {
			candidates := []string{
				filepath.Join(exeDir, "resources"),
				filepath.Join(exeDir, "build_windows_release"),
				filepath.Join(exeDir, "..", "resources"),
				filepath.Join(exeDir, "apps", "capcut-v2", "desktop", "resources"),
			}
			for _, cand := range candidates {
				if stat, statErr := os.Stat(cand); statErr == nil && stat.IsDir() {
					resourcesDir = cand
					break
				}
			}
			if resourcesDir == "" {
				resourcesDir = filepath.Join(exeDir, "resources")
			}
		}
	}

	// Resolve manifest path if not provided
	if manifestPath == "" {
		manifestPath = filepath.Join(resourcesDir, "integrity.manifest.json")
	}

	// Resolve runtime executable binary if not provided
	if runtimeBinary == "" {
		if runtime.GOOS == "windows" {
			candidates := []string{
				filepath.Join(exeDir, "2toolne-runtime.exe"),
				filepath.Join(exeDir, "2TOOLNE AutoEdit_runtime.exe"),
				filepath.Join(exeDir, "electron.exe"),
			}
			for _, cand := range candidates {
				if _, statErr := os.Stat(cand); statErr == nil {
					runtimeBinary = cand
					break
				}
			}
			if runtimeBinary == "" {
				runtimeBinary = filepath.Join(exeDir, "2toolne-runtime.exe")
			}
		} else if runtime.GOOS == "darwin" {
			candidates := []string{
				filepath.Join(exeDir, "2toolne-runtime"),
				filepath.Join(exeDir, "Electron"),
			}
			for _, cand := range candidates {
				if _, statErr := os.Stat(cand); statErr == nil {
					runtimeBinary = cand
					break
				}
			}
			if runtimeBinary == "" {
				runtimeBinary = filepath.Join(exeDir, "2toolne-runtime")
			}
		} else {
			runtimeBinary = filepath.Join(exeDir, "2toolne-runtime")
		}
	}

	// =========================================================================
	// TIER 1: NATIVE PRE-LAUNCH INTEGRITY VERIFICATION (FAIL-SECURE)
	// =========================================================================
	result, verifyErr := VerifyIntegrity(manifestPath, resourcesDir, TrustedPublicKeys)
	if verifyErr != nil {
		secErr, ok := verifyErr.(*SecurityViolationError)
		if !ok {
			secErr = &SecurityViolationError{Code: "ERR_INTEGRITY_TAMPERED", Message: verifyErr.Error()}
		}

		// Log security violation to stderr
		fmt.Fprintf(os.Stderr, "[NativeRoot][FATAL] %s\n", secErr.Error())

		// Display native OS error dialog unless running headless
		if !headless {
			title := "Lỗi bảo mật tính toàn vẹn (Security Integrity Error)"
			message := fmt.Sprintf(
				"Phát hiện ứng dụng hoặc tệp nhị phân hệ thống đã bị chỉnh sửa bất hợp pháp.\n\n"+
					"Mã lỗi: %s\n\n"+
					"Chi tiết vi phạm: %s\n\n"+
					"Ứng dụng 2TOOLNE AutoEdit sẽ buộc đóng ngay lập tức để bảo vệ an toàn dữ liệu.",
				secErr.Code, secErr.Message,
			)
			showNativeErrorDialog(title, message)
		}

		// FAIL-SECURE: Terminate immediately with exit code 1.
		// Electron runtime is NEVER invoked!
		os.Exit(1)
	}

	// If verify-only mode was requested:
	if verifyOnly {
		if printToken {
			fmt.Println(result.BootstrapToken)
		} else {
			fmt.Printf("[PASS] Native root verification succeeded (%d files verified, manifest v%s)\n", result.FilesChecked, result.Version)
		}
		os.Exit(0)
	}

	// Verify runtime binary safety (prevent arbitrary command execution proxy / LOLBin)
	cleanRuntimePath := filepath.Clean(runtimeBinary)
	absRuntimePath := cleanRuntimePath
	if !filepath.IsAbs(absRuntimePath) {
		absRuntimePath = filepath.Join(exeDir, absRuntimePath)
	}
	absRuntimePath = filepath.Clean(absRuntimePath)

	runtimeBase := filepath.Base(absRuntimePath)
	expectedNameWin := "2toolne-runtime.exe"
	expectedNameDarwin := "2toolne-runtime"

	isAllowedName := (runtimeBase == expectedNameWin || runtimeBase == expectedNameDarwin)
	isAllowedDir := (filepath.Dir(absRuntimePath) == exeDir)

	if !isAllowedName || !isAllowedDir {
		errCode := "ERR_ILLEGAL_RUNTIME"
		errMsg := fmt.Sprintf("Illegal runtime binary '%s': runtime binary must resolve to %s or %s in the application directory ('%s')", runtimeBinary, expectedNameWin, expectedNameDarwin, exeDir)
		fmt.Fprintf(os.Stderr, "[NativeRoot][FATAL] %s: %s\n", errCode, errMsg)
		if !headless {
			showNativeErrorDialog("Lỗi bảo mật khởi động", errMsg)
		}
		os.Exit(1)
	}

	if _, statErr := os.Stat(absRuntimePath); os.IsNotExist(statErr) {
		errCode := "ERR_RUNTIME_NOT_FOUND"
		errMsg := fmt.Sprintf("Electron runtime binary not found at '%s'", absRuntimePath)
		fmt.Fprintf(os.Stderr, "[NativeRoot][FATAL] %s: %s\n", errCode, errMsg)
		if !headless {
			showNativeErrorDialog("Lỗi khởi động hệ thống", errMsg)
		}
		os.Exit(1)
	}

	// =========================================================================
	// TIER 2: HANDOFF & RUNTIME INVOCATION
	// =========================================================================
	exitCode, launchErr := launchRuntime(absRuntimePath, forwardArgs, result.BootstrapToken)
	if launchErr != nil {
		fmt.Fprintf(os.Stderr, "[NativeRoot][FATAL] Failed to invoke Electron runtime: %v\n", launchErr)
		os.Exit(1)
	}

	os.Exit(exitCode)
}
