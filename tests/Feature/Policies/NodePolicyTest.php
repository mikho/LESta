<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('node authorization: only a provider admin passes, via the Gate::before bypass', function () {
    $node = Node::factory()->create();
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $node))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $node))->toBeTrue();
});
