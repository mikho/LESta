package generation_test

import (
	"testing"

	"github.com/mikho/LESta/agent/internal/generation"
)

func TestNextGenerationStartsAtOne(t *testing.T) {
	store := generation.New(t.TempDir())

	n, err := store.NextGeneration("resource-a")
	if err != nil {
		t.Fatalf("NextGeneration: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected first generation to be 1, got %d", n)
	}
}

func TestActivateSetsCurrentAndNotPrevious(t *testing.T) {
	store := generation.New(t.TempDir())

	if err := store.Activate("resource-a", 1, []byte("gen1"), false, "sha256:aaa", 1); err != nil {
		t.Fatalf("Activate: %v", err)
	}

	cur, ok, err := store.CurrentGeneration("resource-a")
	if err != nil || !ok || cur != 1 {
		t.Fatalf("expected current=1, got cur=%d ok=%v err=%v", cur, ok, err)
	}

	_, ok, err = store.PreviousGeneration("resource-a")
	if err != nil {
		t.Fatalf("PreviousGeneration: %v", err)
	}
	if ok {
		t.Fatal("expected no previous generation after the first activation")
	}
}

func TestActivateSwapsPreviousOnSecondGeneration(t *testing.T) {
	store := generation.New(t.TempDir())

	mustActivate(t, store, "resource-a", 1, "gen1", "sha256:aaa")
	mustActivate(t, store, "resource-a", 2, "gen2", "sha256:bbb")

	cur, _, _ := store.CurrentGeneration("resource-a")
	prev, ok, _ := store.PreviousGeneration("resource-a")

	if cur != 2 {
		t.Fatalf("expected current=2, got %d", cur)
	}
	if !ok || prev != 1 {
		t.Fatalf("expected previous=1, got prev=%d ok=%v", prev, ok)
	}
}

func TestNextGenerationIsNotSpentByAFailedAttempt(t *testing.T) {
	store := generation.New(t.TempDir())

	n, err := store.NextGeneration("resource-a")
	if err != nil {
		t.Fatalf("NextGeneration: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected 1, got %d", n)
	}

	// Simulate a rejected attempt: never call Activate for this candidate
	// generation number. Retrying must reuse the same number.
	n, err = store.NextGeneration("resource-a")
	if err != nil {
		t.Fatalf("NextGeneration: %v", err)
	}
	if n != 1 {
		t.Fatalf("expected generation number to be reused after a non-activated attempt, got %d", n)
	}
}

func TestRollbackReRunsPreviousGenerationContentThroughActivate(t *testing.T) {
	store := generation.New(t.TempDir())

	mustActivate(t, store, "resource-a", 1, "good content", "sha256:aaa")
	mustActivate(t, store, "resource-a", 2, "bad content", "sha256:bbb")

	// A failed apply's rollback re-runs the previous generation's own stored
	// content through Activate again, landing it in a new generation number.
	prevN, ok, err := store.PreviousGeneration("resource-a")
	if err != nil || !ok {
		t.Fatalf("PreviousGeneration: n=%d ok=%v err=%v", prevN, ok, err)
	}

	content, deleted, err := store.ReadContent("resource-a", prevN)
	if err != nil || deleted {
		t.Fatalf("ReadContent: content=%q deleted=%v err=%v", content, deleted, err)
	}

	n, err := store.NextGeneration("resource-a")
	if err != nil {
		t.Fatalf("NextGeneration: %v", err)
	}

	if err := store.Activate("resource-a", n, content, false, "sha256:aaa", 1); err != nil {
		t.Fatalf("Activate (rollback): %v", err)
	}

	cur, _, _ := store.CurrentGeneration("resource-a")
	if cur != 3 {
		t.Fatalf("expected rollback to land in generation 3, got %d", cur)
	}

	manifest, err := store.ReadManifest("resource-a", cur)
	if err != nil {
		t.Fatalf("ReadManifest: %v", err)
	}
	if manifest.Digest != "sha256:aaa" {
		t.Fatalf("expected the rolled-back generation to carry the restored digest, got %s", manifest.Digest)
	}
}

func TestActivateWithDeletedRecordsNoContent(t *testing.T) {
	store := generation.New(t.TempDir())

	mustActivate(t, store, "resource-a", 1, "gen1", "sha256:aaa")

	if err := store.Activate("resource-a", 2, nil, true, "sha256:empty", 1); err != nil {
		t.Fatalf("Activate (deletion): %v", err)
	}

	_, deleted, err := store.ReadContent("resource-a", 2)
	if err != nil {
		t.Fatalf("ReadContent: %v", err)
	}
	if !deleted {
		t.Fatal("expected generation 2 to be recorded as deleted")
	}
}

func TestPruneRetainsOnlyLastFiveGenerations(t *testing.T) {
	store := generation.New(t.TempDir())
	store.Keep = 5

	for i := 1; i <= 8; i++ {
		mustActivate(t, store, "resource-a", i, "gen", "sha256:x")
	}

	for i := 1; i <= 3; i++ {
		if _, _, err := store.ReadContent("resource-a", i); err == nil {
			t.Fatalf("expected generation %d to have been pruned", i)
		}
	}

	for i := 4; i <= 8; i++ {
		if _, _, err := store.ReadContent("resource-a", i); err != nil {
			t.Fatalf("expected generation %d to still be retained: %v", i, err)
		}
	}
}

func TestHasCurrentFalseForUnknownResource(t *testing.T) {
	store := generation.New(t.TempDir())

	has, err := store.HasCurrent("never-created")
	if err != nil {
		t.Fatalf("HasCurrent: %v", err)
	}
	if has {
		t.Fatal("expected HasCurrent to be false for a resource with no generation history")
	}
}

func mustActivate(t *testing.T, store *generation.Store, resourceID string, n int, content, digest string) {
	t.Helper()

	if err := store.Activate(resourceID, n, []byte(content), false, digest, 1); err != nil {
		t.Fatalf("Activate(%s, %d): %v", resourceID, n, err)
	}
}
