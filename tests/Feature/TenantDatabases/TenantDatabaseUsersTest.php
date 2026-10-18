<?php

use App\Enums\DatabaseUserAccess;
use App\Enums\ProvisioningVerb;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: TenantDatabase, 1: User}
 */
function databaseWithOwner(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'database.tenant.v1']);
    $tenantDatabase = TenantDatabase::factory()->for($node)->create(['database_name' => 'lesta_7_shop', 'database_user' => 'lesta_7_shop', 'stats_user' => 'lesta_7_shop_ro']);

    return [$tenantDatabase, Membership::factory()->for($tenantDatabase->account)->owner()->create()->user];
}

/**
 * @return array<string, mixed>
 */
function latestDatabasePayload(TenantDatabase $tenantDatabase): array
{
    return ProvisioningOperation::query()->where('provisionable_id', $tenantDatabase->id)->latest('id')->firstOrFail()->payload;
}

test('the owner adds a read-only user: its password is shown once, sent once and never stored', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();

    $this->actingAs($owner)
        ->post(route('tenant-databases.users.store', $tenantDatabase), ['label' => 'report', 'access' => 'read_only'])
        ->assertRedirect(route('tenant-databases.edit', $tenantDatabase))
        ->assertInertiaFlash('generatedPassword.database_user', 'lesta_7_shop_report');

    $user = TenantDatabaseUser::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_id', $tenantDatabase->id)->latest('id')->firstOrFail();
    $sent = $operation->payload['users'][0];

    expect($user->username)->toBe('lesta_7_shop_report')
        ->and($user->access)->toBe(DatabaseUserAccess::ReadOnly)
        ->and($operation->operation)->toBe(ProvisioningVerb::Update)
        ->and($operation->payload)->not->toHaveKey('password')
        ->and($sent['name'])->toBe('lesta_7_shop_report')
        ->and($sent['access'])->toBe('read_only')
        ->and($sent['password'])->toMatch('/^[0-9a-f]{48}$/')
        ->and(json_encode($user->getAttributes()))->not->toContain($sent['password'])
        ->and(json_encode(AuditEvent::query()->where('action', 'tenant_database.user_created')->sole()->getAttributes()))->not->toContain($sent['password'])
        ->and($tenantDatabase->refresh()->desired_state_version)->toBe(2);

    $this->actingAs($owner)->get(route('tenant-databases.edit', $tenantDatabase))->assertInertia(fn (Assert $page) => $page
        ->where('users.0.username', 'lesta_7_shop_report')
        ->where('users.0.access', 'read_only')
        ->where('maxUsers', TenantDatabaseUser::MAX_PER_DATABASE));
});

test('later operations list the user without its password, and only a rotation sends a new one', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();
    $user = TenantDatabaseUser::factory()->for($tenantDatabase)->create(['label' => 'admin', 'username' => 'lesta_7_shop_admin']);
    $other = TenantDatabaseUser::factory()->for($tenantDatabase)->readOnly()->create(['label' => 'report', 'username' => 'lesta_7_shop_report']);

    $this->actingAs($owner)->patch(route('tenant-databases.users.update', [$tenantDatabase, $user]), ['access' => 'read_only'])->assertRedirect();
    expect($user->refresh()->access)->toBe(DatabaseUserAccess::ReadOnly)
        ->and(latestDatabasePayload($tenantDatabase)['users'])->toBe([
            ['name' => 'lesta_7_shop_admin', 'access' => 'read_only'],
            ['name' => 'lesta_7_shop_report', 'access' => 'read_only'],
        ]);

    $this->actingAs($owner)->post(route('tenant-databases.users.rotate-password', [$tenantDatabase, $user]))
        ->assertInertiaFlash('generatedPassword.database_user', 'lesta_7_shop_admin');
    $users = latestDatabasePayload($tenantDatabase)['users'];
    expect($users[0]['password'])->toMatch('/^[0-9a-f]{48}$/')
        ->and($users[1])->not->toHaveKey('password');

    $this->actingAs($owner)->post(route('tenant-databases.suspend', $tenantDatabase));
    expect(latestDatabasePayload($tenantDatabase)['users'])->toBe([
        ['name' => 'lesta_7_shop_admin', 'access' => 'read_only'],
        ['name' => 'lesta_7_shop_report', 'access' => 'read_only'],
    ]);

    $this->actingAs($owner)->delete(route('tenant-databases.users.destroy', [$tenantDatabase, $other]))->assertRedirect();
    expect(TenantDatabaseUser::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and(latestDatabasePayload($tenantDatabase)['users'])->toBe([['name' => 'lesta_7_shop_admin', 'access' => 'read_only']]);
});

