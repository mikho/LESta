<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Support\Facades\RateLimiter;

function confirmedSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('an account owner creates a token that is shown once and stored only as a hash', function () {
    $membership = Membership::factory()->owner()->create();
    $owner = $membership->user;

    $response = $this->actingAs($owner)->withSession(confirmedSession())->post(route('api-tokens.store'), [
        'name' => 'Deploy script',
        'abilities' => ['web:read', 'dns:write'],
        'account_id' => $membership->account_id,
        'expires_in_days' => 30,
    ]);

    $response->assertRedirect(route('api-tokens.index'));

    $plaintext = session('inertia.flash_data.createdToken') ?? collect(session()->all())->flatten()->first(fn ($value) => is_string($value) && str_starts_with($value, ApiToken::PREFIX));
    $token = ApiToken::query()->sole();

    expect($plaintext)->toStartWith(ApiToken::PREFIX)
        ->and($token->token_hash)->toBe(ApiToken::hashToken($plaintext))
        ->and($token->abilities)->toBe(['web:read', 'dns:write'])
        ->and($token->account_id)->toBe($membership->account_id)
        ->and($token->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and(AuditEvent::where('action', 'api_token.created')->where('auditable_id', $token->id)->exists())->toBeTrue();
});

test('creating a token needs the password again', function () {
    $owner = Membership::factory()->owner()->create()->user;

    $this->actingAs($owner)->post(route('api-tokens.store'), [
        'name' => 'x', 'abilities' => ['web:read'], 'expires_in_days' => 30,
    ])->assertRedirect(route('password.confirm'));

    expect(ApiToken::query()->count())->toBe(0);
});

test('an account member who is not an owner cannot have tokens', function () {
    $member = Membership::factory()->member()->create()->user;

    $this->actingAs($member)->withSession(confirmedSession())->get(route('api-tokens.index'))->assertForbidden();
    $this->actingAs($member)->withSession(confirmedSession())->post(route('api-tokens.store'), [
        'name' => 'x', 'abilities' => ['web:read'], 'expires_in_days' => 30,
    ])->assertForbidden();
});

test('a token can never be wider than its user', function () {
    $owner = Membership::factory()->owner()->create()->user;
    $foreign = Account::factory()->create();

    $this->actingAs($owner)->withSession(confirmedSession())->post(route('api-tokens.store'), [
        'name' => 'x', 'abilities' => ['admin:read'], 'expires_in_days' => 30,
    ])->assertSessionHasErrors('abilities');

    $this->actingAs($owner)->withSession(confirmedSession())->post(route('api-tokens.store'), [
        'name' => 'x', 'abilities' => ['web:read'], 'account_id' => $foreign->id, 'expires_in_days' => 30,
    ])->assertSessionHasErrors('account_id');

    $this->actingAs($owner)->withSession(confirmedSession())->post(route('api-tokens.store'), [
        'name' => 'x', 'abilities' => ['web:read'], 'expires_in_days' => ApiToken::MAX_DAYS + 1,
    ])->assertSessionHasErrors('expires_in_days');

    expect(ApiToken::query()->count())->toBe(0);
});

test('the API answers as the token user and refuses missing, invalid and expired tokens', function () {
    $owner = Membership::factory()->owner()->create()->user;
    ApiToken::factory()->for($owner)->withPlaintext('lesta_good')->create(['name' => 'ci']);
    ApiToken::factory()->for($owner)->withPlaintext('lesta_old')->expired()->create();

    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_good'])
        ->assertOk()
        ->assertJsonPath('data.email', $owner->email)
        ->assertJsonPath('data.token.name', 'ci')
        ->assertJsonMissingPath('data.token.token_hash');

    $this->getJson('/api/v1/user')->assertUnauthorized();
    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_wrong'])->assertUnauthorized();
    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_old'])->assertUnauthorized();
    $this->getJson('/api/v1/user?token=lesta_good')->assertUnauthorized();

    expect(ApiToken::query()->where('name', 'ci')->value('last_used_at'))->not->toBeNull();
});

test('failed tokens from one address are refused before any lookup after 30 a minute', function () {
    RateLimiter::clear('api-auth-failures:127.0.0.1');

    foreach (range(1, 30) as $attempt) {
        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_guess'.$attempt])->assertUnauthorized();
    }

    $owner = Membership::factory()->owner()->create()->user;
    ApiToken::factory()->for($owner)->withPlaintext('lesta_good')->create();

    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_good'])->assertTooManyRequests();
});

test('the accounts list holds what the user reaches and nothing else', function () {
    $mine = Membership::factory()->owner()->create();
    $owner = $mine->user;
    $managed = Account::factory()->create(['reseller_account_id' => $mine->account_id]);
    Account::factory()->create();

    ApiToken::factory()->for($owner)->withPlaintext('lesta_all')->create();
    ApiToken::factory()->for($owner)->withPlaintext('lesta_one')->create(['account_id' => $managed->id]);

    $ids = collect($this->getJson('/api/v1/accounts', ['Authorization' => 'Bearer lesta_all'])->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$mine->account->public_id, $managed->public_id])->sort()->values()->all());

    $this->getJson('/api/v1/accounts', ['Authorization' => 'Bearer lesta_one'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $managed->public_id)
        ->assertJsonPath('data.0.reseller_account_id', $mine->account->public_id)
        ->assertJsonPath('data.0.is_owner', true);
});

test('only the token user revokes it, and a revoked token stops working', function () {
    $owner = Membership::factory()->owner()->create()->user;
    $other = Membership::factory()->owner()->create()->user;
    $token = ApiToken::factory()->for($owner)->withPlaintext('lesta_mine')->create();

    $this->actingAs($other)->delete(route('api-tokens.destroy', $token))->assertForbidden();
    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_mine'])->assertOk();

    $this->actingAs($owner)->delete(route('api-tokens.destroy', $token))->assertRedirect(route('api-tokens.index'));

    expect(AuditEvent::where('action', 'api_token.revoked')->where('auditable_id', $token->id)->exists())->toBeTrue();

    $this->getJson('/api/v1/user', ['Authorization' => 'Bearer lesta_mine'])->assertUnauthorized();
});
