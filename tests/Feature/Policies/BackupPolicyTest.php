<?php

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Membership;
use App\Models\Node;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\BackupPolicy;
use Illuminate\Support\Facades\Gate;

test('backup authorization: only an admin with the full permission catalog passes, never an owner or member', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $backup))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewAny', Backup::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', Backup::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $backup))->toBeFalse()

        ->and(Gate::forUser($member)->allows('view', $backup))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', Backup::class))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $backup))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', Backup::class))->toBeFalse()

        ->and(Gate::forUser($admin)->allows('view', $backup))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', Backup::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Backup::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $backup))->toBeTrue();
});

test('a platform role without backups.* permissions cannot view or create backups, even though it is otherwise a real admin role', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->for($node)->create();

    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'nodes.view'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('view', $backup))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('create', Backup::class))->toBeFalse()
        ->and(Gate::forUser($limitedAdmin)->allows('delete', $backup))->toBeFalse();
});

test('BackupPolicy exposes no update, suspend, or unsuspend ability: a backup is created or deleted, never mutated in between', function () {
    expect(method_exists(BackupPolicy::class, 'update'))->toBeFalse()
        ->and(method_exists(BackupPolicy::class, 'suspend'))->toBeFalse()
        ->and(method_exists(BackupPolicy::class, 'unsuspend'))->toBeFalse();
});
