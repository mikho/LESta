package backup

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestLimitedBufferRefusesToGrowPastItsCap(t *testing.T) {
	b := &limitedBuffer{max: 10}

	if _, err := b.Write([]byte("0123456789")); err != nil {
		t.Fatalf("writing exactly the cap: %v", err)
	}

	_, err := b.Write([]byte("x"))
	if !errors.Is(err, errNodeBackupTooLarge) {
		t.Fatalf("expected errNodeBackupTooLarge, got %v", err)
	}

	if b.Len() != 10 {
		t.Fatalf("buffer grew past its cap: %d", b.Len())
	}
}

func TestRestoreBudgetRefusesMoreThanItHolds(t *testing.T) {
	b := &restoreBudget{left: 100}

	if err := b.take(60); err != nil {
		t.Fatalf("first take: %v", err)
	}

	if err := b.take(41); !errors.Is(err, errNodeBackupTooLarge) {
		t.Fatalf("expected errNodeBackupTooLarge, got %v", err)
	}

	if err := b.take(-1); !errors.Is(err, errNodeBackupTooLarge) {
		t.Fatalf("a negative size must be refused, got %v", err)
	}

	if err := b.take(40); err != nil {
		t.Fatalf("exactly what is left must be accepted: %v", err)
	}
}

func TestCheckArtifactSize(t *testing.T) {
	path := filepath.Join(t.TempDir(), "a.tar.enc")

	if err := os.WriteFile(path, make([]byte, 64), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := checkArtifactSize(path, 64); err != nil {
		t.Fatalf("a file at the limit is fine: %v", err)
	}

	if err := checkArtifactSize(path, 63); !errors.Is(err, errNodeBackupTooLarge) {
		t.Fatalf("expected errNodeBackupTooLarge, got %v", err)
	}

	if err := checkArtifactSize(filepath.Join(t.TempDir(), "missing"), 1); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("a missing file must stay ErrNotExist so callers can answer artifact_not_found, got %v", err)
	}
}
