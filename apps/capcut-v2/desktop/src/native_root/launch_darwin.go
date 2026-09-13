//go:build darwin
// +build darwin

package main

import (
	"os"
	"os/exec"
	"syscall"
)

func launchRuntime(runtimePath string, args []string, token string) (int, error) {
	env := append(os.Environ(), "_2TOOLNE_BOOTSTRAP_TOKEN="+token)

	// If child spawn mode is explicitly requested (e.g. by integration tests)
	if os.Getenv("_2TOOLNE_LAUNCH_SPAWN") == "1" {
		cmd := exec.Command(runtimePath, args...)
		cmd.Env = env
		cmd.Stdin = os.Stdin
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		if err := cmd.Run(); err != nil {
			if exitErr, ok := err.(*exec.ExitError); ok {
				return exitErr.ExitCode(), nil
			}
			return 1, err
		}
		return 0, nil
	}

	// Production macOS mode: Atomically replace process image via execve
	// Preserves PID, Dock icon, Window server registration, and OS permissions
	execArgs := append([]string{runtimePath}, args...)
	err := syscall.Exec(runtimePath, execArgs, env)
	// Exec only returns if an error occurred
	return 1, err
}
