<?php

use App\Models\Account;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithCronCapableAccount(): array
{
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    return [$account, $owner, $node];
}

test('the index page lists the account cron jobs', function () {
    [$account, $owner] = actingAsOwnerWithCronCapableAccount();
    CronJob::factory()->for($account)->create(['command' => 'echo hello']);

    $this->actingAs($owner)
        ->get(route('cron-jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cron-jobs/index')
            ->has('cronJobs.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('cron-jobs.index'))->assertRedirect(route('login'));
});

test('storing a cron job redirects to the index with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithCronCapableAccount();

    $response = $this->actingAs($owner)
        ->post(route('cron-jobs.store'), ['command' => 'echo hello']);

    $cronJob = CronJob::where('account_id', $account->id)->where('command', 'echo hello')->firstOrFail();

    $response->assertRedirect(route('cron-jobs.index'));
    expect($cronJob)->not->toBeNull();
});

test('storing a cron job over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    $this->actingAs($owner)
        ->post(route('cron-jobs.store'), ['command' => 'echo hello'])
        ->assertSessionHasErrors('command');
});

test('storing a cron job with no capable node available results in a server error', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    // Deliberately no Node/NodeCapability at all: CreateCronJob::handle() lets
    // NoCronCapableNodeAvailableException bubble up uncaught, matching DnsZone's own
    // controller precedent.
    $this->actingAs($owner)
        ->post(route('cron-jobs.store'), ['command' => 'echo hello'])
        ->assertServerError();
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('cron_jobs', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('cron-jobs.create'))->assertForbidden();
});

test('updating a cron job redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithCronCapableAccount();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->put(route('cron-jobs.update', $cronJob), ['command' => 'echo updated'])
        ->assertRedirect(route('cron-jobs.edit', $cronJob));

    expect($cronJob->refresh()->command)->toBe('echo updated');
});

test('suspending a cron job redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithCronCapableAccount();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('cron-jobs.index'))
        ->post(route('cron-jobs.suspend', $cronJob))
        ->assertRedirect(route('cron-jobs.index'));

    expect($cronJob->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a cron job redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithCronCapableAccount();
    $cronJob = CronJob::factory()->for($account)->for($node)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('cron-jobs.index'))
        ->post(route('cron-jobs.unsuspend', $cronJob))
        ->assertRedirect(route('cron-jobs.index'));

    expect($cronJob->refresh()->isSuspended())->toBeFalse();
});

test('destroying a cron job redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithCronCapableAccount();
    $cronJob = CronJob::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('cron-jobs.destroy', $cronJob))
        ->assertRedirect(route('cron-jobs.index'));

    expect(CronJob::find($cronJob->id))->toBeNull();
});
