package agent_test

// Supporting harnesses and a minimal, hand-rolled ACME v2 client for
// acme_end_to_end_test.go. See that file's own top comment for the overall
// rationale (why named is real and disposable, why Pebble is real and
// disposable, why this file's ACME client is deliberately minimal and
// stdlib-only, and why DNS-01 is out of this specific test's scope).

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// --- disposable named (rebuilt from bind9/harness_test.go's own technique,
// using only bind9's exported New/Config/Bind9Capability -- see this
// file's sibling's own top comment for why it can't just import that
// package's own unexported test helpers) --------------------------------

type disposableNamed struct {
	Config   bind9.Config
	Port     int
	rndcConf string
	pidPath  string
	binary   string
}

func newDisposableNamed(t *testing.T) *disposableNamed {
	t.Helper()

	prefix := t.TempDir()
	liveDir := filepath.Join(prefix, "lesta.d")
	stateRoot := filepath.Join(prefix, "state")
	logsDir := filepath.Join(prefix, "logs")

	for _, dir := range []string{liveDir, stateRoot, logsDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	// named's own glob include fails outright if it matches zero files,
	// mirroring bind9/harness_test.go's own identical seeding step.
	if err := os.WriteFile(filepath.Join(liveDir, "_lesta-placeholder.conf"), []byte("# placeholder\n"), 0o644); err != nil {
		t.Fatalf("seeding placeholder fragment: %v", err)
	}

	dnsPort := freePort(t)
	rndcPort := freePort(t)

	pidPath := filepath.Join(prefix, "named.pid")
	namedConfPath := filepath.Join(prefix, "named.conf")
	rndcConfPath := filepath.Join(prefix, "rndc.conf")

	secretBytes := make([]byte, 32)
	if _, err := rand.Read(secretBytes); err != nil {
		t.Fatalf("generating rndc key secret: %v", err)
	}
	secret := base64.StdEncoding.EncodeToString(secretBytes)

	namedConfBody := fmt.Sprintf(`options {
    directory "%s";
    pid-file "%s";
    listen-on port %d { 127.0.0.1; };
    listen-on-v6 { none; };
    recursion no;
    dnssec-validation no;
    allow-transfer { none; };
};

logging {
    channel default_log {
        file "%s";
        severity info;
        print-time yes;
    };
    category default { default_log; };
};

controls {
    inet 127.0.0.1 port %d allow { 127.0.0.1; } keys { "rndc-key"; };
};

key "rndc-key" {
    algorithm hmac-sha256;
    secret "%s";
};

include "%s";
`,
		prefix,
		pidPath,
		dnsPort,
		filepath.Join(logsDir, "named.log"),
		rndcPort,
		secret,
		filepath.Join(liveDir, "*.conf"),
	)

	if err := os.WriteFile(namedConfPath, []byte(namedConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable named.conf: %v", err)
	}

	rndcConfBody := fmt.Sprintf(`options {
    default-server 127.0.0.1;
    default-port %d;
    default-key "rndc-key";
};

key "rndc-key" {
    algorithm hmac-sha256;
    secret "%s";
};
`,
		rndcPort,
		secret,
	)

	if err := os.WriteFile(rndcConfPath, []byte(rndcConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable rndc.conf: %v", err)
	}

	d := &disposableNamed{
		Port:     dnsPort,
		rndcConf: rndcConfPath,
		pidPath:  pidPath,
		binary:   "named",
		Config: bind9.Config{
			LiveDir:              liveDir,
			StateRoot:            stateRoot,
			NamedConfPath:        namedConfPath,
			NamedCheckconfBinary: "named-checkconf",
			RndcBinary:           "rndc",
			RndcConfigPath:       rndcConfPath,
			ListenAddress:        "127.0.0.1",
			Port:                 dnsPort,
			// Deliberately out-of-bailiwick (a different domain than any
			// zone this instance ever serves): an in-bailiwick nameserver
			// name would need its own glue A/AAAA record inside the same
			// zone, or named-checkconf -z rejects the whole zone as
			// unloadable ("has no address records"), found via a real CI
			// failure exactly like bind9/harness_test.go's own identical
			// nameservers were chosen for.
			Nameservers: []string{"ns1.lesta-hosting.test.", "ns2.lesta-hosting.test."},
		},
	}

	cmd := exec.Command("named", "-c", namedConfPath)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("starting disposable named: %v: %s", err, out)
	}

	if err := waitForPidFile(pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable named never wrote its pid file: %v", err)
	}

	t.Cleanup(func() {
		pid := readPid(pidPath)
		_ = exec.Command("rndc", "-c", rndcConfPath, "stop").Run()
		if pid > 0 {
			waitForProcessExit(pid, 5*time.Second)
		}
	})

	return d
}

// seedNamedARecord creates a real zone for domain on named, via the real
// Bind9Capability (not a hand-written zone file), containing exactly one A
// record at the zone apex pointing at 127.0.0.1 -- the same disposable
// nginx instance this test's own tls.acme.v1/nginx capabilities configure.
func seedNamedARecord(t *testing.T, named *disposableNamed, domain string) {
	t.Helper()

	capability := bind9.New(named.Config)

	payload := map[string]any{
		"domain": domain,
		"ttl":    300,
		"records": []map[string]any{
			{"name": "@", "type": "A", "priority": nil, "value": "127.0.0.1", "suspended": false},
		},
		"suspended": false,
	}

	result, err := capability.Apply(context.Background(), newOp("dns.bind9.v1", protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload))
	if err != nil || result.Status != protocol.StatusApplied {
		t.Fatalf("seeding a real A record for %s on the disposable named instance: status=%s err=%v errors=%+v", domain, result.Status, err, result.Errors)
	}
}

func readPid(pidPath string) int {
	raw, err := os.ReadFile(pidPath)
	if err != nil {
		return 0
	}

	pid, err := strconv.Atoi(strings.TrimSpace(string(raw)))
	if err != nil {
		return 0
	}

	return pid
}

func waitForProcessExit(pid int, timeout time.Duration) {
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		proc, err := os.FindProcess(pid)
		if err != nil {
			return
		}

		if err := proc.Signal(syscall.Signal(0)); err != nil {
			return
		}

		time.Sleep(50 * time.Millisecond)
	}
}

// --- disposable Pebble ----------------------------------------------------

type disposablePebble struct {
	directoryURL string
	certPool     *x509.CertPool
}

// newDisposablePebble starts a fresh, disposable pebble process configured
// with a self-signed certificate generated on the fly (never fetching or
// vendoring pebble's own bundled test certs, matching this project's own
// "generate on the fly" precedent for the nginx SSL test), pointed at
// namedPort for DNS resolution (see this file's sibling's own top comment
// for why) and httpPort set to the real disposable nginx instance's own
// port, so pebble's HTTP-01 validator's requests land on it directly.
func newDisposablePebble(t *testing.T, namedPort, nginxHTTPPort int) *disposablePebble {
	t.Helper()

	prefix := t.TempDir()

	certPath, keyPath, pool := generateSelfSignedIPCertificate(t, prefix, "127.0.0.1")

	dirPort := freePort(t)
	tlsPort := freePort(t) // unused (no TLS-ALPN-01 this phase), but pebble's config schema still wants a value

	type profile struct {
		Description    string `json:"description"`
		ValidityPeriod int    `json:"validityPeriod"`
	}

	config := struct {
		Pebble struct {
			ListenAddress    string   `json:"listenAddress"`
			Certificate      string   `json:"certificate"`
			PrivateKey       string   `json:"privateKey"`
			HTTPPort         int      `json:"httpPort"`
			TLSPort          int      `json:"tlsPort"`
			OCSPResponderURL string   `json:"ocspResponderURL"`
			DomainBlocklist  []string `json:"domainBlocklist"`
			KeyAlgorithm     string   `json:"keyAlgorithm"`
			RetryAfter       struct {
				Authz int `json:"authz"`
				Order int `json:"order"`
			} `json:"retryAfter"`
			Profiles map[string]profile `json:"profiles"`
		} `json:"pebble"`
	}{}

	config.Pebble.ListenAddress = fmt.Sprintf("127.0.0.1:%d", dirPort)
	config.Pebble.Certificate = certPath
	config.Pebble.PrivateKey = keyPath
	config.Pebble.HTTPPort = nginxHTTPPort
	config.Pebble.TLSPort = tlsPort
	config.Pebble.KeyAlgorithm = "ecdsa"
	config.Pebble.RetryAfter.Authz = 1
	config.Pebble.RetryAfter.Order = 1
	config.Pebble.Profiles = map[string]profile{
		"default": {Description: "default", ValidityPeriod: 7776000},
	}

	configBytes, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		t.Fatalf("encoding pebble config: %v", err)
	}

	configPath := filepath.Join(prefix, "pebble-config.json")
	if err := os.WriteFile(configPath, configBytes, 0o644); err != nil {
		t.Fatalf("writing pebble config: %v", err)
	}

	logPath := filepath.Join(prefix, "pebble.log")
	logFile, err := os.Create(logPath)
	if err != nil {
		t.Fatalf("creating pebble log file: %v", err)
	}

	cmd := exec.Command("pebble", "-config", configPath, "-dnsserver", fmt.Sprintf("127.0.0.1:%d", namedPort))
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	// Pebble deliberately injects chaos by default (undocumented until you
	// hit it -- see Pebble's own README): it rejects ~5% of otherwise-good
	// anti-replay nonces on purpose, specifically to stress-test real ACME
	// clients' own nonce-retry handling. This test's own acmeTestClient.post
	// already retries once on a rejected nonce (re-signing with the fresh
	// nonce the rejection response itself carries, unlike acmephp/core's own
	// broken equivalent -- see the PHP-side Pebble test's identical
	// discovery), but pollAuthorization/pollOrder call post() up to ~100
	// times over their own polling window, and a request that gets
	// chaos-rejected twice in a row (a low but real per-poll probability
	// that compounds over that many attempts) still fails outright. Since
	// this chaos has nothing to do with what this test exists to prove
	// (real HTTP-01 challenge validation against tls.acme.v1's own file
	// writes and nginx's own location block), PEBBLE_WFE_NONCEREJECT=0
	// disables it; every other part of the real ACME protocol round trip
	// (challenge validation, order finalization, certificate issuance)
	// still happens for real.
	cmd.Env = append(os.Environ(), "PEBBLE_WFE_NONCEREJECT=0")

	if err := cmd.Start(); err != nil {
		t.Fatalf("starting disposable pebble: %v", err)
	}

	t.Cleanup(func() {
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
			_, _ = cmd.Process.Wait()
		}
		_ = logFile.Close()
	})

	d := &disposablePebble{
		directoryURL: fmt.Sprintf("https://127.0.0.1:%d/dir", dirPort),
		certPool:     pool,
	}

	waitForPebbleReady(t, d, logPath)

	return d
}

