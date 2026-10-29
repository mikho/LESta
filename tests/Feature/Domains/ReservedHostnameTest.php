<?php

use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\User;
use App\Models\WebDomain;

/**
 * @return array{0: Node, 1: User, 2: Account}
 */
function ownerOnNamedNode(): array
{
    $node = Node::factory()->create(['hostname' => 'node1.provider.example', 'mail_hostname' => 'mail.provider.example']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    $account = Account::factory()->for(Package::factory()->withLimit('web_domains', 5)->create())->create();

    return [$node, Membership::factory()->for($account)->owner()->create()->user, $account];
}

test('a customer cannot take a node\'s host name or mail host name, as a domain or as an alias', function (array $data, string $field) {
    [, $owner] = ownerOnNamedNode();

    $this->actingAs($owner)->post(route('domains.store'), $data + ['ssl_mode' => 'none'])->assertSessionHasErrors($field);

    expect(WebDomain::where('domain', 'node1.provider.example')->exists())->toBeFalse();
})->with([
    'the node host name as the domain' => [['domain' => 'node1.provider.example'], 'domain'],
    'in another case' => [['domain' => 'NODE1.Provider.Example'], 'domain'],
    'the mail host name as the domain' => [['domain' => 'mail.provider.example'], 'domain'],
    'the node host name as an alias' => [['domain' => 'shop.example', 'aliases' => ['node1.provider.example']], 'aliases.0'],
]);

test('a name is taken whether another domain uses it as its domain or as an alias', function () {
    [$node, $owner] = ownerOnNamedNode();
    $other = WebDomain::factory()->for($node)->create(['domain' => 'victim.example']);
    $other->aliases()->create(['alias' => 'www.victim.example']);

    $this->actingAs($owner)->post(route('domains.store'), ['domain' => 'shop.example', 'aliases' => ['victim.example'], 'ssl_mode' => 'none'])
        ->assertSessionHasErrors('aliases.0');
    $this->actingAs($owner)->post(route('domains.store'), ['domain' => 'www.victim.example', 'ssl_mode' => 'none'])
        ->assertSessionHasErrors('domain');
    $this->actingAs($owner)->post(route('domains.store'), ['domain' => 'shop.example', 'aliases' => ['a.shop.example', 'a.shop.example'], 'ssl_mode' => 'none'])
        ->assertSessionHasErrors('aliases.1');
});

test('a customer domain named after the node is never treated as the node\'s tools host', function () {
    [$node, , $account] = ownerOnNamedNode();
    $customerDomain = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'node1.provider.example', 'certificate_issued_at' => now()]);

    expect($node->toolsWebDomain())->toBeNull()
        ->and($customerDomain->isNodeToolsHost())->toBeFalse();

    $platformDomain = WebDomain::factory()->platformOwned()->for($node)->create(['domain' => 'node2.provider.example', 'certificate_issued_at' => now()]);
    $node->update(['hostname' => 'node2.provider.example']);

    expect($node->refresh()->toolsWebDomain()?->is($platformDomain))->toBeTrue();
});
