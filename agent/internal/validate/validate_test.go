package validate_test

import (
	"testing"

	"github.com/mikho/LESta/agent/internal/validate"
)

func TestAccountUsernameIsOnlyAnAccountsUser(t *testing.T) {
	for _, name := range []string{"lesta-t1", "lesta-t0", "lesta-t123456789012"} {
		if !validate.IsAccountUsername(name) {
			t.Errorf("%q should be an account user", name)
		}
	}

	for _, name := range []string{"root", "mysql", "www-data", "lesta-agent", "lesta-cron", "lesta-t", "lesta-t1x", "lesta-t1234567890123", "Lesta-t1", "../lesta-t1", ""} {
		if validate.IsAccountUsername(name) {
			t.Errorf("%q must not be accepted as an account user", name)
		}
	}
}

func TestAllowAccountUsernameForTestingIsUndone(t *testing.T) {
	restore := validate.AllowAccountUsernameForTesting("builder")
	if !validate.IsAccountUsername("builder") {
		t.Fatal("allowed name not accepted")
	}

	restore()
	if validate.IsAccountUsername("builder") {
		t.Fatal("name still accepted after restore")
	}
}

func TestPathsThatWouldInjectIntoAConfigurationAreRefused(t *testing.T) {
	for _, p := range []string{
		`/var/lib/lesta/acme/certs/a.example/fullchain.pem"; ErrorLog "|/bin/sh -c id`,
		"/run/lesta-php/8.3/x.sock;\n    alias /;",
		"/run/lesta-php/../../etc/shadow",
		"/run//php.sock",
		"/run/./php.sock",
		"run/php.sock",
		"/run/php sock",
		"/run/php{.sock",
	} {
		if validate.IsSafePath(p) {
			t.Errorf("%q must not be a safe path", p)
		}
	}

	if !validate.IsSafePath("/run/lesta-php/8.3/0f0e0d0c-0b0a-4908-8706-050403020100.sock") {
		t.Error("an ordinary socket path should be safe")
	}
}

func TestCertificatesAndSocketsMustBeTheResourcesOwn(t *testing.T) {
	if !validate.IsCertificatePathFor("a.example", "/var/lib/lesta/acme/certs/a.example/fullchain.pem", "/var/lib/lesta/acme/certs/a.example/privkey.pem") {
		t.Error("the domain's own certificate should be accepted")
	}
	if validate.IsCertificatePathFor("a.example", "/var/lib/lesta/acme/certs/b.example/fullchain.pem", "/var/lib/lesta/acme/certs/b.example/privkey.pem") {
		t.Error("another domain's certificate must be refused")
	}

	own := "0f0e0d0c-0b0a-4908-8706-050403020100"
	other := "11111111-2222-4333-8444-555555555555"
	if !validate.IsPHPSocketFor("/run/lesta-php/8.3/"+own+".sock", own) {
		t.Error("the domain's own pool should be accepted")
	}
	if validate.IsPHPSocketFor("/run/lesta-php/8.3/"+other+".sock", own) {
		t.Error("another domain's pool must be refused")
	}
}
