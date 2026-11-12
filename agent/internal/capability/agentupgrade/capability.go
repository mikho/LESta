// Package agentupgrade implements node.agent-upgrade.v1: replacing the agent's own binary with a
// newer one, asked for in the panel. It runs in the "maintenance" applier (root, no sandbox).
//
// The panel is not trusted to decide what runs as root here. The node holds a public Ed25519 key,
// installed with the node, and applies only a binary whose version, sha256, node and expiry an
// administrator signed with the matching private key, which never reaches the panel (the browser
// signs). A request is refused when the key is missing, the signature does not verify, the node
// is another one, the request has expired, the version is not newer than the running one, or the
// downloaded bytes do not match the signed sha256. A signed request may carry the next public key,
// which replaces the current one after a successful swap (key rotation without shell access).
//
// The swap keeps the previous binary as <binary>.previous, and a transient systemd timer restarts
// the agent's units onto the new binary after this result has been reported, then restores the
// previous binary if the daemon is not running the new version a minute later.
package agentupgrade

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/atomicfile"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	// MessagePrefix starts every signed message, so a signature made for this purpose can never be
	// replayed as anything else.
	MessagePrefix = "lesta-agent-upgrade/v1"

	// maxBinaryBytes bounds the download.
	maxBinaryBytes = 64 << 20

	// maxLifetime bounds how long before expiry a request may be signed.
	maxLifetime = 24 * time.Hour
)

var (
	versionPattern = regexp.MustCompile(`^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,6}$`)
	sha256Pattern  = regexp.MustCompile(`^[0-9a-f]{64}$`)
	pathPattern    = regexp.MustCompile(`^/agent/v1/agent-binary$`)
	tokenPattern   = regexp.MustCompile(`^[A-Za-z0-9._~+/=-]{20,200}$`)
)

// Config is the capability's configuration.
type Config struct {
	// BinaryPath is the installed agent binary (production: /var/lib/lesta/agent/bin/lesta-agent).
	BinaryPath string
	// KeyPath holds the node's public upgrade key, base64 of the 32 raw bytes.
	KeyPath string
	// NodeUUID is this node's own identity, as the panel knows it.
	NodeUUID string
	// ControlPlaneURL is the panel's address; the download must come from its host over https.
	ControlPlaneURL string
	// CurrentVersion is the running agent's version.
	CurrentVersion string
	// Client fetches the binary; nil means a client with a timeout that never follows redirects.
	Client *http.Client
	// AllowInsecureHTTP lets tests download from a plain http server.
	AllowInsecureHTTP bool
	// Now is the clock; nil means time.Now.
	Now func() time.Time
	// RunVersion runs a binary's "version" command; nil runs it for real.
	RunVersion func(ctx context.Context, binary string) (string, error)
	// Schedule arranges the restart onto the new binary and the rollback check after this result
	// is reported; nil uses systemd-run.
	Schedule func(binary, version string) error
}

type payload struct {
	Version       string `json:"version"`
	SHA256        string `json:"sha256"`
	NodeUUID      string `json:"node_uuid"`
	ExpiresAt     int64  `json:"expires_at"`
	NextPublicKey string `json:"next_public_key"`
	Signature     string `json:"signature"`
	DownloadURL   string `json:"download_url"`
	DownloadToken string `json:"download_token"`
}

// SignedMessage is the exact byte string the administrator signs. The browser builds the same one.
func SignedMessage(nodeUUID, version, sha256Hex string, expiresAt int64, nextPublicKey string) []byte {
	return []byte(strings.Join([]string{MessagePrefix, nodeUUID, version, sha256Hex, strconv.FormatInt(expiresAt, 10), nextPublicKey}, "\n"))
}

