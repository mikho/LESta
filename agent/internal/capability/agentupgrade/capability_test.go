package agentupgrade

import (
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	nodeUUID = "11111111-1111-4111-8111-111111111111"
	token    = "abcdefghijklmnopqrstuvwxyz012345"
)

type fixture struct {
	t         *testing.T
	dir       string
	priv      ed25519.PrivateKey
	pub       ed25519.PublicKey
	newBinary []byte
	server    *httptest.Server
	scheduled []string
	cap       *AgentUpgradeCapability
	now       time.Time
	reported  string
}

func newFixture(t *testing.T) *fixture {
	t.Helper()

	f := &fixture{t: t, dir: t.TempDir(), newBinary: []byte("the new agent binary"), now: time.Unix(1_800_000_000, 0), reported: "1.0.90"}
	f.pub, f.priv, _ = ed25519.GenerateKey(nil)

	f.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/agent/v1/agent-binary" || r.Header.Get("Authorization") != "Bearer "+token {
			http.Error(w, "no", http.StatusForbidden)

			return
		}

		_, _ = w.Write(f.newBinary)
	}))
	t.Cleanup(f.server.Close)

	must(t, os.WriteFile(filepath.Join(f.dir, "lesta-agent"), []byte("the old agent binary"), 0o755))
	must(t, os.WriteFile(filepath.Join(f.dir, "upgrade-public-key"), []byte(base64.StdEncoding.EncodeToString(f.pub)+"\n"), 0o644))

	f.cap = New(Config{
		BinaryPath: filepath.Join(f.dir, "lesta-agent"), KeyPath: filepath.Join(f.dir, "upgrade-public-key"),
		NodeUUID: nodeUUID, ControlPlaneURL: f.server.URL, CurrentVersion: "1.0.89", AllowInsecureHTTP: true,
		Now:        func() time.Time { return f.now },
		RunVersion: func(context.Context, string) (string, error) { return f.reported, nil },
		Schedule:   func(binary, version string) error { f.scheduled = append(f.scheduled, binary+" "+version); return nil },
	})

	return f
}

func must(t *testing.T, err error) {
	t.Helper()

	if err != nil {
		t.Fatal(err)
	}
}

// request builds a correctly signed payload that tests then spoil one field of.
func (f *fixture) request(mutate func(*payload)) protocol.OperationEnvelope {
	sum := sha256.Sum256(f.newBinary)
	p := payload{
		Version: "1.0.90", SHA256: hex.EncodeToString(sum[:]), NodeUUID: nodeUUID, ExpiresAt: f.now.Add(time.Hour).Unix(),
		DownloadURL: f.server.URL + "/agent/v1/agent-binary", DownloadToken: token,
	}

	if mutate != nil {
		mutate(&p)
	}

	if p.Signature == "" {
		p.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(f.priv, SignedMessage(p.NodeUUID, p.Version, p.SHA256, p.ExpiresAt, p.NextPublicKey)))
	}

	raw, _ := json.Marshal(p)

	return protocol.OperationEnvelope{
		ProtocolVersion: "1", Capability: "node.agent-upgrade.v1", Operation: protocol.OperationUpdate,
		ResourceID: "00000000-0000-4000-8000-000000000001", DesiredStateVersion: 1, IdempotencyKey: "k" + string(raw[:12]) + time.Now().String(),
		CorrelationID: "00000000-0000-4000-8000-000000000002", Payload: raw,
	}
}

func (f *fixture) apply(op protocol.OperationEnvelope) protocol.ResultEnvelope {
	res, _ := f.cap.Apply(context.Background(), op)

	return res
}

func (f *fixture) binary() string {
	raw, _ := os.ReadFile(filepath.Join(f.dir, "lesta-agent"))

	return string(raw)
}

func TestASignedRequestSwapsTheBinaryKeepsThePreviousAndSchedulesTheRestart(t *testing.T) {
	f := newFixture(t)

	res := f.apply(f.request(nil))
	if res.Status != protocol.StatusApplied {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}

	if f.binary() != "the new agent binary" {
		t.Errorf("binary = %q", f.binary())
	}

	if previous, _ := os.ReadFile(filepath.Join(f.dir, "lesta-agent.previous")); string(previous) != "the old agent binary" {
		t.Errorf("previous = %q", previous)
	}

	if len(f.scheduled) != 1 || !strings.HasSuffix(f.scheduled[0], " 1.0.90") {
		t.Errorf("scheduled = %v", f.scheduled)
	}

	if entries, _ := filepath.Glob(filepath.Join(f.dir, ".upgrade-*")); len(entries) != 0 {
		t.Errorf("staged files left behind: %v", entries)
	}
}

func TestAReplayDoesNotUpgradeTwice(t *testing.T) {
	f := newFixture(t)
	op := f.request(nil)

	f.apply(op)

	if again := f.apply(op); again.Status != protocol.StatusAlreadyApplied || len(f.scheduled) != 1 {
		t.Errorf("status %s, scheduled %v", again.Status, f.scheduled)
	}
}

