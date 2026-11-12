package bind9

import (
	"context"
	"fmt"
	"net"
	"os/exec"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// reload issues named's reload as its own separate step from activation.
// Config.ReloadCommand, when set, fully overrides the command (the seam
// failure-injection tests use); the default is `rndc [-c RndcConfigPath]
// reload`, uniformly for every operation. rndc offers a narrower `reconfig`
// (config file and NEW zones only) and a per-zone `reload zone` (data-only);
// neither is used here: `reload` is the only one of rndc's own commands
// that's unambiguously correct for update/delete/suspend semantics too, not
// just create.
func (c *Bind9Capability) reload(ctx context.Context) error {
	var cmd *exec.Cmd

	if len(c.cfg.ReloadCommand) > 0 {
		cmd = exec.CommandContext(ctx, c.cfg.ReloadCommand[0], c.cfg.ReloadCommand[1:]...)
	} else {
		args := c.cfg.rndcArgs("reload")
		cmd = c.cfg.command(ctx, args...)
	}

	return fragment.Reload(cmd, "rndc reload")
}

// resolverAt returns a net.Resolver pinned at cfg's listen address and port,
// via a custom Dial: the well-established, zero-dependency stdlib pattern for
// querying a specific DNS server directly rather than the system's own
// configured resolver.
func (c *Bind9Capability) resolverAt() *net.Resolver {
	return &net.Resolver{
		PreferGo: true,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer

			return d.DialContext(ctx, network, fmt.Sprintf("%s:%d", c.cfg.listenAddress(), c.cfg.Port))
		},
	}
}

// waitHealthy polls a real DNS query for the marker TXT record this resource's
// own rendered zone always carries (see template.go's renderZoneData),
// proving *this* zone answered with *this* resource's content, not just that
// named is alive, or until ctx's deadline is reached. The query name is
// always fully qualified (trailing dot): verified directly against the
// disposable harness that this bypasses any system resolver search-list
// ambiguity a bare relative name could otherwise be subject to.
func (c *Bind9Capability) waitHealthy(ctx context.Context, domain, resourceID string) error {
	resolver := c.resolverAt()
	qname := reservedRecordName + "." + fqdn(domain)

	return fragment.PollUntil(ctx, func() error {
		records, err := resolver.LookupTXT(ctx, qname)
		if err != nil {
			return err
		}

		expected := "resource=" + resourceID
		for _, r := range records {
			if strings.Contains(r, expected) {
				return nil
			}
		}

		return fmt.Errorf("health check for %s: marker TXT record at %s did not contain %q (got %v)", domain, qname, expected, records)
	})
}

// waitHealthyGeneric probes that named itself is still accepting connections
// on cfg's listen address/port, with no per-zone content to check (used
// after delete, and after a rollback that restores a deletion or a
// suspension: there is no more per-zone health to check).
func (c *Bind9Capability) waitHealthyGeneric(ctx context.Context) error {
	return fragment.WaitDial(ctx, "tcp", fmt.Sprintf("%s:%d", c.cfg.listenAddress(), c.cfg.Port))
}
