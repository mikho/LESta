# Installer Contract

Every future installer and the shared bootstrap runner must satisfy this contract. This document is normative. The current repository contains no executable installer yet.

## Invocation

- Non-interactive by default. `--yes` is required for unattended mutation; stdin prompts are forbidden.
- Required modes: `--dry-run`, `--apply`, and `--version`. A leaf-service installer whose own precondition includes an operator-managed config edit (nginx, bind9, apache: see Preflight below) may also offer `--prepare-config`, a separate, explicitly opt-in mode that performs only that one edit and exits; it is never invoked implicitly by `--dry-run` or `--apply`, and it never runs their own preflight or mutation logic.
- Dry-run performs manifest verification and complete preflight without changing packages, services, files, users, ports, or firewall state.
- The installer accepts only a release bundle path and declared options, including `--web-server nginx|apache|both` (the web installer's own profile choice; other leaf-service installers, e.g. the DNS installer, have no such flag) and Apache's own narrower `--web-profile apache|both` (topology-awareness only, never a dispatch flag: it never orchestrates another service the way nginx's `--web-server` does). It does not accept shell fragments, arbitrary package names, arbitrary service names, or arbitrary commands.
- The web profile is immutable after bootstrap. Changing it requires an explicit operator migration workflow with a preflight, backup, port plan, staged validation, and rollback.

## Supply chain

- No `curl | bash`, dynamic remote script execution, or unpinned network fetch is permitted.
- Every downloaded package, binary, template bundle, and metadata file has an exact version, source, SHA-256 digest, and signature or provenance record.
- Offline installation from a previously verified bundle is supported.
- Repository configuration is pinned to approved Ubuntu sources and an explicit release.
- A failed signature or checksum verification exits before mutation.

## Preflight

Preflight must run before any mutation and report:

- Ubuntu release, architecture, kernel, and required privileges
- available disk and inode capacity at every affected filesystem
- occupied required ports and owning processes
- selected web profile and listener plan: nginx public, Apache public, or nginx public plus Apache loopback backend (the web installer's own report; a non-web leaf-service installer reports its own equivalent preflight facts instead, not a web profile)
- conflicting packages, services, users, groups, directories, and firewall tables
- package source reachability or offline bundle completeness
- time synchronization and hostname requirements
- current installer, agent, and manifest versions
- whether an upgrade, repair, or fresh installation is being attempted

Conflicts fail closed. The installer never removes or takes ownership of an operator-managed service without a separate explicit migration workflow.

A service manifest's own `read_only_roots`/`refused_roots` (e.g. nginx's `/etc/nginx/nginx.conf`, bind9's `/etc/bind/named.conf`, apache's `/etc/apache2/apache2.conf`) describe `--dry-run`/`--apply` behavior only: those two modes only ever read the file, detecting the required LESta include line via the exact substring check the running agent's own capability validator also uses, never writing to it. `--prepare-config`, where an installer offers it, is a separate, explicitly-invoked, opt-in exception to that read-only stance -- it inserts exactly that one documented include line and nothing else, is never triggered implicitly by either `--dry-run` or `--apply`, and never touches, matches, or removes any other line already in the file.

## Output and exit codes

Output is newline-delimited JSON on stdout and human-readable diagnostics on stderr. Secrets, tokens, private keys, passwords, and credential-bearing URLs are redacted before either stream or persistent logging.

The final result includes:

```json
{
  "schema_version": "1",
  "installer": "lesta-bootstrap",
  "service": "web",
  "web_profile": "both",
  "mode": "dry-run",
  "status": "would_change",
  "exit_code": 0,
  "release": "2026.08.26",
  "manifest_digest": "sha256:...",
  "capabilities_provided": ["web.nginx.v1", "web.apache.v1"],
  "capabilities_required": ["base.os.v1", "node.health.v1"],
  "changes": [],
  "errors": []
}
```

Exit codes are deterministic:

- `0`: success or valid dry-run
- `10`: invalid invocation or manifest
- `11`: unsupported platform or architecture
- `12`: preflight conflict or insufficient capacity
- `13`: verification, signature, or checksum failure
- `20`: package or filesystem mutation failure
- `21`: service validation or health failure
- `22`: rollback or recovery failure
- `30`: interrupted or incomplete run requiring recovery

## Logging

Logs are written to `/var/log/lesta/install/<run-id>.jsonl` with mode `0640`, owned by root and the approved operator group. The logger redacts known secret fields and secret-shaped values at the boundary. The installer never logs command lines containing credentials, environment dumps, private keys, or unredacted package URLs.

## Capabilities and dependencies

Each installer declares capabilities it provides and requires in its manifest. The runner constructs an acyclic dependency graph and refuses to run an incomplete or cyclic graph. A capability is not registered as usable until its post-install health check passes and the agent reports it to the control plane.

Web profile files are metadata, not executable instructions, and are validated against `.install/profiles/schema.json`. Each contains `schema_version`, `id`, `web_profile`, `services`, `public_listener`, `backend_listener`, and `public_ports`. Profile validation must enforce that `services` matches the selected profile, public ports are `[80, 443]`, and only `both` has a backend listener. Service manifests remain the authority for package, dependency, capability, and ownership declarations.

## Re-run and recovery

Installers are convergent and safe to rerun against the same release. A partial run records a checkpoint and leaves a structured incomplete result. Rerunning performs preflight, repairs only declared LESta-owned state, and resumes from the last safe checkpoint. Existing operator-owned files are never overwritten.

Upgrades use side-by-side generations. The prior healthy generation remains available until the new package set, configuration, reload, and health checks pass. Uninstall and destructive migration are separate operator workflows and are never implied by `--apply`.

## Automated enforcement

The contract is enforceable through JSON Schema validation, manifest graph validation, forbidden-token/static checks, ShellCheck for shell entrypoints, unit tests for exit/result schemas, and disposable Ubuntu 24.04/26.04 integration tests. Release CI must test fresh, rerun, interrupted, conflicting, tampered, failed-validation, failed-health, upgrade, and rollback scenarios.
