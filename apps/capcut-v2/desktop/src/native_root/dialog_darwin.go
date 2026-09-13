//go:build darwin
// +build darwin

package main

import (
	"fmt"
	"os/exec"
)

func showNativeErrorDialog(title, message string) {
	script := fmt.Sprintf(`display alert %q message %q as critical buttons {"Đóng"} default button "Đóng"`, title, message)
	_ = exec.Command("osascript", "-e", script).Run()
}
