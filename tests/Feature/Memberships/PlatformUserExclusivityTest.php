<?php

use App\Actions\Accounts\CreateAccount;
use App\Actions\Memberships\InviteMember;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('a platform administrator cannot be invited into an account', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(fn () => app(InviteMember::class)->handle($owner, $account, $admin->name, $admin->email, 'member'))
        ->toThrow(ValidationException::class);

    expect(Membership::where('user_id', $admin->id)->whereNotNull('account_id')->exists())->toBeFalse();
});

test('a platform administrator cannot be made the owner of a new account', function () {
    $actor = Membership::factory()->providerAdmin()->create()->user;
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $package = Package::factory()->create();

    expect(fn () => app(CreateAccount::class)->handle($actor, [
        'name' => 'Admin Corp',
        'contact_email' => null,
        'package_id' => $package->id,
        'owner_name' => $admin->name,
        'owner_email' => $admin->email,
    ]))->toThrow(ValidationException::class);

    expect(Account::where('name', 'Admin Corp')->exists())->toBeFalse();
});

test('the membership model itself refuses to mix platform and account memberships, in either order', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $ownerRole = Role::query()->firstOrCreate(['name' => 'owner'], ['scope' => 'account']);

    expect(fn () => Membership::create(['user_id' => $admin->id, 'account_id' => Account::factory()->create()->id, 'role_id' => $ownerRole->id]))
        ->toThrow(LogicException::class);

    $member = User::factory()->create();
    Membership::factory()->for(Account::factory()->create())->owner()->create(['user_id' => $member->id]);
    $platformRole = Role::query()->where('name', 'provider_admin')->firstOrFail();

    expect(fn () => Membership::create(['user_id' => $member->id, 'account_id' => null, 'role_id' => $platformRole->id]))
        ->toThrow(LogicException::class);
});
