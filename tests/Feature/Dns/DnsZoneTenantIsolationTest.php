<?php

use App\Actions\Dns\DeleteDnsZone;
use App\Actions\Dns\UpdateDnsZone;
use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Auth\Access\AuthorizationException;

test('a member of another account cannot update a foreign dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(UpdateDnsZone::class)->handle($stranger, $dnsZone, ['domain' => 'hijacked.example.com']);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign dns zone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteDnsZone::class)->handle($stranger, $dnsZone);
})->throws(AuthorizationException::class);
