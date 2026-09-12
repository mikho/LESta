<?php

use App\Actions\Mail\SuspendMailAccount;
use App\Actions\Mail\UnsuspendMailAccount;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('an owner can suspend and unsuspend a mail account without the domain itself transitioning', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    app(SuspendMailAccount::class)->handle($owner, $mailAccount);

    expect($mailAccount->refresh()->isSuspended())->toBeTrue()
        ->and($mailAccount->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($mailDomain->refresh()->isSuspended())->toBeFalse()
        ->and($mailDomain->desired_state_version)->toBe(2);

    app(UnsuspendMailAccount::class)->handle($owner, $mailAccount);

    expect($mailAccount->refresh()->isSuspended())->toBeFalse()
        ->and($mailDomain->refresh()->desired_state_version)->toBe(3);
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    app(SuspendMailAccount::class)->handle($owner, $mailAccount);
    app(SuspendMailAccount::class)->handle($owner, $mailAccount);

    expect(AuditEvent::where('action', 'mail_account.suspended')->where('auditable_id', $mailAccount->id)->count())->toBe(1);
});
