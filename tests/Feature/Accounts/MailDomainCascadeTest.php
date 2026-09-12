<?php

use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnsuspendAccount;
use App\Enums\RoleScope;
use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;

test('account suspend cascades to active mail domains and unsuspend reactivates only cascade-sourced ones', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $preManuallySuspended = MailDomain::factory()->suspended()->for($account)->for($node)->create();
    $active = MailDomain::factory()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->refresh()->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($active->refresh()->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($active->isSuspended())->toBeTrue();

    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeFalse()
        ->and($active->refresh()->isSuspended())->toBeFalse()
        ->and($preManuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('a mail domain suspended individually before the account suspend stays suspended after the account unsuspends', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $manuallySuspended = MailDomain::factory()->suspended()->for($account)->for($node)->create();

    app(SuspendAccount::class)->handle($owner, $account);
    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($manuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($manuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('deleting an account cascades to delete every owned mail domain', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $first = MailDomain::factory()->for($account)->for($node)->create();
    $second = MailDomain::factory()->suspended()->for($account)->for($node)->create();

    app(DeleteAccount::class)->handle($owner, $account);

    expect(MailDomain::find($first->id))->toBeNull()
        ->and(MailDomain::find($second->id))->toBeNull()
        ->and(Account::find($account->id))->toBeNull();
});

test('account suspend cascades three levels deep to mail accounts through the domain, and unsuspend reverses it', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    app(SuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeTrue()
        ->and($mailDomain->refresh()->isSuspended())->toBeTrue()
        ->and($mailDomain->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($mailAccount->refresh()->isSuspended())->toBeTrue()
        ->and($mailAccount->suspension_source)->toBe(SuspensionSource::Cascade);

    app(UnsuspendAccount::class)->handle($owner, $account);

    expect($account->refresh()->isSuspended())->toBeFalse()
        ->and($mailDomain->refresh()->isSuspended())->toBeFalse()
        ->and($mailAccount->refresh()->isSuspended())->toBeFalse();
});

test('a provider admin without accounts.suspend cannot trigger the cascade into a real mail domain', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    MailDomain::factory()->for($account)->for($node)->create();

    $limitedRole = Role::factory()->create(['scope' => RoleScope::Platform]);
    $limitedRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'accounts.view_as_support'])->id);
    $limitedAdmin = Membership::factory()->create(['account_id' => null, 'role_id' => $limitedRole->id])->user;

    app(SuspendAccount::class)->handle($limitedAdmin, $account);
})->throws(AuthorizationException::class);