func waitForPebbleReady(t *testing.T, d *disposablePebble, logPath string) {
	t.Helper()

	client := &http.Client{
		Timeout:   2 * time.Second,
		Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: d.certPool}},
	}

	var lastErr error

	for deadline := time.Now().Add(10 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Get(d.directoryURL)
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return
			}
			lastErr = fmt.Errorf("unexpected status %d", resp.StatusCode)
		} else {
			lastErr = err
		}

		time.Sleep(100 * time.Millisecond)
	}

	logContent, _ := os.ReadFile(logPath)
	t.Fatalf("pebble never became ready at %s: %v\npebble log:\n%s", d.directoryURL, lastErr, logContent)
}

// generateSelfSignedIPCertificate generates, entirely on the fly via Go's
// own crypto/x509 (never fetching or vendoring pebble's own bundled test
// certs), a minimal self-signed ECDSA certificate valid for the given IP
// address (an IP SAN, not a DNS SAN: this test's own Go HTTP client always
// dials pebble by 127.0.0.1 directly, never by a hostname), writing
// cert.pem/key.pem under dir and returning their paths plus an
// *x509.CertPool a test HTTPS client can use to trust it.
func generateSelfSignedIPCertificate(t *testing.T, dir, ipAddress string) (certPath, keyPath string, pool *x509.CertPool) {
	t.Helper()

	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("generating pebble's own test certificate key: %v", err)
	}

	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
	if err != nil {
		t.Fatalf("generating pebble's own test certificate serial number: %v", err)
	}

	template := x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: ipAddress},
		IPAddresses:           []net.IP{net.ParseIP(ipAddress)},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(time.Hour),
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IsCA:                  true,
	}

	derBytes, err := x509.CreateCertificate(rand.Reader, &template, &template, &priv.PublicKey, priv)
	if err != nil {
		t.Fatalf("creating pebble's own self-signed test certificate: %v", err)
	}

	certPath = filepath.Join(dir, "pebble-cert.pem")
	keyPath = filepath.Join(dir, "pebble-key.pem")

	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: derBytes})
	if err := os.WriteFile(certPath, certPEM, 0o644); err != nil {
		t.Fatalf("writing pebble's own test certificate: %v", err)
	}

	keyDER, err := x509.MarshalECPrivateKey(priv)
	if err != nil {
		t.Fatalf("marshaling pebble's own test private key: %v", err)
	}

	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})
	if err := os.WriteFile(keyPath, keyPEM, 0o600); err != nil {
		t.Fatalf("writing pebble's own test private key: %v", err)
	}

	pool = x509.NewCertPool()
	pool.AppendCertsFromPEM(certPEM)

	return certPath, keyPath, pool
}

