package apache

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"os/exec"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// suspendedMarker is the fixed string baked into templates/suspended.html.
// Unlike the default template's marker (per-resource, per-generation), the
// suspended page's content is identical for every resource, so its marker is a
// compile-time constant rather than something rendered fresh per call.
const suspendedMarker = "LESTA-SUSPENDED-MARKER"

// reload issues apache2's reload as its own separate step from activation.
// Config.ReloadCommand, when set, fully overrides the command (the seam a
// later Tier 2 suite against a real systemctl-managed apache2 would use); the
// default is `apache2 -k graceful [-d Prefix] -f ApacheConfPath`.
func (c *ApacheCapability) reload(ctx context.Context) error {
	var cmd *exec.Cmd

	if len(c.cfg.ReloadCommand) > 0 {
		cmd = exec.CommandContext(ctx, c.cfg.ReloadCommand[0], c.cfg.ReloadCommand[1:]...)
	} else {
		args := c.cfg.commandArgs("-k", "graceful", "-f", c.cfg.ApacheConfPath)
		cmd = exec.CommandContext(ctx, c.cfg.apacheBinary(), args...)
	}

	cmd.Env = c.cfg.Env

	return fragment.Reload(cmd, "apache2 reload")
}

// waitHealthy polls a real HTTP request to the vhost (ip:port, with the Host
// header set to domain) until it returns 200 with expectedMarker present in the
// body, proving *this* vhost answered, not just that apache2 is alive, or until
// ctx's deadline is reached.
func (c *ApacheCapability) waitHealthy(ctx context.Context, ip string, port int, domain, expectedMarker string) error {
	return fragment.PollUntil(ctx, func() error {
		url := fmt.Sprintf("http://%s:%d/", ip, port)

		req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			return err
		}
		req.Host = domain

		resp, err := http.DefaultClient.Do(req)
		if err != nil {
			return err
		}
		defer func() { _ = resp.Body.Close() }()

		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return err
		}

		if resp.StatusCode != http.StatusOK {
			return fmt.Errorf("health check for %s got status %d", domain, resp.StatusCode)
		}
		if !bytes.Contains(body, []byte(expectedMarker)) {
			return fmt.Errorf("health check for %s response did not contain expected marker %q", domain, expectedMarker)
		}

		return nil
	})
}

// waitHealthyGeneric probes that apache2 itself is still accepting connections
// on ip:port, with no per-vhost content to check (used after delete, and after
// a rollback that restores a deletion: there is no more per-vhost health to
// check).
func (c *ApacheCapability) waitHealthyGeneric(ctx context.Context, ip string, port int) error {
	return fragment.WaitDial(ctx, "tcp", fmt.Sprintf("%s:%d", ip, port))
}
