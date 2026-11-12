<?php

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Services\Provisioning\RequestDigest;
use Illuminate\Support\Str;

test('the digest of every shared vector is what the agent computes', function (array $vector) {
    $payload = json_decode($vector['payload_wire'], true, 512, JSON_THROW_ON_ERROR);

    expect(RequestDigest::for(
        $vector['capability'],
        $vector['operation'],
        $vector['resource_id'],
        $vector['desired_state_version'],
        $payload,
    ))->toBe($vector['digest']);
})->with(fn () => collect(json_decode(
    file_get_contents(dirname(__DIR__, 3).'/docs/protocol/request-digest-vectors.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
))->mapWithKeys(fn (array $vector) => [$vector['name'] => [$vector]])->all());

test('the digest does not depend on the order of the payload keys', function () {
    expect(RequestDigest::for('c', 'create', 'r', 1, ['a' => 1, 'b' => ['x' => 1, 'y' => 2]]))
        ->toBe(RequestDigest::for('c', 'create', 'r', 1, ['b' => ['y' => 2, 'x' => 1], 'a' => 1]));
});

test('the digest changes with every field it covers', function () {
    $base = RequestDigest::for('web.nginx.v1', 'update', 'r', 1, ['a' => 1]);

    expect(RequestDigest::for('web.apache.v1', 'update', 'r', 1, ['a' => 1]))->not->toBe($base)
        ->and(RequestDigest::for('web.nginx.v1', 'delete', 'r', 1, ['a' => 1]))->not->toBe($base)
        ->and(RequestDigest::for('web.nginx.v1', 'update', 's', 1, ['a' => 1]))->not->toBe($base)
        ->and(RequestDigest::for('web.nginx.v1', 'update', 'r', 2, ['a' => 1]))->not->toBe($base)
        ->and(RequestDigest::for('web.nginx.v1', 'update', 'r', 1, ['a' => 2]))->not->toBe($base);
});

test('a recorded operation still verifies after the database and the wire have re-encoded its payload', function () {
    $operation = app(RecordsProvisioningOperation::class)->record(
        WebDomain::factory()->create(),
        'web.nginx.v1',
        ProvisioningVerb::Create,
        ['domain' => 'räksmörgås.test', 'paths' => ['/var/www', '/tmp'], 'nested' => ['z' => true, 'a' => null], 'empty' => []],
        (string) Str::uuid(),
    );

    $wire = json_decode(
        json_encode(ProvisioningOperation::query()->findOrFail($operation->id)->toEnvelopeArray(), JSON_THROW_ON_ERROR),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(RequestDigest::for(
        $wire['capability'],
        $wire['operation'],
        $wire['resource_id'],
        $wire['desired_state_version'],
        $wire['payload'],
    ))->toBe($wire['request_digest']);
});

test('a value that is not JSON cannot be digested', function () {
    RequestDigest::canonicalJson(['when' => new DateTimeImmutable]);
})->throws(InvalidArgumentException::class);

test('the migration seals again an operation still waiting for its node and leaves a finished one alone', function () {
    $legacy = 'sha256:'.str_repeat('a', 64);

    $waiting = ProvisioningOperation::factory()->create(['status' => ProvisioningStatus::Dispatched, 'request_digest' => $legacy]);
    $finished = ProvisioningOperation::factory()->create(['status' => ProvisioningStatus::Applied, 'request_digest' => $legacy]);

    (require database_path('migrations/2026_11_12_165747_recompute_request_digest_of_open_operations.php'))->up();

    expect($waiting->refresh()->request_digest)->toBe(RequestDigest::for(
        $waiting->capability,
        $waiting->operation->value,
        $waiting->resource_id,
        $waiting->desired_state_version,
        $waiting->payload,
    ))->and($finished->refresh()->request_digest)->toBe($legacy);
});
