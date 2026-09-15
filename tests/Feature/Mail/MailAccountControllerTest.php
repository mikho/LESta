<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithMailDomainAndCapableNode(): array
{
    $package = Package::factory()->withLimit('mail_accounts', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    return [$account, $owner, $node, $mailDomain];
}

test('storing a mail account redirects to the domain edit page and flashes the one-time password', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();

    $this->actingAs($owner)
        ->post(route('mail.accounts.store', $mailDomain), ['local_part' => 'info'])
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect(MailAccount::where('mail_domain_id', $mailDomain->id)->where('local_part', 'info')->exists())->toBeTrue();

    $password = session('inertia.flash_data')['mailAccountPassword'] ?? null;
    expect($password)->toBeString()->not->toBe('');
});

test('a mail account never appears with its password in the domain edit page props', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales']);

    $this->actingAs($owner)
        ->get(route('mail.edit', $mailDomain))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/edit')
            ->has('mailDomain.accounts', 1)
            ->missing('mailDomain.accounts.0.password')
        );
});

test('storing a mail account over the per-domain quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->withLimit('mail_accounts', 0)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->post(route('mail.accounts.store', $mailDomain), ['local_part' => 'info'])
        ->assertSessionHasErrors('local_part');
});

test('updating a mail account redirects to the domain edit page', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['quota_mb' => 100]);

    $this->actingAs($owner)
        ->put(route('mail.accounts.update', [$mailDomain, $mailAccount]), ['quota_mb' => 500])
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect($mailAccount->refresh()->quota_mb)->toBe(500);
});

test('rotating a mail account password redirects back and flashes the new one-time password', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $this->actingAs($owner)
        ->post(route('mail.accounts.rotate-password', [$mailDomain, $mailAccount]))
        ->assertRedirect(route('mail.edit', $mailDomain));

    $password = session('inertia.flash_data')['mailAccountPassword'] ?? null;
    expect($password)->toBeString()->not->toBe('');
});

test('suspending a mail account redirects back', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $this->actingAs($owner)
        ->from(route('mail.edit', $mailDomain))
        ->post(route('mail.accounts.suspend', [$mailDomain, $mailAccount]))
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect($mailAccount->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a mail account redirects back', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $mailAccount = MailAccount::factory()->for($mailDomain)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('mail.edit', $mailDomain))
        ->post(route('mail.accounts.unsuspend', [$mailDomain, $mailAccount]))
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect($mailAccount->refresh()->isSuspended())->toBeFalse();
});

test('destroying a mail account redirects to the domain edit page', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $this->actingAs($owner)
        ->delete(route('mail.accounts.destroy', [$mailDomain, $mailAccount]))
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect(MailAccount::find($mailAccount->id))->toBeNull();
});

test('a non-owner member is forbidden from storing a mail account', function () {
    [$account, $owner, $node, $mailDomain] = actingAsOwnerWithMailDomainAndCapableNode();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)
        ->post(route('mail.accounts.store', $mailDomain), ['local_part' => 'info'])
        ->assertForbidden();
});

test('a mail account belonging to a different domain than the url cannot be updated, returning 404', function () {
    [$accountA, $ownerA, $nodeA, $mailDomainA] = actingAsOwnerWithMailDomainAndCapableNode();
    $nodeB = Node::factory()->create();
    NodeCapability::factory()->for($nodeB)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomainB = MailDomain::factory()->for($accountA)->for($nodeB)->create();
    $mailAccountOnDomainB = MailAccount::factory()->for($mailDomainB)->create();

    $this->actingAs($ownerA)
        ->put(route('mail.accounts.update', [$mailDomainA, $mailAccountOnDomainB]), ['quota_mb' => 200])
        ->assertNotFound();
});
