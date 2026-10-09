<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\PackageLimit;
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
