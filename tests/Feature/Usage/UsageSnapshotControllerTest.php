<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest is redirected to login', function () {
    $this->get(route('usage.index'))->assertRedirect(route('login'));
});

test('an account member can view their own account usage', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales']);

    UsageSnapshot::factory()
        ->for($account)
        ->for($node)
        ->for($mailAccount, 'snapshotable')
        ->create(['disk_bytes' => 12345]);

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('snapshots.data', 1)
            ->where('snapshots.data.0.resource_type', 'mail_account')
            ->where('snapshots.data.0.resource_label', 'sales@'.$mailDomain->domain)
            ->where('snapshots.data.0.disk_bytes', 12345)
        );
});

test('a user only ever sees their own account own usage snapshots, never another account own', function () {
    $ownAccount = Account::factory()->create();
    $owner = Membership::factory()->for($ownAccount)->owner()->create()->user;
    $node = Node::factory()->create();

    $otherAccount = Account::factory()->create();

    UsageSnapshot::factory()->for($ownAccount)->for($node)->create();
    UsageSnapshot::factory()->for($otherAccount)->for($node)->create();

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('snapshots.data', 1)
        );
});

test('a user with no account membership at all is denied', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('usage.index'))->assertNotFound();
});

test('a provider admin can view an arbitrary account own usage via the account query param', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales']);

    UsageSnapshot::factory()
        ->for($account)
        ->for($node)
        ->for($mailAccount, 'snapshotable')
        ->create(['disk_bytes' => 999]);

    $this->actingAs($admin)
        ->get(route('usage.index', ['account' => $account->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('snapshots.data', 1)
            ->where('snapshots.data.0.disk_bytes', 999)
            ->where('viewingAccount.uuid', $account->uuid)
            ->where('viewingAccount.name', $account->name)
        );
});

test('a plain member of one account cannot view another account own usage via the account query param', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $otherAccount = Account::factory()->create();
    $node = Node::factory()->create();

    UsageSnapshot::factory()->for($otherAccount)->for($node)->create();

    $this->actingAs($owner)
        ->get(route('usage.index', ['account' => $otherAccount->uuid]))
        ->assertForbidden();
});

test('an account member sees their own monthly usage rollups alongside raw snapshots', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales']);

    UsageSnapshotRollup::factory()
        ->for($account)
        ->for($node)
        ->for($mailAccount, 'snapshotable')
        ->create(['disk_bytes_last' => 54321]);

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('rollups.data', 1)
            ->where('rollups.data.0.resource_type', 'mail_account')
            ->where('rollups.data.0.resource_label', 'sales@'.$mailDomain->domain)
            ->where('rollups.data.0.disk_bytes_last', 54321)
        );
});

test('a user only ever sees their own account own usage rollups, never another account own', function () {
    $ownAccount = Account::factory()->create();
    $owner = Membership::factory()->for($ownAccount)->owner()->create()->user;
    $node = Node::factory()->create();

    $otherAccount = Account::factory()->create();

    UsageSnapshotRollup::factory()->for($ownAccount)->for($node)->create();
    UsageSnapshotRollup::factory()->for($otherAccount)->for($node)->create();

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('rollups.data', 1)
        );
});

test('the own-account view never sets viewingAccount', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->where('viewingAccount', null)
        );
});
