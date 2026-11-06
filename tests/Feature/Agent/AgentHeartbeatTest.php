<?php

use App\Enums\NodeCapabilityStatus;
use App\Enums\SuspensionSource;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

function heartbeatPayload(array $overrides = []): array
{
    return array_merge([
        'protocol_version' => '1',
        'agent_version' => '1.0.0',
        'ubuntu_release' => '24.04',
        'architecture' => 'amd64',
        'timestamp' => now()->toIso8601String(),
        'capabilities' => [],
    ], $overrides);
}

test('an enrolled node can send a heartbeat', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => [['capability' => 'web.nginx.v1', 'health_state' => 'healthy']],
        ]));

    $response->assertOk()->assertJson(['ack' => true]);

    $node->refresh();
    $capability->refresh();

    expect($node->last_seen_at)->not->toBeNull()
        ->and($capability->last_seen_at)->not->toBeNull();
});

test('a heartbeat with a stale timestamp is rejected', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'timestamp' => now()->subMinutes(10)->toIso8601String(),
        ]));

    $response->assertStatus(422);
});

test('an unauthenticated heartbeat is rejected', function () {
    $response = $this->postJson('/agent/v1/heartbeat', heartbeatPayload());

    $response->assertStatus(401);
});

test('a heartbeat with an invalid bearer credential is rejected', function () {
    $response = $this->withHeader('Authorization', 'Bearer not-a-real-credential')
        ->postJson('/agent/v1/heartbeat', heartbeatPayload());

    $response->assertStatus(401);
});

test('a heartbeat from a suspended node is rejected, even with a real credential', function () {
    $node = Node::factory()->suspended()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload());

    $response->assertStatus(401);
});

test('an out-of-order heartbeat is acknowledged without overwriting newer state', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $newerTimestamp = now()->startOfSecond();
    $node->forceFill(['last_seen_at' => $newerTimestamp])->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'timestamp' => now()->subSeconds(30)->toIso8601String(),
        ]));

    $response->assertOk();

    expect($node->refresh()->last_seen_at->equalTo($newerTimestamp))->toBeTrue();
});

test('heartbeat never creates a NodeCapability row for an unknown capability', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => [['capability' => 'web.nginx.v1', 'health_state' => 'healthy']],
        ]))
        ->assertOk();

    expect(NodeCapability::query()->where('node_id', $node->id)->count())->toBe(0);
});

test('a heartbeat reporting a not-installed capability promotes it to running', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    expect($capability->status)->toBe(NodeCapabilityStatus::NotInstalled);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => [['capability' => 'web.nginx.v1', 'health_state' => 'healthy']],
        ]))
        ->assertOk();

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::Running);
});

test('a running capability absent from a heartbeat becomes stopped', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->running()->create(['capability' => 'web.nginx.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['capabilities' => []]))
        ->assertOk();

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::Stopped);
});

test('a not-installed capability absent from a heartbeat stays not installed', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['capabilities' => []]))
        ->assertOk();

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::NotInstalled);
});

test('a stopped capability absent from a heartbeat stays stopped, not re-flagged every cycle', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->stopped()->create(['capability' => 'web.nginx.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['capabilities' => []]))
        ->assertOk();

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::Stopped);
});

test('a heartbeat updates the underlying status of a suspended capability without touching its suspension', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->suspended()->create(['capability' => 'web.nginx.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => [['capability' => 'web.nginx.v1', 'health_state' => 'healthy']],
        ]))
        ->assertOk();

    $capability->refresh();

    expect($capability->status)->toBe(NodeCapabilityStatus::Running)
        ->and($capability->isSuspended())->toBeTrue()
        ->and($capability->suspension_source)->toBe(SuspensionSource::Manual);
});

test('metrics.usage.v1 is never mutated by a heartbeat even if reported', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $capability = NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload([
            'capabilities' => [['capability' => 'metrics.usage.v1', 'health_state' => 'healthy']],
        ]))
        ->assertOk();

    expect($capability->refresh()->status)->toBe(NodeCapabilityStatus::NotInstalled);
});

test('a heartbeat returns dispatched operations targeting the authenticated node', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $webDomain = WebDomain::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload());

    $response->assertOk();

    $pending = $response->json('pending_operations');

    expect($pending)->toHaveCount(1);
    expect($pending[0])->toMatchArray([
        'protocol_version' => $operation->protocol_version,
        'capability' => $operation->capability,
        'operation' => $operation->operation->value,
        'resource_id' => $operation->resource_id,
        'desired_state_version' => $operation->desired_state_version,
        'idempotency_key' => $operation->idempotency_key,
        'correlation_id' => $operation->correlation_id,
        'request_digest' => $operation->request_digest,
    ]);
});

test('a heartbeat does not return a dispatched operation belonging to a different node', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $webDomain = WebDomain::factory()->for($otherNode)->create();

    ProvisioningOperation::factory()->dispatched()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $otherNode->id,
        'resource_id' => $webDomain->uuid,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload());

    $response->assertOk()->assertJson(['pending_operations' => []]);
});

test('a heartbeat stores the host resources and an invalid figure is refused', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $resources = [
        'load_1' => 0.5, 'load_5' => 0.4, 'load_15' => 0.3, 'cpu_count' => 4, 'processes' => 300,
        'memory_total_kb' => 4096000, 'memory_available_kb' => 2048000,
        'swap_total_kb' => 0, 'swap_free_kb' => 0, 'uptime_seconds' => 86400,
    ];

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['resources' => $resources]))
        ->assertOk();

    expect($node->fresh()->resources)->toMatchArray(['cpu_count' => 4, 'processes' => 300]);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['resources' => [...$resources, 'cpu_count' => 0]]))
        ->assertUnprocessable();
});

test('a heartbeat without resources keeps the last ones', function () {
    $node = Node::factory()->create(['resources' => ['cpu_count' => 2]]);
    $credential = $node->completeEnrollment('1', '1.0.0');

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect($node->fresh()->resources)->toBe(['cpu_count' => 2]);
});

test('a heartbeat stores the installed PHP versions', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['php_versions' => ['8.4', '8.5']]))
        ->assertOk();

    expect($node->fresh()->php_versions)->toBe(['8.4', '8.5']);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/heartbeat', heartbeatPayload(['php_versions' => ['not-a-version']]))
        ->assertUnprocessable();
});
