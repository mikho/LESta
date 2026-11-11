<?php

use App\Models\Backup;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\Storage;

/**
 * A backup the admin has asked to download, with the token its node would send chunks with.
 *
 * @return array{0: Backup, 1: string}
 */
function backupAwaitingUpload(): array
{
    $token = bin2hex(random_bytes(32));
    $backup = Backup::factory()->completed()->for(Node::factory())->create();
    $backup->forceFill([
        'download_token_hash' => Backup::hashDownloadToken($token),
        'download_expires_at' => now()->addHour(),
    ])->save();

    return [$backup, $token];
}

function sendChunk(string $token, int $offset, string $body, bool $final = false)
{
    return test()->call('PUT', route('agent.backup-uploads', array_filter(['offset' => $offset, 'final' => $final ? 1 : null], fn ($v) => $v !== null)), [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $body);
}

test('preparing a download asks the node to stream it to a one-time address', function () {
    Storage::fake('local');
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $backup = Backup::factory()->completed()->for($node)->create();

    $this->actingAs($admin)->post(route('backups.prepare-download', $backup))->assertSessionHasNoErrors();

    $backup->refresh();
    $payload = ProvisioningOperation::query()->where('provisionable_id', $backup->id)->latest('id')->firstOrFail()->payload;

    expect($payload['upload_url'])->toBe(route('agent.backup-uploads'))
        ->and($payload['upload_token'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($payload['encryption_key'])->toBe($backup->encryption_key)
        ->and($backup->download_token_hash)->toBe(Backup::hashDownloadToken($payload['upload_token']))
        ->and($backup->download_ready_at)->toBeNull();
});

test('chunks are stored in order and the download is ready only after the final one', function () {
    Storage::fake('local');
    [$backup, $token] = backupAwaitingUpload();

    sendChunk($token, 0, 'first ')->assertNoContent();
    sendChunk($token, 6, 'second')->assertNoContent();

    expect($backup->refresh()->download_ready_at)->toBeNull()
        ->and($backup->download_token_hash)->not->toBeNull();

    // A half-sent copy cannot be fetched.
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $this->actingAs($admin)->get(route('backups.download', $backup))->assertRedirect();
    expect(Storage::disk('local')->exists($backup->download_path))->toBeTrue();

    sendChunk($token, 12, '', final: true)->assertNoContent();

    $backup->refresh();

    expect(Storage::disk('local')->get($backup->download_path))->toBe('first second')
        ->and($backup->download_ready_at)->not->toBeNull()
        ->and($backup->download_token_hash)->toBeNull();

    // The token is spent.
    sendChunk($token, 12, 'more')->assertNotFound();
});

test('a chunk at the wrong offset is refused and changes nothing', function () {
    Storage::fake('local');
    [$backup, $token] = backupAwaitingUpload();

    sendChunk($token, 0, 'abc')->assertNoContent();
    sendChunk($token, 0, 'abc')->assertStatus(409);
    sendChunk($token, 10, 'abc')->assertStatus(409);

    expect(Storage::disk('local')->get($backup->refresh()->download_path))->toBe('abc');
});

test('an unknown, malformed or expired token is a plain 404', function () {
    Storage::fake('local');
    [$backup, $token] = backupAwaitingUpload();

    sendChunk(bin2hex(random_bytes(32)), 0, 'x')->assertNotFound();
    sendChunk('short', 0, 'x')->assertNotFound();

    $backup->forceFill(['download_expires_at' => now()->subMinute()])->save();
    sendChunk($token, 0, 'x')->assertNotFound();
});

test('a chunk over the limit is refused', function () {
    Storage::fake('local');
    [, $token] = backupAwaitingUpload();

    sendChunk($token, 0, str_repeat('a', 5 * 1024 * 1024 + 1))->assertStatus(413);
});

test('pruning also clears a token whose upload never started', function () {
    [$backup] = backupAwaitingUpload();
    $backup->forceFill(['download_expires_at' => now()->subMinute()])->save();

    $this->artisan('backups:prune-expired-downloads')->assertSuccessful();

    expect($backup->refresh()->download_token_hash)->toBeNull()
        ->and($backup->download_expires_at)->toBeNull();
});
