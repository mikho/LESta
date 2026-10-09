<?php

use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Models\WebDomain;
use App\Services\Provisioning\ProvisioningResult;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Operations are completed by hand below, the way the node's report completes them.
    Queue::fake();
});

/**
 * An account with a site, a mail domain and a database on a backup-capable node.
 *
 * @return array{0: Account, 1: Node, 2: User, 3: WebDomain}
 */
function accountWithData(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $site = WebDomain::factory()->for($node)->create();
    $account = $site->account;
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    MailDomain::factory()->for($account)->for($node)->create(['domain' => 'mail-'.$account->id.'.example.com']);
    TenantDatabase::factory()->for($account)->for($node)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    return [$account, $node, $owner, $site];
}

function complete(ProvisioningOperation $operation, ProvisioningStatus $status, ?array $data = null, array $errors = []): void
{
    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult($status, 1, 'sha256:x', 'none', $errors, now(), $data));
}

test('an owner starts a backup: the node gets the account scope, a fresh key, and it is audited', function () {
    [$account, $node, $owner, $site] = accountWithData();

    $this->actingAs($owner)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => ['files', 'databases']])->assertSessionHasNoErrors();

    $backup = AccountBackup::sole();
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('provisionable_type', $backup->getMorphClass())->sole();
    $database = $account->tenantDatabases()->sole();

    expect($backup->requested_parts)->toBe(['files', 'databases'])
        ->and($backup->encryption_key)->toHaveLength(64)
        ->and($operation->capability)->toBe('backup.encrypted-artifacts.v1')
        ->and($operation->operation)->toBe(ProvisioningVerb::Create)
        ->and($operation->payload['account'])->toBe([
            'username' => 'lesta-t'.$account->id,
            'web_resources' => [$site->uuid],
            'mail_domains' => [$account->mailDomains()->sole()->domain],
            'databases' => [$database->database_name],
            'parts' => ['files', 'databases'],
        ])
        ->and(AuditEvent::where('action', 'account_backup.created')->count())->toBe(1);
});

test('the node reports a finished backup and the panel records it', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = app(CreateAccountBackup::class)->handle($owner, $account, $node, ['files', 'mail']);
    $backup->forceFill(['status' => ProvisioningStatus::Pending])->save();

    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('provisionable_type', $backup->getMorphClass())->sole();

    complete($operation, ProvisioningStatus::Applied, [
        'parts' => ['files', 'mail'], 'size_bytes' => 4096, 'checksum' => 'sha256:'.str_repeat('b', 64),
        'artifact_path' => '/var/lib/lesta/backups/accounts/u/x.acct.enc', 'report' => ['skipped' => ['files/x/link: a link that leaves the folder']],
    ]);

    $backup->refresh();

    expect($backup->isComplete())->toBeTrue()
        ->and($backup->parts)->toBe(['files', 'mail'])
        ->and($backup->size_bytes)->toBe(4096)
        ->and($backup->artifact_path)->toBe('/var/lib/lesta/backups/accounts/u/x.acct.enc');
});

test('a failed backup keeps the reason the node gave', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = app(CreateAccountBackup::class)->handle($owner, $account, $node, ['files']);
    $operation = ProvisioningOperation::where('provisionable_id', $backup->id)->where('provisionable_type', $backup->getMorphClass())->sole();

    complete($operation, ProvisioningStatus::Failed, null, [['code' => 'backup_too_large', 'message' => 'The account is larger than the backup size limit.']]);

    expect($backup->fresh()->status)->toBe(ProvisioningStatus::Failed)
        ->and($backup->fresh()->error_message)->toBe('The account is larger than the backup size limit.');
});

test('a backup is refused when there is nothing to back up, no part is chosen, or one is already running', function () {
    [$account, $node, $owner] = accountWithData();
    $empty = Node::factory()->create();
    NodeCapability::factory()->for($empty)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    WebDomain::factory()->for($empty)->for($account)->create();
    AccountNodeIdentity::factory()->for($account)->for($empty)->create(['system_username' => 'lesta-t'.$account->id]);

    $this->actingAs($owner)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => []])->assertSessionHasErrors('parts');
    $this->actingAs($owner)->post(route('account-backups.store'), ['node' => $empty->uuid, 'parts' => ['mail']])->assertSessionHasErrors('parts');

    AccountBackup::factory()->for($account)->for($node)->create(['status' => ProvisioningStatus::Pending]);

    $this->actingAs($owner)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => ['files']])->assertSessionHasErrors('backup');

    expect(AccountBackup::count())->toBe(1);
});

