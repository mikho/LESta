---
paths:
  - '.install/services/**'
---

# Services

## The shared first phase lives in lib/bootstrap.sh
Every leaf installer sources lib/bootstrap.sh right after lib/checkpoint.sh. The service installers and agent-daemon call bootstrap_base from there (the lesta group, the lesta-agent user, /etc/lesta, /var/lib/lesta and /var/log/lesta, a CA refresh, the bootstrap_base checkpoint); adminer and webmail call only ensure_lesta_group. Do not paste a local copy of either back into an installer (they were byte-identical in eight and eleven installers until 2026-11-12). Service-specific additions go in their own function called right after bootstrap_base in main, as php-fpm's bootstrap_php_fpm_selftest_group does.

## A node carries only what its services need
Every apt-get install passes --no-install-recommends (validate-contract.mjs fails CI otherwise); a recommended package a service really needs is named on the install line, as mail's ANTISPAM_PACKAGES names libmail-spf-perl and gnupg. A heavy optional part is an installer flag plus a node capability with a presence marker, off on a new node, like mail's --antivirus/--antispam (ClamAV alone is about 1 GB). Every memory default a service gets (MariaDB caches, PHP-FPM pm, spamd children) carries a comment with its reason. OS services are only ever disabled in lib/bootstrap.sh, never purged. Measurements are in the vault's Minimal memory footprint item (2026-11-19).

## PHP comes from packages.sury.org, never the ondrej/php PPA
The ondrej/php Launchpad PPA was frozen in November 2026 and its renamed Release Label makes apt-get update fail on any node still listing it. PHP versions beside Ubuntu's own come from packages.sury.org/php, pinned by lib/bootstrap.sh's ensure_sury_php_repo (key 15058500A0235D97F5D10063B188E2B695BD4743, expires 2028-02-04). Every installer that runs apt-get update calls retire_ondrej_php_ppa <capability> right before it (adminer, webmail, mariadb, php-fpm today); a new one must too.
