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

// TestContentTemplatesRenderErrorPagesForTheFourStatuses guards the custom
// error pages: each status prefers the domain's own <status>.html and falls
// back to a built-in page that keeps the original status code.
func TestContentTemplatesRenderErrorPagesForTheFourStatuses(t *testing.T) {
	for name, data := range map[string]vhostData{
		"static":     {},
		"static tls": {CertificatePath: "/etc/ssl/fullchain.pem"},
		"php":        {PhpSocket: "/run/php.sock", FastcgiParamsPath: "/etc/nginx/fastcgi_params"},
	} {
		t.Run(name, func(t *testing.T) {
			data.ResourceID = "00000000-0000-0000-0000-000000000003"
			data.Domain = "errors.example.test"
			data.IPAddress = "127.0.0.1"
			data.Port = 80
			data.SSLPort = 443
			data.Docroot = "/home/acct/domains/x/public"

			rendered, err := renderVhost(data, false)
			if err != nil {
				t.Fatalf("rendering: %v", err)
			}

			body := string(rendered)

			for _, code := range []string{"401", "403", "404", "500"} {
				for _, want := range []string{
					"error_page " + code + " @lesta_" + code + ";",
					"try_files /" + code + ".html @lesta_default_" + code + ";",
					"return " + code + " '<!doctype html>",
				} {
					if !strings.Contains(body, want) {
						t.Errorf("expected %q in rendered vhost:\n%s", want, body)
					}
				}
			}
		})
	}
}
