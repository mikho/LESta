# BIND9

Authoritative DNS capability. Runtime zone files are generated into the LESta-owned include and generation roots. Distribution configuration and unrelated operator zones are read-only or refused.

## Manual prerequisite

`install.sh` never writes to `/etc/bind/named.conf`. That file is listed under both `read_only_roots` and `refused_roots` in this service's `manifest.json`, and the installer only ever reads it, using the exact same detection logic `agent/internal/capability/bind9/validate.go` already uses at runtime (a line containing both the substring `include` and the substring `/etc/bind/lesta.d/*.conf`), so the installer and the running agent can never disagree about whether the precondition holds.

Before running `install.sh --apply --yes`, an operator must, by hand:

1. Install bind9 if it is not already present (`apt-get install -y bind9 bind9-utils`; the installer's own `install_bind9` phase will also run this, idempotently, once preflight has passed).
2. Add this exact line inside `/etc/bind/named.conf` **itself**:

   ```
   include "/etc/bind/lesta.d/*.conf";
   ```

3. **Do not add this line to `/etc/bind/named.conf.local` instead**, even though that file is Ubuntu's own usual convention for adding zones. `agent/internal/capability/bind9/config.go`'s `Config` has no field for `named.conf.local` at all, only `NamedConfPath`, and `buildSyntheticConfig` in `validate.go` only ever reads `NamedConfPath` when building a synthetic config to validate a candidate zone. An include line added to `named.conf.local` instead of `named.conf` would leave the agent unable to find its own live directory at all, breaking every zone operation outright, not just failing to pick up new zones.
4. **Do not remove any other existing `include` line.** Distribution defaults (e.g. `include "/etc/bind/named.conf.local";` or `include "/etc/bind/named.conf.default-zones";`) must stay in place; this installer only ever adds its own dedicated include root alongside them, never in place of them.

If this line is missing, `install.sh` fails preflight with exit code `12` and a message naming this exact remediation. This is deliberate: a single surgical edit to an operator-managed main configuration file is exactly the kind of "helpful" automated mutation the installer contract forbids (see `.install/INSTALLER-CONTRACT.md`'s "Supply chain" and "Preflight" sections). The include line is the one, explicit, hand-verified seam between operator-owned configuration and LESta-owned configuration.
