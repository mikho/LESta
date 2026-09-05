<?php

use App\Enums\ProvisioningStatus;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

function operationResultsPayload(array $results): array
{
    return ['results' => $results];
}

function resultEntry(ProvisioningOperation $operation, array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => $operation->idempotency_key,
        'status' => 'applied',
        'observed_state_version' => $operation->desired_state_version,
        'observed_state_digest' => 'sha256:'.hash('sha256', 'result'),
        'generation_id' => 'generation-1',
        'errors' => [],
        'completed_at' => now()->toIso8601String(),
    ], $overrides);
}

function dispatchedOperationFor(Node $node): ProvisioningOperation
{
    $webDomain = WebDomain::factory()->for($node)->create();

    return ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
    ]);
}

test('a valid result for a dispatched operation completes it', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $operation = dispatchedOperationFor($node);

    $entry = resultEntry($operation, ['status' => 'applied']);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 1]);

    $operation->refresh();

    expect($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->observed_state_version)->toBe($entry['observed_state_version'])
        ->and($operation->observed_state_digest)->toBe($entry['observed_state_digest'])
        ->and($operation->generation_id)->toBe($entry['generation_id'])
        ->and($operation->completed_at)->not->toBeNull();
});

test('a failed result for a dispatched operation completes it as failed', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $operation = dispatchedOperationFor($node);

    $entry = resultEntry($operation, [
        'status' => 'failed',
        'errors' => [['code' => 'apply_failed', 'message' => 'nginx -t failed']],
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 1]);

    expect($operation->refresh()->status)->toBe(ProvisioningStatus::Failed);
});

test('a failed result with observed_state_version zero is accepted, matching the daemon\'s own synthetic dispatch-failure result', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $operation = dispatchedOperationFor($node);

    $entry = resultEntry($operation, [
        'status' => 'failed',
        'observed_state_version' => 0,
        'errors' => [['code' => 'dispatch_failed', 'message' => 'boom']],
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 1]);

    expect($operation->refresh())
        ->status->toBe(ProvisioningStatus::Failed)
        ->observed_state_version->toBe(0);
});

test('a result whose idempotency_key does not match any dispatched row for that node is silently skipped', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $otherNode = Node::factory()->create();
    $operation = dispatchedOperationFor($otherNode);

    $entry = resultEntry($operation);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 0]);

    expect($operation->refresh()->status)->toBe(ProvisioningStatus::Dispatched);
});

test('resending the identical result twice does not error and does not double-process', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $operation = dispatchedOperationFor($node);

    $entry = resultEntry($operation);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]))
        ->assertOk()
        ->assertJson(['accepted' => 1]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/operation-results', operationResultsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 0]);

    expect($operation->refresh()->status)->toBe(ProvisioningStatus::Applied);
});

test('an unauthenticated operation-results request is rejected', function () {
    $response = $this->postJson('/agent/v1/operation-results', operationResultsPayload([]));

    $response->assertStatus(401);
});
