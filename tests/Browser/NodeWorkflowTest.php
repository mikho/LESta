<?php

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

    $page->click('[data-test="add-capability-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('web.nginx.v1');

    expect(NodeCapability::where('node_id', $node->id)->where('capability', 'web.nginx.v1')->exists())->toBeTrue();

    $page->click('[data-test="toggle-suspend-node-button"]')
        ->assertNoJavaScriptErrors();

    expect($node->refresh()->isSuspended())->toBeTrue();

    $page->click('[data-test="toggle-suspend-node-button"]')
        ->assertNoJavaScriptErrors();

    expect($node->refresh()->isSuspended())->toBeFalse();
});
