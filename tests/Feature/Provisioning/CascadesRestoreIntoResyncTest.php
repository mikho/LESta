<?php

use App\Actions\Provisioning\CascadesRestoreIntoResync;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\DnsZone;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;

function restoreCompletionOperation(Backup $backup, array $overrides = []): ProvisioningOperation
{
    return ProvisioningOperation::factory()->create(array_merge([
        'provisionable_type' => $backup->getMorphClass(),
        'provisionable_id' => $backup->id,
        'resource_id' => $backup->uuid,
        'capability' => 'backup.encrypted-artifacts.v1',
        'operation' => ProvisioningVerb::Restore,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ], $overrides));
}

test('a successful restore cascades into a real resync of the owning node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    app(CascadesRestoreIntoResync::class)->handle(restoreCompletionOperation($backup));

    expect(ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', 'update')
        ->exists())->toBeTrue();
});

test('an already-applied restore also cascades into resync', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    app(CascadesRestoreIntoResync::class)->handle(restoreCompletionOperation($backup, ['status' => ProvisioningStatus::AlreadyApplied]));

    expect(ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->where('operation', 'update')
        ->exists())->toBeTrue();
});

test('a failed restore never cascades into resync', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    app(CascadesRestoreIntoResync::class)->handle(restoreCompletionOperation($backup, ['status' => ProvisioningStatus::Failed]));

    expect(ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->exists())->toBeFalse();
});

test('a non-restore operation against a backup is ignored', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    app(CascadesRestoreIntoResync::class)->handle(restoreCompletionOperation($backup, ['operation' => ProvisioningVerb::Observe]));

    expect(ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())
        ->where('provisionable_id', $dnsZone->id)
        ->exists())->toBeFalse();
});

test('a non-backup provisionable is ignored', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $dnsZone->getMorphClass(),
        'provisionable_id' => $dnsZone->id,
        'resource_id' => $dnsZone->uuid,
        'capability' => 'dns.bind9.v1',
        'operation' => ProvisioningVerb::Restore,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ]);

    app(CascadesRestoreIntoResync::class)->handle($operation);

    expect(true)->toBeTrue();
});
