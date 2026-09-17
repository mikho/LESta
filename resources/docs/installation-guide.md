# LESta Installation Guide

This is a working manual for setting up a real infrastructure node — the actual Ubuntu server LESta will manage.

---

## Chapter 1: Prerequisites

- **Operating system**: Ubuntu 24.04 or 26.04 (`.install/base/manifest.json`'s own `supported_ubuntu`). MariaDB is the one exception — see Chapter 6's own note; its upstream package repository doesn't yet support 26.04.
- **A dedicated machine or VM per node.** LESta's own model is one agent per physical/virtual server; nothing here is written to share a node's identity across two separate LESta deployments.
- **Root or sudo access** on that server, since installers create system users, packages, and firewall rules.
- **Outbound network access** to Ubuntu's package archives (or a pre-built offline bundle — see Chapter 3).
- **The LESta control-plane app already running somewhere reachable over HTTPS.** The node's agent authenticates back to it with a bearer token, not mutual TLS (Chapter 2) — the control-plane URL you give the node **must** actually terminate real HTTPS, or the enrollment token and the long-lived credential it exchanges for both travel in plaintext.
- **Gap, genuinely unclear:** nothing in the repository crisply says how an operator is meant to get the `.install/` directory and a matching agent binary onto a fresh server in the first place. The README speaks of "a signed LESta release bundle" as an established artifact, but the only real build tooling found (`.install/scripts/build-release.sh`) produces a *per-service offline package bundle* (vendored `.deb` files for one service, e.g. nginx or MariaDB), not a combined "get LESta itself onto a node" package. In practice today: clone the repository (or `git archive` it, which is confirmed to preserve `.install/` intact) onto the node, and run the scripts from there.

---

## Chapter 2: Enroll the node

Do this in the LESta admin app first, before touching the server:

1. **Register the node**: `Nodes → Add node` (see the Admin Guide, Chapter 5) — just a name and hostname, no server contact happens yet.
2. **Issue an enrollment token**, either from the node's own edit page (**Issue enrollment token** button) or from the command line:
   ```
   php artisan lesta:nodes:issue-enrollment-token <node_uuid>
   ```
   Both produce the exact same one-time, 30-minute token. The raw token is shown/printed exactly once; issue a new one if it's lost.
3. **On the node**, run the agent daemon installer with that token:
   ```
   .install/services/agent-daemon/install.sh --apply --yes \
       --node-uuid <node_uuid> \
       --enrollment-token <token> \
       --control-plane-url https://your-lesta-domain.example
   ```
   This exchanges the token for a long-lived credential (written to `/etc/lesta/agent/node-credential`, root-only), and starts the `lesta-agent-daemon` systemd service, which heartbeats the node's liveness and capabilities back to LESta from here on.

This step alone doesn't give the node any real capability yet — it just makes the node exist and check in. Everything below adds an actual service.

---

## Chapter 3: The real installers, and the order they actually need to run in

| Service | Installs | Depends on |
|---|---|---|
| `firewall` | baseline nftables policy | base only — **runs automatically**, embedded in every other installer below, never invoked by hand |
| `node-health` | agent registration/heartbeat plumbing | base only — **also automatic**, embedded the same way |
| `nginx` | nginx web server | firewall, node-health |
| `apache` | Apache web server | firewall, node-health |
| `bind9` | BIND9 DNS server | firewall, node-health |
| `mariadb` | **both** the tenant database instance (port 3307) and this app's own control-plane database instance (port 3306) | node-health, firewall |
| `cron` | account-scoped scheduled jobs | node-health |
| `acme` | TLS certificates | nginx and/or apache — **no standalone install script**; this is a Laravel-side job (`IssueAcmeCertificate`), not something you run on the node at all. It works automatically once a web server is present. |
| `mail` | Exim + Dovecot mailboxes | **bind9, nginx, and acme, all already healthy** |
| `backups` | encrypted whole-node snapshots | node-health only (deliberately minimal — it backs up whatever else happens to be present) |
| `statistics` | usage/metrics collection | nginx and/or apache, mail, node-health, the tenant database — **no standalone install script either**; real support (access-log directives, a stats database account) is built into nginx/apache's and mariadb's own installers. Only the last step, declaring `metrics.usage.v1` in the admin UI (Admin Guide, Chapter 5), is separate. |

**Firewall and node-health never need to be run by hand at all** — every real installer below bootstraps both automatically and idempotently the first time it runs on a fresh node. The genuinely operator-run installers are: `nginx` and/or `apache`, `bind9`, `mariadb`, `cron`, `mail`, `backups`. `acme` and `statistics` are side effects of the others plus one admin-UI step, not their own install run.

**The dependency order that actually matters, if you want everything**: web server(s) first (nginx and/or apache) → DNS (bind9) → mail last of all, since mail alone needs bind9, a web server, *and* a real ACME certificate all already working before it will even start. Databases (mariadb) and cron have no ordering relationship with the others and can go any time after the first web/DNS install has bootstrapped firewall+node-health. Backups can go literally any time.

Every installer shares the same invocation shape:

```
install.sh --dry-run|--apply|--version [--yes] [--help]
```

`--dry-run` reports what would change with zero mutation — always run this first on a new node. `--apply` requires `--yes` (no interactive prompts, ever). A handful of installers take extra required/optional flags:

- **nginx**: `--web-server nginx|apache|both` (required). Choosing `both` also installs and configures Apache as a loopback-only backend behind nginx — this is the one installer that dispatches to another (`apache/install.sh`) on your behalf.
- **apache** (only if you ever run it standalone, not via nginx's `both` dispatch): `--web-profile apache|both` (defaults to `apache`).
- **mail**: `--mail-hostname <fqdn>` (required with `--apply`) — a `WebDomain` for that exact hostname must already exist in LESta with a real, already-issued ACME certificate at `/var/lib/lesta/acme/certs/<fqdn>/{fullchain,privkey}.pem`. This is the concrete shape of "ACME must already be working" from the table above.
- **mariadb**, **cron**, **mail**: all accept `--offline-bundle <path>` for a fully offline install from a pre-verified package bundle (built by `.install/scripts/build-release.sh`), with zero network access needed at apply time.

**The web profile choice (nginx/apache/both) is permanent once applied.** There is no supported migration path from one to another after bootstrap — re-running the installer with a different profile is explicitly an unsupported operation, not a safe change.

**You don't have to run these one at a time.** Chapter 4 covers `install-selected.sh`, which runs several of them together, in the correct order, from one invocation.

---

## Chapter 4: The combined installer (`install-selected.sh`)

`.install/scripts/install-selected.sh` replaces the manual, order-dependent repetition above with one invocation:

```
install-selected.sh --dry-run|--apply|--version --services <a,b,c> [options]
```

- `--services` is a comma-separated list drawn from the seven operator-run installers in Chapter 3's table: `nginx,apache,bind9,mariadb,cron,mail,backups`. `agent-daemon` (Chapter 2) is deliberately never selectable here — it takes per-node enrollment secrets, not a service choice, and stays its own separate, always-manual step.
- It resolves the real install order itself, from each selected service's own `manifest.json` (`depends_on`/`provides`). You don't have to know the ordering rule from Chapter 3 by heart; naming the services you want is enough.
- Every per-service flag from Chapter 3 still applies, just forwarded through: `--web-server`, `--web-profile`, `--mail-hostname`. `--offline-bundle` is scoped per service here, since one bundle is only ever built for one service: `--offline-bundle nginx=/path/to/bundle` (repeatable, one per service that needs it).
- `--dry-run` genuinely runs each selected service's own real preflight — always run it first, exactly as for a single installer.
- **Fails closed before any mutation** if a selected service's own dependency isn't satisfiable by another selected service — for example, `--services mail` alone, without `bind9`/`nginx` also selected, exits `12` and names exactly what's missing. It never silently pulls in a service you didn't ask for.
- **An already-installed prerequisite satisfies a dependency without being re-listed.** If nginx and bind9 are already installed from an earlier run and you now want to add mail, `--services mail` alone is enough — the orchestrator checks the node's own real state (the same check `--prune` uses) before failing closed, so it only asks you to add a prerequisite to `--services` when that prerequisite genuinely isn't there yet.
- If one service in the run fails, every already-succeeded service in the same run stays applied — there's no automatic rollback across services. Fix the reported blocker and re-run with the same `--services`.
- `install-selected.sh` never runs `--prepare-config` on your behalf (Chapter 6's own manual prerequisite still applies per service).

Example — install nginx and cron together on a fresh node:

```
sudo apt-get install -y nginx
sudo .install/services/nginx/install.sh --prepare-config --yes
sudo .install/scripts/install-selected.sh --dry-run --services nginx,cron --web-server nginx
sudo .install/scripts/install-selected.sh --apply --yes --services nginx,cron --web-server nginx
```

---

## Chapter 5: Removing services (`--prune`)

> **This is a destructive operation. Read this whole chapter before using it.**

`install-selected.sh` also takes `--prune`, alongside the same `--services` selection: instead of only adding what you name, it reconciles the node down to *exactly* that selection plus a fixed protected baseline, removing everything else present.

**This reaches further than LESta's own footprint, by design.** `--prune` doesn't just remove LESta services you no longer want — it also removes unrelated OS packages that aren't part of a small, fixed protected list (`.install/base/protected-packages.txt`): SSH, `sudo`, the package manager and its trust chain, `systemd`, core networking, the firewall/intrusion-prevention baseline, unattended security updates, and the running kernel/bootloader are the only things it will never touch, regardless of what you select. Everything else non-protected and not required by your current `--services` selection is a real removal candidate.

**Always run `--prune --dry-run` first.** It performs zero mutation and lists every real removal candidate by name — which services would be dropped, which packages would be purged, and which dropped services would need the confirmation below.

**A service holding real data gets one extra, explicit gate — not a refusal.** `mariadb` (its tenant instance), `mail` (real received mail), and `backups` (the backup artifacts themselves) can hold genuinely irreplaceable data. If dropping one of these from your selection would remove it, `--prune --apply` alone is not enough: you must also pass `--confirm-data-loss <service>`, once per such service. Miss one and the whole run fails closed before touching anything, naming exactly which service needs it.

**Even confirmed, that data is quarantined, not deleted.** A confirmed removal stops the service, purges its own packages, and moves its real data directory to `/var/lib/lesta/<service>/removed-<UTC-timestamp>/` instead of deleting it outright. Recovering it back into a running state is a manual operation this tooling doesn't automate.

**MariaDB's control-plane instance is never a removal candidate, under any circumstance.** `mariadb-server`/`mariadb-client` are shared between the tenant database (a real, droppable capability) and this application's own control-plane database (never a `NodeCapability`, never something you'd select or drop). Dropping `mariadb` from your selection only ever stops the tenant instance and quarantines its data; the packages stay installed and the control-plane instance keeps running.

**After a real removal, remove the capability from the node in the admin app yourself.** `--prune` has no path back into Laravel's own database, so go to `Nodes → [your node] → Capabilities` and reset the corresponding entry back to Not installed by hand (Admin Guide, Chapter 5).

Example — a node currently running nginx, bind9, and mariadb, dropping bind9 and the mariadb tenant instance, keeping only nginx:

```
sudo .install/scripts/install-selected.sh --prune --dry-run --services nginx --web-server nginx
sudo .install/scripts/install-selected.sh --prune --apply --yes --services nginx --web-server nginx --confirm-data-loss mariadb
```

---

## Chapter 6: One real, per-service manual step you cannot skip

nginx, bind9, and apache each refuse to write to their own distribution's main config file (`/etc/nginx/nginx.conf`, `/etc/bind/named.conf`, `/etc/apache2/apache2.conf`) — this is deliberate, not a bug: a silent edit to an operator-owned file is exactly what the installer contract forbids. Before running any of these three with `--apply` (directly or via `install-selected.sh`), you must add one line to that file, either by hand or via `--prepare-config --yes` (a separate, explicit, idempotent mode that does only this one edit and nothing else):

| Service | File | Line to add |
|---|---|---|
| nginx | `/etc/nginx/nginx.conf`, inside the `http {}` block | `include /etc/nginx/lesta.d/*.conf;` |
| bind9 | `/etc/bind/named.conf` itself — **never** `named.conf.local` | `include "/etc/bind/lesta.d/*.conf";` |
| apache | `/etc/apache2/apache2.conf` | `IncludeOptional /etc/apache2/lesta.d/*.conf` |

Missing this line fails preflight with exit code `12` and a message naming the exact fix — it will not fail silently or partway through.

**Note, not a gap:** MariaDB's upstream 11.4 LTS package repository doesn't currently publish for Ubuntu 26.04 — `mariadb/install.sh` fails closed with a clear `mariadb_repo_unavailable` error on that release rather than silently substituting a different MariaDB version. Use 24.04 for a node that needs MariaDB, until upstream catches up.

---

## Chapter 7: After the installer succeeds

Running `install.sh --apply` on the node (directly, or via `install-selected.sh`) does **not** by itself make LESta aware the capability exists — declare it in the admin app (`Nodes → [your node] → Capabilities`, Admin Guide Chapter 5), matching exactly what you just installed:

| You ran | Declare this capability |
|---|---|
| `nginx/install.sh` | `web.nginx.v1` |
| `apache/install.sh` (standalone or via nginx's `both`) | `web.apache.v1` |
| `bind9/install.sh` | `dns.bind9.v1` |
| `mariadb/install.sh` | `database.tenant.v1` (the control-plane instance is this application's own database — it never gets declared as a tenant-facing `NodeCapability` at all) |
| `cron/install.sh` | `scheduler.account-cron.v1` |
| `mail/install.sh` | `mail.smtp-imap.v1` |
| `backups/install.sh` | `backup.encrypted-artifacts.v1` |
| (nginx/apache + mariadb already installed) | `metrics.usage.v1`, once you're ready to start collecting usage |

A capability's status only shows **Running** once the agent has actually reported it back to the control plane through a real heartbeat (Admin Guide, Chapter 5) — declaring it here is necessary but not sufficient by itself; the agent's own confirmation is what actually flips it. After a `--prune` removal, the reverse applies: reset the capability back to Not installed in the admin app yourself.

---

## Chapter 8: Verifying and re-running

- Every installer writes structured JSON output and a log at `/var/log/lesta/install/<run-id>.jsonl`. Secrets (tokens, credentials, private keys) are redacted before either ever gets written.
- All are safe to re-run: a rerun performs preflight again, repairs only LESta-owned state, and never touches or overwrites a file it doesn't own. Re-running is how you pick up an update, not a separate "upgrade" command.
- Exit codes are consistent across every installer, including `install-selected.sh`: `0` success, `10` invalid invocation, `11` unsupported platform, `12` a preflight conflict (like the missing include line above, or a `--prune` missing `--confirm-data-loss`), `13` a checksum/signature failure, `20` a mutation failure, `21` a health-check failure, `22` a rollback failure, `30` an interrupted run needing another pass.

---

## Chapter 9: Known gaps

1. **`mail/README.md` and `backups/README.md` are out of date** in their own prose: mail's own README still frames it as an ungated future capability pending a threat-model review that has, in fact, already happened and already shipped (the real Exim/Dovecot capability, DKIM, spam/virus scanning); backups' own README says runtime storage "uses object storage," but the real, shipped implementation is local-disk-only, a deliberate, disclosed scoping decision. Neither file documents its own installer's real flags or manual prerequisites at all — someone reading only `mail/README.md` would not learn that `--mail-hostname` exists or is required.
2. **No documented way to get the installer files onto a fresh server in the first place** — see Chapter 1. "Clone the repo" works today but isn't written down as the intended mechanism anywhere.
3. **`statistics`, `node-health`, `firewall`, and `acme` have manifests and dependency entries but no standalone `install.sh`** — this isn't wrong, but it isn't obvious either; someone following the services directory listing literally would go looking for four scripts that were never meant to exist independently.
