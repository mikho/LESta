<?php

namespace App\Enums;

/**
 * The capability strings a node's agent build can report, matching the constants declared in
 * agent/cmd/lesta-agent/main.go (webNginxCapability, dnsBind9Capability, webApacheCapability,
 * tlsAcmeCapability, databaseTenantCapability, schedulerCronCapability). These enum cases exist
 * to validate admin-entered capability strings; a NodeCapability row itself still stores the
 * capability as a plain string column, matching the Go side's own literal values.
 */
enum NodeCapabilityType: string
{
    case WebNginx = 'web.nginx.v1';
    case DnsBind9 = 'dns.bind9.v1';
    case WebApache = 'web.apache.v1';
    case TlsAcme = 'tls.acme.v1';
    case DatabaseTenant = 'database.tenant.v1';
    case SchedulerCron = 'scheduler.account-cron.v1';
}
