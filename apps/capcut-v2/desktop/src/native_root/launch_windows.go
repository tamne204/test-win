//go:build windows
// +build windows

package main

import (
	"os"
	"os/exec"
)

func launchRuntime(runtimePath string, args []string, token string) (int, error) {
	cmd := exec.Command(runtimePath, args...)
	cmd.Env = append(os.Environ(), "_2TOOLNE_BOOTSTRAP_TOKEN="+token)
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
