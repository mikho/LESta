<?php

use App\Actions\Provisioning\ResolvesCronCapableNode;
use App\Exceptions\NoCronCapableNodeAvailableException;
use App\Models\CronJob;
use App\Models\Node;
use App\Models\NodeCapability;

test('toProvisioningPayload returns exactly the expected keys, with no execution-history data', function () {
    $node = Node::factory()->create();
    $cronJob = CronJob::factory()->for($node)->create([
        'minute' => '0',
        'hour' => '3',
        'day_of_month' => '*',
        'month' => '*',
        'day_of_week' => '*',
        'command' => 'php artisan backup:run',
    ]);

    $payload = $cronJob->toProvisioningPayload();

    expect($payload)->toBe([
        'minute' => '0',
        'hour' => '3',
        'day_of_month' => '*',
        'month' => '*',
        'day_of_week' => '*',
        'command' => 'php artisan backup:run',
        'suspended' => false,
    ])
        ->and(array_keys($payload))->toBe(['minute', 'hour', 'day_of_month', 'month', 'day_of_week', 'command', 'suspended'])
        ->and($payload)->not->toHaveKey('last_run_at')
        ->and($payload)->not->toHaveKey('exit_code')
        ->and($payload)->not->toHaveKey('output')
        ->and($payload)->not->toHaveKey('execution_log');
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $cronJob = CronJob::factory()->suspended()->for($node)->create();

    expect($cronJob->toProvisioningPayload()['suspended'])->toBeTrue();
});

test('resolve returns the first non-suspended node with an active scheduler.account-cron.v1 capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    [$resolvedNode, $capability] = app(ResolvesCronCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capability)->toBe('scheduler.account-cron.v1');
});

test('resolve throws when no node has an active cron capability', function () {
    Node::factory()->create();

    app(ResolvesCronCapableNode::class)->resolve();
})->throws(NoCronCapableNodeAvailableException::class);

test('resolveFor returns the capability string for an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);

    expect(app(ResolvesCronCapableNode::class)->resolveFor($node))->toBe('scheduler.account-cron.v1');
});

test('resolveFor throws when the assigned node has no active cron capability', function () {
    $node = Node::factory()->create();

    app(ResolvesCronCapableNode::class)->resolveFor($node);
})->throws(NoCronCapableNodeAvailableException::class);
