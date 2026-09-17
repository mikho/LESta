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

    /**
     * Whether this capability has a real, singular, node-wide "installed or not" concept an
     * agent heartbeat can confirm. Only MetricsUsage lacks one: it has no standalone install.sh
     * and no independent state root, since its real support (access-log directives, a stats
     * database account) is built directly into nginx/apache/mariadb's own installers -- for this
     * one capability, declaring it here already IS the entire "install" step, so a NodeCapability
     * status of "not installed" would be actively wrong rather than merely unconfirmed.
     */
    public function supportsStatusTracking(): bool
    {
        return $this !== self::MetricsUsage;
    }
}
