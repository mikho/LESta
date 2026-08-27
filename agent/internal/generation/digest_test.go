package generation_test

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/generation"
)

func TestComputeDigestEmptyDirIsWellDefined(t *testing.T) {
	dir := t.TempDir()

	digest, err := generation.ComputeDigest(dir)
	if err != nil {
		t.Fatalf("ComputeDigest: %v", err)
	}

	if digest != "sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" {
		t.Fatalf("expected the fixed empty-manifest digest, got %s", digest)
	}
}

func TestComputeDigestIsPathSensitive(t *testing.T) {
	dirA := t.TempDir()
	dirB := t.TempDir()

	// Same byte-identical content, different filenames: the digest must differ,
	// because two byte-identical fragments belonging to different domains must
	// not digest the same.
	writeFile(t, filepath.Join(dirA, "one.conf"), "identical content\n")
	writeFile(t, filepath.Join(dirB, "two.conf"), "identical content\n")

	digestA, err := generation.ComputeDigest(dirA)
	if err != nil {
		t.Fatalf("ComputeDigest dirA: %v", err)
	}
	digestB, err := generation.ComputeDigest(dirB)
	if err != nil {
		t.Fatalf("ComputeDigest dirB: %v", err)
	}

	if digestA == digestB {
		t.Fatalf("expected digests to differ by path, both were %s", digestA)
	}
}

func TestComputeDigestIgnoresNonConfFiles(t *testing.T) {
	dir := t.TempDir()

	writeFile(t, filepath.Join(dir, "site.conf"), "server {}\n")
	writeFile(t, filepath.Join(dir, ".site.conf.staging"), "server { broken\n")
	writeFile(t, filepath.Join(dir, "README.md"), "not a fragment\n")

	digestWithExtras, err := generation.ComputeDigest(dir)
	if err != nil {
		t.Fatalf("ComputeDigest: %v", err)
	}

	dirOnlyReal := t.TempDir()
	writeFile(t, filepath.Join(dirOnlyReal, "site.conf"), "server {}\n")

	digestOnlyReal, err := generation.ComputeDigest(dirOnlyReal)
	if err != nil {
		t.Fatalf("ComputeDigest: %v", err)
	}

	if digestWithExtras != digestOnlyReal {
		t.Fatalf("expected non-.conf files to be ignored: %s != %s", digestWithExtras, digestOnlyReal)
	}
}

// TestComputeDigestMatchesHandComputedSha256sum proves the digest is
// reproducible by hand with coreutils: build the exact `sha256sum`-format
// manifest ourselves and hash it independently of the package under test.
func TestComputeDigestMatchesHandComputedSha256sum(t *testing.T) {
	dir := t.TempDir()

	writeFile(t, filepath.Join(dir, "a.conf"), "alpha\n")
	writeFile(t, filepath.Join(dir, "b.conf"), "beta\n")

	digest, err := generation.ComputeDigest(dir)
	if err != nil {
		t.Fatalf("ComputeDigest: %v", err)
	}

	sumA := sha256.Sum256([]byte("alpha\n"))
	sumB := sha256.Sum256([]byte("beta\n"))
	manifest := fmt.Sprintf("%s  a.conf\n%s  b.conf\n", hex.EncodeToString(sumA[:]), hex.EncodeToString(sumB[:]))
	want := sha256.Sum256([]byte(manifest))

	if digest != "sha256:"+hex.EncodeToString(want[:]) {
		t.Fatalf("digest %s did not match hand-computed sha256sum-format manifest hash", digest)
	}
}

func writeFile(t *testing.T, path, content string) {
	t.Helper()

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("writing %s: %v", path, err)
	}
}
