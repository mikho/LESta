package identity

import (
	"context"
	"fmt"
	"net"
	"os"
	"strings"
	"syscall"
	"time"
)

// reload issues sshd's reload as its own separate step from activation.
// Config.ReloadCommand, when set, fully overrides the command (production:
// ["systemctl", "reload", "ssh"] -- Ubuntu's own sshd service unit is
// literally named "ssh"); otherwise this sends SIGHUP directly to
// Config.ReloadPID, the seam a disposable test harness uses instead of
// systemd. sshd re-reads its own config and re-evaluates every Match block
// on SIGHUP without dropping already-established sessions, the same
// "reload never disrupts what's already live" property nginx's own `-s
// reload` has.
func (c *IdentityCapability) reload(ctx context.Context) error {
	if len(c.cfg.ReloadCommand) > 0 {
		cmd := c.cfg.command(ctx, c.cfg.ReloadCommand[0], c.cfg.ReloadCommand[1:]...)

		out, err := cmd.CombinedOutput()
		if err != nil {
			return fmt.Errorf("sshd reload failed: %w: %s", err, strings.TrimSpace(string(out)))
		}

		return nil
	}

	proc, err := os.FindProcess(c.cfg.ReloadPID)
	if err != nil {
		return fmt.Errorf("finding sshd process %d to reload: %w", c.cfg.ReloadPID, err)
	}

	if err := proc.Signal(syscall.SIGHUP); err != nil {
		return fmt.Errorf("signaling sshd process %d to reload: %w", c.cfg.ReloadPID, err)
	}

	return nil
}

// waitHealthy polls a plain TCP dial against sshd's own listening port until
// it succeeds or ctx's deadline is reached. Unlike nginx's own per-vhost
// HTTP health check (a real request proving a specific vhost answers), this
// only ever proves sshd itself is up and accepting connections after
// reload -- proving a specific account's own key-based chroot login
// actually succeeds needs a real, root-owned chroot tree and a real SSH
// client round trip, which this phase's own unprivileged Go test suite
// cannot set up (see this package's own harness_test.go doc comment); that
// end-to-end proof belongs to step 2b's real installer CI, run with root on
// a disposable Ubuntu instance, mirroring how nginx/bind9's own installer
// CI -- not their Go unit tests -- proves their real system-level
// behavior.
func (c *IdentityCapability) waitHealthy(ctx context.Context) error {
	return pollUntil(ctx, func() error {
		var d net.Dialer

		conn, err := d.DialContext(ctx, "tcp", fmt.Sprintf("127.0.0.1:%d", c.cfg.Port))
		if err != nil {
			return err
		}

		return conn.Close()
	})
}

// pollUntil retries probe with a short backoff until it succeeds or ctx is
// done. A reload signal returns before sshd has necessarily finished
// re-reading its config, so a single immediate probe would be flaky.
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
