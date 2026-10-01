# PHP-FPM

Real PHP execution for tenant web content (`web.php-fpm.v1`), the second half of Tier 0's "PHP execution + SFTP file upload" gate (`Web Application Hosting Threat Model and Isolation Design.md`). One pool per `WebDomain`, running as that domain's own account's dedicated OS identity (`system.account-identity.v1`'s own `lesta-t{account_id}`) — the same isolation boundary SFTP already reuses, never a new one.

Four PHP versions are supported, matching `App\Enums\PhpVersion` exactly: `8.1`, `8.2`, `8.3`, `8.4`. `8.3` is Ubuntu 24.04's own native package; the other three come from the `ondrej/php` PPA, pinned via a manually downloaded, fixed-fingerprint GPG keyring rather than `add-apt-repository`, mirroring `mariadb/install.sh`'s own third-party-repository discipline.

A PHP-enabled domain's real content-serving vhost is rendered by `web.nginx.v1`/`web.apache.v1` (a new opt-in template variant, selected only when a domain's own `php_socket` is set — every domain that never opts into PHP keeps rendering byte-identical output to before this capability existed). This installer only ever creates the per-version `pool.d` directories, installs the packages, and activates each version's own systemd unit; it never touches nginx's or Apache's own config.
