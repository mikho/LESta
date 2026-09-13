package mail_test

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"net"
	"net/textproto"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/mail"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// --- envelope/payload construction helpers -------------------------------

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
		Capability:          "mail.smtp-imap.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(20 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

// account builds one embedded account map for a test payload.
func account(localPart string, password *string) map[string]any {
	return map[string]any{
		"local_part":        localPart,
		"password":          password,
		"quota_mb":          nil,
		"forward_to":        nil,
		"forward_only":      false,
		"autoreply_enabled": false,
		"autoreply_message": nil,
		"suspended":         false,
	}
}

// domainPayload builds a test payload with DKIM either off, or on and
// signing with "lesta1" (this project's own always-first selector name,
// matching what a freshly created real MailDomain always gets) -- every
// existing call site's own assumption before selector rotation existed.
// Rotation-specific tests (pending/retiring selectors) build their own
// payload map directly instead of extending this helper's signature.
func domainPayload(domain string, antivirus, antispam, dkim bool, accounts []map[string]any, suspended bool) map[string]any {
	activeSelector := ""
	if dkim {
		activeSelector = "lesta1"
	}

	return map[string]any{
		"domain":                domain,
		"antivirus_enabled":     antivirus,
		"antispam_enabled":      antispam,
		"dkim_enabled":          dkim,
		"dkim_active_selector":  activeSelector,
		"dkim_pending_selector": nil,
		"dkim_retire_selector":  nil,
		"catchall_email":        nil,
		"accounts":              accounts,
		"suspended":             suspended,
	}
}

func strPtr(s string) *string { return &s }

func randomPassword(t *testing.T) string {
	t.Helper()

	return randomHex(t, 24)
}

// --- real network confirmation helpers (bypass MailCapability entirely) --

// probeRcpt performs one real SMTP session against the disposable exim
// instance and returns the RCPT TO response code, entirely independent of
// MailCapability's own internal logic: this is what actually proves an
// apply took effect, not merely that Apply() returned StatusApplied.
func probeRcpt(t *testing.T, port int, rcptAddress string) int {
	t.Helper()

	conn, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)), 5*time.Second)
	if err != nil {
		t.Fatalf("dialing disposable exim: %v", err)
	}
	defer conn.Close()

	tp := textproto.NewConn(conn)
	defer tp.Close()

	if _, _, err := tp.ReadResponse(220); err != nil {
		t.Fatalf("reading banner: %v", err)
	}

	mustCmd(t, tp, "EHLO test", 250)
	mustCmd(t, tp, "MAIL FROM:<probe@lesta-test.invalid>", 250)

	if err := tp.PrintfLine("RCPT TO:<%s>", rcptAddress); err != nil {
		t.Fatalf("sending RCPT TO: %v", err)
	}

	code, _, err := tp.ReadResponse(0)
	if err != nil {
		t.Fatalf("reading RCPT TO response: %v", err)
	}

	_ = tp.PrintfLine("QUIT")

	return code
}

