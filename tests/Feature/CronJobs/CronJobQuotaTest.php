<?php

use App\Actions\CronJobs\CreateCronJob;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function setUpCronCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('cron_jobs', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    return [$account, $owner];
}

test('a package with no PackageLimit row at all blocks cron job creation', function () {
    [$account, $owner] = setUpCronCapableAccount(-1);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner] = setUpCronCapableAccount(null);

    CronJob::factory()->for($account)->count(10)->create();

    $cronJob = app(CreateCronJob::class)->handle($owner, $account, ['command' => 'echo hello']);

    expect($cronJob)->toBeInstanceOf(CronJob::class);
});

test('a configured and exceeded limit blocks further creation', function () {
    [$account, $owner] = setUpCronCapableAccount(1);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'first']);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('creation under a configured limit is allowed', function () {
    [$account, $owner] = setUpCronCapableAccount(2);

    app(CreateCronJob::class)->handle($owner, $account, ['command' => 'first']);
    $second = app(CreateCronJob::class)->handle($owner, $account, ['command' => 'second']);

    expect($second)->toBeInstanceOf(CronJob::class)
        ->and($account->cronJobs()->count())->toBe(2);
});
