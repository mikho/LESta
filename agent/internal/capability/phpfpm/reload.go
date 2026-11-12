package phpfpm

import (
	"context"
	"fmt"
	"os/exec"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// reload issues this version's own real php<version>-fpm systemd unit
// reload, routed through Config.SudoBinary the same way every other
// systemd-unit mutation in this project already is.
func (c *PhpFpmCapability) reload(ctx context.Context, version string) error {
	unit := "php" + version + "-fpm"

	cmd := c.cfg.command(ctx, "systemctl", "reload", unit)
	if len(c.cfg.ReloadCommand) > 0 {
		cmd = exec.CommandContext(ctx, c.cfg.ReloadCommand[0], c.cfg.ReloadCommand[1:]...)
	}

	return fragment.Reload(cmd, "systemctl reload "+unit)
}

// waitHealthy polls a real unix-socket dial against resourceID's own pool
// socket until it succeeds, proving *this* pool's own worker is up and
// accepting FastCGI connections, or until ctx's deadline is reached. Unlike
// nginx's own HTTP marker check, php-fpm has no HTTP surface of its own to
// probe -- a successful connect is the strongest real signal available
// without actually speaking the FastCGI protocol.
func (c *PhpFpmCapability) waitHealthy(ctx context.Context, version, resourceID string) error {
	socketPath := c.cfg.socketPath(version, resourceID)

	return fragment.WaitDial(ctx, "unix", socketPath)
}

// waitHealthyGeneric probes that version's own php-fpm systemd unit is
// still active, with no per-resource socket to check (used after delete,
// and after a rollback that restores a deletion: there is no more
// per-resource socket to dial).
func (c *PhpFpmCapability) waitHealthyGeneric(ctx context.Context, version string) error {
	unit := "php" + version + "-fpm"

	return fragment.PollUntil(ctx, func() error {
		cmd := exec.CommandContext(ctx, "systemctl", "is-active", "--quiet", unit)
		if len(c.cfg.IsActiveCommand) > 0 {
			cmd = exec.CommandContext(ctx, c.cfg.IsActiveCommand[0], c.cfg.IsActiveCommand[1:]...)
		}

		if err := cmd.Run(); err != nil {
			return fmt.Errorf("%s is not active: %w", unit, err)
		}

		return nil
	})
}
