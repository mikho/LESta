<?php

use App\Actions\Provisioning\RecordsUsageSnapshot;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\DnsZone;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\MetricsCollection;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;

function metricsCollectionOperation(MetricsCollection $collection, array $overrides = []): ProvisioningOperation
{
    return ProvisioningOperation::factory()->create(array_merge([
        'provisionable_type' => $collection->getMorphClass(),
        'provisionable_id' => $collection->id,
        'resource_id' => $collection->uuid,
        'capability' => 'metrics.usage.v1',
        'operation' => ProvisioningVerb::Observe,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
        'data' => ['mail_accounts' => [], 'tenant_databases' => [], 'web_resources' => []],
    ], $overrides));
}

test('a successful observe result creates real usage snapshots correctly attributed to their owning accounts', function () {
    $node = Node::factory()->create();
    $collection = MetricsCollection::factory()->for($node)->create();

    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $tenantDatabase = TenantDatabase::factory()->for($node)->create();

    $webDomain = WebDomain::factory()->for($node)->create();

    $operation = metricsCollectionOperation($collection, [
        'data' => [
            'mail_accounts' => [['resource_uuid' => $mailAccount->uuid, 'disk_bytes' => 12345]],
            'tenant_databases' => [['resource_uuid' => $tenantDatabase->uuid, 'disk_bytes' => 67890]],
            'web_resources' => [['resource_uuid' => $webDomain->uuid, 'request_count' => 42, 'bytes_sent' => 999]],
        ],
    ]);

    app(RecordsUsageSnapshot::class)->handle($operation);

    expect(UsageSnapshot::count())->toBe(3);

    $mailSnapshot = UsageSnapshot::where('snapshotable_type', $mailAccount->getMorphClass())->where('snapshotable_id', $mailAccount->id)->first();
    expect($mailSnapshot)->not->toBeNull()
        ->and($mailSnapshot->account_id)->toBe($mailDomain->account_id)
        ->and($mailSnapshot->disk_bytes)->toBe(12345)
        ->and($mailSnapshot->node_id)->toBe($node->id);

    $dbSnapshot = UsageSnapshot::where('snapshotable_type', $tenantDatabase->getMorphClass())->where('snapshotable_id', $tenantDatabase->id)->first();
    expect($dbSnapshot)->not->toBeNull()
        ->and($dbSnapshot->account_id)->toBe($tenantDatabase->account_id)
        ->and($dbSnapshot->disk_bytes)->toBe(67890);

    $webSnapshot = UsageSnapshot::where('snapshotable_type', $webDomain->getMorphClass())->where('snapshotable_id', $webDomain->id)->first();
    expect($webSnapshot)->not->toBeNull()
        ->and($webSnapshot->account_id)->toBe($webDomain->account_id)
        ->and($webSnapshot->request_count)->toBe(42)
        ->and($webSnapshot->bytes_sent)->toBe(999);

    $collection->refresh();
    expect($collection->status)->toBe(ProvisioningStatus::Applied)
        ->and($collection->completed_at)->not->toBeNull();
});

test('a degraded result (partial measurement failure) still records every resource it did measure', function () {
    $node = Node::factory()->create();
    $collection = MetricsCollection::factory()->for($node)->create();

    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $operation = metricsCollectionOperation($collection, [
        'status' => ProvisioningStatus::Degraded,
        'data' => ['mail_accounts' => [['resource_uuid' => $mailAccount->uuid, 'disk_bytes' => 111]], 'tenant_databases' => [], 'web_resources' => []],
        'errors' => [['code' => 'database_measurement_failed', 'message' => 'connection refused']],
    ]);

    app(RecordsUsageSnapshot::class)->handle($operation);

    expect(UsageSnapshot::count())->toBe(1);

    $collection->refresh();
    expect($collection->status)->toBe(ProvisioningStatus::Degraded);
});

test('a failed result records the error and creates no snapshots', function () {
    $node = Node::factory()->create();
    $collection = MetricsCollection::factory()->for($node)->create();

    $operation = metricsCollectionOperation($collection, [
        'status' => ProvisioningStatus::Failed,
        'data' => null,
        'errors' => [['code' => 'agent_unreachable', 'message' => 'connection timed out']],
    ]);

    app(RecordsUsageSnapshot::class)->handle($operation);

    expect(UsageSnapshot::count())->toBe(0);

    $collection->refresh();
    expect($collection->status)->toBe(ProvisioningStatus::Failed)
        ->and($collection->error_message)->toBe('connection timed out');
});

test('an unknown resource_uuid in the response is skipped gracefully, never a hard failure', function () {
    $node = Node::factory()->create();
    $collection = MetricsCollection::factory()->for($node)->create();

    $operation = metricsCollectionOperation($collection, [
        'data' => ['mail_accounts' => [['resource_uuid' => (string) \Illuminate\Support\Str::uuid(), 'disk_bytes' => 999]], 'tenant_databases' => [], 'web_resources' => []],
    ]);

    app(RecordsUsageSnapshot::class)->handle($operation);

    expect(UsageSnapshot::count())->toBe(0);
});

test('a non-MetricsCollection provisionable is ignored', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $dnsZone->getMorphClass(),
        'provisionable_id' => $dnsZone->id,
        'resource_id' => $dnsZone->uuid,
        'capability' => 'dns.bind9.v1',
        'operation' => ProvisioningVerb::Create,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ]);

    app(RecordsUsageSnapshot::class)->handle($operation);

    expect(true)->toBeTrue();
});