// --- a minimal, hand-rolled ACME v2 client (RFC 8555), stdlib-only --------

type jwkKey struct {
	Crv string `json:"crv"`
	Kty string `json:"kty"`
	X   string `json:"x"`
	Y   string `json:"y"`
}

type joseHeader struct {
	Alg   string  `json:"alg"`
	Jwk   *jwkKey `json:"jwk,omitempty"`
	Kid   string  `json:"kid,omitempty"`
	Nonce string  `json:"nonce"`
	URL   string  `json:"url"`
}

type acmeDirectory struct {
	NewNonce   string `json:"newNonce"`
	NewAccount string `json:"newAccount"`
	NewOrder   string `json:"newOrder"`
}

type acmeOrder struct {
	Status         string   `json:"status"`
	Authorizations []string `json:"authorizations"`
	Finalize       string   `json:"finalize"`
	Certificate    string   `json:"certificate"`
}

type acmeChallenge struct {
	Type   string `json:"type"`
	URL    string `json:"url"`
	Token  string `json:"token"`
	Status string `json:"status"`
}

type acmeAuthorization struct {
	Status     string          `json:"status"`
	Challenges []acmeChallenge `json:"challenges"`
}

// acmeTestClient is a deliberately minimal ACME v2 client: just enough
// protocol surface (JWS signing over ES256, directory discovery, account
// registration, order/authorization/challenge/finalize/certificate-download)
// to prove tls.acme.v1's own file-writing satisfies a real ACME server's
// real HTTP-01 validation. It is not, and is not meant to become, a
// general-purpose or production-grade ACME client -- that role belongs
// entirely to acmephp/core on the PHP side (App\Jobs\IssueAcmeCertificate).
type acmeTestClient struct {
	httpClient   *http.Client
	key          *ecdsa.PrivateKey
	directoryURL string
	dir          acmeDirectory
	nonce        string
	accountURL   string
}

