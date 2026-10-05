<?php

use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\WebmailAccessToken;

function mintWebmailToken(MailAccount $mailAccount, array $overrides = []): string
{
    $raw = 'testtoken'.bin2hex(random_bytes(16));

    WebmailAccessToken::create(array_merge([
        'token_hash' => hash('sha256', $raw),
        'mail_account_id' => $mailAccount->id,
        'expires_at' => now()->addSeconds(60),
    ], $overrides));

    return $raw;
}

test('a valid token returns the full address and decrypted password with no auth required', function () {
    $mailDomain = MailDomain::factory()->create(['domain' => 'example.test']);
    $mailAccount = MailAccount::factory()->for($mailDomain)->create([
        'local_part' => 'alice',
        'password' => 'super-secret-password',
    ]);

    $token = mintWebmailToken($mailAccount);

    // Bare get(), no actingAs(): this endpoint is token-only-authenticated, never a session.
    $response = $this->get("/internal/webmail-credentials/{$token}");

    $response->assertOk();
    $response->assertExactJson([
        'username' => 'alice@example.test',
        'password' => 'super-secret-password',
    ]);
});

test('an expired token is rejected with 404', function () {
    $mailAccount = MailAccount::factory()->create();

    $token = mintWebmailToken($mailAccount, ['expires_at' => now()->subSecond()]);

    $this->get("/internal/webmail-credentials/{$token}")->assertNotFound();
});

test('an already-used token is rejected on the second redemption', function () {
    $mailAccount = MailAccount::factory()->create();

    $token = mintWebmailToken($mailAccount);

    $this->get("/internal/webmail-credentials/{$token}")->assertOk();
    $this->get("/internal/webmail-credentials/{$token}")->assertNotFound();
});

test('a nonexistent token is rejected with 404', function () {
    $this->get('/internal/webmail-credentials/does-not-exist')->assertNotFound();
});

test('a suspended mailbox is rejected even with a valid token', function () {
    $mailAccount = MailAccount::factory()->suspended()->create();

    $token = mintWebmailToken($mailAccount);

    $this->get("/internal/webmail-credentials/{$token}")->assertNotFound();
});

test('a mailbox on a suspended mail domain is rejected even with a valid token', function () {
    $mailDomain = MailDomain::factory()->suspended()->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $token = mintWebmailToken($mailAccount);

    $this->get("/internal/webmail-credentials/{$token}")->assertNotFound();
});
