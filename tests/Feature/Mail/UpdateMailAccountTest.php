<?php

use App\Actions\Mail\UpdateMailAccount;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('an owner can update quota, forwarding, and autoreply, and the domain is re-provisioned', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $updated = app(UpdateMailAccount::class)->handle($owner, $mailAccount, [
        'quota_mb' => 2048,
        'forward_to' => 'elsewhere@example.com',
        'forward_only' => true,
        'autoreply_enabled' => true,
        'autoreply_message' => 'Out of office',
    ]);

    expect($updated->quota_mb)->toBe(2048)
        ->and($updated->forward_to)->toBe('elsewhere@example.com')
        ->and($updated->forward_only)->toBeTrue()
        ->and($updated->autoreply_enabled)->toBeTrue()
        ->and($updated->autoreply_message)->toBe('Out of office')
        ->and($mailDomain->refresh()->desired_state_version)->toBe(2);
});

test('quota_mb can be cleared to null (unlimited)', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['quota_mb' => 1024]);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $updated = app(UpdateMailAccount::class)->handle($owner, $mailAccount, ['quota_mb' => null]);

    expect($updated->quota_mb)->toBeNull();
});
