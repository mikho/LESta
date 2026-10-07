package nginx

import (
	"regexp"
	"strings"
	"testing"
)

// TestWebmailTemplateRoutesStaticPhpPathInfoToPhp guards the PHP location's
// own regex in webmail.conf.tmpl. Roundcube 1.7 serves every asset as
// /static.php/<path>, so a location that only matched URIs ending in .php
// silently sent all of them to index.php instead. The real-nginx tests can't
// prove this end to end (the template's root is the fixed production
// /var/www/lesta-webmail/public_html, so try_files 404s on any test host), so
// this renders the template and checks the rendered location regex itself
// against real Roundcube URIs, using Go's RE2 (a subset of the PCRE nginx
// uses, sufficient for this pattern).
func TestWebmailTemplateRoutesStaticPhpPathInfoToPhp(t *testing.T) {
	rendered, err := renderVhost(vhostData{
		ResourceID:        "00000000-0000-0000-0000-000000000001",
		Domain:            "mail.example.test",
		IPAddress:         "127.0.0.1",
		Port:              80,
		SSLPort:           443,
		AcmeChallengeDir:  "/var/lib/lesta/acme/challenges",
		CertificatePath:   "/var/lib/lesta/acme/certs/mail.example.test/fullchain.pem",
		PrivateKeyPath:    "/var/lib/lesta/acme/certs/mail.example.test/privkey.pem",
		AccessLogPath:     "/var/log/nginx/x.log",
		WebmailSocket:     "/run/lesta-webmail/webmail.sock",
		FastcgiParamsPath: "/etc/nginx/fastcgi_params",
	}, false)
	if err != nil {
		t.Fatalf("rendering webmail template: %v", err)
	}

	body := string(rendered)

	locationPattern := regexp.MustCompile(`location ~ (\S+) \{\s*fastcgi_split_path_info \^\(\.\+\?\\\.php\)\(/\.\*\)\$;`)
	m := locationPattern.FindStringSubmatch(body)
	if m == nil {
		t.Fatalf("expected a PHP location with fastcgi_split_path_info, got:\n%s", body)
	}

	phpLocation := regexp.MustCompile(m[1])

	for _, uri := range []string{"/index.php", "/static.php/skins/elastic/styles/styles.min.css", "/static.php/program/js/app.min.js"} {
		if !phpLocation.MatchString(uri) {
			t.Errorf("expected %q to reach PHP via %s", uri, m[1])
		}
	}

	for _, uri := range []string{"/", "/robots.txt", "/x.phps", "/index.php.bak"} {
		if phpLocation.MatchString(uri) {
			t.Errorf("expected %q never to reach PHP via %s", uri, m[1])
		}
	}

	if !strings.Contains(body, "fastcgi_param PATH_INFO $path_info;") {
		t.Errorf("expected PATH_INFO to be passed through, got:\n%s", body)
	}
}

func nodeToolsVhost(t *testing.T, webmailSocket, adminerSocket string) string {
	t.Helper()

	rendered, err := renderVhost(vhostData{
		ResourceID:        "00000000-0000-0000-0000-000000000002",
		Domain:            "node.example.test",
		IPAddress:         "127.0.0.1",
		Port:              80,
		SSLPort:           443,
		AcmeChallengeDir:  "/var/lib/lesta/acme/challenges",
		CertificatePath:   "/var/lib/lesta/acme/certs/node.example.test/fullchain.pem",
		PrivateKeyPath:    "/var/lib/lesta/acme/certs/node.example.test/privkey.pem",
		AccessLogPath:     "/var/log/nginx/x.log",
		WebmailSocket:     webmailSocket,
		AdminerSocket:     adminerSocket,
		FastcgiParamsPath: "/etc/nginx/fastcgi_params",
	}, false)
	if err != nil {
		t.Fatalf("rendering node tools template: %v", err)
	}

	return string(rendered)
}

// TestNodeToolsHostServesAdminerAlongsideWebmail proves the node's own
// hostname vhost hands /__lesta-adminer__ to Adminer's pool while still
// serving Roundcube everywhere else, and that the exact-match location is
// declared so the PHP regex location cannot capture it.
func TestNodeToolsHostServesAdminerAlongsideWebmail(t *testing.T) {
	body := nodeToolsVhost(t, "/run/lesta-webmail/webmail.sock", "/run/lesta-adminer/adminer.sock")

	for _, want := range []string{
		"location = /__lesta-adminer__ {",
		"fastcgi_pass unix:/run/lesta-adminer/adminer.sock;",
		"fastcgi_pass unix:/run/lesta-webmail/webmail.sock;",
		"try_files $uri $uri/ /index.php$is_args$args;",
	} {
		if !strings.Contains(body, want) {
			t.Errorf("expected the rendered vhost to contain %q, got:\n%s", want, body)
		}
	}
}

// TestNodeToolsHostWithAdminerOnlyRefusesEverythingElse proves a node with
// Adminer but no webmail still renders the tools vhost, answering 404 for
// every path except the Adminer hand-off, and never references a webmail pool.
func TestNodeToolsHostWithAdminerOnlyRefusesEverythingElse(t *testing.T) {
	body := nodeToolsVhost(t, "", "/run/lesta-adminer/adminer.sock")

	if !strings.Contains(body, "location = /__lesta-adminer__ {") {
		t.Errorf("expected the Adminer location, got:\n%s", body)
	}

	if !strings.Contains(body, "location / {\n        return 404;") {
		t.Errorf("expected every other path to answer 404, got:\n%s", body)
	}

	if strings.Contains(body, "webmail.sock") || strings.Contains(body, "index.php$is_args") {
		t.Errorf("expected no webmail pool in an Adminer-only vhost, got:\n%s", body)
	}
}

// TestNodeToolsHostWithWebmailOnlyHasNoAdminerLocation proves the Adminer
// hand-off is absent unless the payload carries an Adminer socket.
func TestNodeToolsHostWithWebmailOnlyHasNoAdminerLocation(t *testing.T) {
	body := nodeToolsVhost(t, "/run/lesta-webmail/webmail.sock", "")

	if strings.Contains(body, "__lesta-adminer__") {
		t.Errorf("expected no Adminer location without an adminer socket, got:\n%s", body)
	}
}

func TestServesNoMarkerForAnAdminerOnlyToolsHost(t *testing.T) {
	p := Payload{AdminerSocket: "/run/lesta-adminer/adminer.sock"}
	p.SSL.CertificatePath = "/etc/x/fullchain.pem"

	if !servesNoMarker(p) {
		t.Error("an Adminer-only tools host serves no marker page, so health probes must not wait for one")
	}
}
