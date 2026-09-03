# Cron

Account-scoped scheduled work only. The runtime capability must reject root execution, unsafe interpreters, cross-account paths, unbounded output, and commands outside the approved catalog or isolated runner policy.

## Execution isolation

Every account's cron commands run as the same shared, non-root `lesta-cron` system user on a given node. There is no per-account OS-level isolation this phase, no separate Linux user per account, and no sandboxing beyond that single fixed identity. A command belonging to one account can, in principle, observe or interfere with another account's job running on the same node at the same time. This is a disclosed, deliberate limitation for this phase, not a bug: the alternative (a real per-account system user, or a fully isolated runner) is a larger, unrequested prerequisite this phase does not build. The security boundary that does exist is architectural, not filesystem-based: a job's crontab line never contains its own raw command text (see `agent/internal/capability/cron`'s own package doc comment), and the runner never executes as root.
