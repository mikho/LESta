<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\UsageSnapshot;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: Node, 1: Node, 2: Account, 3: Account}
 */
function seedUsageAcrossAccounts(): array
{
    $alpha = Node::factory()->create(['name' => 'alpha']);
    $beta = Node::factory()->create(['name' => 'beta']);
    $acme = Account::factory()->create(['name' => 'Acme Corp']);
    $globex = Account::factory()->create(['name' => 'Globex']);

    $mailbox = MailAccount::factory()->for(MailDomain::factory()->for($acme)->for($alpha)->create())->create();

    // Disk is instantaneous, so only this mailbox's latest reading counts; requests and bytes
    // sent are increments, so everything inside the last 30 days adds up.
    UsageSnapshot::factory()->for($acme)->for($alpha)->for($mailbox, 'snapshotable')->create(['disk_bytes' => 100, 'request_count' => 5, 'bytes_sent' => 10, 'collected_at' => now()->subDays(3)]);
    UsageSnapshot::factory()->for($acme)->for($alpha)->for($mailbox, 'snapshotable')->create(['disk_bytes' => 300, 'request_count' => 7, 'bytes_sent' => 20, 'collected_at' => now()->subDay()]);
    UsageSnapshot::factory()->for($acme)->for($alpha)->for($mailbox, 'snapshotable')->create(['disk_bytes' => 50, 'request_count' => 1000, 'bytes_sent' => 1000, 'collected_at' => now()->subDays(40)]);

    $globexMailbox = MailAccount::factory()->for(MailDomain::factory()->for($globex)->for($beta)->create())->create();
    UsageSnapshot::factory()->for($globex)->for($beta)->for($globexMailbox, 'snapshotable')->create(['disk_bytes' => 900, 'request_count' => null, 'bytes_sent' => null, 'collected_at' => now()->subDay()]);

    return [$alpha, $beta, $acme, $globex];
}

test('a provider admin sees every account\'s usage grouped by node, linking to each account\'s own usage page', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    [, , $acme] = seedUsageAcrossAccounts();

    $this->actingAs($admin)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->has('customers.groups', 2)
            ->where('customers.groups.0.node.name', 'alpha')
            ->where('customers.groups.0.rows.0.account.name', 'Acme Corp')
            ->where('customers.groups.0.rows.0.item.account_public_id', $acme->public_id)
            ->where('customers.groups.0.rows.0.item.disk_bytes', 300)
            ->where('customers.groups.0.rows.0.item.request_count', 12)
            ->where('customers.groups.0.rows.0.item.bytes_sent', 30)
            ->where('customers.groups.1.node.name', 'beta')
            ->where('customers.groups.1.rows.0.item.disk_bytes', 900)
            ->where('customers.groups.1.rows.0.item.request_count', null)
            ->where('customers.groups.0.totals.accounts', 1)
            ->where('customers.summary', ['resources' => null, 'accounts' => 2, 'nodes' => 2])
        );
});

test('the node and account filters narrow the usage list', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    seedUsageAcrossAccounts();

    $this->actingAs($admin)
        ->get(route('usage.index', ['node' => 'beta']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.groups', 1)
            ->where('customers.groups.0.node.name', 'beta'));

    $this->actingAs($admin)
        ->get(route('usage.index', ['search' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.groups', 1)
            ->where('customers.groups.0.rows.0.account.name', 'Acme Corp'));
});

test('an admin still reaches one account\'s detail page with ?account=', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    [, , $acme] = seedUsageAcrossAccounts();

    $this->actingAs($admin)
        ->get(route('usage.index', ['account' => $acme->public_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('usage/index')
            ->missing('customers')
            ->where('viewingAccount.name', 'Acme Corp')
            ->has('snapshots.data'));
});

test('an account owner still sees only their own usage, never the summary', function () {
    [, , $acme] = seedUsageAcrossAccounts();
    $owner = Membership::factory()->for($acme)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route('usage.index'))
        ->assertInertia(fn (Assert $page) => $page->missing('customers')->where('viewingAccount', null)->has('snapshots.data'));
});

test('usage rows sort within a node by disk, requests or last collection', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);

    foreach ([['Small', 100, 50], ['Big', 900, 5], ['Middle', 500, 20]] as [$name, $disk, $requests]) {
        $account = Account::factory()->create(['name' => $name]);
        $mailbox = MailAccount::factory()->for(MailDomain::factory()->for($account)->for($node)->create())->create();
        UsageSnapshot::factory()->for($account)->for($node)->for($mailbox, 'snapshotable')->create(['disk_bytes' => $disk, 'request_count' => $requests, 'bytes_sent' => 1, 'collected_at' => now()->subDay()]);
    }

    $names = fn (string $sort) => collect($this->actingAs($admin)->get(route('usage.index', ['sort' => $sort]))->viewData('page')['props']['customers']['groups'][0]['rows'])->pluck('account.name')->all();

    expect($names('-disk'))->toBe(['Big', 'Middle', 'Small'])
        ->and($names('disk'))->toBe(['Small', 'Middle', 'Big'])
        ->and($names('-requests'))->toBe(['Small', 'Middle', 'Big']);
});
