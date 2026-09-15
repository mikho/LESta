<?php

namespace App\Enums;

/**
 * The capability strings a node's agent build can report, matching the constants declared in
 * agent/cmd/lesta-agent/main.go (webNginxCapability, dnsBind9Capability, webApacheCapability,
 * tlsAcmeCapability, databaseTenantCapability, schedulerCronCapability, mailSmtpImapCapability,
 * backupEncryptedArtifactsCapability, metricsUsageCapability). These enum cases exist to
 * validate admin-entered capability strings; a NodeCapability row itself still stores the
 * capability as a plain string column, matching the Go side's own literal values.
 *
 * Deliberately excludes two real capability strings that also exist in main.go:
 * systemAccountIdentityCapability ('system.account-identity.v1') is dispatched automatically by
 * App\Actions\Cron\EnsuresAccountNodeIdentity the first time an account gets a cron job on a
 * node -- nothing ever checks for a NodeCapability row first (unlike every capability listed
 * above, which each have their own ResolvesXCapableNode gate), so there is nothing for an admin
 * to declare here. databaseControlPlaneCapability ('database.control-plane.v1') has no Go
 * capability dispatch case at all (see main.go's own doc comment on that constant) -- it is a
 * fixed internal lookup key for this application's own database, never a real per-node
 * capability, and would only ever fail if an admin were allowed to add it here.
 */
enum NodeCapabilityType: string
{
    case WebNginx = 'web.nginx.v1';
    case DnsBind9 = 'dns.bind9.v1';
    case WebApache = 'web.apache.v1';
    case TlsAcme = 'tls.acme.v1';
    case DatabaseTenant = 'database.tenant.v1';
    case SchedulerCron = 'scheduler.account-cron.v1';
    case MailSmtpImap = 'mail.smtp-imap.v1';
    case BackupEncryptedArtifacts = 'backup.encrypted-artifacts.v1';
    case MetricsUsage = 'metrics.usage.v1';
}
