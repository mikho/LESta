<?php

use App\Actions\Nodes\IssueNodeEnrollmentToken;
use App\Enums\NodeEnrollmentStatus;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;

test('issuing a token returns the raw token once, sets pending status, and records an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    $token = app(IssueNodeEnrollmentToken::class)->handle($admin, $node);

    expect($token)->toBeString()->not->toBeEmpty()
        ->and($node->refresh()->enrollment_status)->toBe(NodeEnrollmentStatus::Pending)
        ->and($node->enrollment_token_hash)->toBe(hash('sha256', $token))
        ->and(AuditEvent::where('action', 'node.enrollment_token_issued')->where('auditable_id', $node->id)->exists())->toBeTrue();
});

test('issuing a second token replaces the first hash', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    app(IssueNodeEnrollmentToken::class)->handle($admin, $node);
    $firstHash = $node->refresh()->enrollment_token_hash;

    $secondToken = app(IssueNodeEnrollmentToken::class)->handle($admin, $node);

    expect($node->refresh()->enrollment_token_hash)->not->toBe($firstHash)
        ->and($node->enrollment_token_hash)->toBe(hash('sha256', $secondToken));
});

test('issuing a token for an already-enrolled node revokes its live credential and is audited as a rotation, not a first enrollment', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $originalCredential = $node->completeEnrollment('1', '1.0.0');

    expect($node->refresh()->enrollment_status)->toBe(NodeEnrollmentStatus::Enrolled)
        ->and($node->node_credential_hash)->toBe(hash('sha256', $originalCredential));

    app(IssueNodeEnrollmentToken::class)->handle($admin, $node);
    $node->refresh();

    expect($node->enrollment_status)->toBe(NodeEnrollmentStatus::Revoked)
        ->and($node->node_credential_hash)->toBeNull()
        ->and(AuditEvent::where('action', 'node.credential_rotated')->where('auditable_id', $node->id)->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'node.enrollment_token_issued')->where('auditable_id', $node->id)->exists())->toBeFalse();

    $this->withHeader('Authorization', 'Bearer '.$originalCredential)
        ->postJson('/agent/v1/heartbeat', [
            'protocol_version' => '1',
            'agent_version' => '1.0.0',
            'ubuntu_release' => '24.04',
            'architecture' => 'amd64',
            'timestamp' => now()->toIso8601String(),
            'capabilities' => [],
        ])
        ->assertStatus(401);
});
