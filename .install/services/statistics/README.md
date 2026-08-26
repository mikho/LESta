# Statistics

Usage and statistics collection capability. Collection must be bounded, incremental, and privacy-aware, with raw logs and large histories streamed or retained under explicit policies. Statistics reads tenant-database state directly, through a dedicated, least-privilege, read-only database account scoped to usage and resource tables. It must never share credentials with tenant provisioning writes, and it must never be granted access to the control-plane schema.
