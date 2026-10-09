//go:build linux

package backup

import (
	"fmt"
	"runtime"
	"syscall"
)

// runAsAccount runs fn with the Linux filesystem user and group of the account:
// every file access inside fn is checked as that user, and files created are
// owned by it, while the process keeps its real and effective root identity so
// it can finish (rename, chown, clean up) afterwards. The filesystem identity is
// per thread, so fn runs on a thread locked to this goroutine.
func runAsAccount(uid, gid int, fn func() error) error {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	if err := syscall.Setfsgid(gid); err != nil {
		return fmt.Errorf("switching to the account's group: %w", err)
	}

	if err := syscall.Setfsuid(uid); err != nil {
		_ = syscall.Setfsgid(0)

		return fmt.Errorf("switching to the account's user: %w", err)
	}

	defer func() {
		_ = syscall.Setfsuid(0)
		_ = syscall.Setfsgid(0)
	}()

	return fn()
}
