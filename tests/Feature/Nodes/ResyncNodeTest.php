<?php

use App\Actions\Nodes\ResyncNode;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

test('resync dispatches a real update for a web domain, a dns zone, and a cron job on the node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    $webDomain = WebDomain::factory()->for($node)->create();
    $dnsZone = DnsZone::factory()->for($node)->create();
    $cronJob = CronJob::factory()->for($node)->create();

    app(ResyncNode::class)->handleSystemInitiated($node);

    expect(ProvisioningOperation::where('provisionable_type', $webDomain->getMorphClass())->where('provisionable_id', $webDomain->id)->where('operation', 'update')->exists())->toBeTrue()
        ->and(ProvisioningOperation::where('provisionable_type', $dnsZone->getMorphClass())->where('provisionable_id', $dnsZone->id)->where('operation', 'update')->exists())->toBeTrue()
        ->and(ProvisioningOperation::where('provisionable_type', $cronJob->getMorphClass())->where('provisionable_id', $cronJob->id)->where('operation', 'update')->exists())->toBeTrue();

    expect($webDomain->refresh()->desired_state_version)->toBe(2)
        ->and($dnsZone->refresh()->desired_state_version)->toBe(2)
        ->and($cronJob->refresh()->desired_state_version)->toBe(2);
});

test('resync dispatches an update for every mail domain on the node, embedding every account own real current password', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = MailDomain::factory()->for($node)->create();
    $accountA = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales', 'password' => 'a-real-current-password']);
    $accountB = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'support', 'password' => 'another-real-current-password']);

    app(ResyncNode::class)->handleSystemInitiated($node);

    $operation = ProvisioningOperation::where('provisionable_type', $mailDomain->getMorphClass())
        ->where('provisionable_id', $mailDomain->id)
        ->where('operation', 'update')
        ->first();

    expect($operation)->not->toBeNull();

    $byLocalPart = collect($operation->payload['accounts'])->keyBy('local_part');

    expect($byLocalPart['sales']['password'])->toBe('a-real-current-password')
        ->and($byLocalPart['support']['password'])->toBe('another-real-current-password');
});

test('resync never touches a resource belonging to a different node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $otherNode = Node::factory()->create();
    $otherZone = DnsZone::factory()->for($otherNode)->create();

    app(ResyncNode::class)->handleSystemInitiated($node);

    expect(ProvisioningOperation::where('provisionable_type', $otherZone->getMorphClass())
        ->where('provisionable_id', $otherZone->id)
        ->exists())->toBeFalse();
});

test('resync records one system-initiated audit event with no attributed actor', function () {
    $node = Node::factory()->create();

    app(ResyncNode::class)->handleSystemInitiated($node);

    expect(AuditEvent::where('action', 'node.resynced')
        ->where('auditable_id', $node->id)
        ->whereNull('actor_id')
        ->exists())->toBeTrue();
});
