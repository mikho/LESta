<?php

use App\Models\Backup;
use App\Models\Node;
use App\Models\NodeCapability;

test('backups:create-scheduled dispatches a new backup for every node with scheduled backups enabled and an active capability', function () {
    $node = Node::factory()->create(['backups_scheduled' => true]);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    expect(Backup::where('node_id', $node->id)->count())->toBe(1)
        ->and(Backup::where('node_id', $node->id)->first()->label)->toStartWith('scheduled-');
});

test('a node with scheduled backups disabled is skipped, even with an active capability', function () {
    $node = Node::factory()->create(['backups_scheduled' => false]);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    expect(Backup::where('node_id', $node->id)->count())->toBe(0);
});

test('a scheduled node with no active backup capability is skipped, not a failure', function () {
    $node = Node::factory()->create(['backups_scheduled' => true]);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    expect(Backup::where('node_id', $node->id)->count())->toBe(0);
});

test('a scheduled node that is itself suspended is skipped', function () {
    $node = Node::factory()->suspended()->create(['backups_scheduled' => true]);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    expect(Backup::where('node_id', $node->id)->count())->toBe(0);
});

test('a scheduled node whose own backup capability is suspended is skipped', function () {
    $node = Node::factory()->create(['backups_scheduled' => true]);
    NodeCapability::factory()->for($node)->suspended()->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    expect(Backup::where('node_id', $node->id)->count())->toBe(0);
});

test('the created backup has no attributed actor, since this is a system-initiated action', function () {
    $node = Node::factory()->create(['backups_scheduled' => true]);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    $this->artisan('backups:create-scheduled')->assertExitCode(0);

    $backup = Backup::where('node_id', $node->id)->first();

    expect(\App\Models\AuditEvent::where('action', 'backup.created')
        ->where('auditable_id', $backup->id)
        ->whereNull('actor_id')
        ->exists())->toBeTrue();
});
