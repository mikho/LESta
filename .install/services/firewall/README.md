# Firewall

The firewall baseline is its own gated installation step, run after the base layer and before any service that opens an inbound port. It establishes SSH recovery access, a deny-by-default policy, and the LESta-owned nftables table that nginx, Apache, BIND, MariaDB, and mail all depend on before they may bind a public or network-reachable port. Tenant-visible arbitrary firewall rules are deferred and must not be added to bootstrap.
