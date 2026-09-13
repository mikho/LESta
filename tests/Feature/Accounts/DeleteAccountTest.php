<?php

use App\Actions\Accounts\DeleteAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

test('deleting a suspended account force-unsuspends then deletes as one action', function () {
    $account = Account::factory()->suspended()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $id = $account->id;

    app(DeleteAccount::class)->handle($owner, $account);

    expect(Account::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'account.deleted')->where('auditable_id', $id)->exists())->toBeTrue();
});

test('a provider admin with accounts.delete can delete an account directly, cascading into its own web domains', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $account = Account::factory()->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $accountId = $account->id;
    $webDomainId = $webDomain->id;

    app(DeleteAccount::class)->handle($admin, $account);

    expect(Account::find($accountId))->toBeNull()
        // The cascade into WebDomainPolicy::delete must not throw for this same admin: it
        // recognizes the identical accounts.delete permission that already authorized the
        // top-level action, not a second, independent grant.
        ->and(WebDomain::find($webDomainId))->toBeNull()
        ->and(AuditEvent::where('action', 'account.deleted')->where('auditable_id', $accountId)->exists())->toBeTrue();
});

test('deleting an account with a real tenant database or cron job no longer throws a raw foreign-key exception', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $account = Account::factory()->create();
    $tenantDatabase = TenantDatabase::factory()->for($account)->for($node)->create();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $accountId = $account->id;

    // Confirmed as a real, reproducible bug before this fix: tenant_databases.account_id and
    // cron_jobs.account_id are both restrictOnDelete, not cascadeOnDelete, so the final
    // $account->delete() call threw a raw, uncaught Illuminate\Database\QueryException without
    // this fix -- never a graceful error, and never actually deleting anything.
    app(DeleteAccount::class)->handle($owner, $account);

    expect(Account::find($accountId))->toBeNull()
        ->and(TenantDatabase::find($tenantDatabase->id))->toBeNull()
        ->and(CronJob::find($cronJob->id))->toBeNull();
});
