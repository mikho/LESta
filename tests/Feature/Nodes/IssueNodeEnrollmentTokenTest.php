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
