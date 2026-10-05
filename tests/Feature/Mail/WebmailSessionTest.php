<?php

use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebmailAccessToken;

/**
 * A mailbox on a node where webmail is fully available (mail_hostname set, a non-suspended
 * mail.webmail.v1 capability).
 *
 * @return array{0: MailDomain, 1: MailAccount}
 */
function webmailReadyMailbox(array $accountAttributes = []): array
{
    $node = Node::factory()->create(['mail_hostname' => 'mail.example.test']);
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.webmail.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create($accountAttributes);

    return [$mailDomain, $mailAccount];
}

test('an owner can prepare a webmail session', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]));

    $response->assertOk();

    $url = $response->json('url');

    expect($url)->toMatch('#^https://mail\.example\.test/\?_lesta_token=[A-Za-z0-9]{64}$#');

    $token = str_replace('https://mail.example.test/?_lesta_token=', '', $url);
    $record = WebmailAccessToken::where('token_hash', hash('sha256', $token))->sole();

    expect($record->mail_account_id)->toBe($mailAccount->id)
        ->and($record->used_at)->toBeNull()
        ->and($record->expires_at->isFuture())->toBeTrue()
        ->and(AuditEvent::where('action', 'mail_account.webmail_session_prepared')->where('auditable_id', $mailAccount->id)->exists())->toBeTrue();
});

test('a non-member is forbidden from preparing a webmail session', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox();
    $outsider = Membership::factory()->owner()->create()->user;

    $this->actingAs($outsider)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertForbidden();

    expect(WebmailAccessToken::count())->toBe(0);
});

test('a non-owner member is forbidden from preparing a webmail session', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox();
    $member = Membership::factory()->for($mailDomain->account)->member()->create()->user;

    $this->actingAs($member)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertForbidden();
});

test('a mailbox from another mail domain is not found', function () {
    [$mailDomain] = webmailReadyMailbox();
    [, $otherMailAccount] = webmailReadyMailbox();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $otherMailAccount]))
        ->assertNotFound();
});

test('a suspended mailbox is rejected', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox(['suspended_at' => now()]);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['mail_account']);
    expect(WebmailAccessToken::count())->toBe(0);
});

test('a mailbox on a suspended mail domain is rejected', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox();
    $mailDomain->forceFill(['suspended_at' => now()])->save();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mail_account']);
});

test('a node without a mail hostname is rejected', function () {
    [$mailDomain, $mailAccount] = webmailReadyMailbox();
    $mailDomain->node->forceFill(['mail_hostname' => null])->save();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mail_account']);
});

test('a node without the webmail capability is rejected', function () {
    $node = Node::factory()->create(['mail_hostname' => 'mail.example.test']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mail_account']);
});

test('a node with only a suspended webmail capability is rejected', function () {
    $node = Node::factory()->create(['mail_hostname' => 'mail.example.test']);
    NodeCapability::factory()->for($node)->suspended()->create(['capability' => 'mail.webmail.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->postJson(route('mail.accounts.open-webmail', [$mailDomain, $mailAccount]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mail_account']);
});

test('the mail domain edit page reports whether webmail is available', function () {
    [$mailDomain] = webmailReadyMailbox();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->get(route('mail.edit', $mailDomain))
        ->assertInertia(fn ($page) => $page->component('mail/edit')->where('webmailAvailable', true));

    $mailDomain->node->forceFill(['mail_hostname' => null])->save();

    $this->actingAs($owner)
        ->get(route('mail.edit', $mailDomain))
        ->assertInertia(fn ($page) => $page->component('mail/edit')->where('webmailAvailable', false));
});
