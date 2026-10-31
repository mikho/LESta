---
paths:
  - 'agent/internal/capability/**'
---

# Capability

## No setgid inside the appliers: set groups explicitly
The root appliers run without CAP_FSETID, so the kernel silently drops a setgid bit they set (no error). Never rely on setgid directories to pass a group down: chown the file (before renaming it into place) and any folder you create to the group that must read it. Found 2026-10-31: cron logs and sidecars came out root-owned and unreadable. Also: a directory a tenant owns may hold symlinks or FIFOs; open files there with O_NOFOLLOW (and O_NONBLOCK) and check it is a regular file.
