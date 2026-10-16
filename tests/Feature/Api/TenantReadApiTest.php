<?php

use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\ApiToken;
use App\Models\CronJob;
use App\Models\CronJobExecution;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An account owner with a token holding every tenant read ability, unless told otherwise.
 *
 * @param  list<string>  $abilities
 * @return array{0: User, 1: Account, 2: array<string, string>}
 */
function readApiOwner(array $abilities = ['web:read', 'dns:read', 'mail:read', 'databases:read', 'cron:read', 'backups:read', 'usage:read'], ?int $restrictTo = null): array
{
    $membership = Membership::factory()->owner()->create();
    $plaintext = ApiToken::PREFIX.'read'.fake()->unique()->numerify('########');
    ApiToken::factory()->for($membership->user)->withPlaintext($plaintext)->create(['abilities' => $abilities, 'account_id' => $restrictTo]);

    return [$membership->user, $membership->account, ['Authorization' => 'Bearer '.$plaintext]];
}

function accountPath(Account $account, string $path = ''): string
{
    return '/api/v1/accounts/'.$account->public_id.($path === '' ? '' : '/'.$path);
}

test('an owner reads every resource family of their account', function () {
    [, $account, $auth] = readApiOwner();

    $web = WebDomain::factory()->for($account)->create(['domain' => 'shop.example']);
    $zone = DnsZone::factory()->for($account)->create(['domain' => 'shop.example']);
    DnsRecord::factory()->for($zone)->count(2)->create();
    $mail = MailDomain::factory()->for($account)->create(['domain' => 'shop.example']);
    MailAccount::factory()->for($mail)->create(['local_part' => 'info']);
    $database = TenantDatabase::factory()->for($account)->create();
    $cron = CronJob::factory()->for($account)->create();
    CronJobExecution::create(['cron_job_id' => $cron->id, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'exit_code' => 0, 'output' => 'done']);
    $backup = AccountBackup::factory()->for($account)->completed()->create();
    UsageSnapshot::factory()->for($account)->for($web, 'snapshotable')->webUsage()->create();
    UsageSnapshotRollup::factory()->for($account)->create(['snapshotable_type' => $web->getMorphClass(), 'snapshotable_id' => $web->id]);

    $this->getJson(accountPath($account, 'web-domains'), $auth)->assertOk()
        ->assertJsonPath('data.0.id', $web->uuid)
        ->assertJsonPath('data.0.domain', 'shop.example');
    $this->getJson(accountPath($account, 'web-domains/'.$web->uuid), $auth)->assertOk()->assertJsonPath('data.web_server', 'nginx');

    $this->getJson(accountPath($account, 'dns-zones'), $auth)->assertOk()->assertJsonPath('data.0.records_count', 2);
    $this->getJson(accountPath($account, 'dns-zones/'.$zone->uuid), $auth)->assertOk()->assertJsonPath('data.domain', 'shop.example');
    $this->getJson(accountPath($account, 'dns-zones/'.$zone->uuid.'/records'), $auth)->assertOk()->assertJsonCount(2, 'data');

    $this->getJson(accountPath($account, 'mail-domains'), $auth)->assertOk()->assertJsonPath('data.0.accounts_count', 1);
    $this->getJson(accountPath($account, 'mail-domains/'.$mail->uuid), $auth)->assertOk()->assertJsonPath('data.id', $mail->uuid);
    $this->getJson(accountPath($account, 'mail-domains/'.$mail->uuid.'/mailboxes'), $auth)->assertOk()
        ->assertJsonPath('data.0.address', 'info@shop.example')
        ->assertJsonMissingPath('data.0.password');

    $this->getJson(accountPath($account, 'databases'), $auth)->assertOk()->assertJsonPath('data.0.database_name', $database->database_name);
    $this->getJson(accountPath($account, 'databases/'.$database->uuid), $auth)->assertOk()
        ->assertJsonPath('data.database_user', $database->database_user)
        ->assertJsonMissingPath('data.password');

    $this->getJson(accountPath($account, 'cron-jobs'), $auth)->assertOk()->assertJsonPath('data.0.command', $cron->command)->assertJsonMissingPath('data.0.executions');
    $this->getJson(accountPath($account, 'cron-jobs/'.$cron->uuid), $auth)->assertOk()->assertJsonPath('data.executions.0.output', 'done');

    $this->getJson(accountPath($account, 'backups'), $auth)->assertOk()->assertJsonPath('data.0.status', 'ready');
    $this->getJson(accountPath($account, 'backups/'.$backup->uuid), $auth)->assertOk()
        ->assertJsonPath('data.id', $backup->uuid)
        ->assertJsonMissingPath('data.encryption_key');

    $this->getJson(accountPath($account, 'usage'), $auth)->assertOk()->assertJsonPath('data.0.resource', ['type' => 'web_domain', 'label' => 'shop.example']);
    $this->getJson(accountPath($account, 'usage/monthly'), $auth)->assertOk()->assertJsonPath('data.0.period', now()->format('Y-m'));
});

