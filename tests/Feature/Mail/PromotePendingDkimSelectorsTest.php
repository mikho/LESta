<?php

use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;

test('a pending selector whose own dns zone has no matching zone is promoted once the 24h fallback window has passed', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subHours(25),
    ]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    $mailDomain->refresh();
    expect($mailDomain->dkim_selector)->toBe('lesta2')
        ->and($mailDomain->dkim_pending_selector)->toBeNull()
        ->and($mailDomain->dkim_pending_selector_published_at)->toBeNull()
        ->and($mailDomain->dkim_retiring_selector)->toBe('lesta1')
        ->and($mailDomain->dkim_retiring_selector_demoted_at)->not->toBeNull()
        ->and($mailDomain->dkim_selector_activated_at)->not->toBeNull();
});

test('a pending selector with no matching dns zone is not promoted before the 24h fallback window has passed', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subHours(1),
    ]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_selector)->toBe('lesta1')
        ->and($mailDomain->dkim_pending_selector)->toBe('lesta2');
});

test('a matching dns zone with a short ttl lets promotion happen sooner than the 24h fallback', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.test',
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subHours(2),
    ]);
    DnsZone::factory()->for($mailDomain->account)->create(['domain' => 'example.test', 'ttl' => 3600]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_selector)->toBe('lesta2');
});

test('a matching dns zone with a long ttl delays promotion past the 24h fallback', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.test',
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subHours(25),
    ]);
    DnsZone::factory()->for($mailDomain->account)->create(['domain' => 'example.test', 'ttl' => 172800]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_selector)->toBe('lesta1');
});

test('a suspended domain mid-rotation is never promoted', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->suspended()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta1',
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subDays(2),
    ]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_selector)->toBe('lesta1');
});

test('promotion is a system-initiated action with no attributed actor', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_pending_selector' => 'lesta2',
        'dkim_pending_selector_published_at' => now()->subDays(2),
    ]);

    $this->artisan('mail:promote-pending-dkim-selectors')->assertExitCode(0);

    expect(AuditEvent::where('action', 'mail_domain.dkim_selector_promoted')
        ->where('auditable_id', $mailDomain->id)
        ->whereNull('actor_id')
        ->exists())->toBeTrue();
});
