<?php

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\IdempotencyReceipt;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Artisan;

/**
 * An account owner on a node that can host web domains, with a token holding $abilities.
 *
 * @param  list<string>  $abilities
 * @return array{0: Account, 1: ApiToken, 2: array<string, string>, 3: Node}
 */
function writeApiOwner(array $abilities = ['web:read', 'web:write']): array
{
    $account = Account::factory()->for(Package::factory()->withLimit('web_domains', 5))->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);

    $plaintext = ApiToken::PREFIX.'write'.fake()->unique()->numerify('########');
    $token = ApiToken::factory()->for($owner)->withPlaintext($plaintext)->create(['abilities' => $abilities]);

    return [$account, $token, ['Authorization' => 'Bearer '.$plaintext], $node];
}

function webDomainsPath(Account $account, string $suffix = ''): string
{
    return '/api/v1/accounts/'.$account->public_id.'/web-domains'.$suffix;
}

test('creating a web domain answers 202 with the domain and an operation to poll', function () {
    [$account, $token, $auth] = writeApiOwner();

    $response = $this->postJson(webDomainsPath($account), ['domain' => 'New.Example', 'aliases' => ['www.new.example']], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.domain', 'new.example')
        ->assertJsonPath('data.aliases', ['www.new.example'])
        ->assertJsonStructure(['operation' => ['id', 'status', 'url']]);

    $operationId = $response->json('operation.id');
    $response->assertHeader('Location', route('api.v1.operations.show', $operationId));

    $webDomain = WebDomain::query()->where('domain', 'new.example')->sole();
    $event = AuditEvent::query()->where('action', 'web_domain.created')->sole();

    expect($webDomain->account_id)->toBe($account->id)
        ->and($event->api_token_id)->toBe($token->id)
        ->and($event->correlation_id)->toBe($operationId);

    // The test provisioner applies at once; a real node takes a moment.
    ProvisioningOperation::query()->where('correlation_id', $operationId)->update(['status' => ProvisioningStatus::Dispatched, 'completed_at' => null]);

    $this->getJson('/api/v1/operations/'.$operationId, $auth)->assertOk()
        ->assertJsonPath('data.action', 'web_domain.created')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.steps.0.capability', 'web.nginx.v1')
        ->assertJsonPath('data.steps.0.verb', 'create');

    ProvisioningOperation::query()->where('correlation_id', $operationId)->update(['status' => ProvisioningStatus::Applied, 'completed_at' => now()]);

    $this->getJson('/api/v1/operations/'.$operationId, $auth)->assertOk()->assertJsonPath('data.status', 'applied');
});

test('a failed step makes the operation failed and shows the node error', function () {
    [$account, , $auth] = writeApiOwner();
    $operationId = $this->postJson(webDomainsPath($account), ['domain' => 'broken.example'], $auth)->assertAccepted()->json('operation.id');

    ProvisioningOperation::query()->where('correlation_id', $operationId)->update([
        'status' => ProvisioningStatus::Failed,
        'errors' => json_encode([['code' => 'nginx_test_failed', 'message' => 'nginx -t failed', 'field' => null]]),
    ]);

    $this->getJson('/api/v1/operations/'.$operationId, $auth)->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.steps.0.errors', [['code' => 'nginx_test_failed', 'message' => 'nginx -t failed']]);
});

test('an operation is visible only to the token that made it', function () {
    [$account, , $auth] = writeApiOwner();
    $operationId = $this->postJson(webDomainsPath($account), ['domain' => 'mine.example'], $auth)->assertAccepted()->json('operation.id');

    [, , $otherAuth] = writeApiOwner();

    $this->getJson('/api/v1/operations/'.$operationId, $otherAuth)->assertNotFound();
    $this->getJson('/api/v1/operations/not-a-uuid', $auth)->assertNotFound();
});

test('the panel validation and quota apply', function () {
    [$account, , $auth] = writeApiOwner();
    WebDomain::factory()->for($account)->create(['domain' => 'taken.example']);

    $this->postJson(webDomainsPath($account), ['domain' => 'taken.example'], $auth)->assertUnprocessable()->assertJsonValidationErrors('domain');
    $this->postJson(webDomainsPath($account), ['domain' => 'not a domain'], $auth)->assertUnprocessable()->assertJsonValidationErrors('domain');

    $account->package->limits()->where('resource_type', 'web_domains')->update(['limit_value' => 1]);

    $this->postJson(webDomainsPath($account), ['domain' => 'over.example'], $auth)->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'limit'));
});

test('no node able to host it answers 503', function () {
    [$account, , $auth] = writeApiOwner();
    NodeCapability::query()->delete();

    $this->postJson(webDomainsPath($account), ['domain' => 'nowhere.example'], $auth)->assertServiceUnavailable();
    expect(WebDomain::query()->count())->toBe(0);
});

test('a patch changes only the fields it sends', function () {
    [$account, , $auth, $node] = writeApiOwner();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['domain' => 'site.example', 'waf_mode' => 'detect']);

    $this->patchJson(webDomainsPath($account, '/'.$webDomain->uuid), ['ssl_mode' => 'lets_encrypt'], $auth)
        ->assertAccepted()
        ->assertJsonPath('data.ssl_mode', 'lets_encrypt')
        ->assertJsonPath('data.domain', 'site.example')
        ->assertJsonPath('data.waf_mode', 'detect');

    $this->patchJson(webDomainsPath($account, '/'.$webDomain->uuid), ['ssl_mode' => 'nonsense'], $auth)->assertUnprocessable();
});

