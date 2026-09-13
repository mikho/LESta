<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\UsageSnapshot;
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