func newAcmeTestClient(directoryURL string, pool *x509.CertPool) *acmeTestClient {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		panic(fmt.Sprintf("generating ACME account key: %v", err))
	}

	return &acmeTestClient{
		httpClient: &http.Client{
			Timeout:   10 * time.Second,
			Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool}},
		},
		key:          key,
		directoryURL: directoryURL,
	}
}

func (c *acmeTestClient) fetchDirectory() error {
	resp, err := c.httpClient.Get(c.directoryURL)
	if err != nil {
		return fmt.Errorf("fetching directory: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("unexpected directory status %d", resp.StatusCode)
	}

	return json.NewDecoder(resp.Body).Decode(&c.dir)
}

func (c *acmeTestClient) publicJWK() *jwkKey {
	x := c.key.PublicKey.X.FillBytes(make([]byte, 32))
	y := c.key.PublicKey.Y.FillBytes(make([]byte, 32))

	return &jwkKey{
		Crv: "P-256",
		Kty: "EC",
		X:   base64.RawURLEncoding.EncodeToString(x),
		Y:   base64.RawURLEncoding.EncodeToString(y),
	}
}

// jwkThumbprint computes the RFC 7638 JWK thumbprint: the base64url-encoded
// SHA-256 digest of the JWK's own canonical JSON form (lexicographic member
// order: crv, kty, x, y). jwkKey's own struct field declaration order
// already matches that exactly, so a plain json.Marshal produces the
// required canonical form with no extra canonicalization step.
func (c *acmeTestClient) jwkThumbprint() string {
	raw, err := json.Marshal(c.publicJWK())
	if err != nil {
		panic(fmt.Sprintf("marshaling JWK for thumbprint: %v", err))
	}

	sum := sha256.Sum256(raw)

	return base64.RawURLEncoding.EncodeToString(sum[:])
}

// keyAuthorization computes the RFC 8555 §8.1 key authorization for token:
// the exact content tls.acme.v1's own http01_challenge kind must write to
// disk for nginx's shared .well-known/acme-challenge/ location to serve.
func (c *acmeTestClient) keyAuthorization(token string) string {
	return token + "." + c.jwkThumbprint()
}

func signJWS(key *ecdsa.PrivateKey, header joseHeader, payload []byte) ([]byte, error) {
	headerBytes, err := json.Marshal(header)
	if err != nil {
		return nil, fmt.Errorf("encoding JWS protected header: %w", err)
	}

	protected := base64.RawURLEncoding.EncodeToString(headerBytes)
	payloadB64 := base64.RawURLEncoding.EncodeToString(payload)
	signingInput := protected + "." + payloadB64

	digest := sha256.Sum256([]byte(signingInput))

	r, s, err := ecdsa.Sign(rand.Reader, key, digest[:])
	if err != nil {
		return nil, fmt.Errorf("signing JWS: %w", err)
	}

	sig := make([]byte, 64)
	r.FillBytes(sig[:32])
	s.FillBytes(sig[32:])

	return json.Marshal(struct {
		Protected string `json:"protected"`
		Payload   string `json:"payload"`
		Signature string `json:"signature"`
	}{protected, payloadB64, base64.RawURLEncoding.EncodeToString(sig)})
}

// post signs payload (nil/empty means a POST-as-GET, per RFC 8555 §6.3) and
// POSTs it to url, using the account's own "kid" once useKid is true (every
// request except the very first account-creation request, which must
// instead embed the raw "jwk"). A single retry absorbs the one legitimate
// reason to retry an ACME request client-side: the server rejecting a
// consumed nonce (RFC 8555 §6.5), never anything else.
func (c *acmeTestClient) post(url string, payload []byte, useKid bool) (*http.Response, []byte, error) {
	var (
		resp     *http.Response
		respBody []byte
	)

	for attempt := 0; attempt < 2; attempt++ {
		header := joseHeader{Alg: "ES256", Nonce: c.nonce, URL: url}
		if useKid {
			header.Kid = c.accountURL
		} else {
			header.Jwk = c.publicJWK()
		}

		body, err := signJWS(c.key, header, payload)
		if err != nil {
			return nil, nil, err
		}

		var err2 error

		resp, err2 = c.httpClient.Post(url, "application/jose+json", strings.NewReader(string(body)))
		if err2 != nil {
			return nil, nil, fmt.Errorf("posting to %s: %w", url, err2)
		}

		respBody, err2 = readAllAndClose(resp)
		if err2 != nil {
			return nil, nil, err2
		}

		if nonce := resp.Header.Get("Replay-Nonce"); nonce != "" {
			c.nonce = nonce
		}

		if resp.StatusCode == http.StatusBadRequest && attempt == 0 && strings.Contains(string(respBody), "badNonce") {
			continue
		}

		return resp, respBody, nil
	}

	return resp, respBody, nil
}

func readAllAndClose(resp *http.Response) ([]byte, error) {
	defer resp.Body.Close()

	return io.ReadAll(resp.Body)
}

func (c *acmeTestClient) primeNonce() error {
	resp, err := c.httpClient.Head(c.dir.NewNonce)
	if err != nil {
		return fmt.Errorf("fetching an initial nonce: %w", err)
	}
	defer resp.Body.Close()

	nonce := resp.Header.Get("Replay-Nonce")
	if nonce == "" {
		return fmt.Errorf("no Replay-Nonce header from newNonce endpoint")
	}

	c.nonce = nonce

	return nil
}

func (c *acmeTestClient) registerAccount() error {
	payload, err := json.Marshal(map[string]any{"termsOfServiceAgreed": true})
	if err != nil {
		return err
	}

	resp, body, err := c.post(c.dir.NewAccount, payload, false)
	if err != nil {
		return err
	}
	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		return fmt.Errorf("unexpected account registration status %d: %s", resp.StatusCode, body)
	}

	location := resp.Header.Get("Location")
	if location == "" {
		return fmt.Errorf("account registration response carried no Location header")
	}

	c.accountURL = location

	return nil
}

