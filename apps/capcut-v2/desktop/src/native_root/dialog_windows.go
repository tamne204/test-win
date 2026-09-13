//go:build windows
// +build windows

package main

import (
	"syscall"
	"unsafe"
)

func showNativeErrorDialog(title, message string) {
	user32 := syscall.NewLazyDLL("user32.dll")
	procMessageBoxW := user32.NewProc("MessageBoxW")

	titlePtr, err := syscall.UTF16PtrFromString(title)
	if err != nil {
		return
	}
	msgPtr, err := syscall.UTF16PtrFromString(message)
	if err != nil {
		return
	}

	const (
		MB_OK          = 0x00000000
		MB_ICONERROR   = 0x00000010
		MB_SYSTEMMODAL = 0x00001000
	)

	procMessageBoxW.Call(
		0,
		uintptr(unsafe.Pointer(msgPtr)),
		uintptr(unsafe.Pointer(titlePtr)),
		uintptr(MB_OK|MB_ICONERROR|MB_SYSTEMMODAL),
	)
}
