# Backups

Backup and restore capability for encrypted desired state and generated artifacts. Runtime storage uses object storage and isolated restore staging. Local bootstrap only establishes protected staging roots and required client prerequisites.

Backups declares what it can back up through `backs_up`, not `depends_on`. It never requires the control-plane database, tenant databases, the web profile, DNS, or cron to exist before it installs; it only requires the base layout and node health. At backup time it includes whichever `backs_up` capabilities are actually present and healthy on the node and silently omits the rest, recording exactly what was included in the run's result.
