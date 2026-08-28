<?php

use App\Actions\Dns\DeleteDnsRecord;
use App\Actions\Dns\UpdateDnsRecord;
use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Auth\Access\AuthorizationException;

test('a member of another account cannot update a foreign dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(UpdateDnsRecord::class)->handle($stranger, $dnsRecord, ['name' => 'www', 'type' => 'A', 'value' => '203.0.113.66']);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();
    $dnsRecord = DnsRecord::factory()->for($dnsZone)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteDnsRecord::class)->handle($stranger, $dnsRecord);
})->throws(AuthorizationException::class);
