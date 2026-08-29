# Apache

Optional first-release web server capability. Apache may own public ports 80 and 443 when selected alone. In the `both` profile, Apache is a LESta-owned loopback backend and nginx is the only public listener. Runtime templates are shipped and versioned with the Go agent.

`install.sh` is a standalone leaf installer, exactly like `bind9/install.sh`: it never orchestrates nginx or any other service. `nginx/install.sh` (the web installer, per `.install/INSTALLER-CONTRACT.md`) is what dispatches here for `--web-server apache|both`; a bare `apache/install.sh --apply --yes` run (no `--web-profile` flag at all) is the common, standalone case, and behaves identically to being invoked directly by an operator.

## `--web-profile apache|both`

Defaults to `apache` when omitted.

- `apache`: this node's own Apache owns public ports 80/443 directly. Preflight checks those ports are free of a conflicting web server package (nginx/lighttpd), mirroring `nginx/install.sh`'s own conflicting-package check in the opposite direction.
- `both`: Apache is a LESta-owned loopback-only backend behind nginx, which owns 80/443 instead. Preflight skips the port/package checks entirely (nginx legitimately owns those ports in this profile), and no firewall ports are registered for Apache at all. `install_apache` rewrites `/etc/apache2/ports.conf` to `Listen 127.0.0.1:8080` only (replacing the package's own stock `Listen 80`) and disables the stock `000-default` site, so Apache never attempts to bind the public port nginx already owns. `/etc/lesta/web-profile` is written with `both` as this installer's final apply step before the self-test, which `agent/cmd/lesta-agent/main.go`'s `apacheProductionConfig()` reads at process start to pick the same port (8080) for every rendered vhost.

Per ADR 0002 Decision 6, the web profile is immutable after bootstrap: once a node is bootstrapped as `both`, re-running with a bare `apache/install.sh --apply --yes` (implicitly `--web-profile apache`) is an unsupported profile migration, not a supported operation.

## Manual prerequisite

`install.sh` never writes to `/etc/apache2/apache2.conf`. That file is listed under both `read_only_roots` and `refused_roots` in this service's `manifest.json`, and the installer only ever reads it, using the exact same detection logic `agent/internal/capability/apache/validate.go` already uses at runtime (a line containing both the substring `Include` and the substring `/etc/apache2/lesta.d/*.conf` -- capitalized, matching Apache's own `Include`/`IncludeOptional` convention), so the installer and the running agent can never disagree about whether the precondition holds.

Before running `install.sh --apply --yes`, an operator must, by hand:

1. Install apache2 if it is not already present (`apt-get install -y apache2`; the installer's own `install_apache` phase will also run this, idempotently, once preflight has passed).
2. Add this exact line inside `/etc/apache2/apache2.conf`:

   ```
   IncludeOptional /etc/apache2/lesta.d/*.conf
   ```

3. **Do not remove any other existing `Include`/`IncludeOptional` line.** Distribution defaults (e.g. `IncludeOptional mods-enabled/*.load`, `IncludeOptional sites-enabled/*.conf`) must stay in place; this installer only ever adds its own dedicated include root alongside them, never in place of them.

If this line is missing, `install.sh` fails preflight with exit code `12` and a message naming this exact remediation. This is deliberate: a single surgical edit to an operator-managed main configuration file is exactly the kind of "helpful" automated mutation the installer contract forbids (see `.install/INSTALLER-CONTRACT.md`'s "Supply chain" and "Preflight" sections). The include line is the one, explicit, hand-verified seam between operator-owned configuration and LESta-owned configuration.
