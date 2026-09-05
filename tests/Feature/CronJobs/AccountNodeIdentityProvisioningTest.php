<?php

use App\Actions\CronJobs\CreateCronJob;
use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;

test('creating a brand-new account\'s first cron job on a node dispatches both an identity and a cron provisioning operation, identity first', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    $cronJob = app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);

    $identity = AccountNodeIdentity::query()
        ->where('account_id', $account->id)
        ->where('node_id', $node->id)
        ->first();

    expect($identity)->not->toBeNull()
        ->and($identity->system_username)->toBe('lesta-t'.$account->id);

    $identityOperation = ProvisioningOperation::query()
        ->where('provisionable_type', $identity->getMorphClass())
        ->where('provisionable_id', $identity->id)
        ->first();

    $cronOperation = ProvisioningOperation::query()
        ->where('provisionable_type', $cronJob->getMorphClass())
        ->where('provisionable_id', $cronJob->id)
        ->first();

    // This suite's own test provisioning driver (config('provisioning.driver'), 'fake' by
    // default; see App\Services\Provisioning\FakeProvisioner) completes an operation
    // synchronously, past Dispatched straight to Applied, immediately after
    // DispatchProvisioningOperation sets it Dispatched -- unlike production's DaemonProvisioner,
    // which leaves an operation sitting at Dispatched until the owning node's agent daemon
    // reports a real result back. Both statuses prove the SAME thing this test actually
    // verifies: the operation was genuinely dispatched (dispatched_at set), not left Pending, so
    // accepting either keeps this test meaningful under either driver rather than coupling it to
    // one specific provisioning backend's own completion timing.
    expect($identityOperation)->not->toBeNull()
        ->and($identityOperation->capability)->toBe('system.account-identity.v1')
        ->and($identityOperation->operation->value)->toBe('create')
        ->and($identityOperation->status)->toBeIn([ProvisioningStatus::Dispatched, ProvisioningStatus::Applied])
        ->and($identityOperation->dispatched_at)->not->toBeNull()
        ->and($cronOperation)->not->toBeNull()
        ->and($cronOperation->capability)->toBe('scheduler.account-cron.v1')
        ->and($cronOperation->status)->toBeIn([ProvisioningStatus::Dispatched, ProvisioningStatus::Applied])
        ->and($cronOperation->dispatched_at)->not->toBeNull()
        ->and($identityOperation->dispatched_at->lessThanOrEqualTo($cronOperation->dispatched_at))->toBeTrue();
});

test('a second cron job for the same account on the same node reuses the existing identity, with no second identity row or operation', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo one']);
    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo two']);

    expect(AccountNodeIdentity::query()->where('account_id', $account->id)->where('node_id', $node->id)->count())->toBe(1)
        ->and(ProvisioningOperation::query()->where('capability', 'system.account-identity.v1')->count())->toBe(1);
});

test('a different account on the same node gets its own distinct identity', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $accountA = Account::factory()->for($package)->create();
    $accountB = Account::factory()->for($package)->create();
    $ownerA = Membership::factory()->for($accountA)->owner()->create()->user;
    $ownerB = Membership::factory()->for($accountB)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    app(CreateCronJob::class)->handle($ownerA, $accountA, ['command' => 'echo a']);
    app(CreateCronJob::class)->handle($ownerB, $accountB, ['command' => 'echo b']);

    $identityA = AccountNodeIdentity::query()->where('account_id', $accountA->id)->where('node_id', $node->id)->firstOrFail();
    $identityB = AccountNodeIdentity::query()->where('account_id', $accountB->id)->where('node_id', $node->id)->firstOrFail();

    expect($identityA->id)->not->toBe($identityB->id)
        ->and($identityA->system_username)->not->toBe($identityB->system_username)
        ->and($identityA->system_username)->toBe('lesta-t'.$accountA->id)
        ->and($identityB->system_username)->toBe('lesta-t'.$accountB->id);
});
