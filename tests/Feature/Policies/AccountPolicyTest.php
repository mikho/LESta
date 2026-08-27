<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('account authorization matrix: owner, member, no membership, admin without impersonation', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('update', $account))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $account))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $account))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $account))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $account))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $account))->toBeFalse();
});
