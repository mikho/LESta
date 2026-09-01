// This file adds one more cross-capability integration test, in the same
// spirit as both_profile_test.go: proof that a real, disposable nginx
// instance -- configured only via tls.acme.v1's own real file writes and the
// new shared `.well-known/acme-challenge/` location block -- actually
// satisfies a real ACME server's real HTTP-01 validation. Pebble
// (github.com/letsencrypt/pebble/v2, Let's Encrypt's own official ACME v2
// test server) is `go install`ed on PATH exactly like nginx/apache2/bind9
// are all real disposable binaries this project already spins up, never a
// live third-party service.
//
// Scope: HTTP-01 only. The plan this phase was built from explicitly allows
// scoping this one Go-side test to HTTP-01 if hand-rolling a full ACME v2
// client (JWS signing, directory discovery, order/authorization/challenge/
// finalize) is too large to also extend to DNS-01's own ephemeral-TXT-record
// dance in the same file. It is: this file already hand-rolls the entire
// ACME v2 protocol using only crypto/, net/http, and encoding/json from the
// standard library (deliberately no new Go dependency -- this is Go-side
// plumbing to prove the Go capability's file-writing is correct against a
// real ACME server's real validation, not a production ACME client; the
// *real* production ACME client is the PHP one in App\Jobs\IssueAcmeCertificate,
// via acmephp/core). Adding DNS-01's own TXT-challenge JWS variant here too
// would roughly double this file's own protocol plumbing for no additional
// proof about tls.acme.v1 or nginx's own file-serving correctness (DNS-01
// doesn't touch either of them at all -- it only touches dns.bind9.v1's own
// zone-update path, which is NOT what this file exists to prove). Part C's
// own PHP tests are what prove the DNS-01 ephemeral-record flow end-to-end,
// using the real acmephp/core client against this exact same disposable
// Pebble instance.
//
// A real disposable `named` instance (rebuilt here from
// internal/capability/bind9/harness_test.go's own technique, using only
// bind9's exported New/Config/real Bind9Capability -- see this file's own
// disposableNamed type) answers Pebble's own DNS resolution for the test
// domain with a single real, agent-rendered A record pointing at 127.0.0.1.
// This is passed to Pebble via -dnsserver, matching the plan's own guidance,
// even though DNS-01 itself is out of this file's scope: Pebble's HTTP-01
// validator ALSO resolves the target domain through whatever resolver
// -dnsserver points at (see pebble's own va.go resolveIP), and the host
// system's default resolver on this dev machine returns "::1" before
// "127.0.0.1" for "localhost"-shaped names, which would make Pebble dial an
// address nginx isn't listening on. Pointing Pebble at a real, disposable,
// authoritative-only named instance side-steps that non-determinism
// entirely, with no hand-rolled DNS stub of our own.
package agent_test

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRealPebble skips the calling test, with a clear reason, if pebble
// isn't on PATH.
func requireRealPebble(t *testing.T) {
	t.Helper()

	if _, err := exec.LookPath("pebble"); err != nil {
		t.Skip("pebble is not installed on PATH; skipping the real Pebble-backed ACME end-to-end test (try: go install github.com/letsencrypt/pebble/v2/cmd/pebble@latest)")
	}
}

// requireRealNamed skips the calling test if named, named-checkconf, or rndc
// aren't all on PATH, mirroring internal/capability/bind9/harness_test.go's
// own requireRealBind9 exactly (that helper is unexported in a _test.go file
// in a different package, so it cannot be imported -- see this file's own
// top comment).
func requireRealNamed(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"named", "named-checkconf", "rndc"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real Pebble-backed ACME end-to-end test", bin)
		}
	}
}

