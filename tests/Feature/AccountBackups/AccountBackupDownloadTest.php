<?php

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AccountBackupDownload;
use App\Models\AccountNodeIdentity;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\User;
use App\Models\WebDomain;
use App\Services\Provisioning\ProvisioningResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
});

/**
 * @return array{0: Account, 1: Node, 2: User, 3: AccountBackup}
 */
function accountWithCompletedBackup(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $site = WebDomain::factory()->for($node)->create();
    $account = $site->account;
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    MailDomain::factory()->for($account)->for($node)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files', 'mail']]);

    return [$account, $node, $owner, $backup];
}

/** The one-time token the node was given, read from the operation's upload address. */
function uploadTokenFor(AccountBackup $backup): string
{
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('provisionable_type', $backup->getMorphClass())->where('operation', ProvisioningVerb::Observe->value)->latest('id')->firstOrFail();

    return basename($operation->payload['upload_url']);
}

function putChunk(string $token, int $offset, string $body, bool $final = false)
{
    return test()->call('PUT', route('agent.account-backup-uploads', ['token' => $token]).'?offset='.$offset.($final ? '&final=1' : ''), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body);
}

test('an owner prepares a download: the node gets a one-time upload address and the key, only the hash is kept', function () {
    [$account, $node, $owner, $backup] = accountWithCompletedBackup();

    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup))->assertSessionHasNoErrors();

    $download = $backup->downloads()->sole();
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('operation', ProvisioningVerb::Observe->value)->sole();
    $token = basename($operation->payload['upload_url']);

    expect($download->status)->toBe('pending')
        ->and($token)->toHaveLength(64)
        ->and($download->token_hash)->toBe(hash('sha256', $token))
        ->and($download->token_hash)->not->toBe($token)
        ->and($operation->payload['upload_url'])->toStartWith('http')
        ->and($operation->payload['encryption_key'])->toBe($backup->encryption_key)
        ->and($operation->payload['artifact_path'])->toBe($backup->artifact_path)
        ->and($operation->payload['account']['username'])->toBe('lesta-t'.$account->id);

    // Asking again while one is in progress reuses it.
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup))->assertSessionHasNoErrors();

    expect($backup->downloads()->count())->toBe(1)
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Observe->value)->count())->toBe(1);
});

test('the node uploads chunks in order and the owner downloads the finished file', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup));
    $token = uploadTokenFor($backup);

    putChunk($token, 0, 'first-')->assertNoContent();
    putChunk($token, 6, 'second')->assertNoContent();

    expect($backup->downloads()->sole()->status)->toBe('pending');

    putChunk($token, 12, '', true)->assertNoContent();

    $download = $backup->downloads()->sole();

    expect($download->status)->toBe('ready')
        ->and($download->size_bytes)->toBe(12)
        ->and($download->expires_at->isFuture())->toBeTrue();

    $response = $this->actingAs($owner)->get(route('account-backups.download', [$backup, $download]));

    $response->assertOk()->assertHeader('content-type', 'application/gzip');
    expect($response->headers->get('content-disposition'))->toContain('.tar.gz')
        ->and($response->streamedContent())->toBe('first-second');

    $this->actingAs($owner)->get(route('account-backups.index'))
        ->assertInertia(fn ($page) => $page->where('backups.0.download.status', 'ready')->where('backups.0.download.size_bytes', 12));
});

test('the upload endpoint refuses a wrong offset, a replay, an unknown or finished token and oversized chunks', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup));
    $token = uploadTokenFor($backup);

    putChunk($token, 0, 'abc')->assertNoContent();

    putChunk($token, 0, 'abc')->assertStatus(409);
    putChunk($token, 99, 'abc')->assertStatus(409);
    putChunk(str_repeat('0', 64), 0, 'abc')->assertNotFound();
    putChunk('short', 0, 'abc')->assertNotFound();
    putChunk($token, 3, str_repeat('x', 5 * 1024 * 1024 + 1))->assertStatus(413);

    expect(Storage::disk('local')->get('account-backup-downloads/'.$backup->downloads()->sole()->uuid.'.tar.gz'))->toBe('abc');

    putChunk($token, 3, '', true)->assertNoContent();

    // A finished download no longer takes chunks.
    putChunk($token, 3, 'more')->assertNotFound();
});

test('an expired upload address is refused', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup));
    $token = uploadTokenFor($backup);
    $backup->downloads()->update(['expires_at' => now()->subMinute()]);

    putChunk($token, 0, 'abc')->assertNotFound();
});

test('a failed node report marks the download failed and removes the partial file', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup));
    $token = uploadTokenFor($backup);
    putChunk($token, 0, 'partial')->assertNoContent();

    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('operation', ProvisioningVerb::Observe->value)->sole();
    $operation->forceFill(['status' => ProvisioningStatus::Pending])->save();

    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult(ProvisioningStatus::Failed, 1, 'sha256:x', 'none', [['code' => 'decryption_failed', 'message' => 'The backup failed authentication.']], now(), null));

    $download = $backup->downloads()->sole();

    expect($download->status)->toBe('failed')
        ->and($download->error_message)->toBe('The backup failed authentication.')
        ->and(Storage::disk('local')->allFiles('account-backup-downloads'))->toBe([]);

    // The owner can try again.
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup))->assertSessionHasNoErrors();

    expect($backup->downloads()->count())->toBe(2);
});