func (c *acmeTestClient) newOrder(domain string) (acmeOrder, string, error) {
	payload, err := json.Marshal(map[string]any{
		"identifiers": []map[string]string{{"type": "dns", "value": domain}},
	})
	if err != nil {
		return acmeOrder{}, "", err
	}

	resp, body, err := c.post(c.dir.NewOrder, payload, true)
	if err != nil {
		return acmeOrder{}, "", err
	}
	if resp.StatusCode != http.StatusCreated {
		return acmeOrder{}, "", fmt.Errorf("unexpected new-order status %d: %s", resp.StatusCode, body)
	}

	var order acmeOrder
	if err := json.Unmarshal(body, &order); err != nil {
		return acmeOrder{}, "", fmt.Errorf("decoding order: %w", err)
	}

	return order, resp.Header.Get("Location"), nil
}

func (c *acmeTestClient) getAuthorization(url string) (acmeAuthorization, error) {
	resp, body, err := c.post(url, nil, true)
	if err != nil {
		return acmeAuthorization{}, err
	}
	if resp.StatusCode != http.StatusOK {
		return acmeAuthorization{}, fmt.Errorf("unexpected authorization status %d: %s", resp.StatusCode, body)
	}

	var authz acmeAuthorization
	if err := json.Unmarshal(body, &authz); err != nil {
		return acmeAuthorization{}, fmt.Errorf("decoding authorization: %w", err)
	}

	return authz, nil
}

