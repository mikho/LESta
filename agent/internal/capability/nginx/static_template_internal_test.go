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
				"try_files $uri $uri/index.html $uri/index.htm =404;",
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

// TestWafDirectivesFollowTheDomainsMode guards the ModSecurity rendering:
// off renders nothing, detect logs only, block enforces, and excluded rule
// ids come after the rule set is loaded (ModSecurity only removes rules
// defined before the removal).
func TestWafDirectivesFollowTheDomainsMode(t *testing.T) {
	render := func(mode string, rules []int) string {
		rendered, err := renderVhost(vhostData{
			ResourceID:       "00000000-0000-0000-0000-000000000004",
			Domain:           "waf.example.test",
			IPAddress:        "127.0.0.1",
			Port:             80,
			Docroot:          "/home/acct/domains/x/public",
			WafMode:          mode,
			WafExcludedRules: rules,
		}, false)
		if err != nil {
			t.Fatalf("rendering: %v", err)
		}

		return string(rendered)
	}

	for _, mode := range []string{"", "off"} {
		if strings.Contains(render(mode, []int{942100}), "modsecurity") {
			t.Errorf("mode %q must not render any ModSecurity directive", mode)
		}
	}

	detect := render("detect", []int{942100, 920350})
	for _, want := range []string{"modsecurity on;", "SecRuleEngine DetectionOnly", "SecRuleRemoveById 942100", "SecRuleRemoveById 920350", "/var/log/lesta/waf/00000000-0000-0000-0000-000000000004.audit.log"} {
		if !strings.Contains(detect, want) {
			t.Errorf("detect mode: expected %q in:\n%s", want, detect)
		}
	}

	if strings.Index(detect, "modsecurity_rules_file") > strings.Index(detect, "SecRuleRemoveById") {
		t.Errorf("rule exclusions must come after the rule set is loaded:\n%s", detect)
	}

	if !strings.Contains(render("block", nil), "SecRuleEngine On") {
		t.Errorf("block mode must enable the engine")
	}
}

func TestWafPayloadValidation(t *testing.T) {
	base := `{"domain":"a.example.test","aliases":[],"ip_address":"127.0.0.1","web_template":"default","account_id":1,"account_username":"","php_socket":"","ssl":{"mode":"off"},"suspended":false,`

	for name, tail := range map[string]string{
		"unknown mode":   `"waf_mode":"paranoid"}`,
		"zero rule id":   `"waf_mode":"block","waf_excluded_rules":[0]}`,
		"huge rule id":   `"waf_mode":"block","waf_excluded_rules":[1000000000]}`,
		"string rule id": `"waf_mode":"block","waf_excluded_rules":["942100; evil"]}`,
	} {
		if _, err := ParsePayload([]byte(base + tail)); err == nil {
			t.Errorf("%s: expected a rejection", name)
		}
	}

	if _, err := ParsePayload([]byte(base + `"waf_mode":"block","waf_excluded_rules":[942100]}`)); err != nil {
		t.Errorf("a valid WAF payload was rejected: %v", err)
	}
}