func mustCmd(t *testing.T, tp *textproto.Conn, line string, wantCode int) {
	t.Helper()

	if err := tp.PrintfLine("%s", line); err != nil {
		t.Fatalf("sending %q: %v", line, err)
	}
	if _, _, err := tp.ReadResponse(wantCode); err != nil {
		t.Fatalf("response to %q: %v", line, err)
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

// --- tests ----------------------------------------------------------------

func TestMailCapability_FullLifecycle(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	const domain = "example.com"

	resourceID := newTestUUID()
	password1 := randomPassword(t)

	createOp := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		domainPayload(domain, false, false, false, []map[string]any{account("sales", &password1)}, false))

	t.Run("create provisions a real domain and account, immediately accepted over real SMTP", func(t *testing.T) {
		result, err := capability.Apply(ctx, createOp)
		requireApplied(t, "create", result, err)

		if code := probeRcpt(t, d.Config.EximListenPort, "sales@"+domain); code < 200 || code >= 300 {
			t.Fatalf("expected sales@%s to be accepted, got %d", domain, code)
		}
	})

	t.Run("an unknown local part on the same hosted domain is rejected", func(t *testing.T) {
		if code := probeRcpt(t, d.Config.EximListenPort, "nosuchuser@"+domain); code < 500 {
			t.Fatalf("expected nosuchuser@%s to be rejected, got %d", domain, code)
		}
	})

	t.Run("a non-hosted domain is rejected as relay-not-permitted, never accepted", func(t *testing.T) {
		if code := probeRcpt(t, d.Config.EximListenPort, "anyone@not-our-domain.invalid"); code < 500 {
			t.Fatalf("expected a non-hosted domain to be rejected, got %d", code)
		}
	})

	t.Run("a replayed create (identical idempotency key) is served from the receipt, not re-applied", func(t *testing.T) {
		replay, err := capability.Apply(ctx, createOp)
		requireStatus(t, "replayed create", replay, err, protocol.StatusAlreadyApplied)
	})

	t.Run("a second create against the same resource is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
			domainPayload(domain, false, false, false, []map[string]any{account("sales", &password1)}, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "duplicate create", result, err, protocol.StatusRejected)
		requireErrorCode(t, "duplicate create", result, "resource_already_exists")
	})

	t.Run("suspend rejects the domain's own account over real SMTP", func(t *testing.T) {
		op := newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2,
			domainPayload(domain, false, false, false, []map[string]any{account("sales", nil)}, true))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "suspend", result, err)

		if code := probeRcpt(t, d.Config.EximListenPort, "sales@"+domain); code < 500 {
			t.Fatalf("expected sales@%s to be rejected after suspend, got %d", domain, code)
		}
	})

	t.Run("unsuspend restores acceptance without any password change", func(t *testing.T) {
		op := newOp(protocol.OperationUnsuspend, resourceID, newTestUUID(), 3,
			domainPayload(domain, false, false, false, []map[string]any{account("sales", nil)}, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "unsuspend", result, err)

		if code := probeRcpt(t, d.Config.EximListenPort, "sales@"+domain); code < 200 || code >= 300 {
			t.Fatalf("expected sales@%s to be accepted again after unsuspend, got %d", domain, code)
		}
	})

	password2 := randomPassword(t)

	t.Run("update rotates the account's own password hash in the real Dovecot passwd file", func(t *testing.T) {
		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 4,
			domainPayload(domain, false, false, false, []map[string]any{account("sales", &password2)}, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "rotate", result, err)

		hash1 := passwdHashFor(t, d.Config.DovecotPasswdPath, "sales@"+domain)
		if hash1 == "" {
			t.Fatal("expected a passwd-file row for sales@example.com after rotation")
		}
		if !strings.HasPrefix(hash1, "{BLF-CRYPT}") {
			t.Fatalf("expected a BLF-CRYPT hash, got %q", hash1)
		}
		// The account is still accepted after rotation: existing grants (in
		// this design, existence in accounts.list) survive a password
		// change untouched.
		if code := probeRcpt(t, d.Config.EximListenPort, "sales@"+domain); code < 200 || code >= 300 {
			t.Fatalf("expected sales@%s to still be accepted after password rotation, got %d", domain, code)
		}
	})

	t.Run("update enabling antivirus/antispam is reflected in the rendered Exim data files", func(t *testing.T) {
		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 5,
			domainPayload(domain, true, true, false, []map[string]any{account("sales", nil)}, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "enable antivirus/antispam", result, err)

		assertListContains(t, filepath.Join(d.Config.EximDataDir, "antivirus.list"), domain)
		assertListContains(t, filepath.Join(d.Config.EximDataDir, "antispam.list"), domain)
	})

	t.Run("delete removes the domain: its own account is rejected again, and the schema records a deletion", func(t *testing.T) {
		op := newOp(protocol.OperationDelete, resourceID, newTestUUID(), 6,
			domainPayload(domain, true, true, false, []map[string]any{account("sales", nil)}, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "delete", result, err)

		if code := probeRcpt(t, d.Config.EximListenPort, "sales@"+domain); code < 500 {
			t.Fatalf("expected sales@%s to be rejected after delete, got %d", domain, code)
		}
	})
}

func TestMailCapability_DKIM(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	const domain = "dkim-example.com"

	resourceID := newTestUUID()
	password := randomPassword(t)

	op := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		domainPayload(domain, false, false, true, []map[string]any{account("sales", &password)}, false))

	result, err := capability.Apply(ctx, op)
	requireApplied(t, "create with dkim enabled", result, err)

	keyPath := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta1.private")

	info, err := os.Stat(keyPath)
	if err != nil {
		t.Fatalf("expected a real DKIM private key to exist at %s: %v", keyPath, err)
	}
	if info.Mode().Perm() != 0o640 {
		t.Fatalf("expected the DKIM private key to be mode 0640 (group-lesta readable, so the mail installer's Debian-exim group membership can actually sign with it), got %v", info.Mode().Perm())
	}

	pubPath := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta1.public")
	pubBytes, err := os.ReadFile(pubPath)
	if err != nil {
		t.Fatalf("expected a real DKIM public key to exist at %s: %v", pubPath, err)
	}
	if !strings.Contains(string(pubBytes), "BEGIN PUBLIC KEY") {
		t.Fatalf("expected a real PEM public key, got %q", string(pubBytes))
	}

	assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_keys.list"), domain+": "+keyPath)

	t.Run("a successful apply reports the active DKIM selector and public key on ResultEnvelope.Data, never the private key", func(t *testing.T) {
		if len(result.Data) == 0 {
			t.Fatal("expected ResultEnvelope.Data to be populated when dkim_enabled is true, got none")
		}

		var data struct {
			Active struct {
				Selector  string `json:"selector"`
				PublicKey string `json:"public_key"`
			} `json:"active"`
			Pending *struct {
				Selector  string `json:"selector"`
				PublicKey string `json:"public_key"`
			} `json:"pending"`
		}
		if err := json.Unmarshal(result.Data, &data); err != nil {
			t.Fatalf("unmarshaling ResultEnvelope.Data: %v", err)
		}

		if data.Active.Selector != "lesta1" {
			t.Fatalf("expected active selector %q, got %q", "lesta1", data.Active.Selector)
		}
		if data.Active.PublicKey == "" {
			t.Fatal("expected a non-empty active public key")
		}
		if strings.Contains(data.Active.PublicKey, "BEGIN") || strings.Contains(data.Active.PublicKey, "\n") {
			t.Fatalf("expected a bare base64 public key with no PEM header or newlines, got %q", data.Active.PublicKey)
		}
		if data.Pending != nil {
			t.Fatalf("expected no pending selector for a non-rotating domain, got %+v", data.Pending)
		}

		privateKeyBytes, err := os.ReadFile(keyPath)
		if err != nil {
			t.Fatalf("reading private key: %v", err)
		}
		if strings.Contains(string(result.Data), string(privateKeyBytes)) {
			t.Fatal("the private key must never appear in ResultEnvelope.Data")
		}
	})

	t.Run("re-applying with dkim already enabled does not regenerate the key", func(t *testing.T) {
		before, err := os.ReadFile(keyPath)
		if err != nil {
			t.Fatalf("reading key before re-apply: %v", err)
		}

		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2,
			domainPayload(domain, false, false, true, []map[string]any{account("sales", nil)}, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "re-apply with dkim still enabled", result, err)

		after, err := os.ReadFile(keyPath)
		if err != nil {
			t.Fatalf("reading key after re-apply: %v", err)
		}

		if string(before) != string(after) {
			t.Fatal("expected the same DKIM key to survive an update that keeps dkim_enabled true, but it changed")
		}
	})
}

