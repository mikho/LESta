---
paths:
  - 'tests/Browser/**'
---

# Browser

## Local mariadb testing DB credentials for browser tests
phpunit.xml's DB_USERNAME=root/DB_PASSWORD=root do not work against this machine's local mariadb (Homebrew, not Herd-managed) -- root has no remote/password login configured. The real working credentials for 127.0.0.1 are user `lesta` / password `lesta`. That user also needs explicit grants on `lesta_testing` (not present by default): `GRANT ALL PRIVILEGES ON lesta_testing.* TO 'lesta'@'127.0.0.1'; FLUSH PRIVILEGES;` (run once via `mysql -u mikho`, which has socket-auth admin access). Run browser tests as: `DB_USERNAME=lesta DB_PASSWORD=lesta vendor/bin/pest tests/Browser`. Non-browser Pest tests still work fine overridden to sqlite (`DB_CONNECTION=sqlite DB_DATABASE=":memory:"`), so this only matters for the real-server browser suite.
