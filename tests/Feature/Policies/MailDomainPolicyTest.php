<?php

use App\Enums\RoleScope;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('mail domain authorization matrix: owner, member, stranger, admin with the full catalog', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create();
    $account = $mailDomain->account;
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('suspend', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('unsuspend', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [MailDomain::class, $account]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [MailDomain::class, $account]))->toBeTrue()

        ->and(Gate::forUser($member)->allows('view', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [MailDomain::class, $account]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $mailDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('suspend', $mailDomain))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', [MailDomain::class, $account]))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $mailDomain))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [MailDomain::class, $account]))->toBeFalse()

        // A provider admin with the full permission catalog can suspend/unsuspend/delete (the
        // same accounts.* permission that authorizes SuspendAccount/UnsuspendAccount/
        // DeleteAccount's own cascade into this domain), but never update or view: those stay
        // owner-scoped only, mirroring WebDomainPolicy/DnsZonePolicy exactly.
        ->and(Gate::forUser($admin)->allows('suspend', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('unsuspend', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $mailDomain))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $mailDomain))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $mailDomain))->toBeFalse();
});

test('a platform role without accounts.suspend cannot suspend a mail domain', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create();

    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'accounts.view_as_support'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    expect(Gate::forUser($limitedAdmin)->allows('suspend', $mailDomain))->toBeFalse();
});