// TestMailCapability_DKIMRotation proves the full real selector-rotation
// state machine this capability supports, end to end at the filesystem/
// ResultEnvelope level (a real daemon signing proof for one selector already
// exists in TestMailCapability_DKIMSigningProof; this test is about the
// multi-selector key lifecycle a rotation drives, not re-proving that Exim
// itself can sign with a key):
//  1. dkim_pending_selector generates a second real key without disturbing
//     the active one, and reports both on ResultEnvelope.Data.
//  2. Promoting (switching dkim_active_selector to the former pending one)
//     switches dkim_keys.list/dkim_selector.list over, while the OLD
//     selector's own key material is left untouched on disk.
//  3. dkim_retire_selector actually deletes that old key material once
//     Laravel decides the rotation is fully done.
func TestMailCapability_DKIMRotation(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	const domain = "dkim-rotation-example.com"

	resourceID := newTestUUID()
	password := randomPassword(t)

	create := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		domainPayload(domain, false, false, true, []map[string]any{account("sales", &password)}, false))
	result, err := capability.Apply(ctx, create)
	requireApplied(t, "create with dkim enabled (lesta1)", result, err)

	lesta1Private := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta1.private")
	lesta1Public := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta1.public")
	lesta2Private := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta2.private")

	t.Run("a pending selector generates its own real key without switching signing", func(t *testing.T) {
		payload := domainPayload(domain, false, false, true, []map[string]any{account("sales", nil)}, false)
		payload["dkim_pending_selector"] = "lesta2"

		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, payload)
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "update with lesta2 pending", result, err)

		if _, err := os.Stat(lesta2Private); err != nil {
			t.Fatalf("expected a real pending DKIM key to exist at %s: %v", lesta2Private, err)
		}

		assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_selector.list"), domain+": lesta1")
		assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_keys.list"), domain+": "+lesta1Private)

		var data struct {
			Active struct {
				Selector string `json:"selector"`
			} `json:"active"`
			Pending *struct {
				Selector  string `json:"selector"`
				PublicKey string `json:"public_key"`
			} `json:"pending"`
		}
		if err := json.Unmarshal(result.Data, &data); err != nil {
			t.Fatalf("unmarshaling ResultEnvelope.Data: %v", err)
		}

		if data.Active.Selector != "lesta1" {
			t.Fatalf("expected signing to still be active on lesta1 during the propagation window, got %q", data.Active.Selector)
		}
		if data.Pending == nil || data.Pending.Selector != "lesta2" || data.Pending.PublicKey == "" {
			t.Fatalf("expected a real pending lesta2 selector/public_key on ResultEnvelope.Data, got %+v", data.Pending)
		}
	})

	t.Run("promoting the pending selector switches signing over, leaving the old key untouched", func(t *testing.T) {
		payload := domainPayload(domain, false, false, true, []map[string]any{account("sales", nil)}, false)
		payload["dkim_active_selector"] = "lesta2"

		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 3, payload)
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "promote lesta2 to active", result, err)

		assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_selector.list"), domain+": lesta2")
		assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_keys.list"), domain+": "+lesta2Private)

		if _, err := os.Stat(lesta1Private); err != nil {
			t.Fatalf("expected the retiring lesta1 key to still exist right after promotion (not yet retired): %v", err)
		}
	})

	t.Run("retiring the old selector deletes its real key material for good", func(t *testing.T) {
		payload := domainPayload(domain, false, false, true, []map[string]any{account("sales", nil)}, false)
		payload["dkim_active_selector"] = "lesta2"
		payload["dkim_retire_selector"] = "lesta1"

		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 4, payload)
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "retire lesta1", result, err)

		if _, err := os.Stat(lesta1Private); !os.IsNotExist(err) {
			t.Fatalf("expected the retired lesta1 private key to be deleted, got err=%v", err)
		}
		if _, err := os.Stat(lesta1Public); !os.IsNotExist(err) {
			t.Fatalf("expected the retired lesta1 public key to be deleted, got err=%v", err)
		}

		// lesta2 keeps signing throughout: retiring lesta1 must not disturb it.
		if _, err := os.Stat(lesta2Private); err != nil {
			t.Fatalf("expected the active lesta2 key to be unaffected by retiring lesta1: %v", err)
		}
		assertListContains(t, filepath.Join(d.Config.EximDataDir, "dkim_selector.list"), domain+": lesta2")
	})
}