test('no secret is ever in a read response', function () {
    [, $account, $auth] = readApiOwner();
    $mail = MailDomain::factory()->for($account)->create();
    $mailbox = MailAccount::factory()->for($mail)->create();
    $database = TenantDatabase::factory()->for($account)->create();
    $backup = AccountBackup::factory()->for($account)->create();

    $bodies = collect([
        accountPath($account, 'mail-domains/'.$mail->uuid.'/mailboxes'),
        accountPath($account, 'databases/'.$database->uuid),
        accountPath($account, 'backups/'.$backup->uuid),
    ])->map(fn (string $path): string => $this->getJson($path, $auth)->assertOk()->getContent())->implode("\n");

    expect($bodies)->not->toContain($mailbox->password)
        ->not->toContain($database->password)
        ->not->toContain($database->stats_password)
        ->not->toContain($backup->encryption_key);
});

test('a token reads only the families it has an ability for', function () {
    [, $account, $auth] = readApiOwner(['dns:read']);

    $this->getJson(accountPath($account, 'dns-zones'), $auth)->assertOk();

    foreach (['web-domains', 'mail-domains', 'databases', 'cron-jobs', 'backups', 'usage', 'usage/monthly'] as $path) {
        $this->getJson(accountPath($account, $path), $auth)->assertForbidden();
    }
});

test('a write ability alone does not read', function () {
    [, $account, $auth] = readApiOwner(['web:write']);

    $this->getJson(accountPath($account, 'web-domains'), $auth)->assertForbidden();
});

test('another account is refused, and its resources are not found under mine', function () {
    [, $account, $auth] = readApiOwner();
    $other = Account::factory()->create();
    $foreign = WebDomain::factory()->for($other)->create();
    $foreignZone = DnsZone::factory()->for($other)->create();

    $this->getJson(accountPath($other, 'web-domains'), $auth)->assertForbidden();
    $this->getJson(accountPath($other, 'web-domains/'.$foreign->uuid), $auth)->assertForbidden();

    $this->getJson(accountPath($account, 'web-domains/'.$foreign->uuid), $auth)->assertNotFound();
    $this->getJson(accountPath($account, 'dns-zones/'.$foreignZone->uuid.'/records'), $auth)->assertNotFound();
});

test('a token restricted to one account cannot read another the user owns', function () {
    $user = User::factory()->create();
    $first = Membership::factory()->owner()->for($user)->create()->account;
    $second = Membership::factory()->owner()->for($user)->create()->account;
    ApiToken::factory()->for($user)->withPlaintext('lesta_restricted')->create(['abilities' => ['web:read'], 'account_id' => $first->id]);
    $auth = ['Authorization' => 'Bearer lesta_restricted'];

    $this->getJson(accountPath($first, 'web-domains'), $auth)->assertOk();
    $this->getJson(accountPath($second, 'web-domains'), $auth)->assertForbidden();
});

test('a reseller owner reads the accounts it manages', function () {
    [, $reseller, $auth] = readApiOwner();
    $managed = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    WebDomain::factory()->for($managed)->create();

    $this->getJson(accountPath($managed, 'web-domains'), $auth)->assertOk()->assertJsonCount(1, 'data');
});

test('a missing or expired token is refused before the account is looked up', function () {
    $owner = Membership::factory()->owner()->create()->user;
    ApiToken::factory()->for($owner)->expired()->withPlaintext('lesta_old')->create();

    $this->getJson('/api/v1/accounts/does-not-exist/web-domains')->assertUnauthorized();
    $this->getJson('/api/v1/accounts/does-not-exist/web-domains', ['Authorization' => 'Bearer lesta_old'])->assertUnauthorized();
});

test('a suspended account is still readable and says so', function () {
    [, $account, $auth] = readApiOwner();
    $account->suspend();
    WebDomain::factory()->for($account)->create(['suspended_at' => now()]);

    $this->getJson(accountPath($account, 'web-domains'), $auth)->assertOk()->assertJsonPath('data.0.suspended', true);
});

test('lists are cursor paged and filter by exact domain', function () {
    [, $account, $auth] = readApiOwner();
    WebDomain::factory()->for($account)->count(3)->create();
    WebDomain::factory()->for($account)->create(['domain' => 'wanted.example']);

    $first = $this->getJson(accountPath($account, 'web-domains').'?limit=2', $auth)->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $this->getJson(accountPath($account, 'web-domains').'?limit=2&cursor='.$cursor, $auth)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.next_cursor', null);

    $this->getJson(accountPath($account, 'web-domains').'?filter[domain]=Wanted.example', $auth)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.domain', 'wanted.example');
    $this->getJson(accountPath($account, 'web-domains').'?filter[domain][]=x', $auth)->assertUnprocessable();
});

test('the latest provisioning state is shown', function () {
    [, $account, $auth] = readApiOwner();
    WebDomain::factory()->for($account)->create();

    $this->getJson(accountPath($account, 'web-domains'), $auth)->assertOk()->assertJsonPath('data.0.provisioning', null);
});

test('the rate limit counts each token on its own', function () {
    $owner = Membership::factory()->owner()->create()->user;
    $busy = ApiToken::factory()->for($owner)->withPlaintext('lesta_busy')->create();
    ApiToken::factory()->for(Membership::factory()->owner()->create()->user)->withPlaintext('lesta_quiet')->create();

    foreach (range(1, 120) as $ignored) {
        RateLimiter::hit(md5('api'."api:read:token:{$busy->id}"));
    }

    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_busy'])->assertTooManyRequests();
    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_quiet'])->assertOk();
});
