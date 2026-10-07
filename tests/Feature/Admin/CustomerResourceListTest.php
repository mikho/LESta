<?php

use App\Models\Account;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

dataset('customer resource pages', [
    'domains' => ['domains.index', 'domains/index', WebDomain::class],
    'dns' => ['dns.index', 'dns/index', DnsZone::class],
    'mail' => ['mail.index', 'mail/index', MailDomain::class],
    'databases' => ['tenant-databases.index', 'tenant-databases/index', TenantDatabase::class],
    'cron jobs' => ['cron-jobs.index', 'cron-jobs/index', CronJob::class],
]);

/**
 * Two nodes, three accounts: acme has resources on both nodes, globex only on the second.
 *
 * @return array{0: Node, 1: Node, 2: Account, 3: Account}
 */
function seedCustomerResources(string $model): array
{
    $alpha = Node::factory()->create(['name' => 'alpha']);
    $beta = Node::factory()->create(['name' => 'beta']);
    $acme = Account::factory()->create(['name' => 'Acme Corp']);
    $globex = Account::factory()->create(['name' => 'Globex']);

    $model::factory()->for($acme)->for($alpha)->create();
    $model::factory()->for($acme)->for($beta)->create();
    $model::factory()->for($globex)->for($beta)->create();

    return [$alpha, $beta, $acme, $globex];
}

test('a provider admin sees every customer resource grouped by node, then account', function (string $route, string $component, string $model) {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    seedCustomerResources($model);

    $this->actingAs($admin)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('customers.groups', 2)
            ->where('customers.groups.0.node.name', 'alpha')
            ->has('customers.groups.0.accounts', 1)
            ->where('customers.groups.0.accounts.0.account.name', 'Acme Corp')
            ->where('customers.groups.1.node.name', 'beta')
            ->has('customers.groups.1.accounts', 2)
            ->where('customers.groups.1.accounts.0.account.name', 'Acme Corp')
            ->where('customers.groups.1.accounts.1.account.name', 'Globex')
            ->where('customers.nodes', fn ($nodes) => collect($nodes)->contains('alpha') && collect($nodes)->contains('beta'))
            ->where('customers.pagination.total', 3)
        );
})->with('customer resource pages');

test('the node filter narrows the list to one node', function (string $route, string $component, string $model) {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    seedCustomerResources($model);

    $this->actingAs($admin)
        ->get(route($route, ['node' => 'alpha']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.groups', 1)
            ->where('customers.groups.0.node.name', 'alpha')
            ->where('customers.filters.node', 'alpha')
            ->where('customers.pagination.total', 1)
        );
})->with('customer resource pages');

test('the account filter matches account name, public id or contact email', function (string $route, string $component, string $model) {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    [, , , $globex] = seedCustomerResources($model);
    $globex->forceFill(['contact_email' => 'billing@globex.example'])->save();

    foreach (['glob', $globex->public_id, 'billing@globex'] as $term) {
        $this->actingAs($admin)
            ->get(route($route, ['account' => $term]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.groups', 1)
                ->where('customers.groups.0.accounts.0.account.name', 'Globex')
                ->where('customers.pagination.total', 1)
            );
    }
})->with('customer resource pages');

test('an account owner still sees only their own resources, never the customer view', function (string $route, string $component, string $model) {
    [, , $acme] = seedCustomerResources($model);
    $owner = Membership::factory()->for($acme)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->missing('customers')
        );
})->with('customer resource pages');

test('the customer view is read-only: a provider admin still cannot create a resource', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->get(route('domains.create'))->assertRedirect(route('domains.index'));
});