test('suspend, unsuspend and delete each answer with their operation', function () {
    [$account, , $auth, $node] = writeApiOwner();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $path = webDomainsPath($account, '/'.$webDomain->uuid);

    $this->postJson($path.'/suspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', true);
    $this->postJson($path.'/suspend', [], $auth)->assertOk()->assertJsonPath('operation', null);
    $this->postJson($path.'/unsuspend', [], $auth)->assertAccepted()->assertJsonPath('data.suspended', false);

    $deleted = $this->deleteJson($path, [], $auth)->assertAccepted()->assertJsonPath('data', null);

    expect(WebDomain::query()->whereKey($webDomain->id)->exists())->toBeFalse();
    $this->getJson('/api/v1/operations/'.$deleted->json('operation.id'), $auth)->assertOk()->assertJsonPath('data.action', 'web_domain.deleted');
});

test('writing needs the write ability, an owner, and an account that is not suspended', function () {
    [$account, , $readOnly] = writeApiOwner(['web:read']);
    $this->postJson(webDomainsPath($account), ['domain' => 'a.example'], $readOnly)->assertForbidden();

    [$suspended, , $auth] = writeApiOwner();
    $suspended->suspend();
    $this->postJson(webDomainsPath($suspended), ['domain' => 'b.example'], $auth)->assertForbidden();

    $memberAccount = Account::factory()->create();
    [, $token, $ownerAuth] = writeApiOwner();
    Membership::factory()->for($memberAccount)->member()->for($token->user)->create();
    $this->postJson(webDomainsPath($memberAccount), ['domain' => 'c.example'], $ownerAuth)->assertForbidden();

    expect(WebDomain::query()->count())->toBe(0);
});

test('a retry with the same idempotency key creates nothing twice', function () {
    [$account, , $auth] = writeApiOwner();
    $headers = $auth + ['Idempotency-Key' => 'create-site-1'];

    $first = $this->postJson(webDomainsPath($account), ['domain' => 'once.example'], $headers)->assertAccepted();
    $again = $this->postJson(webDomainsPath($account), ['domain' => 'once.example'], $headers)->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and($again->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(WebDomain::query()->count())->toBe(1);

    $this->postJson(webDomainsPath($account), ['domain' => 'other.example'], $headers)->assertConflict();
});

test('a failed request keeps no idempotency receipt, so it can be fixed and retried', function () {
    [$account, , $auth] = writeApiOwner();
    $headers = $auth + ['Idempotency-Key' => 'retry-me'];

    $this->postJson(webDomainsPath($account), ['domain' => 'not a domain'], $headers)->assertUnprocessable();
    expect(IdempotencyReceipt::query()->count())->toBe(0);

    $this->postJson(webDomainsPath($account), ['domain' => 'fixed.example'], $headers)->assertAccepted();
});

test('idempotency keys are per token, and a key in use is refused', function () {
    [$account, $token, $auth] = writeApiOwner();
    [$otherAccount, , $otherAuth] = writeApiOwner();

    $this->postJson(webDomainsPath($account), ['domain' => 'one.example'], $auth + ['Idempotency-Key' => 'shared'])->assertAccepted();
    $this->postJson(webDomainsPath($otherAccount), ['domain' => 'two.example'], $otherAuth + ['Idempotency-Key' => 'shared'])->assertAccepted();

    IdempotencyReceipt::query()->create([
        'scope' => 'api-token:'.$token->id,
        'idempotency_key' => 'busy',
        'request_hash' => hash('sha256', 'POST '.ltrim(webDomainsPath($account), '/')."\n".json_encode(['domain' => 'three.example'])),
        'status' => 'processing',
        'correlation_id' => fake()->uuid(),
        'expires_at' => now()->addDay(),
    ]);

    $this->postJson(webDomainsPath($account), ['domain' => 'three.example'], $auth + ['Idempotency-Key' => 'busy'])->assertConflict();
    $this->postJson(webDomainsPath($account), ['domain' => 'four.example'], $auth + ['Idempotency-Key' => str_repeat('x', 256)])->assertBadRequest();
});

test('expired idempotency receipts are pruned', function () {
    IdempotencyReceipt::query()->create(['scope' => 'api-token:1', 'idempotency_key' => 'old', 'status' => 'completed', 'correlation_id' => fake()->uuid(), 'expires_at' => now()->subMinute()]);
    IdempotencyReceipt::query()->create(['scope' => 'api-token:1', 'idempotency_key' => 'new', 'status' => 'completed', 'correlation_id' => fake()->uuid(), 'expires_at' => now()->addHour()]);

    Artisan::call('model:prune', ['--model' => [IdempotencyReceipt::class]]);

    expect(IdempotencyReceipt::query()->pluck('idempotency_key')->all())->toBe(['new']);
});

test('the audit chain still verifies with token-stamped events in it', function () {
    AuditEvent::factory()->create();
    [$account, , $auth] = writeApiOwner();
    $this->postJson(webDomainsPath($account), ['domain' => 'chain.example'], $auth)->assertAccepted();
    AuditEvent::factory()->create();

    expect(AuditEvent::query()->whereNotNull('api_token_id')->exists())->toBeTrue()
        ->and(Artisan::call('audit:verify-chain'))->toBe(0);
});
