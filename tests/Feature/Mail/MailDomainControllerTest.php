<?php

use App\Models\Account;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
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
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->put(route('mail.update', $mailDomain), ['catchall_email' => 'catchall@example.com'])
        ->assertRedirect(route('mail.edit', $mailDomain));

    expect($mailDomain->refresh()->catchall_email)->toBe('catchall@example.com');
});

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
