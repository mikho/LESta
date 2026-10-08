package nginx

import (
	"strings"
	"testing"
)

// TestDefaultTemplatesServeTheDomainsOwnFiles guards the static-hosting
// behaviour of the non-PHP templates: "/" resolves files from the docroot
// (so "None (static only)" actually hosts a site) and the resource marker the
// health check reads lives on a dedicated path instead of answering every
// request.
func TestDefaultTemplatesServeTheDomainsOwnFiles(t *testing.T) {
	for name, certificate := range map[string]string{"http only": "", "with certificate": "/etc/ssl/fullchain.pem"} {
		t.Run(name, func(t *testing.T) {
			rendered, err := renderVhost(vhostData{
				ResourceID:       "00000000-0000-0000-0000-000000000002",
				Domain:           "static.example.test",
				IPAddress:        "127.0.0.1",
				Port:             80,
				SSLPort:          443,
				AcmeChallengeDir: "/var/lib/lesta/acme/challenges",
				CertificatePath:  certificate,
				PrivateKeyPath:   "/etc/ssl/privkey.pem",
				AccessLogPath:    "/var/log/nginx/x.log",
				Docroot:          "/home/acct/domains/x/public",
			}, false)
			if err != nil {
				t.Fatalf("rendering: %v", err)
			}

			body := string(rendered)

			for _, want := range []string{
				"root /home/acct/domains/x/public;",
				"location = /__lesta-health__ {",
				"try_files $uri $uri/ =404;",
				"location ~ /\\.ht {",
			} {
				if !strings.Contains(body, want) {
					t.Errorf("expected %q in rendered vhost:\n%s", want, body)
				}
			}

			if strings.Contains(body, "location / {\n        default_type text/plain;") {
				t.Errorf("'/' must not answer with the marker any more:\n%s", body)
			}
		})
	}
}
