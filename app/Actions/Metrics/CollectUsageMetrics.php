<?php

namespace App\Actions\Metrics;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMetricsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\MailAccount;
use App\Models\MetricsCollection;
use App\Models\Node;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Support\Str;

/**
 * System-triggered (App\Console\Commands\CollectUsageMetrics, a scheduled command), never a user
 * action, so unlike CreateBackup/CreateMailDomain there is no Gate::authorize call here and no
 * AuditEvent: this fires once a day per node as routine telemetry, not a discrete resource-
 * lifecycle event a human actor initiated -- recording an audit trail entry for every one of
 * these across every node, every day, would be pure noise, unlike backup.created/mail_domain.created
 * which each represent a real, occasional provider or tenant decision.
 */
class CollectUsageMetrics
{
    public function __construct(private ResolvesMetricsCapableNode $resolvesMetricsCapableNode) {}

    /**
     * Returns null (a genuine no-op, never an error) when: metrics.usage.v1 isn't active on this
     * node yet (a node not yet bootstrapped with it is a normal, expected state), or the node
     * currently hosts nothing measurable at all -- this never dispatches a pointless empty
     * operation.
     */
    public function handle(Node $node): ?MetricsCollection
    {
        if (! $this->resolvesMetricsCapableNode->isAvailableFor($node)) {
            return null;
        }

        $mailAccounts = MailAccount::query()
            ->whereHas('mailDomain', fn ($query) => $query->where('node_id', $node->id))
            ->with('mailDomain')
            ->get();

        $tenantDatabases = TenantDatabase::query()->where('node_id', $node->id)->get();

        $webDomains = WebDomain::query()->where('node_id', $node->id)->get();

        if ($mailAccounts->isEmpty() && $tenantDatabases->isEmpty() && $webDomains->isEmpty()) {
            return null;
        }

        // nginx always fronts the public listener whenever it is present on a node (ADR 0002 §6,
        // already the exact rule ResolvesWebCapableNode's own resolve() applies): a "both"-profile
        // domain served by apache is still only ever reached THROUGH nginx first, so nginx's own
        // access log is the one real record of external traffic either way. Only a pure apache-
        // only node (no nginx installed at all) has apache's own log be the authoritative one.
        $webServer = $node->capabilities()->where('capability', 'web.nginx.v1')->whereNull('suspended_at')->exists()
            ? 'nginx'
            : 'apache';

        $capability = $this->resolvesMetricsCapableNode->resolveFor($node);

        $collection = MetricsCollection::query()->create([
            'node_id' => $node->id,
            'desired_state_version' => 1,
        ]);

        $payload = $collection->toProvisioningPayload(
            $mailAccounts->map(fn (MailAccount $account): array => [
                'resource_uuid' => $account->uuid,
                'domain' => $account->mailDomain->domain,
                'local_part' => $account->local_part,
            ])->all(),
            $tenantDatabases->map(fn (TenantDatabase $database): array => [
                'resource_uuid' => $database->uuid,
                'database_name' => $database->database_name,
                'stats_user' => $database->stats_user,
                'stats_password' => $database->stats_password,
            ])->all(),
            $webDomains->map(fn (WebDomain $domain): array => [
                'resource_uuid' => $domain->uuid,
                'web_server' => $webServer,
            ])->all(),
        );

        app(RecordsProvisioningOperation::class)->record(
            $collection,
            $capability,
            ProvisioningVerb::Observe,
            $payload,
            (string) Str::uuid(),
            1,
        );

        return $collection;
    }
}
