---
paths:
  - '.install/services/**'
---

# Services

## The shared first phase lives in lib/bootstrap.sh
Every leaf installer sources lib/bootstrap.sh right after lib/checkpoint.sh. The service installers and agent-daemon call bootstrap_base from there (the lesta group, the lesta-agent user, /etc/lesta, /var/lib/lesta and /var/log/lesta, a CA refresh, the bootstrap_base checkpoint); adminer and webmail call only ensure_lesta_group. Do not paste a local copy of either back into an installer (they were byte-identical in eight and eleven installers until 2026-11-12). Service-specific additions go in their own function called right after bootstrap_base in main, as php-fpm's bootstrap_php_fpm_selftest_group does.