test('labels must fit the user name scheme, be unique on the database and stay under the limit', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();
    TenantDatabaseUser::factory()->for($tenantDatabase)->create(['label' => 'report', 'username' => 'lesta_7_shop_report']);
    $store = fn (array $data) => $this->actingAs($owner)->post(route('tenant-databases.users.store', $tenantDatabase), $data);

    $store(['label' => 'ro', 'access' => 'full'])->assertSessionHasErrors('label');
    $store(['label' => 'Report_1', 'access' => 'full'])->assertSessionHasErrors('label');
    $store(['label' => str_repeat('a', 17), 'access' => 'full'])->assertSessionHasErrors('label');
    $store(['label' => 'report', 'access' => 'full'])->assertSessionHasErrors('label');
    $store(['label' => 'admin', 'access' => 'owner'])->assertSessionHasErrors('access');

    TenantDatabaseUser::factory()->for($tenantDatabase)->count(TenantDatabaseUser::MAX_PER_DATABASE - 1)->create();
    $store(['label' => 'onetoomany', 'access' => 'full'])->assertSessionHasErrors('label');

    expect($tenantDatabase->users()->count())->toBe(TenantDatabaseUser::MAX_PER_DATABASE);
});

test('a member cannot manage users, and another database\'s user is not reachable through this one', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();
    $member = Membership::factory()->for($tenantDatabase->account)->member()->create()->user;
    $user = TenantDatabaseUser::factory()->for($tenantDatabase)->create();
    $otherDatabase = TenantDatabase::factory()->for($tenantDatabase->account)->for($tenantDatabase->node)->create();

    $this->actingAs($member)->post(route('tenant-databases.users.store', $tenantDatabase), ['label' => 'admin', 'access' => 'full'])->assertForbidden();
    $this->actingAs($member)->delete(route('tenant-databases.users.destroy', [$tenantDatabase, $user]))->assertForbidden();
    $this->actingAs($owner)->delete(route('tenant-databases.users.destroy', [$otherDatabase, $user]))->assertNotFound();

    expect($user->fresh())->not->toBeNull();
});

test('deleting the database removes its users and the delete names them for the node', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();
    TenantDatabaseUser::factory()->for($tenantDatabase)->create(['label' => 'admin', 'username' => 'lesta_7_shop_admin']);

    $this->actingAs($owner)->delete(route('tenant-databases.destroy', $tenantDatabase))->assertRedirect();

    $operation = ProvisioningOperation::query()->where('provisionable_id', $tenantDatabase->id)->where('operation', ProvisioningVerb::Delete)->sole();
    expect($operation->payload['users'])->toBe([['name' => 'lesta_7_shop_admin', 'access' => 'full']])
        ->and(TenantDatabaseUser::query()->count())->toBe(0);
});

test('the API adds, lists, changes, rotates and removes a user, each answer with the database\'s operation', function () {
    [$tenantDatabase, $owner] = databaseWithOwner();
    ApiToken::factory()->for($owner)->withPlaintext('lesta_dbusers')->create(['abilities' => ['databases:read', 'databases:write']]);
    $base = '/api/v1/accounts/'.$tenantDatabase->account->public_id.'/databases/'.$tenantDatabase->uuid.'/users';
    $auth = ['Authorization' => 'Bearer lesta_dbusers'];

    $created = $this->postJson($base, ['label' => 'report', 'access' => 'read_only'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.username', 'lesta_7_shop_report')
        ->assertJsonPath('data.access', 'read_only');
    expect($created->json('data.password'))->toMatch('/^[0-9a-f]{48}$/');
    $path = $base.'/'.$created->json('data.id');

    $this->getJson($base, $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password');
    $this->getJson($path, $auth)->assertOk()->assertJsonPath('data.label', 'report');
    $this->patchJson($path, ['access' => 'full'], $auth)->assertAccepted()->assertJsonPath('data.access', 'full');

    $rotated = $this->postJson($path.'/rotate-password', [], $auth)->assertAccepted();
    expect($rotated->json('data.password'))->toMatch('/^[0-9a-f]{48}$/')->not->toBe($created->json('data.password'));

    $this->deleteJson($path, [], $auth)->assertAccepted()->assertJsonPath('data', null);
    $this->getJson($path, $auth)->assertNotFound();
});
