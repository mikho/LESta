<?php

use App\Enums\DnsRecordType;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;

test('a retiring selector past the fallback window is retired: its dns record is deleted and its state is cleared', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.test',
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subHours(25),
    ]);
    $dnsZone = DnsZone::factory()->for($mailDomain->account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);
    $record = $dnsZone->records()->create(['name' => 'lesta1._domainkey', 'type' => DnsRecordType::TXT, 'value' => 'v=DKIM1; k=rsa; p=stale']);

    $this->artisan('mail:retire-old-dkim-selectors')->assertExitCode(0);

    $mailDomain->refresh();
    expect($mailDomain->dkim_retiring_selector)->toBeNull()
        ->and($mailDomain->dkim_retiring_selector_demoted_at)->toBeNull()
        ->and($mailDomain->dkim_selector)->toBe('lesta2')
        ->and(DnsZone::find($dnsZone->id)->records()->whereKey($record->id)->exists())->toBeFalse();
});

test('a retiring selector not yet past the fallback window is left alone', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subHours(1),
    ]);

    $this->artisan('mail:retire-old-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_retiring_selector)->toBe('lesta1');
});

test('retiring with no matching dns record at all still clears the rotation state, not a failure', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.test',
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subHours(25),
    ]);

    $this->artisan('mail:retire-old-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_retiring_selector)->toBeNull();
});

test('a suspended domain mid-retirement is never retired', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->suspended()->for($node)->create([
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subDays(2),
    ]);

    $this->artisan('mail:retire-old-dkim-selectors')->assertExitCode(0);

    expect($mailDomain->refresh()->dkim_retiring_selector)->toBe('lesta1');
});

test('retirement is a system-initiated action with no attributed actor, for both the mail domain and the deleted dns record', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create([
        'domain' => 'example.test',
        'dkim_enabled' => true,
        'dkim_selector' => 'lesta2',
        'dkim_retiring_selector' => 'lesta1',
        'dkim_retiring_selector_demoted_at' => now()->subDays(2),
    ]);
    $dnsZone = DnsZone::factory()->for($mailDomain->account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);
    $record = $dnsZone->records()->create(['name' => 'lesta1._domainkey', 'type' => DnsRecordType::TXT, 'value' => 'v=DKIM1; k=rsa; p=stale']);

    $this->artisan('mail:retire-old-dkim-selectors')->assertExitCode(0);

    expect(AuditEvent::where('action', 'mail_domain.dkim_selector_retired')
        ->where('auditable_id', $mailDomain->id)
        ->whereNull('actor_id')
        ->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'dns_record.deleted')
            ->where('auditable_id', $record->id)
            ->whereNull('actor_id')
            ->exists())->toBeTrue();
});
