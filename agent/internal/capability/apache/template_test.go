package apache

import (
	"strings"
	"testing"
)

// TestPhpTemplateRendersHttpsOnlyWithARealSSLPort: in the "both" profile
// apache's SSLPort is 0 (nginx terminates TLS), so a certificate alone must
// never render a <VirtualHost ...:0> block.
func TestPhpTemplateRendersHttpsOnlyWithARealSSLPort(t *testing.T) {
	data := vhostData{
		ResourceID:      "11111111-2222-3333-4444-555555555555",
		Domain:          "php.example.com",
		IPAddress:       "127.0.0.1",
		Port:            8080,
		Docroot:         "/var/lib/lesta/web/accounts/lesta-t1/domains/x/public",
		PhpSocket:       "/run/lesta-php/8.3/x.sock",
		CertificatePath: "/var/lib/lesta/acme/certs/php.example.com/fullchain.pem",
		PrivateKeyPath:  "/var/lib/lesta/acme/certs/php.example.com/privkey.pem",
	}

	for sslPort, wantHTTPS := range map[int]bool{0: false, 443: true} {
		data.SSLPort = sslPort

		out, err := renderVhost(data, false)
		if err != nil {
			t.Fatalf("rendering with SSLPort %d: %v", sslPort, err)
		}

		if got := strings.Contains(string(out), "SSLEngine on"); got != wantHTTPS {
			t.Errorf("SSLPort %d: HTTPS block rendered = %v, want %v\n%s", sslPort, got, wantHTTPS, out)
		}
		if !strings.Contains(string(out), `SetHandler "proxy:unix:/run/lesta-php/8.3/x.sock|fcgi://localhost"`) {
			t.Errorf("SSLPort %d: expected .php handed to the domain's own socket\n%s", sslPort, out)
		}
	}
}
