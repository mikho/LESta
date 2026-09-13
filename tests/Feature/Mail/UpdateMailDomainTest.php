<?php

use App\Actions\Mail\UpdateMailDomain;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('an owner can update antivirus/antispam/catchall, and desired_state_version bumps', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $updated = app(UpdateMailDomain::class)->handle($owner, $mailDomain, [
        'antivirus_enabled' => false,
        'antispam_enabled' => false,
        'catchall_email' => 'catchall@example.com',
    ]);

    expect($updated->antivirus_enabled)->toBeFalse()
        ->and($updated->antispam_enabled)->toBeFalse()
        ->and($updated->catchall_email)->toBe('catchall@example.com')
        ->and($updated->desired_state_version)->toBe(2);
});

test('dkim_enabled can be toggled on and off by update, now that the real capability backs it', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create(['dkim_enabled' => false]);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $enabled = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => true]);
    expect($enabled->dkim_enabled)->toBeTrue();

    $disabled = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => false]);
    expect($disabled->dkim_enabled)->toBeFalse();
});

test('enabling dkim starts its own rotation clock, and disabling it does not clear that timestamp', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create(['dkim_enabled' => false, 'dkim_selector_activated_at' => null]);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $enabled = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => true]);
    expect($enabled->dkim_selector_activated_at)->not->toBeNull();

    $activatedAt = $enabled->dkim_selector_activated_at;

    $disabled = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => false]);
    expect($disabled->dkim_selector_activated_at->equalTo($activatedAt))->toBeTrue();
});

test('re-enabling dkim after it was off resets the rotation clock', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
    ]);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => false]);
    $reEnabled = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['dkim_enabled' => true]);

    expect($reEnabled->dkim_selector_activated_at)->not->toBeNull();
});

test('catchall_email can be cleared back to null', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create(['catchall_email' => 'old@example.com']);
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;

    $updated = app(UpdateMailDomain::class)->handle($owner, $mailDomain, ['catchall_email' => null]);

    expect($updated->catchall_email)->toBeNull();
});
