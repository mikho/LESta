// Package files implements the files.manager.v1 capability: a tenant's own
// browser file manager, scoped to one WebDomain's own per-domain docroot
// (AccountsRoot/<account_username>/domains/<resource_id>/public, the exact
// formula web.nginx.v1/web.php-fpm.v1 already use). Unlike every other
// capability, there is no config to render/validate/activate/reload -- this
// is direct filesystem operations (list, read, create, update/rename,
// delete), the real second front door onto the exact same files the
// tenant's own SFTP session already reaches, never a second write mechanism
// with its own permission model: every write lands owned by that same
// account's own OS identity (lesta-t{account_id}), via the identical
// root-mediated chown pattern nginx.EnsureDocroot/identity.writeAuthorizedKeys
// already established (see privileged.go).
//
// Dispatched over a second, faster poll/report lane than the general 60s
// heartbeat (see agent/internal/daemon's own file-ops loop): an interactive
// file browser cannot wait minutes for a directory listing, but every write
// still becomes a real, audited ProvisioningOperation row, identical
// bookkeeping to every other capability.
package files

import (
	"context"
	"fmt"
	"os/exec"
)

// Config parameterizes Capability by root paths and invocation details, so
// the identical implementation runs against production's real paths or a
// disposable per-test temp directory.
type Config struct {
	// AccountsRoot is system.account-identity.v1's own chroot accounts
	// root (identity.Config's own field of the same name). Production:
	// /var/lib/lesta/web/accounts.
	AccountsRoot string
	// SudoBinary, when non-empty, routes every real filesystem mutation
	// through this same binary's own "files-manager-apply" CLI mode as
	// root (privileged.go) -- creating a new file/directory requires a
	// real chown to the account's own uid/gid, which sudo cannot grant
	// to an in-process Go syscall the way it can a separate
	// exec.Command invocation, the same real constraint
	// identity.ensureChrootTree/nginx.ensureDocroot already hit.
	// Production: "sudo". Empty means run every operation directly,
	// matching this package's own disposable test harness, which
	// already runs with whatever privilege `go test` itself has.
	SudoBinary string
	// AgentBinaryPath is this same lesta-agent binary's own real
	// installed path, used together with SudoBinary to re-invoke its
	// own "files-manager-apply" CLI mode. Must stay in lockstep with
	// .install/lib/agent.sh's own AGENT_BINARY_DEST.
	AgentBinaryPath string
}

// docroot returns AccountsRoot/<accountUsername>/domains/<resourceID>/public,
// the exact per-domain webroot formula web.nginx.v1's own docroot.go
// resolves independently from the same two inputs.
func (c Config) docroot(accountUsername, resourceID string) string {
	return c.AccountsRoot + "/" + accountUsername + "/domains/" + resourceID + "/public"
}

// command builds the real "files-manager-apply" invocation, routed through
// SudoBinary when set.
func (c Config) command(ctx context.Context) *exec.Cmd {
	if c.SudoBinary == "" {
		return exec.CommandContext(ctx, c.AgentBinaryPath, "files-manager-apply")
	}

	return exec.CommandContext(ctx, c.SudoBinary, c.AgentBinaryPath, "files-manager-apply")
}

// maxContentBytes bounds a single create/update's own decoded file content
// -- the whole request already has to fit in one OperationEnvelope's own
// JSON payload (base64-encoded, ~33% larger than the raw bytes), so this
// caps both the wire payload size and the real on-disk write in one place.
// Matches this project's own existing ADR-level "no unbounded transfer
// sizes" requirement (LESta-rewrite-plan.md).
const maxContentBytes = 25 * 1024 * 1024

// errPathEscapesDocroot is returned by resolveSafePath/resolveExistingSafePath
// when a tenant-supplied relative path would resolve outside its own
// docroot -- via "..", an absolute-looking segment, or an existing symlink
// pointing elsewhere. Checked with errors.Is, never by string-matching.
var errPathEscapesDocroot = fmt.Errorf("path escapes the account's own docroot")
