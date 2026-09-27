<?php

use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('the owning account\'s owner can update the ssh credential; a member, a stranger, and a different account\'s owner cannot', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $identity = AccountNodeIdentity::factory()->for($account)->for($node)->create();

    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $otherAccountOwner = Membership::factory()->owner()->create()->user;

    expect(Gate::forUser($owner)->allows('updateSshCredential', $identity))->toBeTrue()
        ->and(Gate::forUser($member)->allows('updateSshCredential', $identity))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('updateSshCredential', $identity))->toBeFalse()
        ->and(Gate::forUser($otherAccountOwner)->allows('updateSshCredential', $identity))->toBeFalse();
});

test('a provider admin bypasses updateSshCredential and delete both, via the global Gate::before', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $identity = AccountNodeIdentity::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($admin)->allows('updateSshCredential', $identity))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $identity))->toBeTrue();
});

test('delete is never true for a non-admin, including the owning account\'s own owner', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $identity = AccountNodeIdentity::factory()->for($account)->for($node)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    expect(Gate::forUser($owner)->allows('delete', $identity))->toBeFalse();
});
