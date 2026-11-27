package daemon

import (
	"bufio"
	"io"
	"os"
	"regexp"
	"sort"
	"strings"
	"syscall"
)

const (
	maxMountsBytes    = 64 << 10
	maxFilesystems    = 16
	maxMountPointLen  = 255
	maxDpkgStatusSize = 8 << 20
	maxPackages       = 64
)

// filesystemUsage is one disk's size and free space, as statfs reports it at its mount point.
type filesystemUsage struct {
	MountPoint     string `json:"mount_point"`
	TotalBytes     uint64 `json:"total_bytes"`
	FreeBytes      uint64 `json:"free_bytes"`
	AvailableBytes uint64 `json:"available_bytes"`
}

// installedPackage is one LESta stack package dpkg reports as installed, with its version.
type installedPackage struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}

// diskFilesystemTypes are the file system types that hold data on a disk; tmpfs, overlay, proc
// and the like are left out.
var diskFilesystemTypes = map[string]bool{
	"btrfs": true, "ext2": true, "ext3": true, "ext4": true, "f2fs": true, "vfat": true, "xfs": true, "zfs": true,
}

var mountPointPattern = regexp.MustCompile(`^/[A-Za-z0-9._/-]*$`)

// statfsUsage reads a mount point's sizes; ok is false when statfs fails.
type statfsUsage func(mountPoint string) (total, free, available uint64, ok bool)

func syscallStatfs(mountPoint string) (uint64, uint64, uint64, bool) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(mountPoint, &stat); err != nil {
		return 0, 0, 0, false
	}

	blockSize := uint64(stat.Bsize) //nolint:gosec // the kernel's block size is never negative

	return stat.Blocks * blockSize, stat.Bfree * blockSize, stat.Bavail * blockSize, true
}

// readFilesystems lists the node's disks from a mount table (/proc/self/mounts in production):
// each device once, at its shortest mount point, since sandboxes mount writable directories of the
// same disk a second time. Sorted by mount point, at most 16. Every value goes to the panel, which
// checks it again.
func readFilesystems(mountsPath string, statfs statfsUsage) []filesystemUsage {
	shortest := map[string]string{}

	for _, line := range strings.Split(readLimited(mountsPath, maxMountsBytes), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 3 || !diskFilesystemTypes[fields[2]] {
			continue
		}

		device, mountPoint := fields[0], fields[1]
		if !validMountPoint(mountPoint) {
			continue
		}

		if current, seen := shortest[device]; !seen || len(mountPoint) < len(current) {
			shortest[device] = mountPoint
		}
	}

	mountPoints := make([]string, 0, len(shortest))
	for _, mountPoint := range shortest {
		mountPoints = append(mountPoints, mountPoint)
	}

	sort.Strings(mountPoints)

	filesystems := []filesystemUsage{}

	for _, mountPoint := range mountPoints {
		if len(filesystems) == maxFilesystems {
			break
		}

		total, free, available, ok := statfs(mountPoint)
		if !ok {
			continue
		}

		filesystems = append(filesystems, filesystemUsage{MountPoint: mountPoint, TotalBytes: total, FreeBytes: free, AvailableBytes: available})
	}

	return filesystems
}

// validMountPoint accepts an absolute path of plain characters, at most 255 bytes, without "..".
// An escaped space (\040) fails the character check and is left out.
func validMountPoint(mountPoint string) bool {
	return len(mountPoint) <= maxMountPointLen &&
		mountPointPattern.MatchString(mountPoint) &&
		!strings.Contains(mountPoint, "..")
}

// stackPackages is every package a .install/services/*/install.sh installs (gathered from the
// scripts; update it with them). Every phpX.Y-fpm counts as well, see isStackPackage.
var stackPackages = map[string]bool{
	"apache2":                       true,
	"bind9":                         true,
	"bind9-utils":                   true,
	"clamav-daemon":                 true,
	"clamav-freshclam":              true,
	"cron":                          true,
	"dovecot-imapd":                 true,
	"dovecot-lmtpd":                 true,
	"dovecot-sieve":                 true,
	"exim4-daemon-heavy":            true,
	"libmail-spf-perl":              true,
	"libnginx-mod-http-modsecurity": true,
	"mariadb-client":                true,
	"mariadb-server":                true,
	"modsecurity-crs":               true,
	"nginx":                         true,
	"spamassassin":                  true,
	"spamd":                         true,
}

var (
	phpFpmPackage  = regexp.MustCompile(`^php[0-9]+\.[0-9]+-fpm$`)
	packageVersion = regexp.MustCompile(`^[A-Za-z0-9.+~:-]{1,64}$`)
)

func isStackPackage(name string) bool {
	return stackPackages[name] || phpFpmPackage.MatchString(name)
}

// readStackPackages lists the installed LESta stack packages and their versions from dpkg's status
// file (/var/lib/dpkg/status in production), without starting dpkg-query. A file above 8 MiB gives
// an empty list. Sorted by name, at most 64.
func readStackPackages(statusPath string) []installedPackage {
	packages := []installedPackage{}

	file, err := os.Open(statusPath)
	if err != nil {
		return packages
	}

	defer func() { _ = file.Close() }()

	raw, err := io.ReadAll(io.LimitReader(file, maxDpkgStatusSize+1))
	if err != nil || len(raw) > maxDpkgStatusSize {
		return packages
	}

	seen := map[string]bool{}
	var name, status, version string

	flush := func() {
		if isStackPackage(name) && status == "install ok installed" && packageVersion.MatchString(version) && !seen[name] {
			seen[name] = true
			packages = append(packages, installedPackage{Name: name, Version: version})
		}

		name, status, version = "", "", ""
	}

	scanner := bufio.NewScanner(strings.NewReader(string(raw)))
	scanner.Buffer(make([]byte, 64<<10), maxDpkgStatusSize)

	for scanner.Scan() {
		line := scanner.Text()
		if line == "" {
			flush()

			continue
		}

		// Continuation lines (descriptions, conffiles) start with a space.
		key, value, found := strings.Cut(line, ": ")
		if !found || strings.HasPrefix(line, " ") {
			continue
		}

		switch key {
		case "Package":
			name = value
		case "Status":
			status = value
		case "Version":
			version = value
		}
	}

	flush()

	sort.Slice(packages, func(a, b int) bool { return packages[a].Name < packages[b].Name })

	if len(packages) > maxPackages {
		packages = packages[:maxPackages]
	}

	return packages
}

// readLimited returns up to limit bytes of a file, or "" when it cannot be read.
func readLimited(path string, limit int64) string {
	file, err := os.Open(path)
	if err != nil {
		return ""
	}

	defer func() { _ = file.Close() }()

	raw, _ := io.ReadAll(io.LimitReader(file, limit))

	return string(raw)
}
