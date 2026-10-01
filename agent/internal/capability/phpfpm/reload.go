package phpfpm

import (
	"context"
	"fmt"
	"net"
	"os/exec"
	"strings"
	"time"
)

// reload issues this version's own real php<version>-fpm systemd unit
// reload, routed through Config.SudoBinary the same way every other
// systemd-unit mutation in this project already is.
func (c *PhpFpmCapability) reload(ctx context.Context, version string) error {
	unit := "php" + version + "-fpm"

	cmd := c.cfg.command(ctx, "systemctl", "reload", unit)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("systemctl reload %s failed: %w: %s", unit, err, strings.TrimSpace(string(out)))
	}

	return nil
}

// waitHealthy polls a real unix-socket dial against resourceID's own pool
// socket until it succeeds, proving *this* pool's own worker is up and
// accepting FastCGI connections, or until ctx's deadline is reached. Unlike
// nginx's own HTTP marker check, php-fpm has no HTTP surface of its own to
// probe -- a successful connect is the strongest real signal available
// without actually speaking the FastCGI protocol.
func (c *PhpFpmCapability) waitHealthy(ctx context.Context, version, resourceID string) error {
	socketPath := c.cfg.socketPath(version, resourceID)

	return pollUntil(ctx, func() error {
		var d net.Dialer

		conn, err := d.DialContext(ctx, "unix", socketPath)
		if err != nil {
			return err
		}

		return conn.Close()
	})
}

// waitHealthyGeneric probes that version's own php-fpm systemd unit is
// still active, with no per-resource socket to check (used after delete,
// and after a rollback that restores a deletion: there is no more
// per-resource socket to dial).
func (c *PhpFpmCapability) waitHealthyGeneric(ctx context.Context, version string) error {
	unit := "php" + version + "-fpm"

	return pollUntil(ctx, func() error {
		cmd := exec.CommandContext(ctx, "systemctl", "is-active", "--quiet", unit)

		if err := cmd.Run(); err != nil {
			return fmt.Errorf("%s is not active: %w", unit, err)
		}

		return nil
	})
}

// pollUntil retries probe with a short backoff until it succeeds or ctx is
// done. A reload signal returns before php-fpm's own master process has
// necessarily finished re-reading its config and (re)binding the pool's
// socket, so a single immediate probe would be flaky.
func pollUntil(ctx context.Context, probe func() error) error {
	var lastErr error

	for {
		if err := probe(); err == nil {
			return nil
		} else {
			lastErr = err
		}

		select {
		case <-ctx.Done():
			return fmt.Errorf("health check did not succeed before deadline: %w (last error: %v)", ctx.Err(), lastErr)
		case <-time.After(50 * time.Millisecond):
		}
	}
}
