package fragment

import (
	"context"
	"fmt"
	"net"
	"time"

	"github.com/mikho/LESta/agent/internal/opctx"
)

// PollUntil retries probe with a short backoff until it succeeds or the health-check deadline
// derived from ctx is reached. A reload signal returns before the service has necessarily
// finished picking up the new config, so a single immediate probe would be flaky; polling within
// the operation's own deadline absorbs that race.
func PollUntil(ctx context.Context, probe func() error) error {
	ctx, cancel := opctx.HealthCheck(ctx)
	defer cancel()

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

// WaitDial polls until something accepts a connection at address ("tcp" host:port, or "unix" a
// socket path): the service is up again after a reload, with no per-resource content to check.
func WaitDial(ctx context.Context, network, address string) error {
	return PollUntil(ctx, func() error {
		var d net.Dialer

		conn, err := d.DialContext(ctx, network, address)
		if err != nil {
			return err
		}

		return conn.Close()
	})
}
