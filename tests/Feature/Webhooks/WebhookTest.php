<?php

use App\Actions\Domains\CreateWebDomain;
use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Provisioning\ProvisioningResult;
use App\Services\Webhooks\WebhookTarget;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Host names resolve here without DNS: hooks.example.com is public, internal.example.com private.
 */
function stubWebhookDns(array $hosts = []): void
{
    $hosts += ['hooks.example.com' => ['93.184.215.14'], 'internal.example.com' => ['10.0.0.5']];

    app()->instance(WebhookTarget::class, new class($hosts) extends WebhookTarget
    {
        public function __construct(private array $hosts) {}

        public function addresses(string $host): array
        {
            return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->hosts[$host] ?? []);
        }
    });
}

function confirmedPassword(): array
{
    return ['auth.password_confirmed_at' => time()];
}

beforeEach(function () {
    stubWebhookDns();
    Http::preventStrayRequests();
});

test('an owner adds a webhook for their account and sees the secret once', function () {
    $membership = Membership::factory()->owner()->create();

    $this->actingAs($membership->user)->withSession(confirmedPassword())->post(route('webhooks.store'), [
        'url' => 'https://hooks.example.com/lesta',
        'account' => $membership->account->public_id,
        'events' => ['operation.finished'],
    ])->assertRedirect(route('webhooks.index'));

    $webhook = Webhook::query()->sole();
    $secret = session('inertia.flash_data.createdWebhookSecret') ?? collect(session()->all())->flatten()->first(fn ($value) => is_string($value) && str_starts_with($value, Webhook::SECRET_PREFIX));

    expect($secret)->toStartWith(Webhook::SECRET_PREFIX)
        ->and($webhook->secret)->toBe($secret)
        ->and($webhook->getRawOriginal('secret'))->not->toContain($secret)
        ->and($webhook->account_id)->toBe($membership->account_id)
        ->and(AuditEvent::query()->where('action', 'webhook.created')->value('account_id'))->toBe($membership->account_id);

    $this->actingAs($membership->user)->withSession(confirmedPassword())->get(route('webhooks.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/webhooks')->has('webhooks', 1)->missing('webhooks.0.secret'));
});

test('only public HTTPS addresses are accepted', function () {
    $membership = Membership::factory()->owner()->create();
    $post = fn (string $url) => $this->actingAs($membership->user)->withSession(confirmedPassword())->post(route('webhooks.store'), [
        'url' => $url, 'account' => $membership->account->public_id, 'events' => ['operation.finished'],
    ]);

    foreach (['http://hooks.example.com/x', 'https://internal.example.com/x', 'https://10.0.0.5/x', 'https://127.0.0.1/x', 'https://[::1]/x', 'https://169.254.169.254/latest', 'https://100.64.1.1/x', 'https://nowhere.example.com/x', 'https://user:pass@hooks.example.com/x'] as $url) {
        $post($url)->assertSessionHasErrors('url');
    }

    expect(Webhook::query()->count())->toBe(0);
});

test('platform-wide webhooks need webhooks.manage, and members cannot manage webhooks', function () {
    $owner = Membership::factory()->owner()->create();
    $member = Membership::factory()->member()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($owner->user)->withSession(confirmedPassword())->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/x', 'events' => ['backup.finished']])->assertForbidden();
    $this->actingAs($member->user)->withSession(confirmedPassword())->get(route('webhooks.index'))->assertForbidden();
    $this->actingAs($owner->user)->withSession(confirmedPassword())->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/x', 'account' => $member->account->public_id, 'events' => ['backup.finished']])->assertForbidden();

    $this->actingAs($admin)->withSession(confirmedPassword())->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/x', 'events' => ['backup.finished']])->assertRedirect();
    expect(Webhook::query()->sole()->isPlatformWide())->toBeTrue();
});

test('a finished change is sent once, signed, to the account and platform webhooks only', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $account = Account::factory()->for(Package::factory()->withLimit('web_domains', 5))->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);

    $mine = Webhook::factory()->for($account)->create(['url' => 'https://hooks.example.com/mine']);
    $platform = Webhook::factory()->platformWide()->create(['url' => 'https://hooks.example.com/platform']);
    $other = Webhook::factory()->create(['url' => 'https://hooks.example.com/other']);
    $backupsOnly = Webhook::factory()->for($account)->create(['url' => 'https://hooks.example.com/backups', 'events' => ['backup.finished']]);

    $webDomain = app(CreateWebDomain::class)->handle($owner, $account, ['domain' => 'hooked.example']);
    $correlation = ProvisioningOperation::query()->where('provisionable_id', $webDomain->id)->value('correlation_id');

    expect(WebhookDelivery::query()->where('status', 'delivered')->pluck('webhook_id')->sort()->values()->all())->toBe(collect([$mine->id, $platform->id])->sort()->values()->all())
        ->and(WebhookDelivery::query()->whereIn('webhook_id', [$other->id, $backupsOnly->id])->count())->toBe(0);

    Http::assertSent(function (SentRequest $request) use ($mine, $correlation, $account): bool {
        if ($request->url() !== $mine->url) {
            return false;
        }

        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('Lesta-Signature')[0], $signature);
        $payload = json_decode($request->body(), true);

        return hash_equals(hash_hmac('sha256', $signature[1].'.'.$request->body(), $mine->secret), $signature[2])
            && $request->header('Lesta-Event')[0] === 'operation.finished'
            && $payload['event'] === 'operation.finished'
            && $payload['account_id'] === $account->public_id
            && $payload['data']['operation']['id'] === $correlation
            && $payload['data']['operation']['action'] === 'web_domain.created'
            && $payload['data']['operation']['status'] === 'applied';
    });

    // The same result reported again sends nothing new.
    $operation = ProvisioningOperation::query()->where('correlation_id', $correlation)->first();
    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult(ProvisioningStatus::Applied, 1, 'sha256:x', 'none', [], now()));

    expect(WebhookDelivery::query()->count())->toBe(2);
});

