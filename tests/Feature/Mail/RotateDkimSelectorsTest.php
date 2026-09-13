<?php

use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;

test('nextDkimSelector increments the trailing digit of the current selector', function () {
    $mailDomain = MailDomain::factory()->create(['dkim_selector' => 'lesta1']);
    expect($mailDomain->nextDkimSelector())->toBe('lesta2');

    $mailDomain->dkim_selector = 'lesta9';
    expect($mailDomain->nextDkimSelector())->toBe('lesta10');
});

test('mail:rotate-dkim-selectors starts a rotation for a domain whose selector has been active for 90+ days', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_selector_activated_at' => now()->subDays(91),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    $mailDomain->refresh();
    expect($mailDomain->dkim_pending_selector)->toBe('lesta2')
        ->and($mailDomain->dkim_pending_selector_published_at)->not->toBeNull()
        ->and($mailDomain->dkim_selector)->toBe('lesta1');
});

test('a domain whose selector has not been active for 90 days yet is skipped', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector_activated_at' => now()->subDays(10),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_pending_selector)->toBeNull();
});

test('a domain with dkim disabled is never rotated', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => false,
        'dkim_selector_activated_at' => now()->subDays(200),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_pending_selector)->toBeNull();
});

test('a suspended domain is never rotated', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->suspended()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector_activated_at' => now()->subDays(200),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_pending_selector)->toBeNull();
});

test('a domain already mid-rotation is never started a second time', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_selector_activated_at' => now()->subDays(200),
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subDays(1),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_pending_selector)->toBe('lesta2');
});

test('a domain whose old selector is still being retired is never started a second time', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_selector_activated_at' => now()->subDays(200),
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subHours(1),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_pending_selector)->toBeNull();
});

test('starting a rotation is a system-initiated action with no attributed actor', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector_activated_at' => now()->subDays(91),
    ]);

    $this->artisan('mail:rotate-dkim-selectors')->assertExitCode(0);

    expect(AuditEvent::where('action', 'mail_domain.dkim_rotation_started')
        ->where('auditable_id', $mailDomain->id)
        ->whereNull('actor_id')
        ->exists())->toBeTrue();
});
