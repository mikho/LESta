package apache

import (
	"fmt"
	"os"
	"path/filepath"
)

// contentPath is the absolute path to resourceID's generation n mod_asis
// content file, the path a rendered vhost fragment's DocumentRoot/DirectoryIndex
// pair resolves to (see template.go's vhostData.ContentDir and templates/*.tmpl).
func (c *ApacheCapability) contentPath(resourceID string, n int) string {
	return filepath.Join(c.store.GenerationDir(resourceID, n), "content")
}

// writeContent writes content to resourceID's generation n mod_asis content
// file, creating the generation directory if it doesn't exist yet: this is
// called before generation.Store's own Activate (which would otherwise create
// it), since the candidate vhost fragment's DocumentRoot must reference a real,
// already-readable directory for apache2 -t to validate against.
func (c *ApacheCapability) writeContent(resourceID string, n int, content []byte) error {
	dir := c.store.GenerationDir(resourceID, n)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("creating generation directory for %s generation %d: %w", resourceID, n, err)
	}

	if err := os.WriteFile(c.contentPath(resourceID, n), content, 0o644); err != nil {
		return fmt.Errorf("writing content for %s generation %d: %w", resourceID, n, err)
	}

	return nil
}

// discardContent removes a generation's mod_asis content file after a rejected
// validation attempt. Disk hygiene only, not load-bearing correctness: an
// orphaned content file under a generation directory that never got Activated
// is harmless (nothing ever references it), this just keeps the state tree
// tidy for operator forensics, mirroring bind9's discardZoneData symmetry.
func (c *ApacheCapability) discardContent(resourceID string, n int) {
	_ = os.Remove(c.contentPath(resourceID, n))
}

// modulesFragmentName is a fixed, never-removed *.conf fragment in LiveDir,
// distinct from every <resource_id>.conf fragment. Its "00-" prefix sorts it
// first in IncludeOptional's glob-then-alphabetical processing, so mod_asis is
// loaded before any vhost fragment that depends on it is parsed.
const modulesFragmentName = "00-lesta-modules.conf"

// asisModulePath is mod_asis.so's fixed, standard installation path under
// Debian/Ubuntu's apache2-bin package layout (verified against Debian/Ubuntu
// apache2 packaging convention: every mods-available/*.load file under
// /etc/apache2/mods-available/ points at a module .so under this exact
// directory). This is deliberately not a Config field: like ApacheBinary in
// main.go's apacheProductionConfig, it is a fixed fact about the one real
// production target this phase supports, not operator-tunable input. The
// disposable test harness's own base config loads mod_asis itself (from
// whichever module directory actually exists on the machine running the test;
// see harness_test.go), so this fragment's own LoadModule line is a harmless,
// silently-skipped no-op there (Apache's module loader de-duplicates by module
// *name*, not by path -- verified directly: a LoadModule line naming an
// already-loaded module is skipped with a warning even when its own .so path
// doesn't exist on disk).
const asisModulePath = "/usr/lib/apache2/modules/mod_asis.so"

// sslModulePath and socacheShmcbModulePath are mod_ssl.so's and
// mod_socache_shmcb.so's fixed, standard installation paths under the same
// Debian/Ubuntu apache2-bin package layout asisModulePath's own doc comment
// verifies. Both ship in that package already (no new installer package
// dependency this phase). mod_ssl needs a shared object cache implementation
// to store its SSL session cache; mod_socache_shmcb (a shared-memory cache
// backed by a cyclic buffer) is Ubuntu's own stock mods-enabled default for
// this, so this project uses the same one rather than introducing a
// dependency (e.g. mod_socache_dbm) Ubuntu's own default install doesn't
// already carry.
const sslModulePath = "/usr/lib/apache2/modules/mod_ssl.so"
const socacheShmcbModulePath = "/usr/lib/apache2/modules/mod_socache_shmcb.so"

// ensureModulesFragment writes LiveDir/00-lesta-modules.conf, unconditionally,
// on every applyGeneration call. It is idempotent: the content never varies for
// a fixed sslPort, so a same-content overwrite is a no-op as far as apache2 and
// this resource's own digest are concerned. This fragment carries no
// generation history of its own (it isn't scoped to any one resource) and
// needs none: it is a fixed, LiveDir-wide precondition for mod_asis to be
// available to whichever vhost fragments use `SetHandler send-as-is`, exactly
// mirroring bind9's own _lesta-placeholder.conf precedent -- except bind9's
// placeholder is written by an installer, once, before named's first-ever
// start, while this fragment is written by the capability's own Go code on
// every apply.
//
// It also unconditionally loads mod_ssl and mod_socache_shmcb (harmless even
// when no domain on this node has SSL enabled yet, exactly like mod_asis's own
// always-loaded precedent), and, only when sslPort is non-zero, emits an
// explicit `Listen <sslPort>` line. That Listen is required, not redundant:
// Ubuntu's stock ports.conf wraps its own `Listen 443` in
// `<IfModule ssl_module>`, evaluated while ports.conf itself is parsed --
// before this project's own LoadModule line (written into this very fragment,
// included later via LiveDir's IncludeOptional) ever loads ssl_module, so that
// conditional never activates and the stock Listen 443 never binds. An
// explicit, unconditional Listen from this fragment is the fix. It is
// suppressed entirely (sslPort == 0) in the "both" web profile, where Apache is
// a loopback-only 8080 backend that must never bind 443 itself, which would
// conflict with nginx's own public 443 listener on the same node.
func ensureModulesFragment(liveDir string, sslPort int) error {
	if err := os.MkdirAll(liveDir, 0o755); err != nil {
		return fmt.Errorf("creating live directory %s: %w", liveDir, err)
	}

	content := "# Managed by LESta. Do not edit by hand.\n" +
		"LoadModule asis_module " + asisModulePath + "\n" +
		"LoadModule ssl_module " + sslModulePath + "\n" +
		"LoadModule socache_shmcb_module " + socacheShmcbModulePath + "\n"

	if sslPort != 0 {
		content += fmt.Sprintf("Listen %d\n", sslPort)
	}

	path := filepath.Join(liveDir, modulesFragmentName)

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return fmt.Errorf("writing modules fragment %s: %w", path, err)
	}

	return nil
}
