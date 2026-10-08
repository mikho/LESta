<?php

use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\MailingList;
use App\Models\MailingListMember;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;

function mailDomainForLists(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    return [$mailDomain, $owner];
}

function latestMailPayload(): array
{
    return ProvisioningOperation::where('capability', 'mail.smtp-imap.v1')->latest('id')->first()->payload;
}

test('an owner creates a list, it is stored in canonical form and sent to the node, and audited', function () {
    [$mailDomain, $owner] = mailDomainForLists();

    $this->actingAs($owner)
        ->post(route('mail.lists.store', $mailDomain), ['local_part' => 'News', 'owner_email' => 'Boss@Corp.Example', 'post_policy' => 'members', 'subject_prefix' => '[News]', 'reply_to_list' => '1'])
        ->assertSessionHasNoErrors();

    $list = $mailDomain->mailingLists()->sole();

    expect($list->local_part)->toBe('news')
        ->and($list->owner_email)->toBe('boss@corp.example')
        ->and($list->reply_to_list)->toBeTrue()
        ->and(AuditEvent::where('action', 'mailing_list.created')->count())->toBe(1)
        ->and(latestMailPayload()['lists'])->toBe([['local_part' => 'news', 'owner_email' => 'boss@corp.example', 'post_policy' => 'members', 'subject_prefix' => '[News]', 'reply_to_list' => true, 'members' => []]]);
});

test('members are added in bulk from pasted text, deduplicated, lower-cased, and removable one by one', function () {
    [$mailDomain, $owner] = mailDomainForLists();
    $list = MailingList::factory()->for($mailDomain)->create(['local_part' => 'news']);
    MailingListMember::factory()->for($list)->create(['email' => 'existing@example.org']);

    $this->actingAs($owner)
        ->post(route('mail.lists.members.store', [$mailDomain, $list]), ['emails' => "A@Example.org, b@example.org;\nexisting@example.org  a@example.org"])
        ->assertSessionHasNoErrors();

    expect($list->members()->pluck('email')->sort()->values()->all())->toBe(['a@example.org', 'b@example.org', 'existing@example.org'])
        ->and(latestMailPayload()['lists'][0]['members'])->toBe(['existing@example.org', 'a@example.org', 'b@example.org']);

    $b = $list->members()->where('email', 'b@example.org')->sole();

    $this->actingAs($owner)->delete(route('mail.lists.members.destroy', [$mailDomain, $list, $b]))->assertSessionHasNoErrors();

    expect($list->members()->count())->toBe(2);
});

test('one bad address refuses the whole paste, and an empty paste says so', function (string $emails) {
    [$mailDomain, $owner] = mailDomainForLists();
    $list = MailingList::factory()->for($mailDomain)->create();

    $this->actingAs($owner)->post(route('mail.lists.members.store', [$mailDomain, $list]), ['emails' => $emails])->assertSessionHasErrors('emails');

    expect($list->members()->count())->toBe(0);
})->with([
    'a fine one and a broken one' => ["fine@example.org\nnot-an-address"],
    'a comma inside an address' => ['a@example.org,evil@y.example,|/bin/sh@z.example'],
    'a pipe' => ['|/bin/sh@example.org'],
    'a fail directive' => [':fail:@example.org'],
    'only separators' => [' ,; '],
]);

test('settings can be changed and a list deleted', function () {
    [$mailDomain, $owner] = mailDomainForLists();
    $list = MailingList::factory()->for($mailDomain)->create(['local_part' => 'news', 'post_policy' => 'members']);

    $this->actingAs($owner)
        ->put(route('mail.lists.update', [$mailDomain, $list]), ['owner_email' => 'new@corp.example', 'post_policy' => 'anyone', 'subject_prefix' => '', 'reply_to_list' => '0'])
        ->assertSessionHasNoErrors();

    expect($list->fresh()->post_policy)->toBe('anyone')
        ->and($list->fresh()->subject_prefix)->toBeNull()
        ->and(latestMailPayload()['lists'][0]['owner_email'])->toBe('new@corp.example');

    $this->actingAs($owner)->delete(route('mail.lists.destroy', [$mailDomain, $list]))->assertRedirect();

    expect(MailingList::count())->toBe(0)
        ->and(latestMailPayload()['lists'])->toBe([]);
});

