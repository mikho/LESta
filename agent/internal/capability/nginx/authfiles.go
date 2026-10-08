package nginx

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// protectedDirRule is a ProtectedDir prepared for the template: Path always
// ends in a slash, and File is the htpasswd file holding its users.
type protectedDirRule struct {
	Path  string
	Realm string
	File  string
}

// authFiles is what applying a payload's protected directories needs: the
// rules to render, and the htpasswd files (path to content) they reference.
type authFiles struct {
	Rules []protectedDirRule
	Files map[string]string
}

// prepareAuthFiles turns a payload's protected directories into rendered rules
// and the htpasswd files they reference. Each file is named by the SHA-256 of
// its content, so identical user lists share a file and a changed list gets a
// new one: the generation being replaced keeps referencing its own file until
// the new generation is proven healthy.
func (c *NginxCapability) prepareAuthFiles(resourceID string, dirs []ProtectedDir) authFiles {
	out := authFiles{Files: map[string]string{}}

	for _, dir := range dirs {
		var content strings.Builder

		for _, user := range dir.Users {
			content.WriteString(user.Username + ":" + user.Hash + "\n")
		}

		sum := sha256.Sum256([]byte(content.String()))
		file := filepath.Join(c.cfg.AuthDir, fmt.Sprintf("%s.%s.htpasswd", resourceID, hex.EncodeToString(sum[:])[:16]))

		out.Files[file] = content.String()
		out.Rules = append(out.Rules, protectedDirRule{
			Path:  strings.TrimSuffix(dir.Path, "/") + "/",
			Realm: dir.Realm,
			File:  file,
		})
	}

	return out
}

// writeAuthFiles writes every file that does not exist yet (mode 0640; the
// directory's group is what lets nginx read them). Existing files are left
// alone: their name is their content hash.
func (c *NginxCapability) writeAuthFiles(files map[string]string) error {
	for path, content := range files {
		if _, err := os.Stat(path); err == nil {
			continue
		}

		tmp := path + ".tmp"
		if err := os.WriteFile(tmp, []byte(content), 0o640); err != nil {
			return fmt.Errorf("writing %s: %w", tmp, err)
		}

		if err := os.Rename(tmp, path); err != nil {
			_ = os.Remove(tmp)

			return fmt.Errorf("activating %s: %w", path, err)
		}
	}

	return nil
}

// defaultAuthPruneGrace is how long an unreferenced htpasswd file survives.
const defaultAuthPruneGrace = 5 * time.Minute

// pruneAuthFiles removes the resource's htpasswd files that the active
// generation does not reference and that are older than the grace period.
// Called only once a generation is healthy, and never for a file that was just
// replaced: nginx workers still draining under the previous configuration
// answer 403 when their user file disappears, and a rollback needs it back.
func (c *NginxCapability) pruneAuthFiles(resourceID string, keep map[string]string) {
	if c.cfg.AuthDir == "" {
		return
	}

	matches, err := filepath.Glob(filepath.Join(c.cfg.AuthDir, resourceID+".*.htpasswd"))
	if err != nil {
		return
	}

	sort.Strings(matches)

	grace := c.cfg.AuthPruneGrace
	if grace <= 0 {
		grace = defaultAuthPruneGrace
	}

	for _, match := range matches {
		if _, referenced := keep[match]; referenced {
			continue
		}

		if info, err := os.Stat(match); err == nil && time.Since(info.ModTime()) < grace && keep != nil {
			// Still inside the grace period: remove it when the period ends,
			// provided the live vhost has not started using it again. A daemon
			// restart in between leaves it for the next apply to prune.
			file := match
			time.AfterFunc(grace-time.Since(info.ModTime())+time.Second, func() { c.removeAuthFileIfUnused(resourceID, file) })

			continue
		}

		_ = os.Remove(match)
	}
}

// removeAuthFileIfUnused removes an htpasswd file once its grace period is over,
// unless the resource's live vhost references it again.
func (c *NginxCapability) removeAuthFileIfUnused(resourceID, file string) {
	live, err := os.ReadFile(c.livePath(resourceID))
	if err == nil && strings.Contains(string(live), file) {
		return
	}

	_ = os.Remove(file)
}
