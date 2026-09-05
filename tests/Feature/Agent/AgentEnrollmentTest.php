<?php

use App\Enums\NodeEnrollmentStatus;
use App\Models\Node;
use Illuminate\Support\Str;

test('a valid enrollment token exchanges for a node credential', function () {
    $node = Node::factory()->create();
    $token = $node->issueEnrollmentToken();

    $response = $this->postJson('/agent/v1/enroll', [
        'node_uuid' => $node->uuid,
        'enrollment_token' => $token,
        'agent_version' => '1.0.0',
        'protocol_version' => '1',
    ]);

    $response->assertOk()->assertJsonStructure(['node_credential', 'heartbeat_interval_seconds', 'protocol_version']);

    $node->refresh();

    expect($node->enrollment_status)->toBe(NodeEnrollmentStatus::Enrolled)
        ->and($node->node_credential_hash)->not->toBeNull()
        ->and($node->enrollment_token_hash)->toBeNull()
        ->and(hash('sha256', $response->json('node_credential')))->toBe($node->node_credential_hash);
});

test('an expired enrollment token is rejected', function () {
    $node = Node::factory()->create();
    $token = $node->issueEnrollmentToken();
    $node->forceFill(['enrollment_token_expires_at' => now()->subMinute()])->save();

    $response = $this->postJson('/agent/v1/enroll', [
        'node_uuid' => $node->uuid,
        'enrollment_token' => $token,
        'agent_version' => '1.0.0',
        'protocol_version' => '1',
    ]);

    $response->assertStatus(422);

    expect($node->refresh()->enrollment_status)->toBe(NodeEnrollmentStatus::Pending);
});

test('the wrong enrollment token is rejected', function () {
    $node = Node::factory()->create();
    $node->issueEnrollmentToken();

    $response = $this->postJson('/agent/v1/enroll', [
        'node_uuid' => $node->uuid,
        'enrollment_token' => bin2hex(random_bytes(40)),
        'agent_version' => '1.0.0',
        'protocol_version' => '1',
    ]);

    $response->assertStatus(422);
});

test('an already-enrolled node cannot re-enroll with a stale token', function () {
    $node = Node::factory()->create();
    $token = $node->issueEnrollmentToken();
    $node->completeEnrollment('1', '1.0.0');

    $response = $this->postJson('/agent/v1/enroll', [
        'node_uuid' => $node->uuid,
        'enrollment_token' => $token,
        'agent_version' => '1.0.0',
        'protocol_version' => '1',
    ]);

    $response->assertStatus(422);
});

test('enrolling an unknown node uuid returns 404', function () {
    $response = $this->postJson('/agent/v1/enroll', [
        'node_uuid' => (string) Str::uuid(),
        'enrollment_token' => bin2hex(random_bytes(40)),
        'agent_version' => '1.0.0',
        'protocol_version' => '1',
    ]);

    $response->assertStatus(404);
});
