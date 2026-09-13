<?php

use App\Actions\Provisioning\PreparesBackupDownload;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Backup;
use App\Models\DnsZone;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\Storage;

function sealForTest(string $plaintext, string $rawKey): string
{
    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $nonce, $tag);

    return $nonce.$ciphertext.$tag;
}

function observeCompletionOperation(Backup $backup, array $overrides = []): ProvisioningOperation
{
    return ProvisioningOperation::factory()->create(array_merge([
        'provisionable_type' => $backup->getMorphClass(),
        'provisionable_id' => $backup->id,
        'resource_id' => $backup->uuid,
        'capability' => 'backup.encrypted-artifacts.v1',
        'operation' => ProvisioningVerb::Observe,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ], $overrides));
}

test('a successful observe result decrypts the sealed artifact and writes a real downloadable copy', function () {
    Storage::fake('local');

    $node = Node::factory()->create();
    $rawKey = random_bytes(32);
    $backup = Backup::factory()->completed()->for($node)->create(['encryption_key' => bin2hex($rawKey)]);

    $plaintext = 'a real tar.gz worth of bytes, standing in for the archive';
    $sealed = sealForTest($plaintext, $rawKey);

    $operation = observeCompletionOperation($backup, ['data' => ['artifact_base64' => base64_encode($sealed)]]);

    app(PreparesBackupDownload::class)->handle($operation);
    $backup->refresh();

    expect($backup->download_path)->not->toBeNull()
        ->and($backup->download_ready_at)->not->toBeNull()
        ->and($backup->download_expires_at)->not->toBeNull()
        ->and($backup->download_expires_at->isFuture())->toBeTrue();

    expect(Storage::disk('local')->get($backup->download_path))->toBe($plaintext);
});

test('already_applied is treated the same as applied', function () {
    Storage::fake('local');

    $node = Node::factory()->create();
    $rawKey = random_bytes(32);
    $backup = Backup::factory()->completed()->for($node)->create(['encryption_key' => bin2hex($rawKey)]);

    $plaintext = 'more archive bytes';
    $sealed = sealForTest($plaintext, $rawKey);

    $operation = observeCompletionOperation($backup, [
        'status' => ProvisioningStatus::AlreadyApplied,
        'data' => ['artifact_base64' => base64_encode($sealed)],
    ]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect(Storage::disk('local')->get($backup->refresh()->download_path))->toBe($plaintext);
});

test('a wrong encryption key fails to decrypt without throwing, and leaves download fields untouched', function () {
    Storage::fake('local');

    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create(['encryption_key' => bin2hex(random_bytes(32))]);

    $sealed = sealForTest('some bytes', random_bytes(32));
    $operation = observeCompletionOperation($backup, ['data' => ['artifact_base64' => base64_encode($sealed)]]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect($backup->refresh()->download_path)->toBeNull();
});

test('malformed base64 does not throw and leaves download fields untouched', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create();

    $operation = observeCompletionOperation($backup, ['data' => ['artifact_base64' => 'not-valid-base64!!!']]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect($backup->refresh()->download_path)->toBeNull();
});

test('a create operation (not observe) is ignored entirely', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create();

    $operation = observeCompletionOperation($backup, [
        'operation' => ProvisioningVerb::Create,
        'data' => ['artifact_base64' => base64_encode('irrelevant')],
    ]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect($backup->refresh()->download_path)->toBeNull();
});

test('a failed observe operation is ignored entirely', function () {
    $node = Node::factory()->create();
    $backup = Backup::factory()->completed()->for($node)->create();

    $operation = observeCompletionOperation($backup, ['status' => ProvisioningStatus::Failed, 'data' => null]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect($backup->refresh()->download_path)->toBeNull();
});

test('a non-Backup provisionable is ignored', function () {
    $node = Node::factory()->create();
    $dnsZone = DnsZone::factory()->for($node)->create();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $dnsZone->getMorphClass(),
        'provisionable_id' => $dnsZone->id,
        'resource_id' => $dnsZone->uuid,
        'capability' => 'dns.bind9.v1',
        'operation' => ProvisioningVerb::Observe,
        'status' => ProvisioningStatus::Applied,
        'completed_at' => now(),
    ]);

    app(PreparesBackupDownload::class)->handle($operation);

    expect(true)->toBeTrue();
});
