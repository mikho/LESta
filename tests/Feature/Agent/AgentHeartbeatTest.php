<?php

use App\Models\Node;
use App\Models\NodeCapability;

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
