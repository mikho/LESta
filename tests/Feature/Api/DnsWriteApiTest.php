<?php

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;

/**
 * @param  list<string>  $abilities
 * @return array{0: Account, 1: array<string, string>, 2: DnsZone}
 */
function dnsWriteOwner(array $abilities = ['dns:read', 'dns:write']): array
{
    [$account, , $auth, $node] = apiWriteOwner($abilities, ['dns.bind9.v1'], ['dns_zones' => 5, 'dns_records' => 5]);

    return [$account, $auth, DnsZone::factory()->for($account)->for($node)->create(['domain' => 'zone.example'])];
}

function dnsPath(Account $account, string $suffix = ''): string
{
    return '/api/v1/accounts/'.$account->public_id.'/dns-zones'.$suffix;
}

test('a zone is created, changed, suspended and deleted, each with an operation', function () {
    [$account, $auth] = dnsWriteOwner();

    $created = $this->postJson(dnsPath($account), ['domain' => 'New-Zone.example', 'ttl' => 300], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.domain', 'new-zone.example')
        ->assertJsonPath('data.ttl', 300);

    $this->getJson('/api/v1/operations/'.$created->json('operation.id'), $auth)->assertOk()->assertJsonPath('data.action', 'dns_zone.created');

    $path = dnsPath($account, '/'.$created->json('data.id'));

    $this->patchJson($path, ['ttl' => 7200], $auth)->assertAccepted()->assertJsonPath('data.ttl', 7200)->assertJsonPath('data.domain', 'new-zone.example');
    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted()->assertJsonPath('data', null);

    expect(DnsZone::query()->where('domain', 'new-zone.example')->exists())->toBeFalse();
});

test('a record is added, read, changed, suspended and deleted', function () {
    [$account, $auth, $zone] = dnsWriteOwner();
    $records = dnsPath($account, '/'.$zone->uuid.'/records');

    $created = $this->postJson($records, ['name' => 'mail', 'type' => 'MX', 'priority' => 10, 'value' => 'mx.zone.example'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.type', 'MX')
        ->assertJsonPath('data.priority', 10);

    $this->getJson('/api/v1/operations/'.$created->json('operation.id'), $auth)->assertOk()->assertJsonPath('data.action', 'dns_record.created');

    $path = $records.'/'.$created->json('data.id');

    $this->getJson($path, $auth)->assertOk()->assertJsonPath('data.value', 'mx.zone.example');
    $this->patchJson($path, ['priority' => 20], $auth)->assertAccepted()->assertJsonPath('data.priority', 20)->assertJsonPath('data.value', 'mx.zone.example');
    $this->patchJson($path, ['value' => 'not a host name!'], $auth)->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted();

    expect(DnsRecord::query()->count())->toBe(0);
});

test('the panel rules apply to records', function () {
    [$account, $auth, $zone] = dnsWriteOwner();
    $records = dnsPath($account, '/'.$zone->uuid.'/records');

    $this->postJson($records, ['name' => 'www', 'type' => 'A', 'value' => '2001:db8::1'], $auth)->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson($records, ['name' => 'mail', 'type' => 'MX', 'value' => 'mx.zone.example'], $auth)->assertUnprocessable()->assertJsonValidationErrors('priority');

    DnsRecord::factory()->for($zone)->count(5)->create();
    $this->postJson($records, ['name' => 'www', 'type' => 'A', 'value' => '192.0.2.1'], $auth)->assertUnprocessable();
});

test('a record is found only inside its own zone and account', function () {
    [$account, $auth, $zone] = dnsWriteOwner();
    $otherZone = DnsZone::factory()->for($account)->create();
    $record = DnsRecord::factory()->for($otherZone)->create();
    $foreign = DnsZone::factory()->create();

    $this->getJson(dnsPath($account, '/'.$zone->uuid.'/records/'.$record->uuid), $auth)->assertNotFound();
    $this->deleteJson(dnsPath($account, '/'.$zone->uuid.'/records/'.$record->uuid), [], $auth)->assertNotFound();
    $this->deleteJson(dnsPath($account, '/'.$foreign->uuid), [], $auth)->assertNotFound();

    expect(DnsRecord::query()->whereKey($record->id)->exists())->toBeTrue();
});

test('dns writes need dns:write', function () {
    [$account, $auth, $zone] = dnsWriteOwner(['dns:read', 'web:write']);

    $this->postJson(dnsPath($account), ['domain' => 'x.example'], $auth)->assertForbidden();
    $this->deleteJson(dnsPath($account, '/'.$zone->uuid), [], $auth)->assertForbidden();
});
