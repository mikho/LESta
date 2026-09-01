<?php

use App\Contracts\Provisioner;
use App\Enums\SslMode;
use App\Jobs\DispatchProvisioningOperation;
use App\Jobs\IssueAcmeCertificate;
use App\Models\DnsZone;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Queue;

function acmeTriggerWebDomain(Node $node, array $overrides = []): WebDomain
{
    $allocation = IpAllocation::factory()->for($node)->create();

    return WebDomain::factory()->for($node)->for($allocation)->create(array_merge([
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
    ], $overrides));
}

function acmeTriggerOperation(string $provisionableType, int $provisionableId, string $resourceId, string $capability, string $operation): ProvisioningOperation
{
    return ProvisioningOperation::factory()->pending()->create([
        'provisionable_type' => $provisionableType,
        'provisionable_id' => $provisionableId,
        'resource_id' => $resourceId,
        'capability' => $capability,
        'operation' => $operation,
    ]);
}

test('IssueAcmeCertificate is dispatched once web.nginx.v1 applies for a lets_encrypt domain with no certificate yet', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = acmeTriggerWebDomain($node);

    $operation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.nginx.v1', 'create');

    (new DispatchProvisioningOperation($operation->id))->handle(app(Provisioner::class));

    Queue::assertPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($webDomain));
});

test('IssueAcmeCertificate is not dispatched when ssl_mode is not lets_encrypt', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = acmeTriggerWebDomain($node, ['ssl_mode' => SslMode::None]);

    $operation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.nginx.v1', 'create');

    (new DispatchProvisioningOperation($operation->id))->handle(app(Provisioner::class));

    Queue::assertNotPushed(IssueAcmeCertificate::class);
});

test('IssueAcmeCertificate is not dispatched when a certificate is already issued', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = acmeTriggerWebDomain($node, ['certificate_issued_at' => now()->subDay()]);

    $operation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.nginx.v1', 'create');

    (new DispatchProvisioningOperation($operation->id))->handle(app(Provisioner::class));

    Queue::assertNotPushed(IssueAcmeCertificate::class);
});

test('IssueAcmeCertificate is not dispatched for a non-WebDomain provisionable', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $dnsZone = DnsZone::factory()->for($node)->create();

    $operation = acmeTriggerOperation($dnsZone->getMorphClass(), $dnsZone->id, $dnsZone->uuid, 'dns.bind9.v1', 'update');

    (new DispatchProvisioningOperation($operation->id))->handle(app(Provisioner::class));

    Queue::assertNotPushed(IssueAcmeCertificate::class);
});

test('IssueAcmeCertificate is not dispatched for the apache backend leg when nginx also fronts the domain', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $webDomain = acmeTriggerWebDomain($node, ['web_server' => 'apache']);

    // The apache leg (the real content-rendering capability in the "both"
    // profile) applying is not the public listener -- nginx is (see
    // TriggersAcmeCertificateIssuance's own doc comment) -- so this must not
    // trigger issuance yet.
    $apacheOperation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.apache.v1', 'create');
    (new DispatchProvisioningOperation($apacheOperation->id))->handle(app(Provisioner::class));

    Queue::assertNotPushed(IssueAcmeCertificate::class);

    $nginxOperation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.nginx.v1', 'create');
    (new DispatchProvisioningOperation($nginxOperation->id))->handle(app(Provisioner::class));

    Queue::assertPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($webDomain));
});

test('IssueAcmeCertificate is not dispatched for a suspend/unsuspend/delete/observe operation', function () {
    Queue::fake([IssueAcmeCertificate::class]);

    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = acmeTriggerWebDomain($node);

    foreach (['suspend', 'unsuspend', 'delete', 'observe'] as $verb) {
        $operation = acmeTriggerOperation($webDomain->getMorphClass(), $webDomain->id, $webDomain->uuid, 'web.nginx.v1', $verb);
        (new DispatchProvisioningOperation($operation->id))->handle(app(Provisioner::class));
    }

    Queue::assertNotPushed(IssueAcmeCertificate::class);
});
