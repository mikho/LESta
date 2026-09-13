<?php

use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnsuspendAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend their account', function () {
    $account = Account::factory()->suspended()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeFalse()
        ->and(AuditEvent::where('action', 'account.unsuspended')->count())->toBe(1);
});

test('a non-owner member cannot unsuspend an account', function () {
    $account = Account::factory()->suspended()->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(UnsuspendAccount::class)->handle($member, $account);
})->throws(AuthorizationException::class);

test('unsuspending an account restores its own cascade-suspended tenant databases and cron jobs, not just web/dns/mail resources', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $account = Account::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(SuspendAccount::class)->handle($admin, $account);
    app(UnsuspendAccount::class)->handle($admin, $account);

    expect($tenantDatabase->refresh()->isSuspended())->toBeFalse()
        ->and($cronJob->refresh()->isSuspended())->toBeFalse();
});

test('unsuspending never restores a tenant database or cron job that was suspended independently, not by the account cascade', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $tenantDatabase = TenantDatabase::factory()->suspended()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);
    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($tenantDatabase->refresh()->isSuspended())->toBeTrue();
});
