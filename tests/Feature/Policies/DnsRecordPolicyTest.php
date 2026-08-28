<?php

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('dns record authorization matrix: owner, member, stranger, admin without impersonation', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();
    $account = $dnsZone->account;
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('suspend', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('unsuspend', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [DnsRecord::class, $dnsZone]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [DnsRecord::class, $dnsZone]))->toBeTrue()

        ->and(Gate::forUser($member)->allows('view', $dnsRecord))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [DnsRecord::class, $dnsZone]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($member)->allows('suspend', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($member)->allows('unsuspend', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', [DnsRecord::class, $dnsZone]))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [DnsRecord::class, $dnsZone]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $dnsRecord))->toBeFalse()

        ->and(Gate::forUser($admin)->allows('view', $dnsRecord))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $dnsRecord))->toBeFalse();
});
