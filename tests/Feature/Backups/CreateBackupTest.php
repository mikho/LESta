<?php

use App\Actions\Backups\CreateBackup;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('a provider admin can create a backup for a backup-capable node and it is provisioned after commit', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $backup = app(CreateBackup::class)->handle($admin, $node, ['label' => 'nightly']);

    expect($backup->node_id)->toBe($node->id)
        ->and($backup->label)->toBe('nightly')
        ->and($backup->desired_state_version)->toBe(1)
        ->and($backup->encryption_key)->not->toBeNull()
        ->and(AuditEvent::where('action', 'backup.created')->where('auditable_id', $backup->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $backup->getMorphClass())
        ->where('provisionable_id', $backup->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('backup.encrypted-artifacts.v1')
        ->and($operation->operation->value)->toBe('create');
});

test('label defaults to null when not given', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $backup = app(CreateBackup::class)->handle($admin, $node, []);

    expect($backup->label)->toBeNull();
});

test('a non-admin cannot create a backup', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateBackup::class)->handle($owner, $node, []);
})->throws(AuthorizationException::class);

test('creation fails when the given node has no active backup capability', function () {
    $node = Node::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(CreateBackup::class)->handle($admin, $node, []);
})->throws(NoBackupCapableNodeAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $node = Node::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    try {
        app(CreateBackup::class)->handle($admin, $node, []);
    } catch (NoBackupCapableNodeAvailableException) {
        // expected
    }

    expect(Backup::count())->toBe(0)
        ->and(AuditEvent::where('action', 'backup.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});

test('each created backup gets its own distinct plaintext encryption key', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $first = app(CreateBackup::class)->handle($admin, $node, []);
    $second = app(CreateBackup::class)->handle($admin, $node, []);

    expect($first->encryption_key)->not->toBe($second->encryption_key);
});
