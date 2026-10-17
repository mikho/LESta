<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebDomain;

/**
 * A platform administrator with every permission, or only $permissions, and a token.
 *
 * @param  list<string>|null  $permissions
 * @param  list<string>  $abilities
 * @return array{0: User, 1: array<string, string>}
 */
function adminToken(?array $permissions = null, array $abilities = ['admin:read', 'admin:write']): array
{
    if ($permissions === null) {
        $user = Membership::factory()->providerAdmin()->create()->user;
    } else {
        $role = Role::query()->create(['name' => 'limited-'.fake()->unique()->numerify('####'), 'scope' => RoleScope::Platform]);
        $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id));
        $user = Membership::factory()->create(['account_id' => null, 'role_id' => $role->id])->user;
    }

    $plaintext = ApiToken::PREFIX.'admin'.fake()->unique()->numerify('########');
    ApiToken::factory()->for($user)->withPlaintext($plaintext)->create(['abilities' => $abilities]);

    return [$user, ['Authorization' => 'Bearer '.$plaintext]];
}

test('an admin lists, searches and reads accounts, never the platform account', function () {
    [, $auth] = adminToken();
    $shop = Account::factory()->create(['name' => 'Shop Ltd']);
    Account::factory()->create(['name' => 'Bakery']);
    $platform = Account::factory()->create(['name' => 'Platform', 'is_platform' => true]);

    $names = collect($this->getJson('/api/v1/admin/accounts', $auth)->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Shop Ltd', 'Bakery')->not->toContain('Platform');

    $this->getJson('/api/v1/admin/accounts?filter[search]=shop', $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shop->public_id);
    $this->getJson('/api/v1/admin/accounts/'.$shop->public_id, $auth)->assertOk()->assertJsonPath('data.package.id', $shop->package->uuid);
    $this->getJson('/api/v1/admin/accounts/'.$platform->public_id, $auth)->assertNotFound();
});

test('an admin creates an account with its owner, changes it, and suspends and unsuspends it', function () {
    [$admin, $auth] = adminToken();
    $package = Package::factory()->create(['is_active' => true]);
    $other = Package::factory()->create(['is_active' => true]);

    $created = $this->postJson('/api/v1/admin/accounts', [
        'name' => 'New Customer', 'package' => $package->uuid, 'owner_name' => 'Ada', 'owner_email' => 'ada@example.com',
    ], $auth)->assertCreated()->assertJsonPath('data.name', 'New Customer')->assertJsonPath('operation', null);

    $account = Account::query()->where('public_id', $created->json('data.id'))->sole();
    expect($account->memberships()->sole()->user->email)->toBe('ada@example.com')
        ->and(AuditEvent::query()->where('action', 'account.created')->value('api_token_id'))->not->toBeNull();

    $path = '/api/v1/admin/accounts/'.$account->public_id;
    $this->patchJson($path, ['package' => $other->uuid], $auth)->assertOk()->assertJsonPath('data.package.id', $other->uuid)->assertJsonPath('data.name', 'New Customer');
    $this->postJson($path.'/suspend', [], $auth)->assertOk()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertOk()->assertJsonPath('data.suspended', false);
});

test('a suspended account can still be changed by an admin', function () {
    [, $auth] = adminToken();
    $account = Account::factory()->create();
    $account->suspend();

    $this->postJson('/api/v1/admin/accounts/'.$account->public_id.'/unsuspend', [], $auth)->assertOk()->assertJsonPath('data.suspended', false);
});

test('deleting an account needs its id as confirmation', function () {
    [, $auth] = adminToken();
    $account = Account::factory()->create();
    $path = '/api/v1/admin/accounts/'.$account->public_id;

    $this->deleteJson($path, [], $auth)->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->deleteJson($path, ['confirm' => 'wrong'], $auth)->assertUnprocessable();
    $this->deleteJson($path, ['confirm' => $account->public_id], $auth)->assertOk()->assertJsonPath('data', null);

    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse();
});

test('a reseller is assigned and removed', function () {
    [, $auth] = adminToken();
    $account = Account::factory()->create();
    $reseller = Account::factory()->create();
    $path = '/api/v1/admin/accounts/'.$account->public_id.'/reseller';

    $this->putJson($path, ['reseller_account_id' => $reseller->public_id], $auth)->assertOk()->assertJsonPath('data.reseller_account_id', $reseller->public_id);
    $this->deleteJson($path, [], $auth)->assertOk()->assertJsonPath('data.reseller_account_id', null);
});

test('packages are listed, created, changed in part, limited and deleted', function () {
    [, $auth] = adminToken();

    $created = $this->postJson('/api/v1/admin/packages', ['name' => 'Small', 'is_active' => true], $auth)
        ->assertCreated()
        ->assertJsonPath('data.limits.web_domains', ['configured' => false, 'limit' => null]);

    $path = '/api/v1/admin/packages/'.$created->json('data.id');

    $this->putJson($path.'/limits/web_domains', ['limit_value' => 3], $auth)->assertOk()->assertJsonPath('data.limits.web_domains', ['configured' => true, 'limit' => 3]);
    $this->putJson($path.'/limits/mail_accounts', ['limit_value' => null], $auth)->assertOk()->assertJsonPath('data.limits.mail_accounts', ['configured' => true, 'limit' => null]);
    $this->putJson($path.'/limits/memberships', ['limit_value' => 1], $auth)->assertNotFound();
    $this->patchJson($path, ['description' => 'Three sites'], $auth)->assertOk()->assertJsonPath('data.description', 'Three sites')->assertJsonPath('data.is_active', true);

    expect(collect($this->getJson('/api/v1/admin/packages', $auth)->assertOk()->json('data'))->pluck('name'))->toContain('Small');

    $this->deleteJson($path, [], $auth)->assertOk();
    expect(Package::query()->where('name', 'Small')->exists())->toBeFalse();
});

test('nodes are listed with their state, and suspended and unsuspended', function () {
    [, $auth] = adminToken();
    $node = Node::factory()->create(['name' => 'web-1', 'service_states' => [['name' => 'nginx', 'state' => 'active']]]);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $this->getJson('/api/v1/admin/nodes', $auth)->assertOk()->assertJsonPath('data.0.name', 'web-1')->assertJsonMissingPath('data.0.node_credential_hash');
    $this->getJson('/api/v1/admin/nodes/'.$node->uuid, $auth)->assertOk()
        ->assertJsonPath('data.services', [['name' => 'nginx', 'state' => 'active']])
        ->assertJsonPath('data.capabilities.0.capability', 'web.nginx.v1');

    $this->postJson('/api/v1/admin/nodes/'.$node->uuid.'/suspend', [], $auth)->assertOk()->assertJsonPath('data.suspended', true);
    $this->postJson('/api/v1/admin/nodes/'.$node->uuid.'/unsuspend', [], $auth)->assertOk()->assertJsonPath('data.suspended', false);
});

test('whole-node backups are listed, started and deleted, never showing their key', function () {
    [, $auth] = adminToken();
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $old = Backup::factory()->for($node)->completed()->create();

    $this->getJson('/api/v1/admin/node-backups', $auth)->assertOk()->assertJsonPath('data.0.status', 'ready')->assertDontSee($old->encryption_key);

    $created = $this->postJson('/api/v1/admin/node-backups', ['node' => $node->uuid, 'label' => 'before upgrade'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.label', 'before upgrade')
        ->assertJsonPath('data.node.id', $node->uuid);

    $this->getJson('/api/v1/admin/node-backups/'.$created->json('data.id'), $auth)->assertOk();
    $this->deleteJson('/api/v1/admin/node-backups/'.$old->uuid, [], $auth)->assertAccepted();
});

test('cross-account lists show every customer resource with its account, filtered', function () {
    [, $auth] = adminToken();
    $a = WebDomain::factory()->create(['domain' => 'one.example']);
    WebDomain::factory()->create(['domain' => 'two.example']);
    WebDomain::factory()->for(Account::factory()->create(['is_platform' => true]))->create(['domain' => 'panel.example']);

    $domains = collect($this->getJson('/api/v1/admin/web-domains', $auth)->assertOk()->json('data'));
    expect($domains->pluck('domain')->sort()->values()->all())->toBe(['one.example', 'two.example']);

    $this->getJson('/api/v1/admin/web-domains?filter[account]='.$a->account->public_id, $auth)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.account_id', $a->account->public_id);
    $this->getJson('/api/v1/admin/web-domains?filter[domain]=TWO.example', $auth)->assertOk()->assertJsonCount(1, 'data');

    foreach (['dns-zones', 'mail-domains', 'databases', 'cron-jobs'] as $list) {
        $this->getJson('/api/v1/admin/'.$list, $auth)->assertOk();
    }
});

test('each admin route needs its own panel permission', function () {
    [, $auth] = adminToken(['packages.view_any', 'packages.view']);
    $account = Account::factory()->create();

    $this->getJson('/api/v1/admin/packages', $auth)->assertOk();
    $this->getJson('/api/v1/admin/accounts', $auth)->assertForbidden()->assertJsonPath('type', 'urn:lesta:problem:missing-permission');
    $this->postJson('/api/v1/admin/accounts/'.$account->public_id.'/suspend', [], $auth)->assertForbidden();
    $this->getJson('/api/v1/admin/web-domains', $auth)->assertForbidden();

    expect($account->refresh()->isSuspended())->toBeFalse();
});

test('admin routes need the admin abilities', function () {
    [, $readOnly] = adminToken(null, ['admin:read']);
    [, $tenantOnly] = adminToken(null, ['web:read', 'web:write']);
    $account = Account::factory()->create();

    $this->getJson('/api/v1/admin/accounts', $readOnly)->assertOk();
    $this->postJson('/api/v1/admin/accounts/'.$account->public_id.'/suspend', [], $readOnly)->assertForbidden()->assertJsonPath('type', 'urn:lesta:problem:missing-ability');
    $this->getJson('/api/v1/admin/accounts', $tenantOnly)->assertForbidden();
});

test('an account owner cannot use the admin surface on their own account', function () {
    $membership = Membership::factory()->owner()->create();
    ApiToken::factory()->for($membership->user)->withPlaintext('lesta_owner_admin')->create(['abilities' => ['admin:read', 'admin:write']]);
    $auth = ['Authorization' => 'Bearer lesta_owner_admin'];

    $this->getJson('/api/v1/admin/accounts/'.$membership->account->public_id, $auth)->assertForbidden();
    $this->postJson('/api/v1/admin/accounts/'.$membership->account->public_id.'/suspend', [], $auth)->assertForbidden();

    expect($membership->account->refresh()->isSuspended())->toBeFalse();
});
