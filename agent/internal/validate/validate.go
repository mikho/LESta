// Package validate holds the shapes several capabilities check the same way, so they cannot
// drift apart: a value that becomes a Linux user, a path segment or part of a rendered
// configuration file must match exactly, whatever the control plane sent.
package validate

import (
	"regexp"
	"strings"
	"sync"
)

var accountUsernameRE = regexp.MustCompile(`^lesta-t[0-9]{1,12}$`)

// accountUsernameMatcher matches an account's Linux user; see AccountUsernamePattern.
type accountUsernameMatcher struct{}

// MatchString reports whether name is an account's Linux user.
func (accountUsernameMatcher) MatchString(name string) bool {
	return accountUsernameRE.MatchString(name) || allowedForTests(name)
}

// AccountUsernamePattern is the Linux user of one hosting account, "lesta-t<account id>", the
// only kind of user the identity capability creates. A capability that runs something as a user,
// owns files by one or names one in a configuration accepts nothing else: not root, not a system
// user (mysql, www-data), not the agent's own. "lesta-t0" is no account (ids start at 1); the
// installers' self-tests use it.
var AccountUsernamePattern = accountUsernameMatcher{}

// IsAccountUsername reports whether name is an account's Linux user.
func IsAccountUsername(name string) bool {
	return AccountUsernamePattern.MatchString(name)
}

// ResourceIDPattern is a resource id as the panel sends it: a lower-case UUID.
var ResourceIDPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// IsResourceID reports whether id is a lower-case UUID.
func IsResourceID(id string) bool {
	return ResourceIDPattern.MatchString(id)
}

var (
	testUsersMu sync.Mutex
	testUsers   = map[string]int{}
)

// AllowAccountUsernameForTesting lets a test use name (the OS user the tests run as, which must
// own the files they create without root) as if it were an account's user, until the returned
// function is called. Only tests call it; nothing in the agent does.
func AllowAccountUsernameForTesting(name string) (restore func()) {
	testUsersMu.Lock()
	testUsers[name]++
	testUsersMu.Unlock()

	return func() {
		testUsersMu.Lock()
		defer testUsersMu.Unlock()

		if testUsers[name]--; testUsers[name] <= 0 {
			delete(testUsers, name)
		}
	}
}

func allowedForTests(name string) bool {
	testUsersMu.Lock()
	defer testUsersMu.Unlock()

	return testUsers[name] > 0
}

var safePathRE = regexp.MustCompile(`^/[A-Za-z0-9._/-]{1,255}$`)

// IsSafePath reports whether p is an absolute path made only of letters, digits, ".", "_", "-"
// and "/", with no "." or ".." segment: a path that can be rendered into an nginx, Apache or
// PHP-FPM configuration as is, where a quote, a line break or a ";" would start a directive.
func IsSafePath(p string) bool {
	if !safePathRE.MatchString(p) {
		return false
	}

	for _, segment := range strings.Split(p[1:], "/") {
		if segment == "" || segment == "." || segment == ".." {
			return false
		}
	}

	return true
}

// IsCertificatePathFor reports whether certificate and key are the files tls.acme.v1 keeps for
// domain (<root>/<domain>/fullchain.pem and privkey.pem, the root whatever it is configured to
// be), so a domain can only be served with its own certificate.
func IsCertificatePathFor(domain, certificate, key string) bool {
	return IsSafePath(certificate) && IsSafePath(key) &&
		strings.HasSuffix(certificate, "/"+domain+"/fullchain.pem") &&
		strings.HasSuffix(key, "/"+domain+"/privkey.pem")
}

// IsPHPSocketFor reports whether socket is the PHP-FPM pool socket of the web domain resourceID
// (…/<version>/<resourceID>.sock), so a domain can only hand its PHP to its own pool, which runs
// as its own account.
func IsPHPSocketFor(socket, resourceID string) bool {
	return IsSafePath(socket) && IsResourceID(resourceID) && strings.HasSuffix(socket, "/"+resourceID+".sock")
}
