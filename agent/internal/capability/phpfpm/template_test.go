package phpfpm

import (
	"strings"
	"testing"
)

func TestRenderPoolProducesTheExpectedDirectivesAndOwnership(t *testing.T) {
	content, err := renderPool(poolData{
		ResourceID:      "res-123",
		AccountUsername: "lesta-t1",
		SocketPath:      "/run/lesta-php/8.3/res-123.sock",
		Docroot:         "/var/lib/lesta/web/accounts/1/domains/res-123/public",
	})
	if err != nil {
		t.Fatalf("rendering pool: %v", err)
	}

	got := string(content)

	for _, want := range []string{
		"[res-123]",
		"user = lesta-t1",
		"group = lesta-t1",
		"listen = /run/lesta-php/8.3/res-123.sock",
		"listen.owner = lesta-t1",
		"php_admin_value[open_basedir] = /var/lib/lesta/web/accounts/1/domains/res-123/public:/tmp",
	} {
		if !strings.Contains(got, want) {
			t.Fatalf("expected rendered pool to contain %q, got:\n%s", want, got)
		}
	}
}

func TestConfigPathHelpersMatchTheDocumentedFormulas(t *testing.T) {
	cfg := Config{
		PoolBaseDir:  "/etc/php",
		AccountsRoot: "/var/lib/lesta/web/accounts",
		SocketRoot:   "/run/lesta-php",
	}

	if got, want := cfg.poolDir("8.3"), "/etc/php/8.3/fpm/pool.d"; got != want {
		t.Fatalf("poolDir: got %q, want %q", got, want)
	}

	if got, want := cfg.fpmConfigPath("8.3"), "/etc/php/8.3/fpm/php-fpm.conf"; got != want {
		t.Fatalf("fpmConfigPath: got %q, want %q", got, want)
	}

	if got, want := cfg.socketPath("8.3", "res-123"), "/run/lesta-php/8.3/res-123.sock"; got != want {
		t.Fatalf("socketPath: got %q, want %q", got, want)
	}

	if got, want := cfg.docroot("1", "res-123"), "/var/lib/lesta/web/accounts/1/domains/res-123/public"; got != want {
		t.Fatalf("docroot: got %q, want %q", got, want)
	}
}
