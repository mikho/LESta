<?php

use App\Enums\ProvisioningVerb;
use App\Jobs\UpdateWebCapabilityCertificate;
use App\Models\AccountNodeIdentity;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A database with an owner and one extra user, on a node whose hostname has (or lacks) the
 * certificate remote access needs.
 *
 * @return array{0: TenantDatabase, 1: User, 2: TenantDatabaseUser}
 */
function remoteAccessDatabase(bool $withCertificate = true): array
{
    $node = Node::factory()->create(['hostname' => 'Node-1.Example.test']);
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    if ($withCertificate) {
        $toolsDomain = WebDomain::factory()->for($node)->create(['domain' => 'node-1.example.test', 'certificate_issued_at' => now()]);
        AccountNodeIdentity::factory()->for($toolsDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$toolsDomain->account_id]);
    }
    $tenantDatabase = TenantDatabase::factory()->for($node)->create(['database_name' => 'lesta_7_shop', 'database_user' => 'lesta_7_shop', 'stats_user' => 'lesta_7_shop_ro']);
    $user = TenantDatabaseUser::factory()->for($tenantDatabase)->readOnly()->create(['label' => 'report', 'username' => 'lesta_7_shop_report']);

    return [$tenantDatabase, Membership::factory()->for($tenantDatabase->account)->owner()->create()->user, $user];
}

/**
 * @return array<string, mixed>
 */
function latestRemoteAccessPayload(TenantDatabase $tenantDatabase): array
{
    return ProvisioningOperation::query()->where('provisionable_id', $tenantDatabase->id)->latest('id')->firstOrFail()->payload;
}

test('the owner gives a user remote hosts as text: they are canonical, de-duplicated and sent with the node hostname', function () {
    [$tenantDatabase, $owner, $user] = remoteAccessDatabase();

    $this->actingAs($owner)
        ->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['remote_hosts' => "203.0.113.10\n 2001:DB8:0:0::10 ,203.0.113.10\n"])
        ->assertRedirect(route('tenant-databases.edit', $tenantDatabase));

    expect($user->refresh()->remoteHosts())->toBe(['203.0.113.10', '2001:db8::10'])
        ->and($user->access->value)->toBe('read_only')
        ->and(latestRemoteAccessPayload($tenantDatabase)['users'])->toBe([
            ['name' => 'lesta_7_shop_report', 'access' => 'read_only', 'remote_hosts' => ['203.0.113.10', '2001:db8::10']],
        ])
        ->and(latestRemoteAccessPayload($tenantDatabase)['tls_hostname'])->toBe('node-1.example.test')
        ->and($tenantDatabase->refresh()->desired_state_version)->toBe(2);

    $this->actingAs($owner)->get(route('tenant-databases.edit', $tenantDatabase))->assertInertia(fn (Assert $page) => $page
        ->where('users.0.remote_hosts', ['203.0.113.10', '2001:db8::10'])
        ->where('remoteAccess.host', 'node-1.example.test')
        ->where('remoteAccess.port', 3307)
        ->where('remoteAccess.max_hosts', TenantDatabaseUser::MAX_REMOTE_HOSTS));
});

test('clearing the list makes the user local only and the payload drops the hostname', function () {
    [$tenantDatabase, $owner, $user] = remoteAccessDatabase();
    $user->update(['remote_hosts' => ['203.0.113.10']]);

    $this->actingAs($owner)->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['remote_hosts' => ''])->assertRedirect();

    expect($user->refresh()->remoteHosts())->toBe([])
        ->and(latestRemoteAccessPayload($tenantDatabase)['users'])->toBe([['name' => 'lesta_7_shop_report', 'access' => 'read_only']])
        ->and(latestRemoteAccessPayload($tenantDatabase))->not->toHaveKey('tls_hostname');
});

test('a node without a certificate for its hostname refuses remote hosts, and the page says why', function () {
    [$tenantDatabase, $owner, $user] = remoteAccessDatabase(withCertificate: false);

    $this->actingAs($owner)
        ->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['remote_hosts' => '203.0.113.10'])
        ->assertSessionHasErrors('remote_hosts');
    $this->actingAs($owner)
        ->post(route('tenant-databases.users.store', $tenantDatabase), ['label' => 'admin', 'access' => 'full', 'remote_hosts' => '203.0.113.10'])
        ->assertSessionHasErrors('remote_hosts');

    expect($user->refresh()->remoteHosts())->toBe([])
        ->and($tenantDatabase->users()->count())->toBe(1);

    $this->actingAs($owner)->get(route('tenant-databases.edit', $tenantDatabase))->assertInertia(fn (Assert $page) => $page
        ->where('remoteAccess', null));
});