// AgentUpgradeCapability applies node.agent-upgrade.v1 operations.
type AgentUpgradeCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns the capability.
func New(cfg Config) *AgentUpgradeCapability {
	if cfg.Now == nil {
		cfg.Now = time.Now
	}

	if cfg.RunVersion == nil {
		cfg.RunVersion = runVersion
	}

	if cfg.Schedule == nil {
		cfg.Schedule = func(binary, version string) error { return scheduleRestart(binary, version) }
	}

	return &AgentUpgradeCapability{cfg: cfg, receipts: idempotency.New()}
}

// Apply runs one update operation.
func (c *AgentUpgradeCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
		if prior.Status == protocol.StatusApplied {
			prior.Status = protocol.StatusAlreadyApplied
		}

		return prior, nil
	}

	res := c.apply(ctx, op)
	c.receipts.Record(op.IdempotencyKey, res)

	return res, nil
}

func (c *AgentUpgradeCapability) apply(ctx context.Context, op protocol.OperationEnvelope) protocol.ResultEnvelope {
	if op.Operation != protocol.OperationUpdate {
		return c.reject(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported; only update", op.Operation))
	}

	var p payload

	dec := json.NewDecoder(bytes.NewReader(op.Payload))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&p); err != nil {
		return c.reject(op, "invalid_payload", err.Error())
	}

	publicKey, err := c.loadKey()
	if err != nil {
		return c.reject(op, "upgrade_key_missing", err.Error())
	}

	if code, msg := c.check(p, publicKey); code != "" {
		return c.reject(op, code, msg)
	}

	source, err := c.downloadSource(p)
	if err != nil {
		return c.reject(op, "invalid_download", err.Error())
	}

	staged, err := c.download(ctx, source, p)
	if err != nil {
		return c.fail(op, "download_failed", err.Error())
	}

	defer os.Remove(staged)

	reported, err := c.cfg.RunVersion(ctx, staged)
	if err != nil || strings.TrimSpace(reported) != p.Version {
		return c.fail(op, "binary_rejected", fmt.Sprintf("the downloaded binary does not report version %s (got %q, %v)", p.Version, strings.TrimSpace(reported), err))
	}

	if err := c.swap(staged); err != nil {
		return c.fail(op, "swap_failed", err.Error())
	}

	if p.NextPublicKey != "" {
		if err := atomicfile.Write(c.cfg.KeyPath, []byte(p.NextPublicKey+"\n"), 0o644); err != nil {
			return c.fail(op, "key_rotation_failed", err.Error())
		}
	}

	if err := c.cfg.Schedule(c.cfg.BinaryPath, p.Version); err != nil {
		return c.fail(op, "restart_not_scheduled", err.Error())
	}

	data, _ := json.Marshal(map[string]any{"from": c.cfg.CurrentVersion, "to": p.Version, "sha256": p.SHA256, "key_rotated": p.NextPublicKey != ""})

	return c.envelope(op, protocol.StatusApplied, p.Version+":"+p.SHA256, nil, data)
}

func (c *AgentUpgradeCapability) loadKey() (ed25519.PublicKey, error) {
	raw, err := os.ReadFile(c.cfg.KeyPath)
	if err != nil {
		return nil, errors.New("this node has no upgrade key; install one with the node installer's --upgrade-public-key")
	}

	return decodeKey(strings.TrimSpace(string(raw)))
}

func decodeKey(encoded string) (ed25519.PublicKey, error) {
	raw, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil || len(raw) != ed25519.PublicKeySize {
		return nil, errors.New("an upgrade key must be the base64 of a 32 byte Ed25519 public key")
	}

	return ed25519.PublicKey(raw), nil
}

