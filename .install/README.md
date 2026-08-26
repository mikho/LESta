# LESta Host Bootstrap

`.install` is the single repository home for operator-invoked installation, upgrade, and recovery logic for services required by a LESta managed Ubuntu node.

## Boundary

Bootstrap installs pinned packages, creates the hardened baseline, lays down dedicated directories, establishes the baseline firewall, and installs the separately versioned Go node agent. Runtime provisioning is owned by the node agent and uses named, schema-validated capabilities.

Laravel never executes host commands and never calls `.install`, directly or through a queue job, controller, HTTP endpoint, or agent capability. The protocol contains no generic shell, package installation, installer execution, or arbitrary command operation. Tenant resource mutations become desired state in Laravel and are reconciled by the agent after authorization, idempotency, and audit checks.

## Execution model

- An operator obtains a signed LESta release bundle and runs the installer locally or from approved offline media.
- Preflight checks run before any mutation.
- Manifests declare dependencies, supported Ubuntu releases, packages, ports, and capabilities.
- Exact versions, checksums, signatures, and provenance are verified before downloads or installation.
- Installation is unattended, convergent, resumable, and observable through structured output.
- Service configuration is rendered by the agent into staged generations, validated, atomically activated, health-checked, and rollback-capable.
- A blank server may select `nginx`, `apache`, or `both`. In the `both` profile, nginx owns public ports 80 and 443 and proxies to Apache on a LESta-owned loopback port.

No service installer is implemented in this scaffolding step. The directory contains contracts, manifests, and discoverable service boundaries only.

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

The root `.install` path is not excluded by `.gitignore` or `.gitattributes`. It is therefore tracked once its files are added to Git and survives `git archive`. Vite and Composer do not package it as runtime output, which is desirable: the installer is a release/source artifact, not an application asset. Release CI must explicitly inspect both the source archive and the signed installer bundle.

## Safety rule

Nothing in the Laravel application, React application, queue system, or node runtime may call these scripts at request time. Any future exception requires a new ADR, a named bootstrap-only operator workflow, and security review.
