<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\MailAccount;
use App\Models\MetricsCollection;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;

/**
 * Mirrors RecordsBackupArtifact's own role: the one place "a metrics.usage.v1 observe operation
 * just completed" knowledge lives on the completion side. A StatusDegraded result (the Go
 * capability's own partial-failure status, see agent/internal/capability/metrics's own doc
 * comment) is treated the same as a success here: whichever resources it DID measure still get
 * real UsageSnapshot rows, exactly matching the capability's own "one bad resource never loses
 * every other resource's real data" design.
 */
class RecordsUsageSnapshot
{
    public function handle(ProvisioningOperation $operation): void
    {
        $collection = $operation->provisionable;
        if (! $collection instanceof MetricsCollection) {
            return;
        }

        if ($operation->operation !== ProvisioningVerb::Observe) {
            return;
        }

        $succeeded = in_array($operation->status, [
            ProvisioningStatus::Applied,
            ProvisioningStatus::AlreadyApplied,
            ProvisioningStatus::Degraded,
        ], true);

        if (! $succeeded) {
            $collection->forceFill([
                'status' => $operation->status,
                'error_message' => $operation->errors[0]['message'] ?? 'Usage collection failed.',
                'completed_at' => $operation->completed_at,
            ])->save();

            return;
        }

        $data = $operation->data ?? [];
        $collectedAt = $operation->completed_at ?? now();

        foreach ($data['mail_accounts'] ?? [] as $entry) {
            $account = MailAccount::query()->where('uuid', $entry['resource_uuid'] ?? null)->with('mailDomain')->first();
            if ($account === null) {
                continue;
            }

            UsageSnapshot::query()->create([
                'account_id' => $account->mailDomain->account_id,
                'node_id' => $collection->node_id,
                'snapshotable_type' => $account->getMorphClass(),
                'snapshotable_id' => $account->id,
                'disk_bytes' => $entry['disk_bytes'] ?? null,
                'collected_at' => $collectedAt,
            ]);
        }

        foreach ($data['tenant_databases'] ?? [] as $entry) {
            $database = TenantDatabase::query()->where('uuid', $entry['resource_uuid'] ?? null)->first();
            if ($database === null) {
                continue;
            }

            UsageSnapshot::query()->create([
                'account_id' => $database->account_id,
                'node_id' => $collection->node_id,
                'snapshotable_type' => $database->getMorphClass(),
                'snapshotable_id' => $database->id,
                'disk_bytes' => $entry['disk_bytes'] ?? null,
                'collected_at' => $collectedAt,
            ]);
        }

        foreach ($data['web_resources'] ?? [] as $entry) {
            $domain = WebDomain::query()->where('uuid', $entry['resource_uuid'] ?? null)->first();
            if ($domain === null) {
                continue;
            }

            UsageSnapshot::query()->create([
                'account_id' => $domain->account_id,
                'node_id' => $collection->node_id,
                'snapshotable_type' => $domain->getMorphClass(),
                'snapshotable_id' => $domain->id,
                'request_count' => $entry['request_count'] ?? null,
                'bytes_sent' => $entry['bytes_sent'] ?? null,
                'collected_at' => $collectedAt,
            ]);
        }

        $collection->forceFill([
            'status' => $operation->status,
            'completed_at' => $collectedAt,
        ])->save();
    }
}
