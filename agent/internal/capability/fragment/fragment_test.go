package fragment

import (
	"context"
	"errors"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestDirStagesActivatesAndRemovesAFragment(t *testing.T) {
	dir := Dir{Path: t.TempDir(), Noun: "fragment"}

	staged, err := dir.WriteStaging("site", []byte("server {}\n"))
	if err != nil {
		t.Fatal(err)
	}

	if filepath.Base(staged) != ".site.conf.staging" {
		t.Fatalf("staging file is %s, not a dotfile the *.conf glob ignores", staged)
	}

	if err := dir.ActivateLive("site"); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(staged); !os.IsNotExist(err) {
		t.Fatalf("staging file still exists after activation: %v", err)
	}

	live, err := os.ReadFile(dir.LivePath("site"))
	if err != nil || string(live) != "server {}\n" {
		t.Fatalf("live fragment: %q, %v", live, err)
	}

	if err := dir.RemoveLive("site"); err != nil {
		t.Fatal(err)
	}

	if err := dir.RemoveLive("site"); err != nil {
		t.Fatalf("removing an already-absent fragment must be idempotent, got %v", err)
	}
}

func TestDirDiscardStagingLeavesTheLiveFragmentAlone(t *testing.T) {
	dir := Dir{Path: t.TempDir(), Noun: "fragment"}

	if err := os.WriteFile(dir.LivePath("site"), []byte("live"), 0o644); err != nil {
		t.Fatal(err)
	}

	if _, err := dir.WriteStaging("site", []byte("candidate")); err != nil {
		t.Fatal(err)
	}

	dir.DiscardStaging("site")

	if _, err := os.Stat(dir.StagingPath("site")); !os.IsNotExist(err) {
		t.Fatalf("staging file survived discard: %v", err)
	}

	live, _ := os.ReadFile(dir.LivePath("site"))
	if string(live) != "live" {
		t.Fatalf("live fragment changed: %q", live)
	}
}

func TestDirErrorsNameTheNoun(t *testing.T) {
	dir := Dir{Path: filepath.Join(t.TempDir(), "missing"), Noun: "Match block"}

	_, err := dir.WriteStaging("acct", []byte("x"))
	if err == nil || !strings.Contains(err.Error(), "writing staged Match block for acct") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func syntheticFixture(t *testing.T) (SyntheticConfig, string) {
	t.Helper()

	live := t.TempDir()
	base := filepath.Join(t.TempDir(), "main.conf")
	liveGlob := filepath.Join(live, "*.conf")

	if err := os.WriteFile(base, []byte("worker 1;\ninclude "+liveGlob+";\nend;\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	return SyntheticConfig{
		Service:          "test",
		BaseConfPath:     base,
		BaseConfName:     "main.conf",
		Dir:              Dir{Path: live, Noun: "fragment"},
		FragmentsDirName: "fragments",
		IncludeKeyword:   "include",
	}, live
}

func TestSyntheticConfigIncludesEveryOtherLiveFragmentPlusTheCandidate(t *testing.T) {
	cfg, live := syntheticFixture(t)

	for _, name := range []string{"a.conf", "b.conf"} {
		if err := os.WriteFile(filepath.Join(live, name), []byte(name), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	candidate := filepath.Join(t.TempDir(), "candidate")
	if err := os.WriteFile(candidate, []byte("new b"), 0o644); err != nil {
		t.Fatal(err)
	}

	path, cleanup, err := cfg.Build("b", candidate)
	if err != nil {
		t.Fatal(err)
	}
	defer cleanup()

	fragDir := filepath.Join(filepath.Dir(path), "fragments")

	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(string(content), "include "+filepath.Join(fragDir, "*.conf")+";") {
		t.Fatalf("include line was not rewritten: %s", content)
	}

	a, _ := os.ReadFile(filepath.Join(fragDir, "a.conf"))
	b, _ := os.ReadFile(filepath.Join(fragDir, "b.conf"))

	if string(a) != "a.conf" || string(b) != "new b" {
		t.Fatalf("scratch fragments: a=%q b=%q", a, b)
	}

	if !strings.HasPrefix(filepath.Base(filepath.Dir(path)), "lesta-test-validate-") {
		t.Fatalf("scratch directory %s is not named after the service", filepath.Dir(path))
	}
}

func TestSyntheticConfigAbsentCandidateCallsThePlaceholderWhenNothingElseIsLive(t *testing.T) {
	cfg, _ := syntheticFixture(t)
	cfg.Placeholder = func(fragDir, _ string) error {
		return os.WriteFile(filepath.Join(fragDir, "placeholder.conf"), []byte("p"), 0o644)
	}
	cfg.EditLines = func(lines []string, tmpDir string) []string {
		return append(lines, "log "+tmpDir+";")
	}

	path, cleanup, err := cfg.Build("only", "")
	if err != nil {
		t.Fatal(err)
	}
	defer cleanup()

	if _, err := os.Stat(filepath.Join(filepath.Dir(path), "fragments", "placeholder.conf")); err != nil {
		t.Fatalf("placeholder was not written: %v", err)
	}

	content, _ := os.ReadFile(path)
	if !strings.Contains(string(content), "log "+filepath.Dir(path)+";") {
		t.Fatalf("EditLines did not apply: %s", content)
	}
}

func TestSyntheticConfigRewriteIncludeAndMissingIncludeLine(t *testing.T) {
	cfg, _ := syntheticFixture(t)
	cfg.RewriteInclude = func(_, _, fragGlob string) string { return "IncludeOptional " + fragGlob }

	path, cleanup, err := cfg.Build("x", "")
	if err != nil {
		t.Fatal(err)
	}
	cleanup()

	if _, err := os.Stat(filepath.Dir(path)); !os.IsNotExist(err) {
		t.Fatalf("cleanup left the scratch tree: %v", err)
	}

	cfg.IncludeKeyword = "nothinghere"

	if _, _, err := cfg.Build("x", ""); err == nil || !strings.Contains(err.Error(), "has no `nothinghere` line") {
		t.Fatalf("expected the missing include line to be reported, got %v", err)
	}
}

func TestPollUntilReturnsTheLastErrorAtTheDeadline(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()

	calls := 0
	err := PollUntil(ctx, func() error {
		calls++

		return errors.New("not yet")
	})

	if err == nil || !strings.Contains(err.Error(), "not yet") {
		t.Fatalf("expected the last probe error in the deadline error, got %v", err)
	}

	if calls < 1 {
		t.Fatal("probe never ran")
	}
}

func TestWaitDialSucceedsOnceSomethingListens(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = ln.Close() }()

	go func() {
		conn, err := ln.Accept()
		if err == nil {
			_ = conn.Close()
		}
	}()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	if err := WaitDial(ctx, "tcp", ln.Addr().String()); err != nil {
		t.Fatal(err)
	}
}

func TestReloadFoldsTheOutputIntoTheError(t *testing.T) {
	if err := Reload(exec.Command("true"), "true reload"); err != nil {
		t.Fatal(err)
	}

	err := Reload(exec.Command("sh", "-c", "echo boom >&2; exit 3"), "sh reload")
	if err == nil || !strings.Contains(err.Error(), "sh reload failed") || !strings.Contains(err.Error(), "boom") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestResultsCarryTheLiveDigestAndTheCurrentGeneration(t *testing.T) {
	live := t.TempDir()
	store := generation.New(t.TempDir())
	results := Results{Store: store, LiveDir: live}

	op := protocol.OperationEnvelope{ProtocolVersion: "1", Capability: "web.nginx.v1", ResourceID: "r1", IdempotencyKey: "k", CorrelationID: "c", DesiredStateVersion: 3}

	if results.CurrentGenerationIDOrNone("r1") != "none" || results.CurrentObservedVersionOrZero("r1") != 0 {
		t.Fatal("a resource without history must report none and 0")
	}

	rejected, err := results.Rejected(op, "bad", "no good", "domain")
	if err != nil {
		t.Fatal(err)
	}

	if rejected.Status != protocol.StatusRejected || rejected.GenerationID != "none" || rejected.ObservedStateVersion != 0 || *rejected.Errors[0].Field != "domain" {
		t.Fatalf("unexpected rejected result: %+v", rejected)
	}

	expectedDigest, _ := generation.ComputeDigest(live)
	if rejected.ObservedStateDigest != expectedDigest {
		t.Fatalf("digest %s is not the live directory's %s", rejected.ObservedStateDigest, expectedDigest)
	}

	if err := store.Activate("r1", 1, []byte("x"), false, expectedDigest, 3); err != nil {
		t.Fatal(err)
	}

	applied, err := results.Build(op, protocol.StatusApplied, 3, results.CurrentGenerationIDOrNone("r1"), nil)
	if err != nil {
		t.Fatal(err)
	}

	if applied.GenerationID != "1" || results.CurrentObservedVersionOrZero("r1") != 3 || len(applied.Errors) != 0 {
		t.Fatalf("unexpected applied result: %+v", applied)
	}

	_, err = results.RejectedFromValidationError(op, errors.New("harness broke"))
	if err == nil || err.Error() != "harness broke" {
		t.Fatalf("a plain error must pass through, got %v", err)
	}

	viaValidation, err := results.RejectedFromValidationError(op, &ValidationError{Code: "c", Message: "m"})
	if err != nil || viaValidation.Errors[0].Code != "c" || viaValidation.Errors[0].Field != nil {
		t.Fatalf("unexpected validation rejection: %+v, %v", viaValidation, err)
	}
}

func TestResultsUseTheCustomDigestWhenSet(t *testing.T) {
	results := Results{Store: generation.New(t.TempDir()), Digest: func(resourceID string) (string, error) {
		return "sha256:" + resourceID, nil
	}}

	res, err := results.Build(protocol.OperationEnvelope{ResourceID: "r9"}, protocol.StatusApplied, 1, "1", nil)
	if err != nil || res.ObservedStateDigest != "sha256:r9" {
		t.Fatalf("unexpected: %+v, %v", res, err)
	}
}
