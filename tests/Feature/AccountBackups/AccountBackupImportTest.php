<?php

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AccountBackupDestination;
use App\Models\AccountBackupImport;
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
 * @return array{0: Account, 1: Node, 2: User}
 */
function accountForImport(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $site = WebDomain::factory()->for($node)->create();
    $account = $site->account;
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    MailDomain::factory()->for($account)->for($node)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    return [$account, $node, $owner];
}

function importChunk(AccountBackupImport|string $import, int $offset, string $body, bool $final = false)
{
    $url = is_string($import) ? $import : route('account-backups.import.chunk', $import);

    return test()->call('PUT', $url.'?offset='.$offset.($final ? '&final=1' : ''), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $body);
}

function completeImport(ProvisioningOperation $operation, ProvisioningStatus $status, array $data = [], array $errors = []): void
{
    $operation->forceFill(['status' => ProvisioningStatus::Pending])->save();

    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult($status, 1, 'sha256:x', 'none', $errors, now(), $data));
}

test('an owner imports a file from their own storage: the node gets the destination, the object and a fresh key', function () {
    [$account, $node, $owner] = accountForImport();
    AccountBackupDestination::factory()->create(['account_id' => $account->id]);

    $this->actingAs($owner)->post(route('account-backups.import.storage'), ['node' => $node->uuid, 'object_key' => 'lesta/acct/2026-10-09-manual.tar.gz', 'label' => 'From the bucket'])
        ->assertSessionHasNoErrors();

    $backup = AccountBackup::sole();
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->sole();

    expect($backup->kind)->toBe('imported')
        ->and($backup->label)->toBe('From the bucket')
        ->and($operation->operation)->toBe(ProvisioningVerb::Create)
        ->and($operation->payload['object_key'])->toBe('lesta/acct/2026-10-09-manual.tar.gz')
        ->and($operation->payload['destination']['bucket'])->toBe($account->backupDestination->bucket)
        ->and($operation->payload['encryption_key'])->toBe($backup->encryption_key)
        ->and($operation->payload['account']['username'])->toBe('lesta-t'.$account->id)
        ->and($operation)->not->toHaveKey('payload.source_url');

    completeImport($operation, ProvisioningStatus::Applied, ['parts' => ['files', 'databases'], 'size_bytes' => 99, 'checksum' => 'sha256:'.str_repeat('a', 64), 'artifact_path' => '/x/imp.acct.enc']);

    $backup->refresh();

    expect($backup->isComplete())->toBeTrue()
        ->and($backup->parts)->toBe(['files', 'databases'])
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Update->value)->count())->toBe(0);
});

test('an import from storage needs saved storage and a safe file name', function () {
    [$account, $node, $owner] = accountForImport();

    $this->actingAs($owner)->post(route('account-backups.import.storage'), ['node' => $node->uuid, 'object_key' => 'a.tar.gz'])
        ->assertSessionHasErrors('object_key');

    AccountBackupDestination::factory()->create(['account_id' => $account->id]);

    foreach (['../a.tar.gz', '/a.tar.gz', 'a//b.tar.gz', 'a b.tar.gz', ''] as $key) {
        $this->actingAs($owner)->post(route('account-backups.import.storage'), ['node' => $node->uuid, 'object_key' => $key])
            ->assertSessionHasErrors('object_key');
    }

    expect(AccountBackup::count())->toBe(0);
});

