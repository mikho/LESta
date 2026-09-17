# Statistics

Usage and statistics collection capability. Collection must be bounded, incremental, and privacy-aware, with raw logs and large histories streamed or retained under explicit policies. Statistics reads tenant-database state directly, through a dedicated, least-privilege, read-only database account scoped to usage and resource tables. It must never share credentials with tenant provisioning writes, and it must never be granted access to the control-plane schema.

## No standalone installer

There is no `install.sh` in this directory. Real support for `metrics.usage.v1` (access-log directives, the read-only stats database account described above) is built directly into nginx/apache's and mariadb's own installers, not a separate script -- there is nothing distinct left to bootstrap once a web server and the tenant database are already installed. The one remaining, genuinely separate step is declaring `metrics.usage.v1` on a node in the admin app once you're ready to start collecting; see the Admin Guide.
