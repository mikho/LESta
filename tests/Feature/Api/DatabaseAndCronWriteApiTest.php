<?php

use App\Models\Account;
use App\Models\CronJob;
use App\Models\IdempotencyReceipt;
use App\Models\Membership;
use App\Models\TenantDatabase;

function accountApiPath(Account $account, string $suffix): string
{
    return '/api/v1/accounts/'.$account->public_id.$suffix;
}

test('a database is created with its password once, rotated, suspended and deleted', function () {
    [$account, , $auth] = apiWriteOwner(['databases:read', 'databases:write'], ['database.tenant.v1'], ['tenant_databases' => 5]);

    $created = $this->postJson(accountApiPath($account, '/databases'), ['label' => 'shop'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.label', 'shop');

    $database = TenantDatabase::query()->sole();
    expect($created->json('data.password'))->toBe($database->password);

    $path = accountApiPath($account, '/databases/'.$database->uuid);
    $this->getJson($path, $auth)->assertOk()->assertJsonMissingPath('data.password');

    $rotated = $this->postJson($path.'/rotate-password', [], $auth)->assertAccepted()->json('data.password');
    expect($rotated)->not->toBe($created->json('data.password'))->toBe($database->refresh()->password);

    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted();

    expect(TenantDatabase::query()->count())->toBe(0);
});

test('a database label is unique within the account in the path, not the user first account', function () {
    [$account, $token, $auth] = apiWriteOwner(['databases:write'], ['database.tenant.v1'], ['tenant_databases' => 5]);
    $first = Account::factory()->create();
    Membership::factory()->for($first)->owner()->for($token->user)->create();
    TenantDatabase::factory()->for($first)->create(['label' => 'shop']);
    TenantDatabase::factory()->for($account)->create(['label' => 'blog']);

    $this->postJson(accountApiPath($account, '/databases'), ['label' => 'blog'], $auth)->assertUnprocessable()->assertJsonValidationErrors('label');
    $this->postJson(accountApiPath($account, '/databases'), ['label' => 'shop'], $auth)->assertAccepted();
});

test('a replayed new database never repeats or stores its password', function () {
    [$account, , $auth] = apiWriteOwner(['databases:write'], ['database.tenant.v1'], ['tenant_databases' => 5]);
    $headers = $auth + ['Idempotency-Key' => 'db-shop'];

    $password = $this->postJson(accountApiPath($account, '/databases'), ['label' => 'shop'], $headers)->assertAccepted()->json('data.password');
    $this->postJson(accountApiPath($account, '/databases'), ['label' => 'shop'], $headers)->assertAccepted()->assertJsonPath('data.password', null);

    expect(json_encode(IdempotencyReceipt::query()->sole()->response))->not->toContain($password)
        ->and(TenantDatabase::query()->count())->toBe(1);
});

test('a cron job is created, changed in part, suspended and deleted', function () {
    [$account, , $auth] = apiWriteOwner(['cron:read', 'cron:write'], ['scheduler.account-cron.v1'], ['cron_jobs' => 5]);

    $created = $this->postJson(accountApiPath($account, '/cron-jobs'), ['minute' => '*/5', 'command' => 'php artisan schedule:run'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.minute', '*/5')
        ->assertJsonPath('data.command', 'php artisan schedule:run');

    $path = accountApiPath($account, '/cron-jobs/'.$created->json('data.id'));

    $this->patchJson($path, ['hour' => '3'], $auth)->assertAccepted()->assertJsonPath('data.hour', '3')->assertJsonPath('data.minute', '*/5');
    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);
    $this->deleteJson($path, [], $auth)->assertAccepted();

    $this->postJson(accountApiPath($account, '/cron-jobs'), ['minute' => '*'], $auth)->assertUnprocessable()->assertJsonValidationErrors('command');
    expect(CronJob::query()->count())->toBe(0);
});

test('database and cron writes need their own write ability', function () {
    [$account, , $auth] = apiWriteOwner(['databases:read', 'cron:read'], ['database.tenant.v1', 'scheduler.account-cron.v1'], ['tenant_databases' => 5, 'cron_jobs' => 5]);

    $this->postJson(accountApiPath($account, '/databases'), ['label' => 'shop'], $auth)->assertForbidden();
    $this->postJson(accountApiPath($account, '/cron-jobs'), ['command' => 'true'], $auth)->assertForbidden();
});