// TestPebbleHTTP01IssuanceAgainstRealNginxAndAcmeCapability drives a minimal
// real ACME v2 protocol round trip (directory discovery, account creation,
// order, HTTP-01 challenge response, validation poll, CSR submission,
// certificate download) against a real disposable Pebble instance, proving
// that tls.acme.v1's own file-writing plus nginx's new
// `.well-known/acme-challenge/` location block genuinely satisfy Pebble's
// real HTTP-01 validator -- not a mock, not an assertion about the payload
// shape, an actual signed certificate downloaded from a real ACME server
// that only issued it because a real HTTP request it made itself, to a real
// nginx process, came back with the right content.
func TestPebbleHTTP01IssuanceAgainstRealNginxAndAcmeCapability(t *testing.T) {
	requireRealPebble(t)
	requireRealNginx(t)
	requireRealNamed(t)

	const domain = "acme-e2e.test"

	ctx := context.Background()

	named := newDisposableNamed(t)
	seedNamedARecord(t, named, domain)

	nginxD := newDisposableNginx(t)
	acmeStateRoot := t.TempDir()
	nginxCfg := nginxD.Config
	nginxCfg.AcmeChallengeDir = filepath.Join(acmeStateRoot, "http-01")
	nginxCap := nginx.New(nginxCfg)

	nginxResourceID := newTestUUID()
	nginxCreated, err := nginxCap.Apply(ctx, newOp("web.nginx.v1", protocol.OperationCreate, nginxResourceID, newTestUUID(), 1, webPayload(domain, "127.0.0.1", "default", false)))
	if err != nil || nginxCreated.Status != protocol.StatusApplied {
		t.Fatalf("creating the real nginx vhost: status=%s err=%v errors=%+v", nginxCreated.Status, err, nginxCreated.Errors)
	}

	acmeCap := acme.New(acme.Config{StateRoot: acmeStateRoot})
	acmeResourceID := newTestUUID()

	pebble := newDisposablePebble(t, named.Port, nginxD.Port)

	client := newAcmeTestClient(pebble.directoryURL, pebble.certPool)
	if err := client.fetchDirectory(); err != nil {
		t.Fatalf("fetching pebble's ACME directory: %v", err)
	}
	if err := client.primeNonce(); err != nil {
		t.Fatalf("priming an initial anti-replay nonce: %v", err)
	}
	if err := client.registerAccount(); err != nil {
		t.Fatalf("registering an ACME account: %v", err)
	}

	order, orderURL, err := client.newOrder(domain)
	if err != nil {
		t.Fatalf("requesting a new order: %v", err)
	}

	if len(order.Authorizations) != 1 {
		t.Fatalf("expected exactly one authorization for a single-domain order, got %d", len(order.Authorizations))
	}

	authz, err := client.getAuthorization(order.Authorizations[0])
	if err != nil {
		t.Fatalf("fetching the order's authorization: %v", err)
	}

	var challenge *acmeChallenge

	for i := range authz.Challenges {
		if authz.Challenges[i].Type == "http-01" {
			challenge = &authz.Challenges[i]

			break
		}
	}

	if challenge == nil {
		t.Fatalf("pebble did not offer an http-01 challenge for this authorization: %+v", authz.Challenges)
	}

	keyAuthorization := client.keyAuthorization(challenge.Token)

	createChallengeOp := newOp("tls.acme.v1", protocol.OperationCreate, acmeResourceID, newTestUUID(), 1, map[string]any{
		"kind":              "http01_challenge",
		"token":             challenge.Token,
		"key_authorization": keyAuthorization,
	})

	challengeResult, err := acmeCap.Apply(ctx, createChallengeOp)
	if err != nil || challengeResult.Status != protocol.StatusApplied {
		t.Fatalf("staging the http-01 challenge via the real tls.acme.v1 capability: status=%s err=%v errors=%+v", challengeResult.Status, err, challengeResult.Errors)
	}

	t.Cleanup(func() {
		deleteChallengeOp := newOp("tls.acme.v1", protocol.OperationDelete, acmeResourceID, newTestUUID(), 2, map[string]any{
			"kind":              "http01_challenge",
			"token":             challenge.Token,
			"key_authorization": keyAuthorization,
		})
		_, _ = acmeCap.Apply(ctx, deleteChallengeOp)
	})

	// Independently confirm, with our own real HTTP request through nginx's
	// own listener, that the challenge file tls.acme.v1 just wrote is
	// actually reachable at the exact path Pebble's own HTTP-01 validator
	// will request -- the same proof internal/capability/nginx's own
	// TestAcmeChallengeLocationServesRealFile makes, repeated here against
	// the SAME files Pebble itself is about to validate against, not a
	// separately-written test fixture.
	verifyChallengeReachableThroughNginx(t, nginxD.Port, domain, challenge.Token, keyAuthorization)

	if err := client.respondToChallenge(challenge.URL); err != nil {
		t.Fatalf("telling pebble to validate the http-01 challenge: %v", err)
	}

	finalStatus, err := client.pollAuthorization(order.Authorizations[0], 20*time.Second)
	if err != nil {
		t.Fatalf("polling the authorization to a terminal status: %v", err)
	}
	if finalStatus != "valid" {
		t.Fatalf("expected pebble's real HTTP-01 validation to succeed (authorization status \"valid\"), got %q -- this means the real request pebble made to nginx's own .well-known/acme-challenge/ location did not come back with the expected content", finalStatus)
	}
	t.Logf("confirmed pebble's own real HTTP-01 validator successfully validated a real HTTP request against nginx's own .well-known/acme-challenge/ location, serving a file tls.acme.v1 itself wrote")

	certKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("generating the certificate's own key pair: %v", err)
	}

	csrDER, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject:  pkix.Name{CommonName: domain},
		DNSNames: []string{domain},
	}, certKey)
	if err != nil {
		t.Fatalf("creating the certificate signing request: %v", err)
	}

	finalizedOrder, err := client.finalizeOrder(order.Finalize, csrDER)
	if err != nil {
		t.Fatalf("finalizing the order: %v", err)
	}

	finalizedOrder, err = client.pollOrder(finalizedOrder, orderURL, 20*time.Second)
	if err != nil {
		t.Fatalf("polling the order to a terminal status: %v", err)
	}
	if finalizedOrder.Status != "valid" {
		t.Fatalf("expected the order to reach status \"valid\" after finalization, got %q", finalizedOrder.Status)
	}

	certChainPEM, err := client.downloadCertificate(finalizedOrder.Certificate)
	if err != nil {
		t.Fatalf("downloading the issued certificate: %v", err)
	}

	leafBlock, _ := pem.Decode(certChainPEM)
	if leafBlock == nil {
		t.Fatalf("expected at least one PEM block in the downloaded certificate chain, got none in: %q", certChainPEM)
	}

	leaf, err := x509.ParseCertificate(leafBlock.Bytes)
	if err != nil {
		t.Fatalf("parsing the issued certificate: %v", err)
	}

	if len(leaf.DNSNames) != 1 || leaf.DNSNames[0] != domain {
		t.Fatalf("expected the issued certificate's DNSNames to be exactly [%q], got %v", domain, leaf.DNSNames)
	}
	t.Logf("confirmed a real certificate was issued by pebble for %q, only because tls.acme.v1's challenge file + nginx's location block satisfied a real HTTP-01 validation", domain)

	// Close the loop: write the real issued certificate through
	// tls.acme.v1's own "certificate" kind, and confirm it lands exactly
	// where WebDomain::toProvisioningPayload('web.nginx.v1') will point
	// nginx's own ssl.certificate_path/private_key_path once issuance
	// succeeds for real.
	keyDER, err := x509.MarshalECPrivateKey(certKey)
	if err != nil {
		t.Fatalf("marshaling the certificate's own private key: %v", err)
	}
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})

	installCertOp := newOp("tls.acme.v1", protocol.OperationCreate, acmeResourceID, newTestUUID(), 3, map[string]any{
		"kind":            "certificate",
		"domain":          domain,
		"full_chain_pem":  string(certChainPEM),
		"private_key_pem": string(keyPEM),
	})

	installResult, err := acmeCap.Apply(ctx, installCertOp)
	if err != nil || installResult.Status != protocol.StatusApplied {
		t.Fatalf("installing the real issued certificate via tls.acme.v1: status=%s err=%v errors=%+v", installResult.Status, err, installResult.Errors)
	}

	installedChain, err := os.ReadFile(filepath.Join(acmeStateRoot, "certs", domain, "fullchain.pem"))
	if err != nil {
		t.Fatalf("reading the installed fullchain.pem: %v", err)
	}
	if !bytes.Equal(installedChain, certChainPEM) {
		t.Fatalf("expected the installed fullchain.pem to byte-match the real certificate pebble issued")
	}
	t.Logf("confirmed the real issued certificate was written to disk by tls.acme.v1's own certificate kind, at the exact path nginx's own ssl.certificate_path will point at")
}

// verifyChallengeReachableThroughNginx issues its own real HTTP GET (not
// trusting anything Pebble itself will do) against the exact path Pebble's
// HTTP-01 validator is about to request.
func verifyChallengeReachableThroughNginx(t *testing.T, nginxPort int, domain, token, expectedKeyAuthorization string) {
	t.Helper()

	url := fmt.Sprintf("http://127.0.0.1:%d/.well-known/acme-challenge/%s", nginxPort, token)

	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		t.Fatalf("building challenge verification request: %v", err)
	}
	req.Host = domain

	client := &http.Client{Timeout: 5 * time.Second}

	var lastErr error

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		body, readErr := io.ReadAll(resp.Body)
		_ = resp.Body.Close()

		if readErr != nil {
			t.Fatalf("reading challenge verification response: %v", readErr)
		}
		if resp.StatusCode != http.StatusOK {
			t.Fatalf("expected 200 from %s, got %d: %s", url, resp.StatusCode, body)
		}
		if string(body) != expectedKeyAuthorization {
			t.Fatalf("expected challenge content %q, got %q", expectedKeyAuthorization, string(body))
		}

		return
	}

	t.Fatalf("never got a response from %s: %v", url, lastErr)
}
