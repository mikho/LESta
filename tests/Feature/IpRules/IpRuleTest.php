<?php

use App\Models\AccountIpRule;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

function accountWithNginxDomains(int $domains = 2): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $first = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($first->account)->for($node)->create(['system_username' => 'lesta-t'.$first->account_id]);

    for ($i = 2; $i <= $domains; $i++) {
        WebDomain::factory()->for($first->account)->for($node)->create();
    }

    $owner = Membership::factory()->for($first->account)->owner()->create()->user;

    return [$first->account, $owner];
}

test('an owner adds a rule, it is stored canonically, and every nginx domain is re-rendered with it', function () {
    [$account, $owner] = accountWithNginxDomains(2);

    $this->actingAs($owner)
        ->post(route('ip-rules.store'), ['action' => 'deny', 'cidr' => '203.0.113.9/24', 'note' => 'scanner'])
        ->assertSessionHasNoErrors();

    $rule = AccountIpRule::where('account_id', $account->id)->sole();

    expect($rule->cidr)->toBe('203.0.113.0/24')
        ->and($rule->note)->toBe('scanner')
        ->and(AuditEvent::where('action', 'ip_rule.created')->count())->toBe(1);

    $operations = ProvisioningOperation::where('capability', 'web.nginx.v1')->get();

    expect($operations)->toHaveCount(2)
        ->and($operations->every(fn ($op) => $op->payload['ip_rules'] === [['action' => 'deny', 'cidr' => '203.0.113.0/24']]))->toBeTrue();
});

test('removing a rule re-renders the domains without it', function () {
    [$account, $owner] = accountWithNginxDomains(1);
    $rule = AccountIpRule::factory()->for($account)->create(['cidr' => '198.51.100.7']);

    $this->actingAs($owner)->delete(route('ip-rules.destroy', $rule))->assertRedirect();

    expect(AccountIpRule::count())->toBe(0)
        ->and(ProvisioningOperation::where('capability', 'web.nginx.v1')->latest('id')->first()->payload['ip_rules'])->toBe([]);
});

test('addresses are normalised, and anything that is not an address is rejected', function (string $input, ?string $expected) {
    expect(AccountIpRule::normalize($input))->toBe($expected);
})->with([
    'plain v4' => ['203.0.113.5', '203.0.113.5'],
    'range is masked' => ['203.0.113.9/24', '203.0.113.0/24'],
    'v6 is compressed' => ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'],
    'v6 range' => ['2001:db8:abcd::1/32', '2001:db8::/32'],
    'zero range' => ['0.0.0.0/0', '0.0.0.0/0'],
    'prefix too long' => ['1.2.3.4/33', null],
    'hostname' => ['example.com', null],
    'injection' => ['1.2.3.4; return 200', null],
    'empty' => ['', null],
]);

test('invalid input is refused with a message', function (array $input) {
    [, $owner] = accountWithNginxDomains(1);

    $this->actingAs($owner)->post(route('ip-rules.store'), $input)->assertSessionHasErrors();

    expect(AccountIpRule::count())->toBe(0);
})->with([
    'bad address' => [['action' => 'deny', 'cidr' => 'not-an-ip']],
    'bad action' => [['action' => 'permit', 'cidr' => '203.0.113.5']],
    'no address' => [['action' => 'deny']],
]);

test('a duplicate rule is refused and the account limit is enforced', function () {
    [$account, $owner] = accountWithNginxDomains(1);
    AccountIpRule::factory()->for($account)->create(['action' => 'deny', 'cidr' => '203.0.113.5']);

    $this->actingAs($owner)->post(route('ip-rules.store'), ['action' => 'deny', 'cidr' => '203.0.113.5'])->assertSessionHasErrors('cidr');

    for ($i = 1; $i < AccountIpRule::MAX_PER_ACCOUNT; $i++) {
        AccountIpRule::factory()->for($account)->create(['cidr' => '10.'.intdiv($i, 250).'.'.($i % 250).'.1']);
    }

    $this->actingAs($owner)->post(route('ip-rules.store'), ['action' => 'deny', 'cidr' => '198.51.100.1'])->assertSessionHasErrors('cidr');
});

test('only an owner can change the list, another account cannot touch it, and members can view it', function () {
    [$account, $owner] = accountWithNginxDomains(1);
    $member = Membership::factory()->for($account)->member()->create()->user;
    $rule = AccountIpRule::factory()->for($account)->create();
    [, $stranger] = accountWithNginxDomains(1);

    $this->actingAs($member)->post(route('ip-rules.store'), ['action' => 'deny', 'cidr' => '203.0.113.5'])->assertForbidden();
    $this->actingAs($member)->delete(route('ip-rules.destroy', $rule))->assertForbidden();
    $this->actingAs($member)->get(route('ip-rules.index'))->assertOk();
    $this->actingAs($stranger)->delete(route('ip-rules.destroy', $rule))->assertForbidden();

    expect(AccountIpRule::count())->toBe(1);
});

test('the index lists the account rules and shows nothing for a user with no account', function () {
    [$account, $owner] = accountWithNginxDomains(1);
    AccountIpRule::factory()->for($account)->allow()->create(['cidr' => '203.0.113.5']);

    $this->actingAs($owner)->get(route('ip-rules.index'))
        ->assertInertia(fn ($page) => $page->component('ip-rules/index')->has('rules', 1)->where('rules.0.cidr', '203.0.113.5'));

    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->get(route('ip-rules.index'))
        ->assertInertia(fn ($page) => $page->where('rules', null));
});

test('a domain update payload carries the account rules, the apache payload does not', function () {
    [$account] = accountWithNginxDomains(1);
    AccountIpRule::factory()->for($account)->create(['action' => 'deny', 'cidr' => '203.0.113.5']);

    $webDomain = $account->webDomains()->first();

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ip_rules'])->toBe([['action' => 'deny', 'cidr' => '203.0.113.5']])
        ->and($webDomain->toProvisioningPayload('web.apache.v1'))->not->toHaveKey('ip_rules');
});
