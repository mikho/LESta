<?php

use App\Actions\Nodes\GrantNodeAdmin;
use App\Actions\Nodes\RevokeNodeAdminGrant;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('a provider admin can grant node admin access to an existing user, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $grantee = User::factory()->create();

    $grant = app(GrantNodeAdmin::class)->handle($admin, $node, $grantee->email);

    expect($grant)->toBeInstanceOf(NodeAdminGrant::class)
        ->and($grant->user_id)->toBe($grantee->id)
        ->and($grant->node_id)->toBe($node->id)
        ->and(AuditEvent::where('action', 'node_admin_grant.created')->where('auditable_id', $grant->id)->exists())->toBeTrue();
});

test('granting to an unknown email fails validation, not a 500', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    app(GrantNodeAdmin::class)->handle($admin, $node, 'nobody@example.test');
})->throws(ValidationException::class);

test('granting the same user the same node twice is idempotent, not a duplicate row', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $grantee = User::factory()->create();

    app(GrantNodeAdmin::class)->handle($admin, $node, $grantee->email);
    app(GrantNodeAdmin::class)->handle($admin, $node, $grantee->email);

    expect(NodeAdminGrant::where('user_id', $grantee->id)->where('node_id', $node->id)->count())->toBe(1);
});

test('a non-platform-admin cannot grant node admin access, even a delegated node admin of that same node', function () {
    $node = Node::factory()->create();
    $delegatedAdmin = User::factory()->create();
    NodeAdminGrant::factory()->for($delegatedAdmin)->for($node)->create();
    $grantee = User::factory()->create();

    app(GrantNodeAdmin::class)->handle($delegatedAdmin, $node, $grantee->email);
})->throws(AuthorizationException::class);

test('a provider admin can revoke a grant, with an audit event, and it stops working immediately', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    $grantee = User::factory()->create();
    $grant = NodeAdminGrant::factory()->for($grantee, 'user')->for($node)->create();

    app(RevokeNodeAdminGrant::class)->handle($admin, $grant);

    expect(NodeAdminGrant::find($grant->id))->toBeNull()
        ->and(AuditEvent::where('action', 'node_admin_grant.revoked')->where('auditable_id', $grant->id)->exists())->toBeTrue()
        ->and($grantee->hasNodeAdminGrant($node))->toBeFalse();
});

test('a non-platform-admin cannot revoke a grant', function () {
    $node = Node::factory()->create();
    $grantee = User::factory()->create();
    $grant = NodeAdminGrant::factory()->for($grantee, 'user')->for($node)->create();
    $stranger = User::factory()->create();

    app(RevokeNodeAdminGrant::class)->handle($stranger, $grant);
})->throws(AuthorizationException::class);

test('hasNodeAdminGrant is true for a provider admin regardless of any real grant row', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();

    expect($admin->hasNodeAdminGrant($node))->toBeTrue();
});

test('hasNodeAdminGrant only ever reaches the specific granted node, never another one', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $user = User::factory()->create();
    NodeAdminGrant::factory()->for($user)->for($node)->create();

    expect($user->hasNodeAdminGrant($node))->toBeTrue()
        ->and($user->hasNodeAdminGrant($otherNode))->toBeFalse();
});

test('a plain tenant account owner never gets a node admin grant just by being an owner', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();

    expect($owner->hasNodeAdminGrant($node))->toBeFalse();
});
