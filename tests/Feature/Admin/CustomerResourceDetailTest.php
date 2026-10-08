<?php

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\CronJobExecution;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use Illuminate\Database\Eloquent\Model;
use Inertia\Testing\AssertableInertia as Assert;

dataset('customer resource types', [
    'domains' => ['domains', WebDomain::class],
    'dns' => ['dns', DnsZone::class],
    'mail' => ['mail', MailDomain::class],
    'databases' => ['databases', TenantDatabase::class],
    'cron jobs' => ['cron-jobs', CronJob::class],
]);

function customerResource(string $model): Model
{
    $node = Node::query()->where('name', 'alpha')->first() ?? Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create(['name' => 'Acme Corp']);

    return $model::factory()->for($account)->for($node)->create();
}

test('a provider admin sees the read-only detail page for each kind of customer resource', function (string $type, string $model) {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $resource = customerResource($model);

    $this->actingAs($admin)
        ->get(route('customer-resources.show', [$type, $resource->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customer-resources/show')
            ->where('resource.account.name', 'Acme Corp')
            ->where('resource.node', 'alpha')
            ->has('resource.facts')
            ->has('resource.operations')
        );
})->with('customer resource types');

test('only a user with the view_any permission reaches it', function (string $type, string $model) {
    $resource = customerResource($model);
    $owner = Membership::factory()->for($resource->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route('customer-resources.show', [$type, $resource->uuid]))
        ->assertForbidden();

    auth()->logout();

    $this->get(route('customer-resources.show', [$type, $resource->uuid]))->assertRedirect(route('login'));
})->with('customer resource types');

test('an unknown type, a malformed id and a missing resource are all not found', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->get('/customer-resources/widgets/'.fake()->uuid())->assertNotFound();
    $this->actingAs($admin)->get('/customer-resources/domains/not-a-uuid')->assertNotFound();
    $this->actingAs($admin)->get(route('customer-resources.show', ['domains', fake()->uuid()]))->assertNotFound();
});

test('a failed operation shows the reason the node reported, and never its payload', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $domain = customerResource(WebDomain::class);

    ProvisioningOperation::factory()->create([
        'provisionable_type' => $domain->getMorphClass(),
        'provisionable_id' => $domain->id,
        'resource_id' => $domain->uuid,
        'status' => ProvisioningStatus::Failed,
        'payload' => ['secret_token' => 'top-secret-value'],
        'errors' => [['code' => 'nginx_test_failed', 'message' => 'nginx -t rejected the configuration']],
    ]);

    $response = $this->actingAs($admin)->get(route('customer-resources.show', ['domains', $domain->uuid]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('resource.operations.0.status', 'failed')
        ->where('resource.operations.0.error', 'nginx -t rejected the configuration'));

    expect($response->getContent())->not->toContain('top-secret-value');
});

test('credentials and customer content are never part of the response', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $database = customerResource(TenantDatabase::class);
    $mailDomain = customerResource(MailDomain::class);
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales', 'password' => 'mailbox-secret-pass', 'forward_to' => 'private-forward@elsewhere.example']);
    $job = customerResource(CronJob::class);
    CronJobExecution::create(['cron_job_id' => $job->id, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'exit_code' => 0, 'output' => 'customer-job-output-xyz']);

    $bodies = [
        $this->actingAs($admin)->get(route('customer-resources.show', ['databases', $database->uuid]))->getContent(),
        $this->actingAs($admin)->get(route('customer-resources.show', ['mail', $mailDomain->uuid]))->getContent(),
        $this->actingAs($admin)->get(route('customer-resources.show', ['cron-jobs', $job->uuid]))->getContent(),
    ];

    expect($bodies[0])->not->toContain($database->password)->not->toContain($database->stats_password)
        ->and($bodies[1])->toContain('sales@')->not->toContain('mailbox-secret-pass')->not->toContain('private-forward@elsewhere.example')
        ->and($bodies[2])->not->toContain('customer-job-output-xyz');
});

test('the dns page lists the zone\'s records and the cron page its recent runs', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $zone = customerResource(DnsZone::class);
    DnsRecord::factory()->for($zone, 'dnsZone')->create(['name' => 'www', 'value' => '192.0.2.10']);
    $job = customerResource(CronJob::class);
    CronJobExecution::create(['cron_job_id' => $job->id, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'exit_code' => 7, 'output' => '']);

    $this->actingAs($admin)
        ->get(route('customer-resources.show', ['dns', $zone->uuid]))
        ->assertInertia(fn (Assert $page) => $page->where('resource.tables.0.rows.0.1', 'www')->where('resource.tables.0.rows.0.3', '192.0.2.10'));

    $this->actingAs($admin)
        ->get(route('customer-resources.show', ['cron-jobs', $job->uuid]))
        ->assertInertia(fn (Assert $page) => $page->where('resource.tables.0.rows.0.2', '7'));
});
