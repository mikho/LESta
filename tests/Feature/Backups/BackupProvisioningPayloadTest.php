<?php

use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Models\Backup;
use App\Models\Node;
use App\Models\NodeCapability;

test('toProvisioningPayload with a plaintext key returns exactly label and encryption_key', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create(['label' => 'weekly']);

    $payload = $backup->toProvisioningPayload('a-plaintext-key');

    expect($payload)->toBe(['label' => 'weekly', 'encryption_key' => 'a-plaintext-key'])
        ->and(array_keys($payload))->toBe(['label', 'encryption_key']);
});

test('toProvisioningPayload with no key returns only artifact_path, never the encrypted-at-rest key', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create([
        'encryption_key' => 'super-secret-stored-key',
    ]);

    $payload = $backup->toProvisioningPayload();

    expect($payload)->toBe(['artifact_path' => $backup->artifact_path])
        ->and($payload)->not->toHaveKey('encryption_key');
    expect(json_encode($payload))->not->toContain('super-secret-stored-key');
});

test('toProvisioningPayload never leaks the stored key into a create payload either, regardless of the model own stored value', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create(['encryption_key' => 'super-secret-stored-key']);

    $payload = $backup->toProvisioningPayload('a-freshly-generated-key');

    expect(json_encode($payload))->not->toContain('super-secret-stored-key')
        ->and($payload['encryption_key'])->toBe('a-freshly-generated-key');
});

test('resolveFor returns the capability string for a node with an active backup capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    expect(app(ResolvesBackupCapableNode::class)->resolveFor($node))->toBe('backup.encrypted-artifacts.v1');
});

test('resolveFor throws when the node has no active backup capability', function () {
    $node = Node::factory()->create();

    app(ResolvesBackupCapableNode::class)->resolveFor($node);
})->throws(NoBackupCapableNodeAvailableException::class);

test('resolveFor throws when the node capability is suspended', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->suspended()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);

    app(ResolvesBackupCapableNode::class)->resolveFor($node);
})->throws(NoBackupCapableNodeAvailableException::class);
