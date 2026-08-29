package main

import (
	"os"
	"path/filepath"
	"testing"
)

// TestApachePortForProfile covers the pure mapping apacheProductionConfig
// relies on: "both" selects Apache's loopback-backend port (8080); every
// other value, including empty (a missing/unreadable web-profile file's own
// safe default from readWebProfile), keeps 80, Apache as the public
// listener.
func TestApachePortForProfile(t *testing.T) {
	cases := []struct {
		profile string
		want    int
	}{
		{profile: "both", want: 8080},
		{profile: "apache", want: 80},
		{profile: "", want: 80},
		{profile: "garbage", want: 80},
	}

	for _, tc := range cases {
		if got := apachePortForProfile(tc.profile); got != tc.want {
			t.Errorf("apachePortForProfile(%q) = %d, want %d", tc.profile, got, tc.want)
		}
	}
}

// TestReadWebProfile covers the file-reading half: trimmed content on
// success, "" on a missing file. A real /etc/lesta/web-profile is never
// touched by this test; readWebProfile takes an explicit path precisely so
// tests never need to.
func TestReadWebProfile(t *testing.T) {
	t.Run("missing file returns empty string", func(t *testing.T) {
		if got := readWebProfile(filepath.Join(t.TempDir(), "does-not-exist")); got != "" {
			t.Errorf("readWebProfile(missing) = %q, want empty string", got)
		}
	})

	t.Run("trims surrounding whitespace and a trailing newline", func(t *testing.T) {
		path := filepath.Join(t.TempDir(), "web-profile")
		writeFile(t, path, "both\n")

		if got := readWebProfile(path); got != "both" {
			t.Errorf("readWebProfile(%q) = %q, want %q", path, got, "both")
		}
	})

	t.Run("a plain apache profile round-trips unchanged", func(t *testing.T) {
		path := filepath.Join(t.TempDir(), "web-profile")
		writeFile(t, path, "apache")

		if got := readWebProfile(path); got != "apache" {
			t.Errorf("readWebProfile(%q) = %q, want %q", path, got, "apache")
		}
	})
}

// TestApacheProductionConfigPortSelection is the integration of both halves,
// via a real webProfilePath override: apacheProductionConfig itself always
// reads the fixed production constant, so this exercises
// apachePortForProfile(readWebProfile(...)) exactly as that function
// composes them, against a real temp file standing in for
// /etc/lesta/web-profile.
func TestApacheProductionConfigPortSelection(t *testing.T) {
	t.Run("both profile selects port 8080", func(t *testing.T) {
		path := filepath.Join(t.TempDir(), "web-profile")
		writeFile(t, path, "both")

		if got := apachePortForProfile(readWebProfile(path)); got != 8080 {
			t.Errorf("port for a 'both' profile file = %d, want 8080", got)
		}
	})

	t.Run("apache profile keeps port 80", func(t *testing.T) {
		path := filepath.Join(t.TempDir(), "web-profile")
		writeFile(t, path, "apache")

		if got := apachePortForProfile(readWebProfile(path)); got != 80 {
			t.Errorf("port for an 'apache' profile file = %d, want 80", got)
		}
	})

	t.Run("missing web-profile file keeps the safe default of port 80", func(t *testing.T) {
		path := filepath.Join(t.TempDir(), "never-written")

		if got := apachePortForProfile(readWebProfile(path)); got != 80 {
			t.Errorf("port for a missing profile file = %d, want 80", got)
		}
	})
}

func writeFile(t *testing.T, path, content string) {
	t.Helper()

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("writing %s: %v", path, err)
	}
}
