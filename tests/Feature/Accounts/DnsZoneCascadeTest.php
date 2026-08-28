<?php

use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnsuspendAccount;
use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('account suspend cascades to active dns zones and unsuspend reactivates only cascade-sourced ones', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $preManuallySuspended = DnsZone::factory()->suspended()->for($account)->for($node)->create();
    $active = DnsZone::factory()->for($account)->for($node)->create();

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

test('a dns zone suspended individually before the account suspend stays suspended after the account unsuspends', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $manuallySuspended = DnsZone::factory()->suspended()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);
    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($manuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($manuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('deleting an account cascades to delete every owned dns zone', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $first = DnsZone::factory()->for($account)->for($node)->create();
    $second = DnsZone::factory()->suspended()->for($account)->for($node)->create();

    app(DeleteAccount::class)->handle($owner, $account);

    expect(DnsZone::find($first->id))->toBeNull()
        ->and(DnsZone::find($second->id))->toBeNull()
        ->and(Account::find($account->id))->toBeNull();
});

test('account suspend cascades three levels deep to dns records through the zone, and unsuspend reverses it', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $dnsZone = DnsZone::factory()->for($account)->for($node)->create();
    $record = DnsRecord::factory()->for($dnsZone)->create();

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and($dnsZone->refresh()->isSuspended())->toBeTrue()
        ->and($dnsZone->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($record->refresh()->isSuspended())->toBeTrue()
        ->and($record->suspension_source)->toBe(SuspensionSource::Cascade);

    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeFalse()
        ->and($dnsZone->refresh()->isSuspended())->toBeFalse()
        ->and($record->refresh()->isSuspended())->toBeFalse();
});
