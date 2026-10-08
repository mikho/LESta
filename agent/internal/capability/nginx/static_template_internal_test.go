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

// TestWafWordpressPresetIsScopedToAdminAndRestPaths guards the preset: its
// exclusions are applied by a rule that only matches the CMS's admin, login
// and REST paths, never as a site-wide SecRuleRemoveById.
func TestWafWordpressPresetIsScopedToAdminAndRestPaths(t *testing.T) {
	rendered, err := renderVhost(vhostData{
		ResourceID: "00000000-0000-0000-0000-000000000005",
		Domain:     "wp.example.test",
		IPAddress:  "127.0.0.1",
		Port:       80,
		Docroot:    "/home/acct/domains/x/public",
		WafMode:    "block",
		WafPreset:  "wordpress",
	}, false)
	if err != nil {
		t.Fatalf("rendering: %v", err)
	}

	body := string(rendered)

	for _, want := range []string{"wp-admin/|wp-json/|wp-login[.]php", "rest_route=", "ctl:ruleRemoveById=941100", "ctl:ruleRemoveById=932100"} {
		if !strings.Contains(body, want) {
			t.Errorf("expected %q in rendered vhost:\n%s", want, body)
		}
	}

	if strings.Contains(body, "SecRuleRemoveById") {
		t.Errorf("the preset must not remove rules site wide:\n%s", body)
	}

	off, err := renderVhost(vhostData{ResourceID: "00000000-0000-0000-0000-000000000006", Domain: "n.example.test", IPAddress: "127.0.0.1", Port: 80, WafMode: "block", WafPreset: "none"}, false)
	if err != nil {
		t.Fatalf("rendering: %v", err)
	}

	if strings.Contains(string(off), "id:9000001") {
		t.Errorf("preset none must not render the preset rule")
	}
}

// TestHotlinkProtectionRendersOnlyWhenEnabled guards the hotlink location:
// absent by default, and when enabled it allows the domain's own names plus
// each configured host and its subdomains.
func TestHotlinkProtectionRendersOnlyWhenEnabled(t *testing.T) {
	render := func(enabled bool, hosts []string) string {
		rendered, err := renderVhost(vhostData{
			ResourceID:          "00000000-0000-0000-0000-000000000007",
			Domain:              "photos.example.test",
			IPAddress:           "127.0.0.1",
			Port:                80,
			Docroot:             "/home/acct/domains/x/public",
			HotlinkEnabled:      enabled,
			HotlinkAllowedHosts: hosts,
		}, false)
		if err != nil {
			t.Fatalf("rendering: %v", err)
		}

		return string(rendered)
	}

	if strings.Contains(render(false, []string{"partner.example"}), "valid_referers") {
		t.Errorf("hotlink protection off must render nothing")
	}

	on := render(true, []string{"partner.example"})
	for _, want := range []string{"valid_referers none blocked server_names partner.example *.partner.example;", "if ($invalid_referer) {", "return 403;"} {
		if !strings.Contains(on, want) {
			t.Errorf("expected %q in rendered vhost:\n%s", want, on)
		}
	}
}

func TestHotlinkPayloadValidation(t *testing.T) {
	base := `{"domain":"a.example.test","aliases":[],"ip_address":"127.0.0.1","web_template":"default","account_id":1,"account_username":"","php_socket":"","ssl":{"mode":"off"},"suspended":false,"hotlink_protection":true,`

	for name, tail := range map[string]string{
		"injection attempt": `"hotlink_allowed_hosts":["evil.example; return 200"]}`,
		"scheme":            `"hotlink_allowed_hosts":["https://partner.example"]}`,
		"single label":      `"hotlink_allowed_hosts":["localhost"]}`,
	} {
		if _, err := ParsePayload([]byte(base + tail)); err == nil {
			t.Errorf("%s: expected a rejection", name)
		}
	}

	if _, err := ParsePayload([]byte(base + `"hotlink_allowed_hosts":["partner.example"]}`)); err != nil {
		t.Errorf("a valid payload was rejected: %v", err)
	}
}

// TestIpRulesRenderAllowsBeforeDeniesAndKeepAcmeReachable guards the account
// IP access list: allow entries come first (nginx's first match wins), the
// addresses are rendered in canonical form, and the ACME challenge and health
// locations opt out so certificate issuance and health checks keep working.
func TestIpRulesRenderAllowsBeforeDeniesAndKeepAcmeReachable(t *testing.T) {
	payload := Payload{IpRules: []IpRule{
		{Action: "deny", Cidr: "203.0.113.0/24"},
		{Action: "allow", Cidr: "203.0.113.5"},
		{Action: "deny", Cidr: "2001:DB8::/32"},
	}}

	rendered, err := renderVhost(vhostData{
		ResourceID:       "00000000-0000-0000-0000-000000000008",
		Domain:           "ip.example.test",
		IPAddress:        "127.0.0.1",
		Port:             80,
		AcmeChallengeDir: "/var/lib/lesta/acme/challenges",
		Docroot:          "/home/acct/domains/x/public",
		IpAllows:         payload.ipAddresses("allow"),
		IpDenies:         payload.ipAddresses("deny"),
	}, false)
	if err != nil {
		t.Fatalf("rendering: %v", err)
	}

	body := string(rendered)

	allowAt := strings.Index(body, "allow 203.0.113.5;")
	denyAt := strings.Index(body, "deny 203.0.113.0/24;")

	if allowAt < 0 || denyAt < 0 || allowAt > denyAt {
		t.Errorf("expected the allow entry before the deny entries:\n%s", body)
	}

	if !strings.Contains(body, "deny 2001:db8::/32;") {
		t.Errorf("expected the IPv6 range in canonical form:\n%s", body)
	}

	if strings.Count(body, "allow all;") != 2 {
		t.Errorf("expected the ACME and health locations to opt out (2x allow all), got:\n%s", body)
	}
}

func TestIpRulePayloadValidation(t *testing.T) {
	base := `{"domain":"a.example.test","aliases":[],"ip_address":"127.0.0.1","web_template":"default","account_id":1,"account_username":"","php_socket":"","ssl":{"mode":"off"},"suspended":false,`

	for name, tail := range map[string]string{
		"injection":      `"ip_rules":[{"action":"deny","cidr":"1.2.3.4; return 200"}]}`,
		"bad action":     `"ip_rules":[{"action":"permit","cidr":"1.2.3.4"}]}`,
		"bad prefix":     `"ip_rules":[{"action":"deny","cidr":"1.2.3.4/40"}]}`,
		"hostname":       `"ip_rules":[{"action":"deny","cidr":"example.com"}]}`,
		"empty cidr":     `"ip_rules":[{"action":"deny","cidr":""}]}`,
		"all as keyword": `"ip_rules":[{"action":"deny","cidr":"all"}]}`,
	} {
		if _, err := ParsePayload([]byte(base + tail)); err == nil {
			t.Errorf("%s: expected a rejection", name)
		}
	}

	if _, err := ParsePayload([]byte(base + `"ip_rules":[{"action":"deny","cidr":"0.0.0.0/0"},{"action":"allow","cidr":"203.0.113.5"},{"action":"deny","cidr":"::/0"}]}`)); err != nil {
		t.Errorf("a valid payload was rejected: %v", err)
	}
}