func (c *acmeTestClient) respondToChallenge(challengeURL string) error {
	resp, body, err := c.post(challengeURL, []byte("{}"), true)
	if err != nil {
		return err
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("unexpected challenge-response status %d: %s", resp.StatusCode, body)
	}

	return nil
}

func (c *acmeTestClient) pollAuthorization(url string, timeout time.Duration) (string, error) {
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		authz, err := c.getAuthorization(url)
		if err != nil {
			return "", err
		}

		if authz.Status == "valid" || authz.Status == "invalid" {
			return authz.Status, nil
		}

		time.Sleep(200 * time.Millisecond)
	}

	return "", fmt.Errorf("authorization %s did not reach a terminal status within %s", url, timeout)
}

func (c *acmeTestClient) finalizeOrder(finalizeURL string, csrDER []byte) (acmeOrder, error) {
	payload, err := json.Marshal(map[string]string{
		"csr": base64.RawURLEncoding.EncodeToString(csrDER),
	})
	if err != nil {
		return acmeOrder{}, err
	}

	resp, body, err := c.post(finalizeURL, payload, true)
	if err != nil {
		return acmeOrder{}, err
	}
	if resp.StatusCode != http.StatusOK {
		return acmeOrder{}, fmt.Errorf("unexpected finalize status %d: %s", resp.StatusCode, body)
	}

	var order acmeOrder
	if err := json.Unmarshal(body, &order); err != nil {
		return acmeOrder{}, fmt.Errorf("decoding finalized order: %w", err)
	}

	return order, nil
}

func (c *acmeTestClient) pollOrder(current acmeOrder, orderURL string, timeout time.Duration) (acmeOrder, error) {
	if current.Status == "valid" {
		return current, nil
	}

	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		resp, body, err := c.post(orderURL, nil, true)
		if err != nil {
			return acmeOrder{}, err
		}
		if resp.StatusCode != http.StatusOK {
			return acmeOrder{}, fmt.Errorf("unexpected order-poll status %d: %s", resp.StatusCode, body)
		}

		var order acmeOrder
		if err := json.Unmarshal(body, &order); err != nil {
			return acmeOrder{}, fmt.Errorf("decoding polled order: %w", err)
		}

		if order.Status == "valid" || order.Status == "invalid" {
			return order, nil
		}

		time.Sleep(200 * time.Millisecond)
	}

	return acmeOrder{}, fmt.Errorf("order %s did not reach a terminal status within %s", orderURL, timeout)
}

func (c *acmeTestClient) downloadCertificate(certURL string) ([]byte, error) {
	resp, body, err := c.post(certURL, nil, true)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("unexpected certificate-download status %d: %s", resp.StatusCode, body)
	}

	return body, nil
}