test('a node without the backup capability is refused with a clear message', function () {
    [$account, $node, $owner] = accountWithData();
    $node->capabilities()->delete();

    $this->actingAs($owner)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => ['files']])->assertSessionHasErrors('backup');
});

test('a restore takes a safety backup first and restores when it completes', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files', 'databases', 'mail']]);

    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['files', 'databases'], 'confirm' => '1'])->assertSessionHasNoErrors();

    $safety = AccountBackup::where('kind', 'before_restore')->sole();
    $backup->refresh();

    expect($safety->restore_after_id)->toBe($backup->id)
        ->and($safety->restore_parts)->toBe(['files', 'databases'])
        ->and($safety->requested_parts)->toBe(['files', 'databases'])
        ->and($safety->label)->toStartWith('Before restoring')
        ->and($backup->last_restore_status)->toBe('running')
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Restore->value)->count())->toBe(0)
        ->and(AuditEvent::where('action', 'account_backup.restore_started')->count())->toBe(1);

    // The safety backup finishes: the restore is dispatched, with the current scope and the chosen parts.
    $safetyOp = ProvisioningOperation::where('provisionable_id', $safety->id)->where('provisionable_type', $safety->getMorphClass())->sole();
    $safetyOp->forceFill(['status' => ProvisioningStatus::Pending])->save();
    complete($safetyOp, ProvisioningStatus::Applied, ['parts' => ['files', 'databases'], 'size_bytes' => 10, 'checksum' => 'sha256:'.str_repeat('c', 64), 'artifact_path' => '/x/safety.acct.enc']);

    $restoreOp = ProvisioningOperation::where('operation', ProvisioningVerb::Restore->value)->sole();

    expect($restoreOp->provisionable_id)->toBe($backup->id)
        ->and($restoreOp->payload['artifact_path'])->toBe($backup->artifact_path)
        ->and($restoreOp->payload['account']['parts'])->toBe(['files', 'databases'])
        ->and($restoreOp->payload['account']['username'])->toBe('lesta-t'.$account->id);

    $restoreOp->forceFill(['status' => ProvisioningStatus::Pending])->save();
    complete($restoreOp, ProvisioningStatus::Applied, ['restored' => ['files', 'databases'], 'skipped' => ['files/x/y: a link']]);

    $backup->refresh();

    expect($backup->last_restore_status)->toBe('applied')
        ->and($backup->last_restore_report['restored'])->toBe(['files', 'databases']);
});

test('if the safety backup fails nothing is restored and the reason is shown', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files', 'databases']]);

    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['files'], 'confirm' => '1']);

    $safety = AccountBackup::where('kind', 'before_restore')->sole();
    $safetyOp = ProvisioningOperation::where('provisionable_id', $safety->id)->where('provisionable_type', $safety->getMorphClass())->sole();
    $safetyOp->forceFill(['status' => ProvisioningStatus::Pending])->save();

    complete($safetyOp, ProvisioningStatus::Failed, null, [['code' => 'not_enough_space', 'message' => 'This node has only 10 MB free.']]);

    $backup->refresh();

    expect(ProvisioningOperation::where('operation', ProvisioningVerb::Restore->value)->count())->toBe(0)
        ->and($backup->last_restore_status)->toBe('failed')
        ->and($backup->last_restore_error)->toContain('This node has only 10 MB free.');
});

test('a failed restore keeps the reason', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files'], 'last_restore_status' => 'running']);
    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $backup->getMorphClass(), 'provisionable_id' => $backup->id, 'node_id' => $node->id,
        'resource_id' => $backup->uuid, 'capability' => 'backup.encrypted-artifacts.v1', 'operation' => ProvisioningVerb::Restore, 'status' => ProvisioningStatus::Pending,
    ]);

    complete($operation, ProvisioningStatus::Failed, null, [['code' => 'restore_failed', 'message' => 'The backup failed authentication.']]);

    expect($backup->fresh()->last_restore_status)->toBe('failed')
        ->and($backup->fresh()->last_restore_error)->toBe('The backup failed authentication.');
});

test('a restore with nothing present to protect starts straight away', function () {
    [$account, $node, $owner, $site] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files', 'databases']]);
    $account->tenantDatabases()->delete();

    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['databases'], 'confirm' => '1'])->assertSessionHasNoErrors();

    expect(AccountBackup::where('kind', 'before_restore')->count())->toBe(0)
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Restore->value)->count())->toBe(1);
});

