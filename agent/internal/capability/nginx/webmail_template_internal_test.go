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
