package cron_test

import (
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/user"
	"regexp"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// testRunAs is a real system group name every test in this package uses as
// its run_as value, resolved once at package init from the test process's
// own current user: ensureAccountDir (account.go) looks up run_as as a real
// OS group via user.LookupGroup and chowns StateRoot/accounts/<run_as> to
// it, so a hardcoded literal like "lesta-t42" (which only exists on a real,
// provisioned node) would fail to even resolve outside one. The current
// user's own primary group is the one real, always-resolvable group name
// available in any test environment; whether this test process can
// actually chown to it (some sandboxes disallow chown(2) entirely, even to
// a group the caller already belongs to) is a separate question, checked
// live by requireChownableGroup below, not assumed here. testRunAsResolveErr
// is non-nil when even resolving a group NAME failed (no current user, no
// primary group record at all); requireChownableGroup skips on that too.
var (
	testRunAs           string
	testRunAsResolveErr error
)

var runAsTestPattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

func init() {
	testRunAs, testRunAsResolveErr = resolveTestRunAs()
	if testRunAsResolveErr != nil {
		// Payload-shape-only tests (payload_test.go) just need a string
		// matching the run_as charset, not a real group, so a synthetic
		// fallback keeps them running even when group resolution itself
		// failed; requireChownableGroup is what gates the tests that
		// actually need the real thing.
		testRunAs = "lesta-t42"
	}
}

func resolveTestRunAs() (string, error) {
	u, err := user.Current()
	if err != nil {
		return "", fmt.Errorf("determining current user: %w", err)
	}

	g, err := user.LookupGroupId(u.Gid)
	if err != nil {
		return "", fmt.Errorf("looking up current user's own primary group: %w", err)
	}

	if !runAsTestPattern.MatchString(g.Name) {
		return "", fmt.Errorf("current user's own primary group name %q does not match cron's run_as charset (^[a-z][a-z0-9_-]{0,31}$)", g.Name)
	}

	return g.Name, nil
}

// requireChownableGroup skips the calling test, with a clear reason, unless
// this process can both resolve testRunAs as a real group AND actually
// chown a directory it owns to that group's gid right now. Every cron test
// that exercises a real create/update/delete Apply call (which internally
// calls account.go's own ensureAccountDir) needs this: unlike
// nginx/apache/bind9/mariadb's own requireReal* helpers (which skip when an
// external binary is missing from PATH), what's actually environment-
// dependent here is whether chown(2) is even permitted for this process at
// all, which varies by OS and sandbox (some deny it outright even to a
// group the caller already belongs to, which POSIX itself otherwise
// permits for an unprivileged owner).
func requireChownableGroup(t *testing.T) {
	t.Helper()

	if testRunAsResolveErr != nil {
		t.Skipf("cannot resolve a real group to exercise ensureAccountDir's own chown: %v", testRunAsResolveErr)
	}

	grp, err := user.LookupGroup(testRunAs)
	if err != nil {
		t.Skipf("group %q no longer resolves: %v", testRunAs, err)
	}

	gid, err := strconv.Atoi(grp.Gid)
	if err != nil {
		t.Skipf("group %q has a non-numeric gid %q: %v", testRunAs, grp.Gid, err)
	}

	// Mirrors ensureAccountDir's own os.Chown(dir, -1, gid) exactly: leaves
	// the owner unchanged (it's this test process's own uid, exactly as
	// ensureAccountDir leaves it as whatever created the directory, root in
	// production), only changing the group -- the one chown POSIX permits
	// an unprivileged owner to do, provided they belong to the target
	// group. This is expected to succeed in almost any environment (this
	// process's own primary group always qualifies); it exists mainly to
	// catch sandboxes that deny chown(2) outright regardless of POSIX
	// permission (observed on this project's own macOS dev sandbox).
	dir := t.TempDir()
	if err := os.Chown(dir, -1, gid); err != nil {
		t.Skipf("this process cannot chgrp a directory it owns to its own primary group %q (gid %d): %v; skipping the real per-account directory-ownership tests in this environment", testRunAs, gid, err)
	}
}

// Like internal/capability/acme's own harness_test.go, this file spins up no
// disposable external process at all: this capability's own write/delete/
// observe logic is pure filesystem logic, provable against a t.TempDir()-
// rooted Config with no real cron daemon involved. Only RunJob (runner_test.go)
// execs a real process, and even that needs no daemon, just `sh`.

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "scheduler.account-cron.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

func cronPayload(minute, hour, dayOfMonth, month, dayOfWeek, command string, suspended bool) map[string]any {
	return cronPayloadRunAs(minute, hour, dayOfMonth, month, dayOfWeek, command, suspended, testRunAs)
}

func cronPayloadRunAs(minute, hour, dayOfMonth, month, dayOfWeek, command string, suspended bool, runAs string) map[string]any {
	return map[string]any{
		"minute":       minute,
		"hour":         hour,
		"day_of_month": dayOfMonth,
		"month":        month,
		"day_of_week":  dayOfWeek,
		"command":      command,
		"suspended":    suspended,
		"run_as":       runAs,
	}
}

func requireApplied(t *testing.T, label string, result protocol.ResultEnvelope, err error) {
	t.Helper()

	requireStatus(t, label, result, err, protocol.StatusApplied)
}

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}
	if result.Status != want {
		t.Fatalf("%s: expected status %s, got %s (errors=%+v)", label, want, result.Status, result.Errors)
	}
}

func requireErrorCode(t *testing.T, label string, result protocol.ResultEnvelope, code string) {
	t.Helper()

	for _, e := range result.Errors {
		if e.Code == code {
			return
		}
	}

	t.Fatalf("%s: expected an error with code %q, got %+v", label, code, result.Errors)
}

func marshalPayload(t *testing.T, payload map[string]any) json.RawMessage {
	t.Helper()

	raw, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("marshaling test payload: %v", err)
	}

	return raw
}

func asValidationError(err error, target **cron.ValidationError) bool {
	return errors.As(err, target)
}
