# Mail

Real, shipped mail hosting capability: Exim4 (SMTP submission/relay) and Dovecot (IMAP), with DKIM signing (automatic key rotation), real ClamAV antivirus and SpamAssassin antispam scanning on incoming mail, per-mailbox quotas, and mailbox password rotation. TLS for the submission (587)/smtps (465)/imaps (993) listeners reuses the existing WebDomain+ACME flow rather than a separate certificate mechanism -- see "Manual prerequisite" below.

## Manual prerequisite

`install.sh` never writes to `/etc/dovecot/dovecot.conf`. That file is a `read_only_root` in this service's `manifest.json`; the installer only ever reads it. Exim is the one exception among every installer in this family: its config format has exactly one `begin acl`/`begin routers`/`begin transports`/`begin authenticators` section each, and Debian's default split-config assembly already defines all four, so an include-line approach (like nginx/bind9/apache) would produce duplicate-section errors, not a working config. `install.sh` instead takes full ownership of `/etc/exim4/exim4.conf` itself, forcing Debian's own `dc_use_split_config='false'`, and writes one complete, self-contained config every apply.

Before running `install.sh --apply --yes --mail-hostname <fqdn>`, an operator must, by hand:

1. Install `dovecot-core` if it is not already present (`apt-get install -y dovecot-core`; the installer's own install phase will also run this, idempotently, once preflight has passed).
2. Add this exact line inside `/etc/dovecot/dovecot.conf` itself:

   ```
   !include /etc/dovecot/lesta.conf
   ```

3. Have a `WebDomain` for `<fqdn>` already created in LESta (any web profile) with a real, already-issued ACME certificate at `/var/lib/lesta/acme/certs/<fqdn>/{fullchain,privkey}.pem`. Preflight fails closed if that certificate doesn't exist yet -- this installer never issues one itself.

If the Dovecot include line is missing, `install.sh` fails preflight with exit code `12` naming this exact remediation. As an automated alternative to the hand edit in step 2, `install.sh --prepare-config --yes` appends the same line to `dovecot.conf` for you (idempotent).

## `install.sh` flags

```
install.sh --dry-run|--apply|--version|--prepare-config [--mail-hostname <fqdn>] [--offline-bundle <path>] [--yes] [--help]
```

- `--mail-hostname <fqdn>` — **required** with `--dry-run`/`--apply`. The real hostname whose already-issued ACME certificate (see step 3 above) becomes this node's mail TLS certificate.
- `--offline-bundle <path>` — optional. Installs `exim4-daemon-heavy`, `dovecot-imapd`/`dovecot-lmtpd`/`dovecot-sieve`, `clamav-daemon`/`clamav-freshclam`, and `spamassassin` from a pre-verified offline bundle (built by `.install/scripts/build-release.sh`) instead of Ubuntu's package archive, mirroring `mariadb/install.sh`'s own precedent.
- `--prepare-config` — see "Manual prerequisite" above. Requires `--yes`. Never run implicitly by `--dry-run`/`--apply`.

## What health-checking actually proves, and what doesn't run on a live node

`bootstrap_node_health`'s self-test creates a real, disposable mail domain against the just-installed Exim/Dovecot, then deletes it. `install.sh` itself also runs a real protocol-level health probe against both scanner daemons before reporting the capability installed: `clamd_health_probe` speaks ClamAV's own local-socket protocol and checks for `PONG`, `spamd_health_probe` speaks spamd's own wire protocol and checks for `PING`/`PONG` -- proving each daemon is actually up and answering, not merely that its systemd unit is active.

Full end-to-end detection proof (a real EICAR test string for ClamAV, a real GTUBE test string for SpamAssassin, actually scanned through Exim's ACLs) is this project's own CI test suite's job, not something `install.sh` runs against a live node -- the installer's own probes above are a liveness check, not a detection test.
