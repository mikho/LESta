# LESta Host Bootstrap

`.install` is the single repository home for operator-invoked installation, upgrade, and recovery logic for services required by a LESta managed Ubuntu node.

## Boundary

Bootstrap installs pinned packages, creates the hardened baseline, lays down dedicated directories, establishes the baseline firewall, and installs the separately versioned Go node agent. Runtime provisioning is owned by the node agent and uses named, schema-validated capabilities.

Laravel never executes host commands and never calls `.install`, directly or through a queue job, controller, HTTP endpoint, or agent capability. The protocol contains no generic shell, package installation, installer execution, or arbitrary command operation. Tenant resource mutations become desired state in Laravel and are reconciled by the agent after authorization, idempotency, and audit checks.

## Execution model

- Preflight checks run before any mutation.
- Manifests declare dependencies, supported Ubuntu releases, packages, ports, and capabilities.
- Exact versions, checksums, signatures, and provenance are verified before downloads or installation.
- Installation is unattended, convergent, resumable, and observable through structured output.
- Service configuration is rendered by the agent into staged generations, validated, atomically activated, health-checked, and rollback-capable.
- A blank server may select `nginx`, `apache`, or `both`. In the `both` profile, nginx owns public ports 80 and 443 and proxies to Apache on a LESta-owned loopback port.

Seven services have a real, independently-runnable installer: `nginx`, `apache`, `bind9`, `mariadb`, `cron`, `mail`, `backups` (plus `agent-daemon`, the separate node-enrollment installer). `firewall` and `node-health` bootstrap automatically inside every one of those; `acme` and `statistics` have no standalone installer at all -- their own real support (TLS issuance, access-log directives, a stats database account) is built into the web server/mariadb installers above and one admin-UI declaration step, not a fifth or sixth script that was ever meant to exist independently. `.install/scripts/install-selected.sh` runs several of the seven together in the correct dependency order from one invocation, including its `--prune` option for removing services no longer wanted. See the in-app Installation Guide (`/docs/installation-guide` in the running application, sourced from `resources/docs/installation-guide.md`) for the full operator walkthrough.

## Getting `.install` onto a fresh node

There is no separate "LESta release bundle" artifact that bundles the app, the agent binary, and `.install` together for a fresh node -- the only real build tooling here, `.install/scripts/build-release.sh`, produces a *per-service offline package bundle* (vendored `.deb` files for one apt-based service, e.g. nginx or mariadb, consumed by that service's own `--offline-bundle` flag), not a combined "get LESta itself onto a node" package.

The full application source (Laravel app code, the full Go agent source tree, tests, docs) has no business sitting on a hosting node, though, so cloning this entire repository onto one is not the intended mechanism either. `install.sh`, at the repository root, is: it fetches exactly the subset a node needs -- `.install/` and the one prebuilt agent binary at `agent/dist/lesta-agent-linux-amd64` -- via a real git partial clone plus cone-mode sparse-checkout (the root `.install` path is tracked and not excluded by `.gitignore`/`.gitattributes`, confirmed under "Tracking and release behavior" below, so it survives this cleanly). Fetch it, read it, then run it explicitly -- never a piped `curl | sh`, per the "Safety rule" below and `INSTALLER-CONTRACT.md`'s own supply-chain rule, even though this particular script does no system mutation at all:

```
curl -fsSL -o install.sh https://raw.githubusercontent.com/mikho/LESta/main/install.sh
less install.sh   # read it before running it -- it's short, and only ever runs git
chmod +x install.sh
sudo ./install.sh --apply --yes --dest /opt/lesta
```

See the Installation Guide's own Chapter 2 for the full walkthrough, including `--dry-run`, updating an existing checkout in place, and `--repo`/`--ref` overrides for a fork or a pinned release.

## Directory layout

```text
.install/
  README.md
  INSTALLER-CONTRACT.md
  manifest.schema.json
  profiles/
    README.md
    schema.json
    nginx.json
    apache.json
    both.json
  base/
    manifest.json
    README.md
  services/
    nginx/
    apache/
    acme/
    bind9/
    mariadb/
    cron/
    firewall/
    mail/
    backups/
    statistics/
    node-health/
```

The base layer is shared. Service manifests contain only service-specific metadata. Runtime templates do not live here, because the node agent must version and test the renderer together with its protocol and activation logic. `.install` may reference a signed agent release, but it must not become a second template engine.

The web profile is selected once during blank-node bootstrap. `nginx` and `apache` are mutually exclusive public listeners. `both` installs both capabilities, assigns nginx the public listener, and binds Apache to a fixed loopback-only backend listener. The selected profile is recorded in node state and cannot be changed by a tenant request. Profile metadata is validated against `profiles/schema.json` before service manifests are planned.

## Tracking and release behavior

The root `.install` path is not excluded by `.gitignore` or `.gitattributes`. It is therefore tracked once its files are added to Git and survives `git archive`. Vite and Composer do not package it as runtime output, which is desirable: the installer is a release/source artifact, not an application asset. See "Getting `.install` onto a fresh node" above for how an operator actually obtains it today.

## Safety rule

Nothing in the Laravel application, React application, queue system, or node runtime may call these scripts at request time. Any future exception requires a new ADR, a named bootstrap-only operator workflow, and security review.
