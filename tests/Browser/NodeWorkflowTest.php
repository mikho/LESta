<?php

use App\Enums\NodeCapabilityStatus;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('a full create, issue-token, add-capability, suspend, unsuspend workflow works in a real browser', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);

    $page = visit('/nodes');

    $page->assertNoJavaScriptErrors()
        ->assertSee('Nodes')
        ->assertSee('No nodes yet.')
        ->click('Add node')
        ->assertNoJavaScriptErrors()
        ->fill('name', 'browser-node')
        ->fill('hostname', 'browser-node.example.net')
        ->click('[data-test="create-node-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Manage node');

    $node = Node::where('name', 'browser-node')->sole();

    $page->click('[data-test="issue-enrollment-token-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-issue-enrollment-token-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Enrollment token')
        ->click('[data-test="dismiss-enrollment-token-button"]')
        ->assertNoJavaScriptErrors();

    expect($node->refresh()->enrollment_token_hash)->not->toBeNull();

    // No real agent ever heartbeats in this test, so the node stays
    // permanently unreachable and every capability's own display_status
    // would otherwise collapse to "unknown" regardless of its real stored
    // status (correct, honest behavior -- verified separately in
    // NodeControllerTest). Force a recent last_seen_at here so the
    // capability-status assertions below can actually observe the real
    // stored not_installed/stopped values through the UI.
    $node->forceFill(['last_seen_at' => now()])->save();

    $page->click('[data-test="add-capability-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('web.nginx.v1')
        ->assertSee('Not installed');

    $webNginx = NodeCapability::where('node_id', $node->id)->where('capability', 'web.nginx.v1')->sole();

    // A freshly-added capability is not_installed, so "Mark as stopped" is
    // the only remaining manual-status option (not_installed itself is
    // excluded as the current value, and Running is never offered at all) --
    // it is already the control's own default selection, so submitting the
    // form as-is exercises the real default, no dropdown interaction needed.
    $page->click('[data-test="update-capability-status-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Stopped');

    expect($webNginx->refresh()->status)->toBe(NodeCapabilityStatus::Stopped);

    // web.nginx.v1 was just added, so it must have dropped out of the
    // capability dropdown: clicking "Add capability" again with no other
    // interaction adds whatever is now the first remaining option
    // (dns.bind9.v1) rather than rejecting a re-selected duplicate.
    $page->click('[data-test="add-capability-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('dns.bind9.v1');

    expect(NodeCapability::where('node_id', $node->id)->where('capability', 'dns.bind9.v1')->exists())->toBeTrue();

    $page->click('[data-test="toggle-suspend-node-button"]')
        ->assertNoJavaScriptErrors();

    expect($node->refresh()->isSuspended())->toBeTrue();

    $page->click('[data-test="toggle-suspend-node-button"]')
        ->assertNoJavaScriptErrors();

    expect($node->refresh()->isSuspended())->toBeFalse();
});

test('rotating an already-enrolled node credential shows real revocation copy and actually revokes it', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $originalCredential = $node->completeEnrollment('1', '1.0.0');

    $this->actingAs($admin);

    $page = visit("/nodes/{$node->uuid}/edit");

    $page->assertNoJavaScriptErrors()
        ->assertSee('Rotate credential')
        ->click('[data-test="issue-enrollment-token-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('will stop authenticating immediately')
        ->click('[data-test="confirm-issue-enrollment-token-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Enrollment token');

    expect($node->refresh()->enrollment_status->value)->toBe('revoked')
        ->and($node->node_credential_hash)->toBeNull();

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
