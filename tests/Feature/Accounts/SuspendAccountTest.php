<?php

use App\Actions\Accounts\SuspendAccount;
use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their account', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and(AuditEvent::where('action', 'account.suspended')->count())->toBe(1);
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(SuspendAccount::class)->handle($owner, $account);
    app(SuspendAccount::class)->handle($owner, $account);

    expect(AuditEvent::where('action', 'account.suspended')->count())->toBe(1);
});

test('a non-owner member cannot suspend an account', function () {
    $account = Account::factory()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(SuspendAccount::class)->handle($member, $account);
})->throws(AuthorizationException::class);

test('a provider admin with accounts.suspend can suspend an account directly, cascading into its own web domains', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $account = Account::factory()->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(SuspendAccount::class)->handle($admin, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        // The cascade into WebDomainPolicy::suspend must not throw for this same admin: it
        // recognizes the identical accounts.suspend permission that already authorized the
        // top-level action, not a second, independent grant.
        ->and($webDomain->refresh()->isSuspended())->toBeTrue()
        ->and(AuditEvent::where('action', 'account.suspended')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('suspending an account also suspends its own tenant databases and cron jobs, not just web/dns/mail resources', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $account = Account::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(SuspendAccount::class)->handle($admin, $account);

    // Confirmed as a real, reproducible bug before this fix: neither resource was suspended at
    // all, meaning a "suspended" account's own database and cron jobs stayed fully usable.
    expect($tenantDatabase->refresh()->isSuspended())->toBeTrue()
        ->and($cronJob->refresh()->isSuspended())->toBeTrue();
});

test('a platform role without accounts.suspend cannot suspend an account', function () {
    $account = Account::factory()->create();
    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'accounts.view_as_support'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    app(SuspendAccount::class)->handle($limitedAdmin, $account);
})->throws(AuthorizationException::class);
