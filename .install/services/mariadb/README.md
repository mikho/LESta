# MariaDB

MariaDB supports two isolated roles: the private control-plane store and the tenant database capability. They may share a physical node initially, but never share schemas, credentials, grants, or resource limits. The final installer manifest must select the approved MariaDB or MySQL release explicitly.
