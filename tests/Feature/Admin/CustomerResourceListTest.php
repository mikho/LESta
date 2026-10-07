<?php

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\ProvisioningOperation;
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
            ->has('customers.groups.0.rows', 1)
            ->where('customers.groups.0.rows.0.account.name', 'Acme Corp')
            ->where('customers.groups.1.node.name', 'beta')
            ->has('customers.groups.1.rows', 2)
            ->where('customers.groups.1.rows.0.account.name', 'Acme Corp')
            ->where('customers.groups.1.rows.1.account.name', 'Globex')
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
                ->where('customers.groups.0.rows.0.account.name', 'Globex')
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

test('each node group carries its own totals, and the summary covers the whole filtered list', function (string $route, string $component, string $model) {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    seedCustomerResources($model);

    $this->actingAs($admin)
        ->get(route($route))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.groups.0.totals', ['resources' => 1, 'accounts' => 1])
            ->where('customers.groups.1.totals', ['resources' => 2, 'accounts' => 2])
            ->where('customers.summary.resources', 3)
            ->where('customers.summary.accounts', 2)
            ->where('customers.summary.nodes', fn ($nodes) => $nodes >= 2)
        );
})->with('customer resource pages');

test('the problems filter keeps suspended resources and resources whose latest operation failed', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create();

    $healthy = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'healthy.example']);
    $suspended = WebDomain::factory()->for($account)->for($node)->suspended()->create(['domain' => 'suspended.example']);
    $failed = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'failed.example']);
    $recovered = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'recovered.example']);

    $operation = fn (WebDomain $domain, ProvisioningStatus $status) => ProvisioningOperation::factory()
        ->create(['provisionable_type' => $domain->getMorphClass(), 'provisionable_id' => $domain->id, 'resource_id' => $domain->uuid, 'status' => $status]);

    $operation($healthy, ProvisioningStatus::Applied);
    $operation($failed, ProvisioningStatus::Failed);
    $operation($recovered, ProvisioningStatus::Failed);
    $operation($recovered, ProvisioningStatus::Applied);

    $this->actingAs($admin)
        ->get(route('domains.index', ['status' => 'problems', 'node' => 'alpha']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.filters.status', 'problems')
            ->where('customers.pagination.total', 2)
            ->where('customers.groups.0.rows', fn ($rows) => collect($rows)->pluck('item.domain')->sort()->values()->all() === ['failed.example', 'suspended.example'])
        );
});

test('rows are one ordered list per node and can be sorted within each node', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create(['name' => 'Acme']);

    foreach (['b.example', 'c.example', 'a.example'] as $domain) {
        WebDomain::factory()->for($account)->for($node)->create(['domain' => $domain]);
    }

    $domains = fn (string $sort) => $this->actingAs($admin)
        ->get(route('domains.index', ['sort' => $sort]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.filters.sort', $sort)
            ->where('customers.groups.0.rows', fn ($rows) => collect($rows)->pluck('item.domain')->all() === ($sort === 'domain' ? ['a.example', 'b.example', 'c.example'] : ['c.example', 'b.example', 'a.example'])));

    $domains('domain');
    $domains('-domain');
});

test('an unknown sort key is ignored rather than used as SQL', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    seedCustomerResources(WebDomain::class);

    $this->actingAs($admin)
        ->get(route('domains.index', ['sort' => 'id; drop table users']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customers.filters.sort', '')->where('customers.pagination.total', 3));
});

test('a failed row carries the reason the node reported, and a healthy row carries none', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create();
    $failed = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'failed.example']);
    $healthy = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'healthy.example']);

    ProvisioningOperation::factory()->create(['provisionable_type' => $failed->getMorphClass(), 'provisionable_id' => $failed->id, 'resource_id' => $failed->uuid, 'status' => ProvisioningStatus::Failed, 'errors' => [['code' => 'nginx_test_failed', 'message' => 'nginx -t rejected the configuration']]]);
    ProvisioningOperation::factory()->create(['provisionable_type' => $healthy->getMorphClass(), 'provisionable_id' => $healthy->id, 'resource_id' => $healthy->uuid, 'status' => ProvisioningStatus::Applied]);

    $this->actingAs($admin)
        ->get(route('domains.index'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.groups.0.rows', function ($rows) {
            $byDomain = collect($rows)->keyBy('item.domain');

            return $byDomain['failed.example']['item']['provisioning_error'] === 'nginx -t rejected the configuration'
                && $byDomain['healthy.example']['item']['provisioning_error'] === null;
        }));
});

test('on the mail list, a domain with antivirus or antispam switched off counts as a problem', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create();

    MailDomain::factory()->for($account)->for($node)->create(['domain' => 'safe.example', 'antivirus_enabled' => true, 'antispam_enabled' => true]);
    MailDomain::factory()->for($account)->for($node)->create(['domain' => 'no-av.example', 'antivirus_enabled' => false, 'antispam_enabled' => true]);
    MailDomain::factory()->for($account)->for($node)->create(['domain' => 'no-spam.example', 'antivirus_enabled' => true, 'antispam_enabled' => false]);
    MailDomain::factory()->for($account)->for($node)->create(['domain' => 'no-dkim.example', 'antivirus_enabled' => true, 'antispam_enabled' => true, 'dkim_enabled' => false]);

    $this->actingAs($admin)
        ->get(route('mail.index', ['status' => 'problems']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.groups.0.rows', fn ($rows) => collect($rows)->pluck('item.domain')->sort()->values()->all() === ['no-av.example', 'no-spam.example']));
});
