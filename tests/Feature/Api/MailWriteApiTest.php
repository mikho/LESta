<?php

use App\Models\Account;
use App\Models\IdempotencyReceipt;
use App\Models\MailAccount;
use App\Models\MailDomain;

/**
 * @param  list<string>  $abilities
 * @return array{0: Account, 1: array<string, string>, 2: MailDomain}
 */
function mailWriteOwner(array $abilities = ['mail:read', 'mail:write']): array
{
    [$account, , $auth, $node] = apiWriteOwner($abilities, ['mail.smtp-imap.v1'], ['mail_domains' => 5, 'mail_accounts' => 5]);

    return [$account, $auth, MailDomain::factory()->for($account)->for($node)->create(['domain' => 'post.example'])];
}

function mailPath(Account $account, string $suffix = ''): string
{
    return '/api/v1/accounts/'.$account->public_id.'/mail-domains'.$suffix;
}

test('a mail domain is created, changed, suspended and deleted', function () {
    [$account, $auth] = mailWriteOwner();

    $created = $this->postJson(mailPath($account), ['domain' => 'Letters.example', 'antispam_enabled' => true], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.domain', 'letters.example')
        ->assertJsonPath('data.antispam_enabled', true);

    $path = mailPath($account, '/'.$created->json('data.id'));

    $this->patchJson($path, ['antivirus_enabled' => false], $auth)->assertAccepted()
        ->assertJsonPath('data.antivirus_enabled', false)
        ->assertJsonPath('data.antispam_enabled', true);
    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted()->assertJsonPath('data', null);

    expect(MailDomain::query()->where('domain', 'letters.example')->exists())->toBeFalse();
});

test('a new mailbox answers with its password once, and rotating answers with a new one', function () {
    [$account, $auth, $mailDomain] = mailWriteOwner();
    $mailboxes = mailPath($account, '/'.$mailDomain->uuid.'/mailboxes');

    $created = $this->postJson($mailboxes, ['local_part' => 'info', 'quota_mb' => 512], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.address', 'info@post.example')
        ->assertJsonPath('data.quota_mb', 512);

    $mailbox = MailAccount::query()->sole();
    $first = $created->json('data.password');
    expect($first)->toBe($mailbox->password);

    $path = $mailboxes.'/'.$mailbox->uuid;
    $this->getJson($path, $auth)->assertOk()->assertJsonMissingPath('data.password');

    $rotated = $this->postJson($path.'/rotate-password', [], $auth)->assertAccepted()->json('data.password');
    expect($rotated)->not->toBe($first)->toBe($mailbox->refresh()->password);

    $this->patchJson($path, ['forward_to' => 'elsewhere@example.net'], $auth)->assertAccepted()
        ->assertJsonPath('data.forward_to', 'elsewhere@example.net')
        ->assertJsonPath('data.quota_mb', 512)
        ->assertJsonMissingPath('data.password');
    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted();

    expect(MailAccount::query()->count())->toBe(0);
});

test('an idempotent retry of a new mailbox never stores or repeats the password', function () {
    [$account, $auth, $mailDomain] = mailWriteOwner();
    $headers = $auth + ['Idempotency-Key' => 'mailbox-info'];
    $mailboxes = mailPath($account, '/'.$mailDomain->uuid.'/mailboxes');

    $first = $this->postJson($mailboxes, ['local_part' => 'info'], $headers)->assertAccepted();
    $password = $first->json('data.password');

    $replay = $this->postJson($mailboxes, ['local_part' => 'info'], $headers)->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');

    expect($password)->toBeString()->not->toBeEmpty()
        ->and($replay->json('data.password'))->toBeNull()
        ->and($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(json_encode(IdempotencyReceipt::query()->sole()->response))->not->toContain($password)
        ->and(MailAccount::query()->count())->toBe(1);
});

test('mailbox names follow the panel rules', function () {
    [$account, $auth, $mailDomain] = mailWriteOwner();
    MailAccount::factory()->for($mailDomain)->create(['local_part' => 'taken']);
    $mailboxes = mailPath($account, '/'.$mailDomain->uuid.'/mailboxes');

    $this->postJson($mailboxes, ['local_part' => 'taken'], $auth)->assertUnprocessable()->assertJsonValidationErrors('local_part');
    $this->postJson($mailboxes, ['local_part' => 'bad name'], $auth)->assertUnprocessable()->assertJsonValidationErrors('local_part');
    $this->patchJson($mailboxes.'/'.MailAccount::query()->sole()->uuid, ['forward_to' => 'not-an-address'], $auth)->assertUnprocessable();
});

test('a mailbox is found only under its own mail domain', function () {
    [$account, $auth, $mailDomain] = mailWriteOwner();
    $elsewhere = MailAccount::factory()->for(MailDomain::factory()->for($account))->create();

    $this->getJson(mailPath($account, '/'.$mailDomain->uuid.'/mailboxes/'.$elsewhere->uuid), $auth)->assertNotFound();
    $this->postJson(mailPath($account, '/'.$mailDomain->uuid.'/mailboxes/'.$elsewhere->uuid.'/rotate-password'), [], $auth)->assertNotFound();
});

test('mail writes need mail:write', function () {
    [$account, $auth, $mailDomain] = mailWriteOwner(['mail:read']);

    $this->postJson(mailPath($account, '/'.$mailDomain->uuid.'/mailboxes'), ['local_part' => 'x'], $auth)->assertForbidden();
});