test('a backup and a restore finishing are sent with the backup', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $backup = AccountBackup::factory()->create();
    Webhook::factory()->for($backup->account)->create();

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $backup->getMorphClass(), 'provisionable_id' => $backup->id, 'node_id' => $backup->node_id,
        'resource_id' => $backup->uuid, 'capability' => 'backup.encrypted-artifacts.v1', 'status' => ProvisioningStatus::Dispatched,
    ]);
    app(CompletesProvisioningOperation::class)->handle($operation, new ProvisioningResult(ProvisioningStatus::Applied, 1, 'sha256:x', 'none', [], now(), ['parts' => ['files'], 'size_bytes' => 10]));

    $sent = WebhookDelivery::query()->where('event', 'backup.finished')->sole();
    expect($sent->payload['data']['backup']['id'])->toBe($backup->uuid)
        ->and($sent->payload['data']['backup']['status'])->toBe('ready')
        ->and($sent->status)->toBe('delivered');
});

test('a failing receiver is retried, then the delivery fails, and a webhook that keeps failing switches off', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $webhook = Webhook::factory()->create(['consecutive_failures' => Webhook::DISABLE_AFTER_FAILURES - 1]);
    $delivery = WebhookDelivery::factory()->for($webhook)->create();

    (new DeliverWebhook($delivery->id))->handle(app(WebhookTarget::class));
    expect($delivery->refresh()->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->next_attempt_at->diffInSeconds(now()->addSeconds(60), true))->toBeLessThan(5);

    $delivery->forceFill(['attempts' => count(WebhookDelivery::RETRY_AFTER_SECONDS)])->save();
    (new DeliverWebhook($delivery->id))->handle(app(WebhookTarget::class));

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($webhook->refresh()->isDisabled())->toBeTrue();
});

test('the address is checked again when sending, and the sweep sends what is due', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $webhook = Webhook::factory()->create(['url' => 'https://moved.example.com/x']);
    $due = WebhookDelivery::factory()->for($webhook)->create(['next_attempt_at' => now()->subMinute()]);
    $later = WebhookDelivery::factory()->for($webhook)->create(['next_attempt_at' => now()->addHour()]);

    // The host now points into the private network: nothing is sent.
    stubWebhookDns(['moved.example.com' => ['192.168.1.10']]);
    Artisan::call('webhooks:deliver-due');

    expect($due->refresh()->error)->toContain('private')->and($due->attempts)->toBe(1);
    Http::assertNothingSent();

    stubWebhookDns(['moved.example.com' => ['93.184.215.14']]);
    $due->forceFill(['next_attempt_at' => now()->subMinute()])->save();
    Artisan::call('webhooks:deliver-due');

    expect($due->refresh()->status)->toBe('delivered')->and($later->refresh()->status)->toBe('pending');
});

test('a test ping is sent, and switches a webhook back on', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $membership = Membership::factory()->owner()->create();
    $webhook = Webhook::factory()->for($membership->account)->create(['disabled_at' => now(), 'consecutive_failures' => 10]);

    $this->actingAs($membership->user)->post(route('webhooks.test', $webhook))->assertRedirect();

    expect($webhook->refresh()->isDisabled())->toBeFalse()
        ->and(WebhookDelivery::query()->sole()->event)->toBe('ping')
        ->and(WebhookDelivery::query()->sole()->status)->toBe('delivered');
});

test('an owner removes a webhook, a stranger cannot', function () {
    $membership = Membership::factory()->owner()->create();
    $webhook = Webhook::factory()->for($membership->account)->create();
    $stranger = Membership::factory()->owner()->create()->user;

    $this->actingAs($stranger)->delete(route('webhooks.destroy', $webhook))->assertForbidden();
    $this->actingAs($membership->user)->delete(route('webhooks.destroy', $webhook))->assertRedirect();

    expect(Webhook::query()->count())->toBe(0);
});

test('a finished restore is sent once', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $backup = AccountBackup::factory()->completed()->create(['last_restore_status' => 'running', 'last_restore_at' => now()]);
    Webhook::factory()->for($backup->account)->create(['events' => ['restore.finished']]);

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $backup->getMorphClass(), 'provisionable_id' => $backup->id, 'node_id' => $backup->node_id,
        'resource_id' => $backup->uuid, 'capability' => 'backup.encrypted-artifacts.v1', 'operation' => ProvisioningVerb::Restore,
        'status' => ProvisioningStatus::Dispatched,
    ]);
    $result = new ProvisioningResult(ProvisioningStatus::Applied, 1, 'sha256:x', 'none', [], now(), ['restored' => ['files']]);
    app(CompletesProvisioningOperation::class)->handle($operation, $result);
    app(CompletesProvisioningOperation::class)->handle($operation, $result);

    $sent = WebhookDelivery::query()->sole();
    expect($sent->event)->toBe('restore.finished')
        ->and($sent->payload['data']['backup']['last_restore']['status'])->toBe('applied');
});
