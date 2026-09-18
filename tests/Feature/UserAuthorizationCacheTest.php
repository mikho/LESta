<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('isProviderAdmin only ever queries once per instance', function () {
    $user = Membership::factory()->providerAdmin()->create()->user;

    expect($user->isProviderAdmin())->toBeTrue();

    DB::enableQueryLog();
    $user->isProviderAdmin();
    $user->isProviderAdmin();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(0);
});

test('hasAccountRole caches per account and role, never confusing two different accounts', function () {
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();
    $user = Membership::factory()->for($accountA)->owner()->create()->user;

    expect($user->hasAccountRole($accountA, 'owner'))->toBeTrue()
        ->and($user->hasAccountRole($accountB, 'owner'))->toBeFalse();

    DB::enableQueryLog();
    expect($user->hasAccountRole($accountA, 'owner'))->toBeTrue()
        ->and($user->hasAccountRole($accountB, 'owner'))->toBeFalse();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(0);
});

test('hasNodeAdminGrant caches per node, never confusing two different nodes', function () {
    $nodeA = Node::factory()->create();
    $nodeB = Node::factory()->create();
    $user = User::factory()->create();
    $user->nodeAdminGrants()->create(['node_id' => $nodeA->id]);

    expect($user->hasNodeAdminGrant($nodeA))->toBeTrue()
        ->and($user->hasNodeAdminGrant($nodeB))->toBeFalse();

    DB::enableQueryLog();
    expect($user->hasNodeAdminGrant($nodeA))->toBeTrue()
        ->and($user->hasNodeAdminGrant($nodeB))->toBeFalse();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(0);
});

test('a freshly refetched instance of the same user is not affected by another instance own cache', function () {
    $account = Account::factory()->create();
    $user = Membership::factory()->for($account)->owner()->create()->user;

    expect($user->hasAccountRole($account, 'owner'))->toBeTrue();

    $sameUserDifferentInstance = User::find($user->id);

    DB::enableQueryLog();
    expect($sameUserDifferentInstance->hasAccountRole($account, 'owner'))->toBeTrue();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeGreaterThan(0);
});
