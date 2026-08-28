<?php

use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('dns zone authorization matrix: owner, member, stranger, admin without impersonation', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();
    $account = $dnsZone->account;
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('suspend', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('unsuspend', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [DnsZone::class, $account]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [DnsZone::class, $account]))->toBeTrue()

        ->and(Gate::forUser($member)->allows('view', $dnsZone))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [DnsZone::class, $account]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($member)->allows('suspend', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($member)->allows('unsuspend', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', [DnsZone::class, $account]))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [DnsZone::class, $account]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $dnsZone))->toBeFalse()

        ->and(Gate::forUser($admin)->allows('view', $dnsZone))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $dnsZone))->toBeFalse();
});
