<?php

use App\Actions\Metrics\CollectUsageMetrics;
use App\Enums\ProvisioningStatus;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\MetricsCollection;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

test('a node with no metrics capability yet is silently skipped, not a hard failure', function () {
    $node = Node::factory()->create();
    TenantDatabase::factory()->for($node)->create();

    $result = app(CollectUsageMetrics::class)->handle($node);

    expect($result)->toBeNull()
        ->and(MetricsCollection::count())->toBe(0);
});

test('a metrics-capable node with nothing to measure dispatches nothing', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);

    $result = app(CollectUsageMetrics::class)->handle($node);

    expect($result)->toBeNull()
        ->and(MetricsCollection::count())->toBe(0);
});

test('a metrics-capable node with real resources dispatches a real observe operation with the correct payload shape', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    $mailDomain = MailDomain::factory()->for($node)->create(['domain' => 'example.com']);
    $mailAccount = MailAccount::factory()->for($mailDomain)->create(['local_part' => 'sales']);

    $tenantDatabase = TenantDatabase::factory()->for($node)->create([
        'database_name' => 'lesta_1_app1',
        'stats_user' => 'lesta_1_app1_ro',
        'stats_password' => 'a-real-plaintext-stats-password',
    ]);

    $webDomain = WebDomain::factory()->for($node)->create();

    $collection = app(CollectUsageMetrics::class)->handle($node);

    expect($collection)->not->toBeNull()
        ->and($collection->node_id)->toBe($node->id);

    $operation = ProvisioningOperation::where('provisionable_type', $collection->getMorphClass())
        ->where('provisionable_id', $collection->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->capability)->toBe('metrics.usage.v1')
        ->and($operation->operation->value)->toBe('observe')
        ->and($operation->status)->toBe(ProvisioningStatus::Applied);

    $payload = $operation->payload;

    expect($payload['mail_accounts'])->toHaveCount(1)
        ->and($payload['mail_accounts'][0]['resource_uuid'])->toBe($mailAccount->uuid)
        ->and($payload['mail_accounts'][0]['domain'])->toBe('example.com')
        ->and($payload['mail_accounts'][0]['local_part'])->toBe('sales')
        ->and($payload['tenant_databases'])->toHaveCount(1)
        ->and($payload['tenant_databases'][0]['resource_uuid'])->toBe($tenantDatabase->uuid)
        ->and($payload['tenant_databases'][0]['database_name'])->toBe('lesta_1_app1')
        ->and($payload['tenant_databases'][0]['stats_password'])->toBe('a-real-plaintext-stats-password')
        ->and($payload['web_resources'])->toHaveCount(1)
        ->and($payload['web_resources'][0]['resource_uuid'])->toBe($webDomain->uuid)
        ->and($payload['web_resources'][0]['web_server'])->toBe('nginx');
});

test('an apache-only node (no nginx capability) selects apache as the web_server', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    $webDomain = WebDomain::factory()->for($node)->create();

    $collection = app(CollectUsageMetrics::class)->handle($node);

    $operation = ProvisioningOperation::where('provisionable_type', $collection->getMorphClass())
        ->where('provisionable_id', $collection->id)
        ->first();

    expect($operation->payload['web_resources'][0]['web_server'])->toBe('apache');
});

test('a both-profile node (nginx and apache both active) still selects nginx as the web_server', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'metrics.usage.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    WebDomain::factory()->for($node)->create();

    $collection = app(CollectUsageMetrics::class)->handle($node);

    $operation = ProvisioningOperation::where('provisionable_type', $collection->getMorphClass())
        ->where('provisionable_id', $collection->id)
        ->first();

    expect($operation->payload['web_resources'][0]['web_server'])->toBe('nginx');
});