test('the download is refused for another account, a member, an unready or expired copy and a backup that is not finished', function () {
    [$account, , $owner, $backup] = accountWithCompletedBackup();
    $member = Membership::factory()->for($account)->member()->create()->user;
    [, , $stranger] = accountWithCompletedBackup();
    $pending = AccountBackupDownload::factory()->create(['account_backup_id' => $backup->id]);
    $ready = AccountBackupDownload::factory()->create(['account_backup_id' => $backup->id, 'status' => 'ready', 'path' => 'account-backup-downloads/x.tar.gz', 'size_bytes' => 3, 'expires_at' => now()->addMinutes(10)]);
    Storage::disk('local')->put('account-backup-downloads/x.tar.gz', 'abc');
    $unfinished = AccountBackup::factory()->for($account)->for($backup->node)->create(['status' => ProvisioningStatus::Failed]);

    $this->actingAs($member)->post(route('account-backups.prepare-download', $backup))->assertForbidden();
    $this->actingAs($member)->get(route('account-backups.download', [$backup, $ready]))->assertForbidden();
    $this->actingAs($stranger)->get(route('account-backups.download', [$backup, $ready]))->assertForbidden();
    $this->actingAs($owner)->get(route('account-backups.download', [$backup, $pending]))->assertNotFound();

    $ready->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->actingAs($owner)->get(route('account-backups.download', [$backup, $ready]))->assertNotFound();
    $this->actingAs($owner)->post(route('account-backups.prepare-download', $unfinished))->assertSessionHasErrors('backup');
});

test('a download of another backup cannot be fetched through this one', function () {
    [$account, $node, $owner, $backup] = accountWithCompletedBackup();
    $other = AccountBackup::factory()->completed()->for($account)->for($node)->create();
    $foreign = AccountBackupDownload::factory()->create(['account_backup_id' => $other->id, 'status' => 'ready', 'path' => 'account-backup-downloads/y.tar.gz', 'expires_at' => now()->addMinutes(10)]);
    Storage::disk('local')->put('account-backup-downloads/y.tar.gz', 'secret');

    $this->actingAs($owner)->get(route('account-backups.download', [$backup, $foreign]))->assertNotFound();
});

test('a backup above the download limit is refused', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    $backup->forceFill(['size_bytes' => AccountBackupDownload::MAX_BYTES + 1])->save();

    $this->actingAs($owner)->post(route('account-backups.prepare-download', $backup))->assertSessionHasErrors('backup');

    expect($backup->downloads()->count())->toBe(0);
});

test('expired downloads and their files are pruned', function () {
    [, , , $backup] = accountWithCompletedBackup();
    Storage::disk('local')->put('account-backup-downloads/old.tar.gz', 'old');
    Storage::disk('local')->put('account-backup-downloads/new.tar.gz', 'new');
    $old = AccountBackupDownload::factory()->create(['account_backup_id' => $backup->id, 'status' => 'ready', 'path' => 'account-backup-downloads/old.tar.gz', 'expires_at' => now()->subMinute()]);
    $current = AccountBackupDownload::factory()->create(['account_backup_id' => $backup->id, 'status' => 'ready', 'path' => 'account-backup-downloads/new.tar.gz', 'expires_at' => now()->addMinutes(30)]);

    $this->artisan('account-backups:prune-downloads')->assertSuccessful();

    expect(AccountBackupDownload::find($old->id))->toBeNull()
        ->and(AccountBackupDownload::find($current->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists('account-backup-downloads/old.tar.gz'))->toBeFalse()
        ->and(Storage::disk('local')->exists('account-backup-downloads/new.tar.gz'))->toBeTrue();
});

test('deleting a backup removes its prepared downloads, and the prune sweeps stray old files', function () {
    [, , $owner, $backup] = accountWithCompletedBackup();
    Storage::disk('local')->put('account-backup-downloads/mine.tar.gz', 'x');
    Storage::disk('local')->put('account-backup-downloads/stray-old.tar.gz', 'x');
    Storage::disk('local')->put('account-backup-downloads/stray-new.tar.gz', 'x');
    AccountBackupDownload::factory()->create(['account_backup_id' => $backup->id, 'status' => 'ready', 'path' => 'account-backup-downloads/mine.tar.gz', 'expires_at' => now()->addMinutes(30)]);

    touch(Storage::disk('local')->path('account-backup-downloads/stray-old.tar.gz'), now()->subHours(5)->getTimestamp());

    $this->artisan('account-backups:prune-downloads')->assertSuccessful();

    expect(Storage::disk('local')->exists('account-backup-downloads/stray-old.tar.gz'))->toBeFalse()
        ->and(Storage::disk('local')->exists('account-backup-downloads/stray-new.tar.gz'))->toBeTrue()
        ->and(Storage::disk('local')->exists('account-backup-downloads/mine.tar.gz'))->toBeTrue();

    $this->actingAs($owner)->delete(route('account-backups.destroy', $backup))->assertSessionHasNoErrors();

    expect(Storage::disk('local')->exists('account-backup-downloads/mine.tar.gz'))->toBeFalse();
});
