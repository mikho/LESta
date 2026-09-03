<?php

use App\Actions\CronJobs\CreateCronJob;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoCronCapableNodeAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a cron job and it is provisioned after commit', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    $cronJob = app(CreateCronJob::class)->handle($owner, $account, [
        'minute' => '0',
        'hour' => '3',
        'command' => 'php artisan backup:run',
    ]);

    expect($cronJob->minute)->toBe('0')
        ->and($cronJob->hour)->toBe('3')
        ->and($cronJob->day_of_month)->toBe('*')
        ->and($cronJob->month)->toBe('*')
        ->and($cronJob->day_of_week)->toBe('*')
        ->and($cronJob->command)->toBe('php artisan backup:run')
        ->and($cronJob->account_id)->toBe($account->id)
        ->and($cronJob->node_id)->toBe($node->id)
        ->and($cronJob->desired_state_version)->toBe(1)
        ->and(AuditEvent::where('action', 'cron_job.created')->where('auditable_id', $cronJob->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $cronJob->getMorphClass())
        ->where('provisionable_id', $cronJob->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('scheduler.account-cron.v1')
        ->and($operation->operation->value)->toBe('create');
});

test('creating a cron job defaults every schedule field to a wildcard', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    $cronJob = app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);

    expect($cronJob->minute)->toBe('*')
        ->and($cronJob->hour)->toBe('*')
        ->and($cronJob->day_of_month)->toBe('*')
        ->and($cronJob->month)->toBe('*')
        ->and($cronJob->day_of_week)->toBe('*');
});

test('a non-owner member cannot create a cron job', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(CreateCronJob::class)->handle($member, $account, ['command' => 'echo hello']);
})->throws(AuthorizationException::class);

test('a package with no cron_jobs limit row blocks creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('cron_jobs', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'first']);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('creation fails when no cron-capable node is available', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);
})->throws(NoCronCapableNodeAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    try {
        app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);
    } catch (NoCronCapableNodeAvailableException) {
        // expected
    }

    expect(CronJob::count())->toBe(0)
        ->and(AuditEvent::where('action', 'cron_job.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});
