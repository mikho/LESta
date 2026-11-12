---
paths:
  - 'agent/internal/capability/**'
---

# Capability

## No setgid inside the appliers: set groups explicitly
The root appliers run without CAP_FSETID, so the kernel silently drops a setgid bit they set (no error). Never rely on setgid directories to pass a group down: chown the file (before renaming it into place) and any folder you create to the group that must read it. Found 2026-10-31: cron logs and sidecars came out root-owned and unreadable. Also: a directory a tenant owns may hold symlinks or FIFOs; open files there with O_NOFOLLOW (and O_NONBLOCK) and check it is a regular file.

## File-fragment capabilities build on internal/capability/fragment
nginx, apache, bind9, phpfpm and identity keep one *.conf fragment per resource in a glob-included directory and share internal/capability/fragment for it: Dir (staging dotfile, ActivateLive, RemoveLive), SyntheticConfig (the scratch copy a candidate is validated against), PollUntil/WaitDial, Reload and Results (envelopes, ValidationError). A new fragment capability uses it instead of copying those; the apply orchestration (applyGeneration, recoverFromFailure) stays per capability on purpose, the rollback strategies differ. Expose dir() and results() as methods derived from cfg, never stored fields: the internal tests build the capability structs as literals without New().