func TestMailCapability_NoResultDataWhenDKIMDisabled(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	password := randomPassword(t)

	op := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		domainPayload("no-dkim.example.com", false, false, false, []map[string]any{account("sales", &password)}, false))

	result, err := capability.Apply(ctx, op)
	requireApplied(t, "create with dkim disabled", result, err)

	if len(result.Data) != 0 {
		t.Fatalf("expected no ResultEnvelope.Data when dkim_enabled is false, got %s", result.Data)
	}
}

func TestMailCapability_PayloadValidationRejections(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	t.Run("invalid domain shape is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1,
			domainPayload("not a domain", false, false, false, nil, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid domain", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid domain", result, "invalid_domain")
	})

	t.Run("invalid local_part shape is rejected", func(t *testing.T) {
		password := randomPassword(t)
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1,
			domainPayload("example.org", false, false, false, []map[string]any{account("not valid!", &password)}, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid local_part", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid local_part", result, "invalid_local_part")
	})

	t.Run("duplicate local_part within one domain is rejected", func(t *testing.T) {
		password := randomPassword(t)
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1,
			domainPayload("example.org", false, false, false, []map[string]any{account("sales", &password), account("sales", &password)}, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "duplicate local_part", result, err, protocol.StatusRejected)
		requireErrorCode(t, "duplicate local_part", result, "duplicate_local_part")
	})

	t.Run("malformed password shape is rejected", func(t *testing.T) {
		bad := "not-48-hex-chars"
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1,
			domainPayload("example.org", false, false, false, []map[string]any{account("sales", &bad)}, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "malformed password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "malformed password", result, "invalid_account_password")
	})
}