test('unsafe or invalid list settings are refused', function (array $input) {
    [$mailDomain, $owner] = mailDomainForLists();

    $this->actingAs($owner)
        ->post(route('mail.lists.store', $mailDomain), $input + ['local_part' => 'news', 'owner_email' => 'boss@corp.example', 'post_policy' => 'members'])
        ->assertSessionHasErrors();

    expect($mailDomain->mailingLists()->count())->toBe(0);
})->with([
    'a path as the name' => [['local_part' => '../etc']],
    'a name ending in -owner' => [['local_part' => 'news-owner']],
    'a bad owner' => [['owner_email' => 'not an email']],
    'an unknown policy' => [['post_policy' => 'everyone']],
    'an expansion in the prefix' => [['subject_prefix' => '${run{x}}']],
    'a header injection in the prefix' => [['subject_prefix' => "a\nBcc: x@y.example"]],
]);

test('a list cannot take the name of a mailbox, a mailbox cannot take the name of a list, and names are unique', function () {
    [$mailDomain, $owner] = mailDomainForLists();
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'info']);
    MailingList::factory()->for($mailDomain)->create(['local_part' => 'news']);

    foreach (['info', 'news'] as $name) {
        $this->actingAs($owner)->post(route('mail.lists.store', $mailDomain), ['local_part' => $name, 'owner_email' => 'boss@corp.example', 'post_policy' => 'anyone'])->assertSessionHasErrors('local_part');
    }

    foreach (['news', 'news-owner'] as $name) {
        $this->actingAs($owner)->post(route('mail.accounts.store', $mailDomain), ['local_part' => $name])->assertSessionHasErrors('local_part');
    }

    expect($mailDomain->mailingLists()->count())->toBe(1);
});

test('only the owner of the domain can manage lists, and ids from another domain are not honoured', function () {
    [$mailDomain, $owner] = mailDomainForLists();
    $member = Membership::factory()->for($mailDomain->account)->member()->create()->user;
    [, $stranger] = mailDomainForLists();
    $list = MailingList::factory()->for($mailDomain)->create();
    $other = MailDomain::factory()->for($mailDomain->node)->for($mailDomain->account)->create();
    $foreign = MailingList::factory()->for($other)->create();

    $input = ['local_part' => 'x', 'owner_email' => 'boss@corp.example', 'post_policy' => 'anyone'];

    $this->actingAs($member)->post(route('mail.lists.store', $mailDomain), $input)->assertForbidden();
    $this->actingAs($stranger)->post(route('mail.lists.store', $mailDomain), $input)->assertForbidden();
    $this->actingAs($stranger)->get(route('mail.lists.index', $mailDomain))->assertForbidden();
    $this->actingAs($stranger)->delete(route('mail.lists.destroy', [$mailDomain, $list]))->assertForbidden();
    $this->actingAs($owner)->delete(route('mail.lists.destroy', [$mailDomain, $foreign]))->assertNotFound();

    expect(MailingList::count())->toBe(2);
});

test('the page lists the lists and their members', function () {
    [$mailDomain, $owner] = mailDomainForLists();
    $list = MailingList::factory()->for($mailDomain)->create(['local_part' => 'news']);
    MailingListMember::factory()->for($list)->create(['email' => 'zed@example.org']);

    $this->actingAs($owner)->get(route('mail.lists.index', $mailDomain))
        ->assertInertia(fn ($page) => $page->component('mail/lists')->has('lists', 1)->where('lists.0.local_part', 'news')->where('lists.0.members.0.email', 'zed@example.org'));
});

test('a list is part of the domain payload with an empty subject prefix as a string', function () {
    [$mailDomain] = mailDomainForLists();
    MailingList::factory()->for($mailDomain)->create(['local_part' => 'news', 'subject_prefix' => null]);

    expect($mailDomain->toProvisioningPayload()['lists'][0]['subject_prefix'])->toBe('');
});