test('restore refuses an unfinished backup, a part the backup lacks, no confirmation, and a second run', function () {
    [$account, $node, $owner] = accountWithData();
    $unfinished = AccountBackup::factory()->for($account)->for($node)->create(['status' => ProvisioningStatus::Failed]);
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files']]);

    $this->actingAs($owner)->post(route('account-backups.restore', $unfinished), ['parts' => ['files'], 'confirm' => '1'])->assertSessionHasErrors('backup');
    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['mail'], 'confirm' => '1'])->assertSessionHasErrors('parts');
    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['files']])->assertSessionHasErrors('confirm');

    $backup->forceFill(['last_restore_status' => 'running'])->save();

    $this->actingAs($owner)->post(route('account-backups.restore', $backup), ['parts' => ['files'], 'confirm' => '1'])->assertSessionHasErrors('backup');

    expect(ProvisioningOperation::where('operation', ProvisioningVerb::Restore->value)->count())->toBe(0);
});

test('only the newest five completed backups are kept per account and node', function () {
    [$account, $node, $owner] = accountWithData();

    $old = collect(range(1, 5))->map(fn (int $i) => AccountBackup::factory()->completed()->for($account)->for($node)->create(['completed_at' => now()->subDays(10 - $i)]));
    $newest = app(CreateAccountBackup::class)->handle($owner, $account, $node, ['files']);
    $newest->forceFill(['status' => ProvisioningStatus::Pending])->save();
    $operation = ProvisioningOperation::where('provisionable_id', $newest->id)->where('provisionable_type', $newest->getMorphClass())->sole();

    complete($operation, ProvisioningStatus::Applied, ['parts' => ['files'], 'size_bytes' => 1, 'checksum' => 'sha256:'.str_repeat('d', 64), 'artifact_path' => '/x/new.acct.enc']);

    expect(AccountBackup::count())->toBe(AccountBackup::KEEP)
        ->and(AccountBackup::find($old[0]->id))->toBeNull()
        ->and(AccountBackup::find($old[1]->id))->not->toBeNull()
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Delete->value)->where('resource_id', $old[0]->uuid)->exists())->toBeTrue();
});

test('deleting a backup dispatches the removal of its archive, unless it is being restored', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create();
    $busy = AccountBackup::factory()->completed()->for($account)->for($node)->create(['last_restore_status' => 'running']);

    $this->actingAs($owner)->delete(route('account-backups.destroy', $backup))->assertSessionHasNoErrors();
    $this->actingAs($owner)->delete(route('account-backups.destroy', $busy))->assertSessionHasErrors('backup');

    expect(AccountBackup::find($backup->id))->toBeNull()
        ->and(AccountBackup::find($busy->id))->not->toBeNull()
        ->and(ProvisioningOperation::where('operation', ProvisioningVerb::Delete->value)->where('resource_id', $backup->uuid)->sole()->payload)->toBe(['artifact_path' => $backup->artifact_path]);
});

test('only an owner can back up, restore or delete, members can look, strangers see nothing', function () {
    [$account, $node, $owner] = accountWithData();
    $member = Membership::factory()->for($account)->member()->create()->user;
    [$other, , $stranger] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create();

    $this->actingAs($member)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => ['files']])->assertForbidden();
    $this->actingAs($member)->post(route('account-backups.restore', $backup), ['parts' => ['files'], 'confirm' => '1'])->assertForbidden();
    $this->actingAs($member)->delete(route('account-backups.destroy', $backup))->assertForbidden();
    $this->actingAs($member)->get(route('account-backups.index'))->assertOk();

    $this->actingAs($stranger)->post(route('account-backups.restore', $backup), ['parts' => ['files'], 'confirm' => '1'])->assertForbidden();
    $this->actingAs($stranger)->delete(route('account-backups.destroy', $backup))->assertForbidden();
    $this->actingAs($stranger)->post(route('account-backups.store'), ['node' => $node->uuid, 'parts' => ['files']])->assertNotFound();

    expect(AccountBackup::count())->toBe(1);
});

test('the page lists backups and nodes and never exposes the key', function () {
    [$account, $node, $owner] = accountWithData();
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['label' => 'before the redesign']);

    $response = $this->actingAs($owner)->get(route('account-backups.index'));

    $response->assertInertia(fn ($page) => $page->component('account-backups/index')
        ->has('backups', 1)->where('backups.0.label', 'before the redesign')->where('backups.0.status', 'ready')
        ->has('nodes', 1)->where('nodes.0.name', $node->name)->where('nodes.0.parts', ['files', 'databases', 'mail']));

    expect($response->getContent())->not->toContain($backup->encryption_key);
});

test('a user with no account sees an empty page', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->get(route('account-backups.index'))->assertInertia(fn ($page) => $page->where('backups', null));
});
