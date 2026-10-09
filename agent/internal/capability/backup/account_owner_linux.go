//go:build linux

package backup

import (
	"os"
	"syscall"
)

type owner struct{ uid, gid uint32 }

// dirOwner is the owner of a directory, so a file the root helper writes there
// stays deletable by the daemon that created the directory.
func dirOwner(info os.FileInfo) owner {
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		return owner{st.Uid, st.Gid}
	}

	return owner{uint32(os.Getuid()), uint32(os.Getgid())}
}

func freeBytes(dir string) uint64 {
	var st syscall.Statfs_t
	if err := syscall.Statfs(dir, &st); err != nil {
		return 0
	}

	return uint64(st.Bavail) * uint64(st.Bsize)
}
