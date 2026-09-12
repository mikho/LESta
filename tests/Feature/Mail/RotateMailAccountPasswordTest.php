<?php

use App\Actions\Mail\RotateMailAccountPassword;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;

test('an owner can rotate their mail account password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['password' => 'old-password']);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    [$rotated, $newPassword] = app(RotateMailAccountPassword::class)->handle($owner, $mailAccount);

    expect($newPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($newPassword)->not->toBe('old-password')
        ->and($rotated->password)->toBe($newPassword)
        ->and(AuditEvent::where('action', 'mail_account.password_rotated')->where('auditable_id', $mailAccount->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $mailDomain->id)
        ->where('operation', 'update')
        ->latest('id')
        ->firstOrFail();

    expect($operation->payload['accounts'][0]['password'])->toBe($newPassword);
});

test('rotating never logs the password itself in the audit event', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    [, $newPassword] = app(RotateMailAccountPassword::class)->handle($owner, $mailAccount);

    $event = AuditEvent::where('action', 'mail_account.password_rotated')->where('auditable_id', $mailAccount->id)->firstOrFail();

    expect(json_encode($event->getAttributes()))->not->toContain($newPassword);
});