func TestMailCapability_UnknownResourceRejections(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	cases := []struct {
		name string
		op   protocol.Operation
	}{
		{"update", protocol.OperationUpdate},
		{"suspend", protocol.OperationSuspend},
		{"unsuspend", protocol.OperationUnsuspend},
		{"delete", protocol.OperationDelete},
		{"observe", protocol.OperationObserve},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			op := newOp(tc.op, newTestUUID(), newTestUUID(), 1,
				domainPayload("never-existed.example", false, false, false, nil, false))
			result, err := capability.Apply(ctx, op)
			requireStatus(t, tc.name, result, err, protocol.StatusRejected)
			requireErrorCode(t, tc.name, result, "unknown_resource")
		})
	}
}

// --- passwd-file inspection helper -----------------------------------------

func passwdHashFor(t *testing.T, path, address string) string {
	t.Helper()

	f, err := os.Open(path)
	if err != nil {
		t.Fatalf("opening passwd file: %v", err)
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		fields := strings.SplitN(scanner.Text(), ":", 3)
		if len(fields) >= 2 && fields[0] == address {
			return fields[1]
		}
	}

	return ""
}

func assertListContains(t *testing.T, path, want string) {
	t.Helper()

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading %s: %v", path, err)
	}

	if !strings.Contains(string(raw), want) {
		t.Fatalf("expected %s to contain %q, got:\n%s", path, want, string(raw))
	}
}

