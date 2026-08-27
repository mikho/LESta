<?php

use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnsuspendAccount;
use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('account suspend cascades to active web domains and unsuspend reactivates only cascade-sourced ones', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $preManuallySuspended = WebDomain::factory()->suspended()->for($account)->for($node)->create();
    $active = WebDomain::factory()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->refresh()->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($active->refresh()->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($active->isSuspended())->toBeTrue();

    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeFalse()
        ->and($active->refresh()->isSuspended())->toBeFalse()
        ->and($preManuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('a web domain suspended individually before the account suspend stays suspended after the account unsuspends', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $manuallySuspended = WebDomain::factory()->suspended()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);
    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($manuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($manuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('deleting an account cascades to delete every owned web domain', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $first = WebDomain::factory()->for($account)->for($node)->create();
    $second = WebDomain::factory()->suspended()->for($account)->for($node)->create();

    app(DeleteAccount::class)->handle($owner, $account);

    expect(WebDomain::find($first->id))->toBeNull()
        ->and(WebDomain::find($second->id))->toBeNull()
        ->and(Account::find($account->id))->toBeNull();
});
