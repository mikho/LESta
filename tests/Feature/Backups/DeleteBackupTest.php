<?php

use App\Actions\Backups\DeleteBackup;
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

test('a provider admin can delete a backup and it dispatches a real delete operation', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $id = $backup->id;

    app(DeleteBackup::class)->handle($admin, $backup);

    expect(Backup::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'backup.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', (new Backup)->getMorphClass())
        ->where('provisionable_id', $id)
        ->where('operation', 'delete')
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('backup.encrypted-artifacts.v1');
});

test('a non-admin cannot delete a backup', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(DeleteBackup::class)->handle($owner, $backup);
})->throws(AuthorizationException::class);

test('handleSystemInitiated deletes a backup without an actor and records an unattributed audit event', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();
    $id = $backup->id;

    app(DeleteBackup::class)->handleSystemInitiated($backup);

    expect(Backup::find($id))->toBeNull();

    $auditEvent = AuditEvent::where('action', 'backup.deleted')->where('auditable_id', $id)->first();

    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->actor_type)->toBeNull()
        ->and($auditEvent->actor_id)->toBeNull();
});

test('deleting a backup on a node with no active backup capability throws', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(DeleteBackup::class)->handle($admin, $backup);
})->throws(NoBackupCapableNodeAvailableException::class);