// TestMailCapability_DKIMSigningProof proves, end to end, that the real
// DKIM key ensureDKIMKey generates through the ordinary Apply(Create) path
// produces a real, valid DKIM-Signature header when Exim actually sends a
// message signed with it. This runs independently of the shared disposable
// daemon (see harness_test.go's own top comment: dkim_domain/dkim_selector/
// dkim_private_key on a transport make Exim refuse to start as a daemon at
// all under this test process's own untrusted -C invocation, a real Exim
// security feature that never applies in production, where Exim is started
// by systemd as root from its own real config, never via -C). `exim -bS`
// (batch SMTP, a single one-shot invocation, never a long-running daemon)
// is not subject to that same restriction, confirmed directly, and is what
// this test uses instead.
func TestMailCapability_DKIMSigningProof(t *testing.T) {
	requireRealExim(t)

	d := newDisposableExim(t)
	capability := mail.New(d.Config)
	ctx := context.Background()

	const domain = "dkim-signing-example.com"

	resourceID := newTestUUID()
	password := randomPassword(t)

	op := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		domainPayload(domain, false, false, true, []map[string]any{account("sales", &password)}, false))

	result, err := capability.Apply(ctx, op)
	requireApplied(t, "create with dkim enabled", result, err)

	keyPath := filepath.Join(d.Config.DKIMKeyRoot, domain, "lesta1.private")
	if _, err := os.Stat(keyPath); err != nil {
		t.Fatalf("expected the real DKIM key Apply() just generated to exist: %v", err)
	}

	// A minimal, standalone config (deliberately not the shared daemon's
	// own): an smtp transport signs with the real key and delivers to a
	// throwaway local TCP listener that just captures the raw message,
	// exactly like the manual proof this design is based on.
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("starting capture listener: %v", err)
	}
	defer listener.Close()

	capturedCh := make(chan string, 1)
	go captureOneSMTPMessage(listener, capturedCh)

	capturePort := listener.Addr().(*net.TCPAddr).Port
	prefix := t.TempDir()
	spoolDir := filepath.Join(prefix, "spool")
	if err := os.MkdirAll(spoolDir, 0o755); err != nil {
		t.Fatalf("creating spool dir: %v", err)
	}

	signingConf := fmt.Sprintf(`exim_user = %s
exim_group = %s
spool_directory = %s
primary_hostname = mail.lesta-test.invalid

begin routers
r:
  driver = accept
  transport = capture

begin transports
capture:
  driver = smtp
  hosts = 127.0.0.1
  port = %d
  allow_localhost
  dkim_domain = %s
  dkim_selector = lesta1
  dkim_private_key = %s

begin authenticators
`, currentUsername(t), currentGroupname(t), spoolDir, capturePort, domain, keyPath)

	signingConfPath := filepath.Join(prefix, "signing.conf")
	if err := os.WriteFile(signingConfPath, []byte(signingConf), 0o644); err != nil {
		t.Fatalf("writing signing config: %v", err)
	}

	batch := "MAIL FROM:<a@b.invalid>\r\n" +
		"RCPT TO:<sales@" + domain + ">\r\n" +
		"DATA\r\n" +
		"From: a@b.invalid\r\n" +
		"To: sales@" + domain + "\r\n" +
		"Subject: dkim signing proof\r\n" +
		"\r\n" +
		"body\r\n" +
		".\r\n" +
		"QUIT\r\n"

	cmd := exec.CommandContext(ctx, "exim", "-C", signingConfPath, "-odi", "-bS")
	cmd.Stdin = strings.NewReader(batch)

	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("running exim -bS to send the signing-proof message: %v: %s", err, string(out))
	}

	select {
	case captured := <-capturedCh:
		if !strings.Contains(captured, "DKIM-Signature:") {
			t.Fatalf("expected a real DKIM-Signature header in the delivered message, got:\n%s", captured)
		}
		if !strings.Contains(captured, "d="+domain) {
			t.Fatalf("expected the DKIM-Signature to name d=%s, got:\n%s", domain, captured)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("timed out waiting for the signed message to be captured")
	}
}

// captureOneSMTPMessage accepts exactly one connection and speaks just
// enough SMTP to receive one message's DATA, sending the captured content
// on ch.
func captureOneSMTPMessage(listener net.Listener, ch chan<- string) {
	conn, err := listener.Accept()
	if err != nil {
		return
	}
	defer conn.Close()

	tp := textproto.NewConn(conn)
	defer tp.Close()

	_ = tp.PrintfLine("220 catcher ready")

	var captured strings.Builder
	inData := false

	for {
		line, err := tp.ReadLine()
		if err != nil {
			return
		}

		switch {
		case inData:
			if line == "." {
				inData = false
				_ = tp.PrintfLine("250 OK")
				ch <- captured.String()

				continue
			}

			captured.WriteString(line + "\r\n")
		case strings.HasPrefix(strings.ToUpper(line), "DATA"):
			inData = true
			_ = tp.PrintfLine("354 go")
		case strings.HasPrefix(strings.ToUpper(line), "QUIT"):
			_ = tp.PrintfLine("221 bye")

			return
		default:
			_ = tp.PrintfLine("250 OK")
		}
	}
}
