<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function actingAsOwnerWithMailCapableAccount(): array
{
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    return [$account, $owner, $node];
}

test('the index page lists the account mail domains', function () {
    [$account, $owner] = actingAsOwnerWithMailCapableAccount();
    MailDomain::factory()->for($account)->create(['domain' => 'example.com']);

    $this->actingAs($owner)
        ->get(route('mail.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/index')
            ->has('mailDomains.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('mail.index'))->assertRedirect(route('login'));
});

test('a user with no account membership sees the index with no data instead of a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('mail.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mail/index')
            ->where('mailDomains', null)
        );
});

test('a user with no account membership is redirected away from the create page, not 404d', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('mail.create'))
        ->assertRedirect(route('mail.index'));
});

test('storing a mail domain redirects to the index with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithMailCapableAccount();

    $this->actingAs($owner)
        ->post(route('mail.store'), ['domain' => 'example.com'])
        ->assertRedirect(route('mail.index'));

    expect(MailDomain::where('account_id', $account->id)->where('domain', 'example.com')->exists())->toBeTrue();
});

test('storing a mail domain over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $this->actingAs($owner)
        ->post(route('mail.store'), ['domain' => 'example.com'])
        ->assertSessionHasErrors('domain');
});

test('storing a mail domain with no mail-capable node available results in a server error', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner)
        ->post(route('mail.store'), ['domain' => 'example.com'])
        ->assertServerError();
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('mail.create'))->assertForbidden();
});

test('updating a mail domain redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create(['domain' => 'example.com']);
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'catchall']);

    $this->actingAs($owner)
        ->put(route('mail.update', $mailDomain), ['catchall_email' => 'catchall@example.com'])
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect($mailDomain->refresh()->catchall_email)->toBe('catchall@example.com');
});

test('the edit form saves, sending its checkboxes as 1 and 0', function () {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create(['domain' => 'example.com', 'antivirus_enabled' => true]);
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'alice']);

    $this->actingAs($owner)
        ->put(route('mail.update', $mailDomain), [
            'catchall_email' => 'alice@example.com',
            'antivirus_enabled' => '0',
            'antispam_enabled' => '1',
            'dkim_enabled' => '0',
        ])
        ->assertSessionHasNoErrors();

    $mailDomain->refresh();

    expect($mailDomain->catchall_email)->toBe('alice@example.com')
        ->and($mailDomain->antivirus_enabled)->toBeFalse()
        ->and($mailDomain->antispam_enabled)->toBeTrue();
});

test('a catch-all must be an existing mailbox on the same domain', function (string $catchall) {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create(['domain' => 'example.com']);
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'real']);

    $this->actingAs($owner)
        ->put(route('mail.update', $mailDomain), ['catchall_email' => $catchall])
        ->assertSessionHasErrors('catchall_email');

    expect($mailDomain->refresh()->catchall_email)->toBeNull();
})->with(['external address' => 'someone@gmail.com', 'unknown local part' => 'ghost@example.com']);

test('suspending a mail domain redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('mail.index'))
        ->post(route('mail.suspend', $mailDomain))
        ->assertRedirect(route('mail.index'));

    expect($mailDomain->refresh()->isSuspended())->toBeTrue();
});

test('unsuspending a mail domain redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->suspended()->create();

    $this->actingAs($owner)
        ->from(route('mail.index'))
        ->post(route('mail.unsuspend', $mailDomain))
        ->assertRedirect(route('mail.index'));

    expect($mailDomain->refresh()->isSuspended())->toBeFalse();
});

test('destroying a mail domain redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithMailCapableAccount();
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('mail.destroy', $mailDomain))
        ->assertRedirect(route('mail.index'));

    expect(MailDomain::find($mailDomain->id))->toBeNull();
});
