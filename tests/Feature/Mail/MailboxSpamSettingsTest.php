<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: MailDomain, 1: User}
 */
function spamMailDomain(): array
{
    $account = Account::factory()->for(Package::factory()->withLimit('mail_accounts', 5))->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    return [MailDomain::factory()->for($account)->for($node)->create(['antispam_enabled' => true]), $owner];
}

test('a new mailbox files likely spam by default and sends its settings to the node', function () {
    [$mailDomain, $owner] = spamMailDomain();

    $this->actingAs($owner)->post(route('mail.accounts.store', $mailDomain), ['local_part' => 'info'])->assertRedirect();

    $mailbox = MailAccount::query()->sole();
    $payload = ProvisioningOperation::query()->latest('id')->value('payload');
    $sent = collect($payload['accounts'])->firstWhere('local_part', 'info');

    expect($mailbox->spam_sensitivity)->toBe('medium')
        ->and($sent['spam_sensitivity'])->toBe('medium')
        ->and($sent['spam_allow'])->toBe([])
        ->and($sent['spam_block'])->toBe([]);
});

test('the owner sets sensitivity and lists, written one per line, cleaned up', function () {
    [$mailDomain, $owner] = spamMailDomain();
    $mailbox = MailAccount::factory()->for($mailDomain)->create();

    $this->actingAs($owner)->put(route('mail.accounts.update', [$mailDomain, $mailbox]), [
        'spam_sensitivity' => 'high',
        'spam_allow' => "Friend@Partner.example\n partner.example \n\nfriend@partner.example",
        'spam_block' => 'pest@spam.example, junkmail.example',
    ])->assertRedirect(route('mail.edit', $mailDomain));

    $mailbox->refresh();
    expect($mailbox->spam_sensitivity)->toBe('high')
        ->and($mailbox->spam_allow)->toBe(['friend@partner.example', 'partner.example'])
        ->and($mailbox->spam_block)->toBe(['pest@spam.example', 'junkmail.example']);

    $sent = collect(ProvisioningOperation::query()->latest('id')->value('payload')['accounts'])->firstWhere('local_part', $mailbox->local_part);
    expect($sent['spam_allow'])->toBe(['friend@partner.example', 'partner.example']);

    $this->actingAs($owner)->get(route('mail.edit', $mailDomain))->assertInertia(fn (Assert $page) => $page
        ->where('mailDomain.accounts.0.spam_sensitivity', 'high')
        ->where('mailDomain.accounts.0.spam_block', ['pest@spam.example', 'junkmail.example']));
});

test('an update that leaves out the spam settings keeps them, and an empty list clears one', function () {
    [$mailDomain, $owner] = spamMailDomain();
    $mailbox = MailAccount::factory()->for($mailDomain)->create(['spam_sensitivity' => 'low', 'spam_block' => ['spam.example'], 'spam_allow' => ['friend.example']]);

    $this->actingAs($owner)->put(route('mail.accounts.update', [$mailDomain, $mailbox]), ['quota_mb' => 300])->assertRedirect();
    expect($mailbox->refresh()->spam_sensitivity)->toBe('low')->and($mailbox->spam_block)->toBe(['spam.example']);

    $this->actingAs($owner)->put(route('mail.accounts.update', [$mailDomain, $mailbox]), ['spam_block' => ''])->assertRedirect();
    expect($mailbox->refresh()->spam_block)->toBe([])->and($mailbox->spam_allow)->toBe(['friend.example']);
});

test('entries that are not an address or a domain, an unknown sensitivity and too many entries are refused', function () {
    [$mailDomain, $owner] = spamMailDomain();
    $mailbox = MailAccount::factory()->for($mailDomain)->create();
    $update = fn (array $data) => $this->actingAs($owner)->put(route('mail.accounts.update', [$mailDomain, $mailbox]), $data);

    $update(['spam_block' => ['evil"@example.com']])->assertSessionHasErrors('spam_block.0');
    $update(['spam_allow' => ['not an address']])->assertSessionHasErrors('spam_allow.0');
    $update(['spam_allow' => ['@example.com']])->assertSessionHasErrors('spam_allow.0');
    $update(['spam_sensitivity' => 'extreme'])->assertSessionHasErrors('spam_sensitivity');
    $update(['spam_block' => array_map(fn (int $i): string => "spam{$i}.example", range(1, 101))])->assertSessionHasErrors('spam_block');

    expect($mailbox->refresh()->spam_block)->toBeNull();
});

test('the API reads and changes the spam settings', function () {
    [$mailDomain, $owner] = spamMailDomain();
    $mailbox = MailAccount::factory()->for($mailDomain)->create();
    ApiToken::factory()->for($owner)->withPlaintext('lesta_spam')->create(['abilities' => ['mail:read', 'mail:write']]);
    $path = '/api/v1/accounts/'.$mailDomain->account->public_id.'/mail-domains/'.$mailDomain->uuid.'/mailboxes/'.$mailbox->uuid;
    $auth = ['Authorization' => 'Bearer lesta_spam'];

    $this->getJson($path, $auth)->assertOk()->assertJsonPath('data.spam_sensitivity', 'medium')->assertJsonPath('data.spam_allow', []);
    $this->patchJson($path, ['spam_sensitivity' => 'off', 'spam_allow' => ['partner.example']], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.spam_sensitivity', 'off')
        ->assertJsonPath('data.spam_allow', ['partner.example']);
});
