//go:build !windows && !darwin
// +build !windows,!darwin

package main

import (
	"fmt"
	"os"
)

func showNativeErrorDialog(title, message string) {
	fmt.Fprintf(os.Stderr, "\n=======================================================\n%s\n-------------------------------------------------------\n%s\n=======================================================\n\n", title, message)
}
