# Base Layer

The base layer is shared by every service. It defines supported Ubuntu releases and architectures, pinned package sources, time and hostname prerequisites, dedicated `lesta` service identities, directory and permission conventions, TLS trust, agent bootstrap prerequisites, log locations, backup staging roots, and the baseline firewall policy.

It must not install tenant services, create tenant resources, render tenant configuration, or accept control-plane payloads. Tenant-visible firewall rules, arbitrary service changes, and privileged shell access remain outside the base layer.
