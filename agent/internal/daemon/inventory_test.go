package daemon

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fakeStatfs gives every mount point the same sizes, and fails for /broken.
func fakeStatfs(mountPoint string) (uint64, uint64, uint64, bool) {
	if mountPoint == "/broken" {
		return 0, 0, 0, false
	}

	return 1000, 400, 300, true
}

func writeFile(t *testing.T, content string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "file")
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func mountPoints(filesystems []filesystemUsage) string {
	points := make([]string, 0, len(filesystems))
	for _, filesystem := range filesystems {
		points = append(points, filesystem.MountPoint)
	}

	return strings.Join(points, " ")
}

func TestReadFilesystemsReportsEachDiskOnceAtItsShortestMountPoint(t *testing.T) {
	long := "/" + strings.Repeat("a", 299)
	mounts := strings.Join([]string{
		"/dev/sda1 /var/lib/lesta/agent/daemon-state ext4 rw 0 0", // the sandbox's bind mount, listed first
		"/dev/sda1 / ext4 rw,relatime 0 0",
		"/dev/sda1 /usr ext4 ro,relatime 0 0",
		"/dev/sdb1 /home xfs rw 0 0",
		"/dev/sdc1 /mnt/with\\040space ext4 rw 0 0",
		"/dev/sdd1 /mnt/../etc ext4 rw 0 0",
		"/dev/sde1 " + long + " ext4 rw 0 0",
		"tmpfs /run tmpfs rw 0 0",
		"overlay /var/lib/docker/x overlay rw 0 0",
		"/dev/sdf1 /boot/efi vfat rw 0 0",
		"/dev/sdg1 /broken ext4 rw 0 0",
		"short line",
	}, "\n")

	got := readFilesystems(writeFile(t, mounts), fakeStatfs)

	if mountPoints(got) != "/ /boot/efi /home" {
		t.Fatalf("got %q", mountPoints(got))
	}

	if got[0].TotalBytes != 1000 || got[0].FreeBytes != 400 || got[0].AvailableBytes != 300 {
		t.Fatalf("sizes: %+v", got[0])
	}
}

func TestReadFilesystemsKeepsAtMostSixteen(t *testing.T) {
	var lines []string
	for i := 0; i < 17; i++ {
		lines = append(lines, fmt.Sprintf("/dev/vd%c /data%02d ext4 rw 0 0", 'a'+i, i))
	}

	got := readFilesystems(writeFile(t, strings.Join(lines, "\n")), fakeStatfs)
	if len(got) != 16 || got[15].MountPoint != "/data15" {
		t.Fatalf("got %d: %q", len(got), mountPoints(got))
	}
}

func TestReadFilesystemsWithoutAMountTableIsEmpty(t *testing.T) {
	got := readFilesystems(filepath.Join(t.TempDir(), "missing"), fakeStatfs)
	if got == nil || len(got) != 0 {
		t.Fatalf("expected an empty list, got %#v", got)
	}
}

func TestReadStackPackagesKeepsInstalledStackPackagesWithValidVersions(t *testing.T) {
	status := strings.Join([]string{
		"Package: nginx",
		"Status: install ok installed",
		"Priority: optional",
		"Version: 1.24.0-2ubuntu7",
		"Description: small, powerful web server",
		" Version: 9.9 (a continuation line is never a field)",
		"",
		"Package: apache2",
		"Status: deinstall ok config-files",
		"Version: 2.4.58-1ubuntu8",
		"",
		"Package: php8.4-fpm",
		"Status: install ok installed",
		"Version: 8.4.15-1+ubuntu24.04.1+deb.sury.org+1",
		"",
		"Package: mariadb-server",
		"Status: install ok installed",
		"Version: 1:11.4.14 maria",
		"",
		"Package: bind9",
		"Status: install ok installed",
		"Version: " + strings.Repeat("1", 65),
		"",
		"Package: vim",
		"Status: install ok installed",
		"Version: 2:9.1.0016-1ubuntu7",
		"",
		"Package: cron",
		"Status: install ok installed",
		"Version: 3.0pl1-184ubuntu2",
		"",
		"Package: spamd",
		"Status: install ok installed",
	}, "\n") // the last stanza is cut off before its Version

	got := readStackPackages(writeFile(t, status))

	want := []installedPackage{
		{Name: "cron", Version: "3.0pl1-184ubuntu2"},
		{Name: "nginx", Version: "1.24.0-2ubuntu7"},
		{Name: "php8.4-fpm", Version: "8.4.15-1+ubuntu24.04.1+deb.sury.org+1"},
	}

	if fmt.Sprint(got) != fmt.Sprint(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
}

func TestReadStackPackagesGivesAnEmptyListAboveEightMiB(t *testing.T) {
	stanza := "Package: nginx\nStatus: install ok installed\nVersion: 1.24.0\n\n"
	content := stanza + strings.Repeat("x", maxDpkgStatusSize+1-len(stanza))

	if got := readStackPackages(writeFile(t, content)); got == nil || len(got) != 0 {
		t.Fatalf("expected an empty list, got %v", got)
	}

	if got := readStackPackages(writeFile(t, stanza)); len(got) != 1 {
		t.Fatalf("the same stanza alone should count, got %v", got)
	}
}

func TestReadStackPackagesWithoutAStatusFileIsEmpty(t *testing.T) {
	got := readStackPackages(filepath.Join(t.TempDir(), "missing"))
	if got == nil || len(got) != 0 {
		t.Fatalf("expected an empty list, got %#v", got)
	}
}
