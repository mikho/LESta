<?php

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\Node;
use App\Models\WebDomain;

/**
 * An owner whose account has a site on a node that takes backups.
 *
 * @param  list<string>  $abilities
 * @return array{0: Account, 1: array<string, string>, 2: Node}
 */
function backupWriteOwner(array $abilities = ['backups:read', 'backups:write']): array
{
    [$account, , $auth, $node] = apiWriteOwner($abilities, ['backup.encrypted-artifacts.v1', 'web.nginx.v1'], []);
    WebDomain::factory()->for($account)->for($node)->create();

    return [$account, $auth, $node];
}

function backupsPath(Account $account, string $suffix = ''): string
{
    return '/api/v1/accounts/'.$account->public_id.'/backups'.$suffix;
}

test('a backup is started on the only node with data, or the named one', function () {
    [$account, $auth, $node] = backupWriteOwner();

    $created = $this->postJson(backupsPath($account), ['parts' => ['files']], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.node', $node->name)
        ->assertJsonPath('data.kind', 'manual');

    $this->getJson('/api/v1/operations/'.$created->json('operation.id'), $auth)->assertOk()->assertJsonPath('data.action', 'account_backup.created');

    $this->postJson(backupsPath($account), ['parts' => ['files'], 'node' => $node->name, 'label' => 'Before the move'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.label', 'Before the move');
});

test('the node must be named when the account has data on several, and must hold its data', function () {
    [$account, $auth] = backupWriteOwner();
    $second = Node::factory()->create(['name' => 'second-node']);
    WebDomain::factory()->for($account)->for($second)->create();

    $this->postJson(backupsPath($account), ['parts' => ['files']], $auth)->assertUnprocessable()->assertJsonValidationErrors('node');
    $this->postJson(backupsPath($account), ['parts' => ['files'], 'node' => 'no-such-node'], $auth)->assertUnprocessable()->assertJsonValidationErrors('node');
    $this->postJson(backupsPath($account), ['parts' => ['everything']], $auth)->assertUnprocessable()->assertJsonValidationErrors('parts.0');
});

test('a restore needs confirming, takes a safety backup first and points at the backup', function () {
    [$account, $auth, $node] = backupWriteOwner();
    $backup = AccountBackup::factory()->for($account)->for($node)->completed()->create(['parts' => ['files']]);
    $path = backupsPath($account, '/'.$backup->uuid);

    $this->postJson($path.'/restore', ['parts' => ['files']], $auth)->assertUnprocessable()->assertJsonValidationErrors('confirm');

    $this->postJson($path.'/restore', ['parts' => ['files'], 'confirm' => true], $auth)
        ->assertAccepted()
        ->assertHeader('Location', route('api.v1.accounts.backups.show', [$account, $backup]))
        ->assertJsonPath('operation', null)
        ->assertJsonPath('data.last_restore.status', fn (string $status): bool => in_array($status, ['running', 'applied'], true));

    expect(AccountBackup::query()->where('kind', 'before_restore')->exists())->toBeTrue();
});

test('a backup that is not finished cannot be restored', function () {
    [$account, $auth, $node] = backupWriteOwner();
    $running = AccountBackup::factory()->for($account)->for($node)->create(['status' => ProvisioningStatus::Dispatched]);

    $this->postJson(backupsPath($account, '/'.$running->uuid.'/restore'), ['parts' => ['files'], 'confirm' => true], $auth)->assertUnprocessable();
});

test('a backup is deleted with an operation', function () {
    [$account, $auth, $node] = backupWriteOwner();
    $backup = AccountBackup::factory()->for($account)->for($node)->completed()->create();

    $this->deleteJson(backupsPath($account, '/'.$backup->uuid), [], $auth)->assertAccepted()->assertJsonPath('data', null);
});

test('backup writes need backups:write', function () {
    [$account, $auth] = backupWriteOwner(['backups:read']);

    $this->postJson(backupsPath($account), ['parts' => ['files']], $auth)->assertForbidden();
});
