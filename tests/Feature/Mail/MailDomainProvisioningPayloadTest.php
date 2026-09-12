<?php

use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Exceptions\NoMailCapableNodeAvailableException;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;

test('toProvisioningPayload returns exactly the expected keys with no secret-shaped values by default', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.com',
        'antivirus_enabled' => true,
        'antispam_enabled' => false,
        'dkim_enabled' => false,
        'catchall_email' => 'catchall@example.com',
    ]);
    MailAccount::factory()->for($mailDomain)->create([
        'local_part' => 'sales',
        'quota_mb' => 512,
        'forward_to' => null,
        'forward_only' => false,
        'autoreply_enabled' => false,
        'autoreply_message' => null,
    ]);

    $payload = $mailDomain->toProvisioningPayload();

    expect($payload)->toBe([
        'domain' => 'example.com',
        'antivirus_enabled' => true,
        'antispam_enabled' => false,
        'dkim_enabled' => false,
        'catchall_email' => 'catchall@example.com',
        'accounts' => [
            [
                'local_part' => 'sales',
                'quota_mb' => 512,
                'forward_to' => null,
                'forward_only' => false,
                'autoreply_enabled' => false,
                'autoreply_message' => null,
                'suspended' => false,
            ],
        ],
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['domain', 'antivirus_enabled', 'antispam_enabled', 'dkim_enabled', 'catchall_email', 'accounts', 'suspended'])
        ->and(array_keys($payload['accounts'][0]))->not->toContain('password');
});

test('toProvisioningPayload includes a password only for the one account explicitly requested', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create();
    $target = MailAccount::factory()->for($mailDomain)->create();
    $sibling = MailAccount::factory()->for($mailDomain)->create();

    $payload = $mailDomain->toProvisioningPayload(includePasswordForAccountId: $target->id, plaintextPassword: 'a-plaintext-password');

    $byLocalPart = collect($payload['accounts'])->keyBy('local_part');

    expect($byLocalPart[$target->local_part]['password'])->toBe('a-plaintext-password')
        ->and($byLocalPart[$sibling->local_part])->not->toHaveKey('password');
});

test('toProvisioningPayload never leaks any account own encrypted-at-rest password, regardless of the model own stored values', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create();
    MailAccount::factory()->for($mailDomain)->create(['password' => 'super-secret-stored-password']);

    $payload = $mailDomain->toProvisioningPayload();

    expect(json_encode($payload))->not->toContain('super-secret-stored-password');
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->suspended()->for($node)->create();

    expect($mailDomain->toProvisioningPayload()['suspended'])->toBeTrue();
});

test('resolve returns the first non-suspended node with an active mail.smtp-imap.v1 capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    [$resolvedNode, $capability] = app(ResolvesMailCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('mail.smtp-imap.v1');
});

test('resolve throws when no node has an active mail capability', function () {
    Node::factory()->create();

    app(ResolvesMailCapableNode::class)->resolve();
})->throws(NoMailCapableNodeAvailableException::class);

test('resolveFor returns the capability string for an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    expect(app(ResolvesMailCapableNode::class)->resolveFor($node))->toBe('mail.smtp-imap.v1');
});

test('resolveFor throws when the assigned node has no active mail capability', function () {
    $node = Node::factory()->create();

    app(ResolvesMailCapableNode::class)->resolveFor($node);
})->throws(NoMailCapableNodeAvailableException::class);
