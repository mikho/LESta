<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Gate;

test('web domain authorization matrix: owner, member, stranger, admin without impersonation', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    $account = $webDomain->account;
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $webDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $webDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('suspend', $webDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('unsuspend', $webDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $webDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [WebDomain::class, $account]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [WebDomain::class, $account]))->toBeTrue()

        ->and(Gate::forUser($member)->allows('view', $webDomain))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [WebDomain::class, $account]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $webDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('suspend', $webDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('unsuspend', $webDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $webDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', [WebDomain::class, $account]))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $webDomain))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [WebDomain::class, $account]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $webDomain))->toBeFalse()

        ->and(Gate::forUser($admin)->allows('view', $webDomain))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $webDomain))->toBeFalse();
});

test('a reseller-account owner can manage a managed account web domain, with zero changes to this policy', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    $account = $webDomain->account;
    $reseller = Account::factory()->create();
    $account->update(['reseller_account_id' => $reseller->id]);
    $resellerOwner = Membership::factory()->for($reseller)->owner()->create()->user;

    expect(Gate::forUser($resellerOwner)->allows('view', $webDomain))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('viewAny', [WebDomain::class, $account]))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('update', $webDomain))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('suspend', $webDomain))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('unsuspend', $webDomain))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('delete', $webDomain))->toBeTrue()
        ->and(Gate::forUser($resellerOwner)->allows('create', [WebDomain::class, $account]))->toBeTrue();
});
