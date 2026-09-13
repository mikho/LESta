<?php

use App\Actions\Provisioning\RecordsBackupArtifact;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\DnsZone;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;

function backupCompletionOperation(Backup $backup, array $overrides = []): ProvisioningOperation
{
    return ProvisioningOperation::factory()->create(array_merge([
        'provisionable_type' => $backup->getMorphClass(),
        'provisionable_id' => $backup->id,
        'resource_id' => $backup->uuid,
        'capability' => 'backup.encrypted-artifacts.v1',
        'operation' => ProvisioningVerb::Create,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
        'data' => [
            'included_capabilities' => ['web.nginx.v1', 'dns.bind9.v1'],
            'size_bytes' => 2048,
            'checksum' => 'sha256:'.hash('sha256', 'artifact-bytes'),
            'artifact_path' => '/var/lib/lesta/backups/'.$backup->uuid.'.tar.enc',
        ],
    ], $overrides));
}

test('a successful create result populates the backup row from the operation data', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();

    $operation = backupCompletionOperation($backup);

    app(RecordsBackupArtifact::class)->handle($operation);
    $backup->refresh();

    expect($backup->status)->toBe(ProvisioningStatus::Applied)
        ->and($backup->included_capabilities)->toBe(['web.nginx.v1', 'dns.bind9.v1'])
        ->and($backup->size_bytes)->toBe(2048)
        ->and($backup->checksum)->toBe($operation->data['checksum'])
        ->and($backup->artifact_path)->toBe($operation->data['artifact_path'])
        ->and($backup->completed_at)->not->toBeNull();
});

test('already_applied is treated the same as applied', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();

    $operation = backupCompletionOperation($backup, ['status' => ProvisioningStatus::AlreadyApplied]);

    app(RecordsBackupArtifact::class)->handle($operation);
    $backup->refresh();

    expect($backup->status)->toBe(ProvisioningStatus::AlreadyApplied)
        ->and($backup->artifact_path)->not->toBeNull();
});

test('a failed create result records the error message and leaves artifact fields untouched', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();

    $operation = backupCompletionOperation($backup, [
        'status' => ProvisioningStatus::Failed,
        'data' => null,
        'errors' => [['code' => 'archive_failed', 'message' => 'disk full']],
    ]);

    app(RecordsBackupArtifact::class)->handle($operation);
    $backup->refresh();

    expect($backup->status)->toBe(ProvisioningStatus::Failed)
        ->and($backup->error_message)->toBe('disk full')
        ->and($backup->artifact_path)->toBeNull()
        ->and($backup->size_bytes)->toBeNull();
});

test('a delete operation is ignored entirely: DeleteBackup already removed the row itself', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create();
    $originalArtifactPath = $backup->artifact_path;

    $operation = backupCompletionOperation($backup, ['operation' => ProvisioningVerb::Delete, 'data' => null]);

    app(RecordsBackupArtifact::class)->handle($operation);
    $backup->refresh();

    expect($backup->artifact_path)->toBe($originalArtifactPath);
});

test('a non-Backup provisionable is ignored', function () {
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

    // Must not throw when handed a provisionable that isn't a Backup at all.
    app(RecordsBackupArtifact::class)->handle($operation);

    expect(true)->toBeTrue();
});

test('completing a 6th backup for the same node prunes the oldest one down to the retained 5', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $existing = collect(range(1, 5))->map(fn (int $i) => Backup::factory()->completed()->for($node)->create([
        'completed_at' => now()->subDays(10 - $i),
    ]));

    $newest = Backup::factory()->for($node)->create();
    $operation = backupCompletionOperation($newest, ['completed_at' => now()]);

    app(RecordsBackupArtifact::class)->handle($operation);

    expect(Backup::where('node_id', $node->id)->count())->toBe(5)
        ->and(Backup::find($existing->first()->id))->toBeNull()
        ->and(Backup::find($newest->id))->not->toBeNull();

    $pruneOperation = ProvisioningOperation::where('provisionable_type', (new Backup)->getMorphClass())
        ->where('provisionable_id', $existing->first()->id)
        ->where('operation', 'delete')
        ->first();

    expect($pruneOperation)->not->toBeNull()
        ->and($pruneOperation->capability)->toBe('backup.encrypted-artifacts.v1');
});

test('retention pruning never touches backups on a different node', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    collect(range(1, 5))->each(fn (int $i) => Backup::factory()->completed()->for($node)->create([
        'completed_at' => now()->subDays(10 - $i),
    ]));
    $otherNodeBackup = Backup::factory()->completed()->for($otherNode)->create(['completed_at' => now()->subDays(100)]);

    $newest = Backup::factory()->for($node)->create();
    $operation = backupCompletionOperation($newest, ['completed_at' => now()]);

    app(RecordsBackupArtifact::class)->handle($operation);

    expect(Backup::find($otherNodeBackup->id))->not->toBeNull();
});
