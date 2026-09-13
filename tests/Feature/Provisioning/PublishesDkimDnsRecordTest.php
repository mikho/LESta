<?php

use App\Actions\Provisioning\PublishesDkimDnsRecord;
use App\Enums\DnsRecordType;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;

function dkimCompletionOperation(MailDomain $mailDomain, array $overrides = []): ProvisioningOperation
{
    return ProvisioningOperation::factory()->create(array_merge([
        'provisionable_type' => $mailDomain->getMorphClass(),
        'provisionable_id' => $mailDomain->id,
        'resource_id' => $mailDomain->uuid,
        'capability' => 'mail.smtp-imap.v1',
        'operation' => ProvisioningVerb::Create,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
        'data' => [
            'selector' => 'lesta1',
            'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'.'test-public-key-bytes',
        ],
    ], $overrides));
}

test('a successful mail domain apply publishes a real DKIM TXT record on the matching dns zone', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    $dnsZone = DnsZone::factory()->for($account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);

    $operation = dkimCompletionOperation($mailDomain);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    $record = $dnsZone->records()->where('name', 'lesta1._domainkey')->where('type', DnsRecordType::TXT)->first();

    expect($record)->not->toBeNull()
        ->and($record->value)->toBe('v=DKIM1; k=rsa; p='.$operation->data['public_key']);

    expect(AuditEvent::where('action', 'dns_record.dkim_published')
        ->where('auditable_id', $record->id)
        ->exists())->toBeTrue();

    expect($dnsZone->refresh()->desired_state_version)->toBe(2);
});

test('re-publishing the same public key is a no-op: no duplicate record, no version bump', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    $dnsZone = DnsZone::factory()->for($account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);

    $operation = dkimCompletionOperation($mailDomain);
    app(PublishesDkimDnsRecord::class)->handle($operation);

    $versionAfterFirst = $dnsZone->refresh()->desired_state_version;

    app(PublishesDkimDnsRecord::class)->handle(dkimCompletionOperation($mailDomain));

    expect($dnsZone->records()->where('name', 'lesta1._domainkey')->count())->toBe(1)
        ->and($dnsZone->refresh()->desired_state_version)->toBe($versionAfterFirst);
});

test('a changed public key updates the existing record in place rather than creating a second one', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    $dnsZone = DnsZone::factory()->for($account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);

    app(PublishesDkimDnsRecord::class)->handle(dkimCompletionOperation($mailDomain));

    $rotated = dkimCompletionOperation($mailDomain, ['data' => ['selector' => 'lesta1', 'public_key' => 'a-different-rotated-key']]);
    app(PublishesDkimDnsRecord::class)->handle($rotated);

    expect($dnsZone->records()->where('name', 'lesta1._domainkey')->count())->toBe(1);

    $record = $dnsZone->records()->where('name', 'lesta1._domainkey')->first();
    expect($record->value)->toBe('v=DKIM1; k=rsa; p=a-different-rotated-key');

    expect(AuditEvent::where('action', 'dns_record.dkim_updated')->where('auditable_id', $record->id)->exists())->toBeTrue();
});

test('no matching dns zone for the account is a logged no-op, not an exception', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);

    $operation = dkimCompletionOperation($mailDomain);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect(true)->toBeTrue();
});

test('a dns zone owned by a different account is never matched', function () {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    DnsZone::factory()->for($otherAccount)->create(['domain' => 'example.test']);

    $operation = dkimCompletionOperation($mailDomain);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect(AuditEvent::where('action', 'dns_record.dkim_published')->exists())->toBeFalse();
});

test('a dns zone whose node has no active dns.bind9.v1 capability is a logged no-op, not an exception', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    $node = Node::factory()->create();
    DnsZone::factory()->for($account)->for($node)->create(['domain' => 'example.test']);

    $operation = dkimCompletionOperation($mailDomain);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect(true)->toBeTrue();
});

test('a failed mail domain operation is ignored entirely', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => true]);
    $dnsZone = DnsZone::factory()->for($account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);

    $operation = dkimCompletionOperation($mailDomain, ['status' => ProvisioningStatus::Failed, 'data' => null]);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect($dnsZone->records()->count())->toBe(0);
});

test('an operation with no dkim data at all (dkim disabled) is ignored entirely', function () {
    $account = Account::factory()->create();
    $mailDomain = MailDomain::factory()->for($account)->create(['domain' => 'example.test', 'dkim_enabled' => false]);
    $dnsZone = DnsZone::factory()->for($account)->create(['domain' => 'example.test']);
    NodeCapability::factory()->for($dnsZone->node)->create(['capability' => 'dns.bind9.v1']);

    $operation = dkimCompletionOperation($mailDomain, ['data' => null]);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect($dnsZone->records()->count())->toBe(0);
});

test('a non-MailDomain provisionable is ignored', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $dnsZone->getMorphClass(),
        'provisionable_id' => $dnsZone->id,
        'resource_id' => $dnsZone->uuid,
        'capability' => 'dns.bind9.v1',
        'operation' => ProvisioningVerb::Create,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ]);

    app(PublishesDkimDnsRecord::class)->handle($operation);

    expect(true)->toBeTrue();
});