// check runs every test that does not touch the network or the disk and returns an error code
// and message, or "" when the request is acceptable.
func (c *AgentUpgradeCapability) check(p payload, key ed25519.PublicKey) (string, string) {
	if !versionPattern.MatchString(p.Version) {
		return "invalid_version", "the version must look like 1.2.3"
	}

	if !sha256Pattern.MatchString(p.SHA256) {
		return "invalid_sha256", "the sha256 must be 64 lowercase hex characters"
	}

	if p.NextPublicKey != "" {
		if _, err := decodeKey(p.NextPublicKey); err != nil {
			return "invalid_next_key", err.Error()
		}
	}

	signature, err := base64.StdEncoding.DecodeString(p.Signature)
	if err != nil || len(signature) != ed25519.SignatureSize {
		return "invalid_signature", "the signature must be the base64 of a 64 byte Ed25519 signature"
	}

	if !ed25519.Verify(key, SignedMessage(p.NodeUUID, p.Version, p.SHA256, p.ExpiresAt, p.NextPublicKey), signature) {
		return "signature_mismatch", "the signature does not verify against this node's upgrade key"
	}

	if p.NodeUUID != c.cfg.NodeUUID || c.cfg.NodeUUID == "" {
		return "wrong_node", "the request is signed for another node"
	}

	now := c.cfg.Now()
	expires := time.Unix(p.ExpiresAt, 0)

	if !expires.After(now) {
		return "expired", "the signed request has expired"
	}

	if expires.After(now.Add(maxLifetime)) {
		return "lifetime_too_long", "the signed request is valid for longer than 24 hours"
	}

	if compareVersions(p.Version, c.cfg.CurrentVersion) <= 0 {
		return "not_newer", fmt.Sprintf("version %s is not newer than the running %s; downgrades are refused", p.Version, c.cfg.CurrentVersion)
	}

	return "", ""
}

// compareVersions compares two X.Y.Z versions numerically; an unparseable one sorts lowest.
func compareVersions(a, b string) int {
	pa, pb := parseVersion(a), parseVersion(b)

	for i := range pa {
		if pa[i] != pb[i] {
			if pa[i] < pb[i] {
				return -1
			}

			return 1
		}
	}

	return 0
}

func parseVersion(v string) [3]int {
	var out [3]int

	for i, part := range strings.SplitN(v, ".", 3) {
		n, err := strconv.Atoi(part)
		if err != nil {
			return [3]int{}
		}

		out[i] = n
	}

	return out
}

// downloadSource checks the address is the panel's own binary endpoint over https.
func (c *AgentUpgradeCapability) downloadSource(p payload) (*url.URL, error) {
	target, err := url.Parse(p.DownloadURL)
	control, cpErr := url.Parse(c.cfg.ControlPlaneURL)

	if err != nil || cpErr != nil || target.Host == "" || target.Host != control.Host || target.RawQuery != "" || !pathPattern.MatchString(target.Path) {
		return nil, errors.New("the download address is not the control plane's agent binary address")
	}

	if target.Scheme != "https" && !(c.cfg.AllowInsecureHTTP && target.Scheme == "http") {
		return nil, errors.New("the download address must use https")
	}

	if !tokenPattern.MatchString(p.DownloadToken) {
		return nil, errors.New("the download token is not valid")
	}

	return target, nil
}

// download fetches the binary into the binary's own (root-owned) directory and checks its sha256.
func (c *AgentUpgradeCapability) download(ctx context.Context, source *url.URL, p payload) (string, error) {
	client := c.cfg.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Minute}
	}

	noRedirects := *client
	noRedirects.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, source.String(), nil)
	if err != nil {
		return "", err
	}

	req.Header.Set("Authorization", "Bearer "+p.DownloadToken)

	resp, err := noRedirects.Do(req)
	if err != nil {
		return "", err
	}

	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("the control plane answered %d", resp.StatusCode)
	}

	file, err := os.CreateTemp(filepath.Dir(c.cfg.BinaryPath), ".upgrade-*.tmp")
	if err != nil {
		return "", err
	}

	hash := sha256.New()
	written, copyErr := io.Copy(io.MultiWriter(file, hash), io.LimitReader(resp.Body, maxBinaryBytes+1))
	closeErr := file.Close()

	fail := func(err error) (string, error) {
		os.Remove(file.Name())

		return "", err
	}

	if copyErr != nil {
		return fail(copyErr)
	}

	if closeErr != nil {
		return fail(closeErr)
	}

	if written > maxBinaryBytes {
		return fail(errors.New("the binary is larger than the allowed size"))
	}

	if hex.EncodeToString(hash.Sum(nil)) != p.SHA256 {
		return fail(errors.New("the downloaded binary does not match the signed sha256"))
	}

	if err := os.Chmod(file.Name(), 0o755); err != nil {
		return fail(err)
	}

	return file.Name(), nil
}

