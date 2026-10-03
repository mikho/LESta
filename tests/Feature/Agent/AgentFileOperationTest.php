<?php

use App\Models\AccountNodeIdentity;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

test('an enrolled node can poll for pending files.manager.v1 operations', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    $operation = ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
        'capability' => 'files.manager.v1',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/file-operations/poll');

    $response->assertOk();

    $pending = $response->json('pending_operations');

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['idempotency_key'])->toBe($operation->idempotency_key)
        ->and($pending[0]['capability'])->toBe('files.manager.v1');
});

test('the poll endpoint never returns a dispatched operation for a different capability', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
        'capability' => 'web.nginx.v1',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/file-operations/poll');

    $response->assertOk()->assertJson(['pending_operations' => []]);
});

test('the poll endpoint never returns an operation belonging to a different node', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $otherNode = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($otherNode)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($otherNode)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $otherNode->id,
        'resource_id' => $webDomain->uuid,
        'capability' => 'files.manager.v1',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/file-operations/poll');

    $response->assertOk()->assertJson(['pending_operations' => []]);
});

test('an unauthenticated poll request is rejected', function () {
    $this->postJson('/agent/v1/file-operations/poll')->assertUnauthorized();
});
