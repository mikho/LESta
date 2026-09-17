# Firewall

The firewall baseline is its own gated installation step, run after the base layer and before any service that opens an inbound port. It establishes SSH recovery access, a deny-by-default policy, and the LESta-owned nftables table that nginx, Apache, BIND, MariaDB, and mail all depend on before they may bind a public or network-reachable port. Tenant-visible arbitrary firewall rules are deferred and must not be added to bootstrap.

## No standalone installer

There is no `install.sh` in this directory, and there never will be one to run by hand: this manifest exists so other services can declare a real `depends_on: ["firewall.baseline.v1"]` entry, but the baseline itself is bootstrapped automatically and idempotently, embedded inside every operator-run installer that needs it (nginx, apache, bind9, mariadb, cron, mail). An operator never invokes firewall bootstrap directly or names `firewall` in `install-selected.sh --services`.