func TestRefusals(t *testing.T) {
	cases := map[string]struct {
		mutate func(*fixture, *payload)
		code   string
	}{
		"a signature from another key": {func(f *fixture, p *payload) {
			_, other, _ := ed25519.GenerateKey(nil)
			p.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(other, SignedMessage(p.NodeUUID, p.Version, p.SHA256, p.ExpiresAt, p.NextPublicKey)))
		}, "signature_mismatch"},
		"a changed sha256 after signing": {func(f *fixture, p *payload) {
			signed := base64.StdEncoding.EncodeToString(ed25519.Sign(f.priv, SignedMessage(p.NodeUUID, p.Version, p.SHA256, p.ExpiresAt, "")))
			p.Signature = signed
			p.SHA256 = strings.Repeat("a", 64)
		}, "signature_mismatch"},
		"another node":          {func(f *fixture, p *payload) { p.NodeUUID = "22222222-2222-4222-8222-222222222222" }, "wrong_node"},
		"an expired request":    {func(f *fixture, p *payload) { p.ExpiresAt = f.now.Add(-time.Second).Unix() }, "expired"},
		"a lifetime over a day": {func(f *fixture, p *payload) { p.ExpiresAt = f.now.Add(48 * time.Hour).Unix() }, "lifetime_too_long"},
		"the running version":   {func(f *fixture, p *payload) { p.Version = "1.0.89" }, "not_newer"},
		"a downgrade":           {func(f *fixture, p *payload) { p.Version = "1.0.5" }, "not_newer"},
		"a malformed version":   {func(f *fixture, p *payload) { p.Version = "1.0.x" }, "invalid_version"},
		"a download from another host": {func(f *fixture, p *payload) {
			p.DownloadURL = "http://evil.example/agent/v1/agent-binary"
		}, "invalid_download"},
		"a download with a query": {func(f *fixture, p *payload) { p.DownloadURL += "?x=1" }, "invalid_download"},
		"a bad download token":    {func(f *fixture, p *payload) { p.DownloadToken = "short" }, "invalid_download"},
	}

	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			f := newFixture(t)
			res := f.apply(f.request(func(p *payload) { tc.mutate(f, p) }))

			if res.Status != protocol.StatusRejected || len(res.Errors) != 1 || res.Errors[0].Code != tc.code {
				t.Fatalf("status %s errors %+v, want rejected %s", res.Status, res.Errors, tc.code)
			}

			if f.binary() != "the old agent binary" || len(f.scheduled) != 0 {
				t.Errorf("nothing may change: binary %q scheduled %v", f.binary(), f.scheduled)
			}
		})
	}
}

func TestANodeWithoutAKeyRefuses(t *testing.T) {
	f := newFixture(t)
	must(t, os.Remove(filepath.Join(f.dir, "upgrade-public-key")))

	if res := f.apply(f.request(nil)); res.Status != protocol.StatusRejected || res.Errors[0].Code != "upgrade_key_missing" {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}
}

func TestBytesThatDoNotMatchTheSignedHashAreNotInstalled(t *testing.T) {
	f := newFixture(t)
	op := f.request(nil)
	f.newBinary = []byte("something the panel substituted")

	if res := f.apply(op); res.Status != protocol.StatusFailed || res.Errors[0].Code != "download_failed" {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}

	if f.binary() != "the old agent binary" || len(f.scheduled) != 0 {
		t.Errorf("binary %q scheduled %v", f.binary(), f.scheduled)
	}
}

func TestABinaryThatReportsAnotherVersionIsNotInstalled(t *testing.T) {
	f := newFixture(t)
	f.reported = "1.0.91"

	if res := f.apply(f.request(nil)); res.Status != protocol.StatusFailed || res.Errors[0].Code != "binary_rejected" {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}

	if f.binary() != "the old agent binary" {
		t.Errorf("binary %q", f.binary())
	}
}

func TestAWrongDownloadTokenFailsWithoutChangingAnything(t *testing.T) {
	f := newFixture(t)

	res := f.apply(f.request(func(p *payload) { p.DownloadToken = "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz" }))
	if res.Status != protocol.StatusFailed || f.binary() != "the old agent binary" {
		t.Fatalf("status %s binary %q", res.Status, f.binary())
	}
}

func TestASignedNextKeyReplacesTheNodeKeyAfterTheSwap(t *testing.T) {
	f := newFixture(t)
	nextPub, _, _ := ed25519.GenerateKey(nil)
	next := base64.StdEncoding.EncodeToString(nextPub)

	if res := f.apply(f.request(func(p *payload) { p.NextPublicKey = next })); res.Status != protocol.StatusApplied {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}

	if stored, _ := os.ReadFile(filepath.Join(f.dir, "upgrade-public-key")); strings.TrimSpace(string(stored)) != next {
		t.Errorf("stored key = %q", stored)
	}
}

func TestAnUnsignedNextKeyIsRefused(t *testing.T) {
	f := newFixture(t)
	other, _, _ := ed25519.GenerateKey(nil)
	op := f.request(nil)

	var p payload

	must(t, json.Unmarshal(op.Payload, &p))

	p.NextPublicKey = base64.StdEncoding.EncodeToString(other)
	op.Payload, _ = json.Marshal(p)

	if res := f.apply(op); res.Status != protocol.StatusRejected || res.Errors[0].Code != "signature_mismatch" {
		t.Fatalf("status %s errors %+v", res.Status, res.Errors)
	}
}

func TestVersionsCompareNumerically(t *testing.T) {
	if compareVersions("1.0.10", "1.0.9") != 1 || compareVersions("1.0.9", "1.0.10") != -1 || compareVersions("1.2.0", "1.2.0") != 0 || compareVersions("2.0.0", "1.99.99") != 1 {
		t.Error("versions are not compared as numbers")
	}
}
