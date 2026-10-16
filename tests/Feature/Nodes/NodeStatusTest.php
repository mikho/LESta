<?php

use App\Console\Commands\PingNodes;
use App\Enums\NodeCapabilityStatus;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

function statusHeartbeat(array $overrides = []): array
{
    return array_merge([
        'protocol_version' => '1',
        'agent_version' => '1.0.73',
        'ubuntu_release' => '24.04',
        'architecture' => 'amd64',
        'timestamp' => now()->toIso8601String(),
        'capabilities' => [],
    ], $overrides);
}

test('a heartbeat records the service states and a capability with a service down is stopped', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $nginx = NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $mail = NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $this->withHeader('Authorization', 'Bearer '.$credential)->postJson('/agent/v1/heartbeat', statusHeartbeat([
        'capabilities' => [
            ['capability' => 'web.nginx.v1', 'health_state' => 'healthy'],
            ['capability' => 'mail.smtp-imap.v1', 'health_state' => 'unhealthy'],
        ],
        'services' => [
            ['name' => 'nginx', 'state' => 'active'],
            ['name' => 'dovecot', 'state' => 'failed'],
        ],
    ]))->assertOk();

    expect($node->fresh()->service_states)->toBe([['name' => 'nginx', 'state' => 'active'], ['name' => 'dovecot', 'state' => 'failed']])
        ->and($nginx->fresh()->status)->toBe(NodeCapabilityStatus::Running)
        ->and($mail->fresh()->status)->toBe(NodeCapabilityStatus::Stopped);
});

test('a heartbeat from an older agent keeps the last service states', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $node->forceFill(['service_states' => [['name' => 'nginx', 'state' => 'active']]])->save();

    $this->withHeader('Authorization', 'Bearer '.$credential)->postJson('/agent/v1/heartbeat', statusHeartbeat())->assertOk();

    expect($node->fresh()->service_states)->toBe([['name' => 'nginx', 'state' => 'active']]);
});

test('a service name that is not a unit name is refused', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');

    $this->withHeader('Authorization', 'Bearer '.$credential)->postJson('/agent/v1/heartbeat', statusHeartbeat([
        'services' => [['name' => '<script>', 'state' => 'active']],
    ]))->assertUnprocessable();
});

test('nodes:ping records the round trip time or a missing reply', function () {
    $up = Node::factory()->create(['hostname' => 'up.example.test']);
    $down = Node::factory()->create(['hostname' => 'down.example.test']);

    Process::fake([
        '*up.example.test*' => Process::result("64 bytes from 10.0.0.50: icmp_seq=1 ttl=64 time=0.412 ms\n"),
        '*down.example.test*' => Process::result('', '', 1),
    ]);

    $this->artisan('nodes:ping')->assertSuccessful();

    expect($up->fresh()->ping_ok)->toBeTrue()
        ->and($up->fresh()->ping_ms)->toBe(0.41)
        ->and($down->fresh()->ping_ok)->toBeFalse()
        ->and($down->fresh()->ping_ms)->toBeNull()
        ->and($down->fresh()->pinged_at)->not->toBeNull();
});

test('the ping output parser reads the time and nothing else', function () {
    expect(PingNodes::roundTrip('64 bytes: icmp_seq=1 ttl=64 time=12.5 ms'))->toBe(12.5)
        ->and(PingNodes::roundTrip('64 bytes: time<1 ms'))->toBe(1.0)
        ->and(PingNodes::roundTrip('1 packets transmitted, 0 received'))->toBeNull();
});

test('a provider admin sees every node on the dashboard', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $online = Node::factory()->create(['name' => 'a-node']);
    $online->forceFill(['last_seen_at' => now()->subSeconds(30), 'service_states' => [['name' => 'nginx', 'state' => 'active']], 'ping_ok' => true, 'ping_ms' => 0.5, 'pinged_at' => now()])->save();
    $offline = Node::factory()->create(['name' => 'b-node']);
    $offline->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('nodeStatus', 2)
        ->where('nodeStatus.0.name', 'a-node')
        ->where('nodeStatus.0.heartbeat.online', true)
        ->where('nodeStatus.0.ping.ms', 0.5)
        ->where('nodeStatus.0.services.0.state', 'active')
        ->where('nodeStatus.1.heartbeat.online', false)
        ->where('nodeStatus.1.ping', null));
});

test('a tenant sees no node status', function () {
    $owner = Membership::factory()->owner()->create()->user;
    Node::factory()->create();

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('nodeStatus', null));
});
