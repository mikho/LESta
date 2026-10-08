<?php

use App\Enums\ProvisioningStatus;
use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;

function domainWithLogs(): array
{
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    return [$webDomain, $owner];
}

test('a log read is dispatched as a files observe with a fixed kind and mode and no path', function () {
    [$webDomain, $owner] = domainWithLogs();

    $response = $this->actingAs($owner)
        ->postJson(route('domains.logs.observe', $webDomain), ['kind' => 'error', 'mode' => 'tail', 'lines' => 200])
        ->assertStatus(202);

    $operation = ProvisioningOperation::find($response->json('operation_id'));

    expect($operation->capability)->toBe('files.manager.v1')
        ->and($operation->operation->value)->toBe('observe')
        ->and($operation->payload)->toMatchArray(['log_kind' => 'error', 'log_mode' => 'tail', 'log_lines' => 200])
        ->and($operation->payload)->not->toHaveKey('path');
});

test('any member can read logs, a stranger cannot', function () {
    [$webDomain] = domainWithLogs();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;
    [, $stranger] = domainWithLogs();

    $this->actingAs($member)->postJson(route('domains.logs.observe', $webDomain), ['kind' => 'access', 'mode' => 'summary'])->assertStatus(202);
    $this->actingAs($member)->get(route('domains.logs.index', $webDomain))->assertOk();
    $this->actingAs($stranger)->postJson(route('domains.logs.observe', $webDomain), ['kind' => 'access', 'mode' => 'summary'])->assertForbidden();
    $this->actingAs($stranger)->get(route('domains.logs.index', $webDomain))->assertForbidden();
});

test('only known kinds, modes and line counts are accepted', function (array $input) {
    [$webDomain, $owner] = domainWithLogs();

    $this->actingAs($owner)->postJson(route('domains.logs.observe', $webDomain), $input + ['kind' => 'access', 'mode' => 'tail'])->assertStatus(422);
})->with([
    'a path as the kind' => [['kind' => '../../etc/shadow']],
    'an unknown kind' => [['kind' => 'auth']],
    'an unknown mode' => [['mode' => 'follow']],
    'too many lines' => [['lines' => 100000]],
]);

test('a finished download streams the decoded log as a file', function () {
    [$webDomain, $owner] = domainWithLogs();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'resource_id' => $webDomain->uuid,
        'capability' => 'files.manager.v1',
        'status' => ProvisioningStatus::Applied,
        'data' => ['kind' => 'access', 'filename' => 'access.log', 'content_base64' => base64_encode("line one\nline two\n")],
    ]);

    $response = $this->actingAs($owner)->get(route('domains.logs.download', [$webDomain, $operation]));

    $response->assertOk()->assertHeader('content-disposition', 'attachment; filename='.$webDomain->domain.'-access.log');

    expect($response->streamedContent())->toBe("line one\nline two\n");
});

test('a download is refused for another domain, another capability, or a non-log result', function () {
    [$webDomain, $owner] = domainWithLogs();
    [$other] = domainWithLogs();

    $make = fn (WebDomain $domain, string $capability, array $data) => ProvisioningOperation::factory()->create([
        'provisionable_type' => $domain->getMorphClass(),
        'provisionable_id' => $domain->id,
        'resource_id' => $domain->uuid,
        'capability' => $capability,
        'status' => ProvisioningStatus::Applied,
        'data' => $data,
    ]);

    $log = ['kind' => 'access', 'content_base64' => base64_encode('x')];

    $this->actingAs($owner)->get(route('domains.logs.download', [$webDomain, $make($other, 'files.manager.v1', $log)]))->assertNotFound();
    $this->actingAs($owner)->get(route('domains.logs.download', [$webDomain, $make($webDomain, 'web.nginx.v1', $log)]))->assertNotFound();
    $this->actingAs($owner)->get(route('domains.logs.download', [$webDomain, $make($webDomain, 'files.manager.v1', ['type' => 'file', 'content_base64' => base64_encode('secret')])]))->assertNotFound();
    $this->actingAs($owner)->get(route('domains.logs.download', [$webDomain, $make($webDomain, 'files.manager.v1', ['kind' => '../x', 'content_base64' => base64_encode('x')])]))->assertNotFound();
});

test('the page carries the recorded daily requests and bandwidth of the last 30 days', function () {
    [$webDomain, $owner] = domainWithLogs();

    foreach ([[2, 100, 5000], [2, 50, 1000], [40, 999, 999]] as [$daysAgo, $requests, $bytes]) {
        UsageSnapshot::factory()->create([
            'account_id' => $webDomain->account_id,
            'node_id' => $webDomain->node_id,
            'snapshotable_type' => $webDomain->getMorphClass(),
            'snapshotable_id' => $webDomain->id,
            'request_count' => $requests,
            'bytes_sent' => $bytes,
            'collected_at' => now()->subDays($daysAgo)->setTime(12, 0),
        ]);
    }

    $this->actingAs($owner)->get(route('domains.logs.index', $webDomain))
        ->assertInertia(fn ($page) => $page->component('domains/logs')
            ->has('history', 1)
            ->where('history.0.requests', 150)
            ->where('history.0.bytes_sent', 6000));
});
