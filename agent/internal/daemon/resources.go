package daemon

import (
	"bufio"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
)

// nodeResources is the host's current load as the kernel reports it, sent with every heartbeat so
// the panel can show it live. It is read from /proc by the unprivileged daemon and carries no
// per-process or per-account detail.
type nodeResources struct {
	Load1             float64 `json:"load_1"`
	Load5             float64 `json:"load_5"`
	Load15            float64 `json:"load_15"`
	CPUCount          int     `json:"cpu_count"`
	Processes         int     `json:"processes"`
	MemoryTotalKB     int64   `json:"memory_total_kb"`
	MemoryAvailableKB int64   `json:"memory_available_kb"`
	SwapTotalKB       int64   `json:"swap_total_kb"`
	SwapFreeKB        int64   `json:"swap_free_kb"`
	UptimeSeconds     int64   `json:"uptime_seconds"`
}

// readNodeResources reads procRoot (/proc in production). It returns nil when the load average,
// the one figure every Linux host has, cannot be read, so the heartbeat simply leaves the section out.
func readNodeResources(procRoot string) *nodeResources {
	loadFields := strings.Fields(readSmallFile(filepath.Join(procRoot, "loadavg")))
	if len(loadFields) < 4 {
		return nil
	}

	resources := &nodeResources{CPUCount: runtime.NumCPU()}
	resources.Load1, _ = strconv.ParseFloat(loadFields[0], 64)
	resources.Load5, _ = strconv.ParseFloat(loadFields[1], 64)
	resources.Load15, _ = strconv.ParseFloat(loadFields[2], 64)

	// The fourth field is "running/total" kernel scheduling entities (threads).
	if _, total, found := strings.Cut(loadFields[3], "/"); found {
		resources.Processes, _ = strconv.Atoi(total)
	}

	if uptime := strings.Fields(readSmallFile(filepath.Join(procRoot, "uptime"))); len(uptime) > 0 {
		seconds, _ := strconv.ParseFloat(uptime[0], 64)
		resources.UptimeSeconds = int64(seconds)
	}

	scanner := bufio.NewScanner(strings.NewReader(readSmallFile(filepath.Join(procRoot, "meminfo"))))

	for scanner.Scan() {
		name, rest, found := strings.Cut(scanner.Text(), ":")
		if !found {
			continue
		}

		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}

		kilobytes, _ := strconv.ParseInt(fields[0], 10, 64)

		switch name {
		case "MemTotal":
			resources.MemoryTotalKB = kilobytes
		case "MemAvailable":
			resources.MemoryAvailableKB = kilobytes
		case "SwapTotal":
			resources.SwapTotalKB = kilobytes
		case "SwapFree":
			resources.SwapFreeKB = kilobytes
		}
	}

	return resources
}

// readSmallFile returns up to 64 KiB of a /proc file, or "" when it cannot be read.
func readSmallFile(path string) string {
	file, err := os.Open(path)
	if err != nil {
		return ""
	}

	defer func() { _ = file.Close() }()

	buffer := make([]byte, 64<<10)
	n, _ := file.Read(buffer)

	return string(buffer[:n])
}