test('an uploaded file is received in order, then handed to the node at a one-time address', function () {
    [, $node, $owner] = accountForImport();

    $begin = $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid, 'label' => 'My laptop copy'])->assertOk();
    $import = AccountBackupImport::sole();
    $gz = "\x1f\x8b".'first-';

    importChunk($import, 0, $gz)->assertOk()->assertJson(['received' => strlen($gz)]);
    importChunk($import, 0, $gz)->assertStatus(409);
    importChunk($import, strlen($gz), 'second')->assertOk();
    importChunk($import, strlen($gz) + 6, '', true)->assertOk()->assertJson(['status' => 'started']);

    $backup = AccountBackup::sole();
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->sole();
    $token = basename($operation->payload['source_url']);
    $import->refresh();

    expect($begin->json('max_bytes'))->toBe(AccountBackupImport::MAX_BYTES)
        ->and($backup->kind)->toBe('imported')
        ->and($backup->label)->toBe('My laptop copy')
        ->and($import->status)->toBe('ready')
        ->and($import->account_backup_id)->toBe($backup->id)
        ->and($import->token_hash)->toBe(hash('sha256', $token))
        ->and($import->size_bytes)->toBe(strlen($gz) + 6)
        ->and($operation->payload)->not->toHaveKey('destination');

    $fetched = $this->get(route('agent.account-backup-imports', ['token' => $token]));
    $fetched->assertOk();
    expect($fetched->streamedContent())->toBe($gz.'second');

    $this->get(route('agent.account-backup-imports', ['token' => str_repeat('0', 64)]))->assertNotFound();
    $this->get(route('agent.account-backup-imports', ['token' => 'short']))->assertNotFound();

    // The staged file goes as soon as the node reports, whatever the outcome.
    completeImport($operation, ProvisioningStatus::Applied, ['parts' => ['files'], 'size_bytes' => 9, 'checksum' => 'sha256:'.str_repeat('b', 64), 'artifact_path' => '/x/up.acct.enc']);

    expect(AccountBackupImport::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('account-backup-imports'))->toBe([]);

    $this->get(route('agent.account-backup-imports', ['token' => $token]))->assertNotFound();
});

test('a failed import keeps the reason and removes the staged file', function () {
    [, $node, $owner] = accountForImport();

    $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid]);
    $import = AccountBackupImport::sole();
    importChunk($import, 0, "\x1f\x8bdata", true)->assertOk();

    $backup = AccountBackup::sole();
    completeImport(ProvisioningOperation::where('provisionable_id', $backup->id)->sole(), ProvisioningStatus::Failed, [], [['code' => 'import_invalid', 'message' => 'This backup was made for a different account and cannot be imported here.']]);

    $backup->refresh();

    expect($backup->status)->toBe(ProvisioningStatus::Failed)
        ->and($backup->error_message)->toBe('This backup was made for a different account and cannot be imported here.')
        ->and(AccountBackupImport::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('account-backup-imports'))->toBe([]);
});

test('the upload refuses a file that is not gzip, an empty file, another account, a stranger and a finished upload', function () {
    [, $node, $owner] = accountForImport();
    [, , $stranger] = accountForImport();

    $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid]);
    $import = AccountBackupImport::sole();

    $this->actingAs($stranger);
    importChunk($import, 0, "\x1f\x8bdata")->assertNotFound();

    $this->actingAs($owner);
    importChunk($import, 0, 'PK not gzip')->assertStatus(422);
    expect(AccountBackupImport::count())->toBe(0);

    $this->postJson(route('account-backups.import.begin'), ['node' => $node->uuid]);
    $empty = AccountBackupImport::sole();
    importChunk($empty, 0, '', true)->assertStatus(422);
    expect(AccountBackupImport::count())->toBe(0);

    $this->postJson(route('account-backups.import.begin'), ['node' => $node->uuid]);
    $done = AccountBackupImport::sole();
    importChunk($done, 0, "\x1f\x8bok", true)->assertOk();
    importChunk($done, 4, 'more')->assertNotFound();
    importChunk($done, 4, str_repeat('x', 5 * 1024 * 1024 + 1))->assertNotFound();
});

test('an import is refused while a backup is running, for a node without data and for a member', function () {
    [$account, $node, $owner] = accountForImport();
    AccountBackup::factory()->for($account)->for($node)->create(['status' => ProvisioningStatus::Pending]);

    $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid])->assertStatus(422)->assertJsonValidationErrors('backup');

    $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => Node::factory()->create()->uuid])->assertNotFound();

    $member = Membership::factory()->for($account)->create()->user;
    $this->actingAs($member)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid])->assertForbidden();

    expect(AccountBackupImport::count())->toBe(0);
});

test('deleting an imported backup and pruning remove staged files', function () {
    [, $node, $owner] = accountForImport();
    $this->actingAs($owner)->postJson(route('account-backups.import.begin'), ['node' => $node->uuid]);
    $import = AccountBackupImport::sole();
    importChunk($import, 0, "\x1f\x8bpart");

    expect(Storage::disk('local')->allFiles('account-backup-imports'))->toHaveCount(1);

    $import->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->artisan('account-backups:prune-downloads')->assertSuccessful();

    expect(AccountBackupImport::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('account-backup-imports'))->toBe([]);
});