// swap keeps the running binary as .previous and moves the staged one into place.
func (c *AgentUpgradeCapability) swap(staged string) error {
	current, err := os.ReadFile(c.cfg.BinaryPath)
	if err != nil {
		return fmt.Errorf("reading the running binary: %w", err)
	}

	if err := atomicfile.Write(c.cfg.BinaryPath+".previous", current, 0o755); err != nil {
		return fmt.Errorf("backing up the running binary: %w", err)
	}

	return os.Rename(staged, c.cfg.BinaryPath)
}

func runVersion(ctx context.Context, binary string) (string, error) {
	runCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()

	out, err := exec.CommandContext(runCtx, binary, "version").Output() //nolint:gosec // the staged, checksum-verified binary
	if err != nil {
		return "", err
	}

	return string(out), nil
}

// restartScript runs a few seconds after the result is reported (so this applier is not killed
// before the daemon has it), restarts the agent's units onto the new binary and, a minute later,
// puts the previous binary back unless the daemon is running the new version. $1 is the binary and
// $2 the version; neither comes from the request except the version, which matched the pattern.
const restartScript = `
restart_units() {
  for u in lesta-applier lesta-applier-identity lesta-applier-tenant lesta-applier-backup lesta-agent-daemon lesta-applier-maintenance; do
    if systemctl is-enabled "$u" >/dev/null 2>&1; then systemctl restart "$u" >/dev/null 2>&1 || true; fi
  done
}
restart_units
sleep 60
if systemctl is-active --quiet lesta-agent-daemon && [ "$("$1" version 2>/dev/null)" = "$2" ]; then exit 0; fi
if [ -f "$1.previous" ]; then
  cp "$1.previous" "$1.rollback.tmp" && chmod 0755 "$1.rollback.tmp" && mv -f "$1.rollback.tmp" "$1"
  restart_units
fi
`

func scheduleRestart(binary, version string) error {
	args := []string{"--unit=lesta-agent-upgrade", "--on-active=3s", "--collect", "/bin/sh", "-c", restartScript, "lesta-upgrade", binary, version}

	if out, err := exec.Command("systemd-run", args...).CombinedOutput(); err != nil { //nolint:gosec // fixed script
		return fmt.Errorf("systemd-run: %v: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func (c *AgentUpgradeCapability) reject(op protocol.OperationEnvelope, code, message string) protocol.ResultEnvelope {
	return c.envelope(op, protocol.StatusRejected, "", []protocol.ResultError{{Code: code, Message: message}}, nil)
}

func (c *AgentUpgradeCapability) fail(op protocol.OperationEnvelope, code, message string) protocol.ResultEnvelope {
	return c.envelope(op, protocol.StatusFailed, "", []protocol.ResultError{{Code: code, Message: message}}, nil)
}

func (c *AgentUpgradeCapability) envelope(op protocol.OperationEnvelope, status protocol.Status, observed string, errs []protocol.ResultError, data json.RawMessage) protocol.ResultEnvelope {
	if errs == nil {
		errs = []protocol.ResultError{}
	}

	sum := sha256.Sum256([]byte(observed))

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: op.DesiredStateVersion,
		ObservedStateDigest:  "sha256:" + hex.EncodeToString(sum[:]),
		GenerationID:         "none",
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
		Data:                 data,
	}
}