test('only single addresses a remote client can have are accepted', function (string $host) {
    [$tenantDatabase, $owner, $user] = remoteAccessDatabase();

    $this->actingAs($owner)
        ->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['remote_hosts' => $host])
        ->assertSessionHasErrors('remote_hosts.0');

    expect($user->refresh()->remoteHosts())->toBe([]);
})->with([
    'a range' => '203.0.113.0/24',
    'a hostname' => 'db.example.com',
    'a wildcard' => '%',
    'loopback' => '127.0.0.1',
    'another loopback' => '127.1.2.3',
    'IPv6 loopback' => '::1',
    'unspecified' => '0.0.0.0',
    'IPv6 unspecified' => '::',
    'link-local' => '169.254.10.1',
    'IPv6 link-local' => 'fe80::1',
    'multicast' => '224.0.0.1',
    'IPv6 multicast' => 'ff02::1',
    'IPv4 written as IPv6' => '::ffff:203.0.113.5',
]);

test('private addresses are accepted, and a list longer than the limit is not', function () {
    [$tenantDatabase, $owner, $user] = remoteAccessDatabase();
    $update = fn (string $hosts) => $this->actingAs($owner)->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['remote_hosts' => $hosts]);

    $update("10.0.0.5\n192.168.1.20\nfd00::5")->assertSessionHasNoErrors();
    expect($user->refresh()->remoteHosts())->toBe(['10.0.0.5', '192.168.1.20', 'fd00::5']);

    $tooMany = implode("\n", array_map(fn (int $i): string => "203.0.113.{$i}", range(1, TenantDatabaseUser::MAX_REMOTE_HOSTS + 1)));
    $update($tooMany)->assertSessionHasErrors('remote_hosts');
    expect($user->refresh()->remoteHosts())->toHaveCount(3);
});

test('the API sets remote hosts on create and on a change, and a change of access alone keeps them', function () {
    [$tenantDatabase, $owner] = remoteAccessDatabase();
    ApiToken::factory()->for($owner)->withPlaintext('lesta_remote')->create(['abilities' => ['databases:read', 'databases:write']]);
    $base = '/api/v1/accounts/'.$tenantDatabase->account->public_id.'/databases/'.$tenantDatabase->uuid.'/users';
    $auth = ['Authorization' => 'Bearer lesta_remote'];

    $created = $this->postJson($base, ['label' => 'admin', 'access' => 'full', 'remote_hosts' => ['198.51.100.7']], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.remote_hosts', ['198.51.100.7']);
    $path = $base.'/'.$created->json('data.id');

    $this->patchJson($path, ['access' => 'read_only'], $auth)->assertAccepted()
        ->assertJsonPath('data.access', 'read_only')
        ->assertJsonPath('data.remote_hosts', ['198.51.100.7']);
    $this->patchJson($path, ['remote_hosts' => ['198.51.100.7', '2001:db8::7']], $auth)->assertAccepted()
        ->assertJsonPath('data.access', 'read_only')
        ->assertJsonPath('data.remote_hosts', ['198.51.100.7', '2001:db8::7']);
    $this->patchJson($path, ['remote_hosts' => ['198.51.100.0/24']], $auth)->assertUnprocessable();

    $this->getJson($path, $auth)->assertOk()->assertJsonPath('data.remote_hosts', ['198.51.100.7', '2001:db8::7']);
});

test('a new certificate for the node hostname is handed to one database with remote access on that node', function () {
    [$tenantDatabase, , $user] = remoteAccessDatabase();
    $user->update(['remote_hosts' => ['203.0.113.10']]);
    NodeCapability::factory()->for($tenantDatabase->node)->create(['capability' => 'web.nginx.v1']);
    $toolsDomain = WebDomain::query()->where('domain', 'node-1.example.test')->sole();

    (new UpdateWebCapabilityCertificate($toolsDomain))->handle();

    $operation = ProvisioningOperation::query()->where('provisionable_id', $tenantDatabase->id)->where('provisionable_type', $tenantDatabase->getMorphClass())->sole();
    expect($operation->operation)->toBe(ProvisioningVerb::Update)
        ->and($operation->capability)->toBe('database.tenant.v1')
        ->and($operation->payload['tls_hostname'])->toBe('node-1.example.test')
        ->and($operation->payload)->not->toHaveKey('password')
        ->and($operation->desired_state_version)->toBe($tenantDatabase->desired_state_version);
});

test('a certificate for any other domain, or a node without remote access, sends nothing to the databases', function () {
    [$tenantDatabase] = remoteAccessDatabase();
    NodeCapability::factory()->for($tenantDatabase->node)->create(['capability' => 'web.nginx.v1']);
    $customerDomain = WebDomain::factory()->for($tenantDatabase->node)->create(['domain' => 'shop.example.test', 'certificate_issued_at' => now()]);
    AccountNodeIdentity::factory()->for($customerDomain->account)->for($tenantDatabase->node)->create(['system_username' => 'lesta-t'.$customerDomain->account_id]);
    $toolsDomain = WebDomain::query()->where('domain', 'node-1.example.test')->sole();

    (new UpdateWebCapabilityCertificate($customerDomain))->handle();
    (new UpdateWebCapabilityCertificate($toolsDomain))->handle();

    expect(ProvisioningOperation::query()->where('provisionable_type', $tenantDatabase->getMorphClass())->exists())->toBeFalse();
});
