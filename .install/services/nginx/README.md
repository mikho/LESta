# nginx

First-release web server capability. nginx may own public ports 80 and 443 when selected alone, or serve as the sole public listener in the `both` profile with Apache as a loopback-only backend. Runtime templates belong to the Go agent release. Bootstrap owns package installation and the dedicated include root only.

## Manual prerequisite

`install.sh` never writes to `/etc/nginx/nginx.conf`. That file is listed under both `read_only_roots` and `refused_roots` in this service's `manifest.json`, and the installer only ever reads it, using the exact same detection logic `agent/internal/capability/nginx/validate.go` already uses at runtime (a line containing both the substring `include` and the substring `/etc/nginx/lesta.d/*.conf`), so the installer and the running agent can never disagree about whether the precondition holds.

Before running `install.sh --apply --web-server nginx --yes`, an operator must, by hand:

1. Install nginx if it is not already present (`apt-get install -y nginx`; the installer's own `install_nginx` phase will also run this, idempotently, once preflight has passed).
2. Add this exact line inside `/etc/nginx/nginx.conf`'s `http {}` block:

   ```
   include /etc/nginx/lesta.d/*.conf;
   ```

3. **Do not remove any other existing `include` line.** Distribution defaults (e.g. `include /etc/nginx/conf.d/*.conf;` or `include /etc/nginx/sites-enabled/*;`) must stay in place; this installer only ever adds its own dedicated include root alongside them, never in place of them.

If this line is missing, `install.sh` fails preflight with exit code `12` and a message naming this exact remediation. This is deliberate: a single surgical edit to an operator-managed main configuration file is exactly the kind of "helpful" automated mutation the installer contract forbids (see `.install/INSTALLER-CONTRACT.md`'s "Supply chain" and "Preflight" sections). The include line is the one, explicit, hand-verified seam between operator-owned configuration and LESta-owned configuration.
