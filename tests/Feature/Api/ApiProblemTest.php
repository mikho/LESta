<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Route;

/**
 * @return array{0: Account, 1: array<string, string>}
 */
function problemOwner(array $abilities = ['web:read']): array
{
    $membership = Membership::factory()->owner()->create();
    ApiToken::factory()->for($membership->user)->withPlaintext('lesta_problem')->create(['abilities' => $abilities]);

    return [$membership->account, ['Authorization' => 'Bearer lesta_problem']];
}

function assertProblem($response, int $status, string $type): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', $type)
        ->assertJsonPath('status', $status)
        ->assertJsonStructure(['type', 'title', 'status', 'detail']);
}

test('every API error is a problem document', function () {
    [$account, $auth] = problemOwner();

    assertProblem($this->getJson('/api/v1/user'), 401, 'urn:lesta:problem:invalid-token');
    assertProblem($this->getJson('/api/v1/accounts/'.$account->public_id.'/dns-zones', $auth), 403, 'urn:lesta:problem:missing-ability');
    assertProblem($this->getJson('/api/v1/accounts/'.$account->public_id.'/web-domains/'.fake()->uuid(), $auth), 404, 'about:blank');
    assertProblem($this->getJson('/api/v1/nothing-here', $auth), 404, 'about:blank');
    assertProblem($this->getJson('/api/v1/accounts/'.$account->public_id.'/web-domains?filter[domain][]=x', $auth), 422, 'urn:lesta:problem:validation');
});

test('a validation problem keeps the field errors', function () {
    [$account, $auth] = problemOwner(['web:write']);

    $this->postJson('/api/v1/accounts/'.$account->public_id.'/web-domains', ['domain' => 'not a domain'], $auth)
        ->assertUnprocessable()
        ->assertJsonPath('type', 'urn:lesta:problem:validation')
        ->assertJsonPath('title', 'Unprocessable Content')
        ->assertJsonValidationErrors('domain');
});

test('a 404 never names the model, and a server error never shows its message', function () {
    [$account, $auth] = problemOwner();

    $this->getJson('/api/v1/accounts/'.$account->public_id.'/web-domains/'.fake()->uuid(), $auth)
        ->assertJsonPath('detail', 'The resource does not exist.')
        ->assertDontSee('App\\\\Models', false);

    Route::middleware(AuthenticateApiToken::class)->get('api/v1/boom', fn () => throw new RuntimeException('secret internals'));

    $response = $this->getJson('/api/v1/boom', $auth);
    assertProblem($response, 500, 'about:blank');
    $response->assertDontSee('secret internals');
});

test('a policy refusal and a suspended account are problems too', function () {
    [, $auth] = problemOwner(['web:read', 'web:write']);
    $other = Account::factory()->create();

    assertProblem($this->getJson('/api/v1/accounts/'.$other->public_id.'/web-domains', $auth), 403, 'about:blank');

    $membership = Membership::factory()->owner()->create();
    $membership->account->suspend();
    ApiToken::factory()->for($membership->user)->withPlaintext('lesta_suspended')->create(['abilities' => ['web:write']]);
    $domain = WebDomain::factory()->for($membership->account)->create();

    assertProblem($this->deleteJson('/api/v1/accounts/'.$membership->account->public_id.'/web-domains/'.$domain->uuid, [], ['Authorization' => 'Bearer lesta_suspended']), 403, 'urn:lesta:problem:account-suspended');
});

test('the failed-token lockout is a problem with Retry-After', function () {
    foreach (range(1, 30) as $ignored) {
        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_wrong']);
    }

    $response = $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_wrong']);
    assertProblem($response, 429, 'urn:lesta:problem:too-many-failed-tokens');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('the panel keeps its own error pages', function () {
    $this->get('/definitely-not-a-page')->assertNotFound();
    expect($this->get('/definitely-not-a-page')->headers->get('Content-Type'))->not->toContain('problem');
});
