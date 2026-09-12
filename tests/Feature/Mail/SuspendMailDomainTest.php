<?php

use App\Actions\Mail\SuspendMailDomain;
use App\Actions\Mail\UnsuspendMailDomain;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('mail domain suspend cascades to active accounts and unsuspend reactivates only cascade-sourced ones', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $preManuallySuspended = MailAccount::factory()->for($mailDomain)->suspended()->create();
    $active = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    app(SuspendMailDomain::class)->handle($owner, $mailDomain);

    expect($mailDomain->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->refresh()->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($active->refresh()->suspension_source)->toBe(SuspensionSource::Cascade)
        ->and($active->isSuspended())->toBeTrue();

    app(UnsuspendMailDomain::class)->handle($owner, $mailDomain);

    expect($mailDomain->refresh()->isSuspended())->toBeFalse()
        ->and($active->refresh()->isSuspended())->toBeFalse()
        ->and($preManuallySuspended->refresh()->isSuspended())->toBeTrue()
        ->and($preManuallySuspended->suspension_source)->toBe(SuspensionSource::Manual);
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    app(SuspendMailDomain::class)->handle($owner, $mailDomain);
    app(SuspendMailDomain::class)->handle($owner, $mailDomain);

    expect(AuditEvent::where('action', 'mail_domain.suspended')->where('auditable_id', $mailDomain->id)->count())->toBe(1);
});
