<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\PackageLimit;
use App\Models\ProvisioningOperation;
use App\Models\UsageSnapshot;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('a user with no account membership sees the welcome state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('account', null));
});

test('a member sees their own account resource counts', function () {
    $account = Account::factory()->create(['name' => 'acme-inc']);
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->for($account)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.name', 'acme-inc')
            ->where('account.web_domains_count', 1)
        );
});

test('the dashboard shows plan limits against use and the previous sign-in', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    WebDomain::factory()->for($account)->create();
    PackageLimit::factory()->create(['package_id' => $account->package_id, 'resource_type' => 'web_domains', 'limit_value' => 5]);
    PackageLimit::factory()->create(['package_id' => $account->package_id, 'resource_type' => 'dns_zones', 'limit_value' => null]);
    UsageSnapshot::factory()->webUsage()->create(['account_id' => $account->id, 'request_count' => 40, 'bytes_sent' => 2048]);
    $owner->forceFill(['previous_login_at' => now()->subDay(), 'previous_login_ip' => '203.0.113.9'])->save();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.role', 'owner')
            ->where('overview.package', $account->package->name)
            ->where('overview.previous_sign_in.ip', '203.0.113.9')
            ->where('usage.limits.0', fn ($row) => $row['key'] === 'web_domains' && $row['used'] === 1 && $row['limit'] === 5 && $row['included'])
            ->where('usage.limits.1', fn ($row) => $row['key'] === 'dns_zones' && $row['included'] && $row['limit'] === null)
            ->where('usage.limits.2.included', false)
            ->where('usage.requests_30d', 40)
            ->where('usage.bandwidth_bytes_30d', 2048)
        );
});

test('the dashboard lists what the server could not apply, with its reason, and counts what is still being applied', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $broken = WebDomain::factory()->for($account)->create(['domain' => 'broken.example']);
    $busy = WebDomain::factory()->for($account)->create(['domain' => 'busy.example']);
    $operation = fn (WebDomain $webDomain) => [
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'resource_id' => $webDomain->uuid,
        'node_id' => $webDomain->node_id,
    ];
    ProvisioningOperation::factory()->failed()->create($operation($broken));
    ProvisioningOperation::factory()->pending()->create($operation($busy));

    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('health.attention', 1)
        ->where('health.attention.0.kind', 'Website')
        ->where('health.attention.0.name', 'broken.example')
        ->where('health.attention.0.error', 'Provisioner failed to apply.')
        ->where('health.attention.0.url', route('domains.edit', $broken))
        ->where('health.applying', 1));
});
