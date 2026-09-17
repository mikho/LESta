<?php

use App\Actions\Accounts\AssignAccountToReseller;
use App\Actions\Accounts\UnassignAccountFromReseller;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('a platform admin can assign an account to a reseller, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();
    $reseller = Account::factory()->create();

    app(AssignAccountToReseller::class)->handle($admin, $account, $reseller);

    expect($account->fresh()->reseller_account_id)->toBe($reseller->id)
        ->and(AuditEvent::where('action', 'account.reseller_assigned')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('a non-platform-admin cannot assign an account to a reseller', function () {
    $account = Account::factory()->create();
    $reseller = Account::factory()->create();
    $owner = Membership::factory()->for($reseller)->owner()->create()->user;

    app(AssignAccountToReseller::class)->handle($owner, $account, $reseller);
})->throws(AuthorizationException::class);

test('an account cannot be assigned as its own reseller', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();

    app(AssignAccountToReseller::class)->handle($admin, $account, $account);
})->throws(ValidationException::class);

test('an account already reseller-managed cannot itself act as a reseller (one level only)', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $topReseller = Account::factory()->create();
    $alreadyManaged = Account::factory()->create(['reseller_account_id' => $topReseller->id]);
    $candidate = Account::factory()->create();

    app(AssignAccountToReseller::class)->handle($admin, $candidate, $alreadyManaged);
})->throws(ValidationException::class);

test('an account that already manages other accounts cannot itself become reseller-managed (one level only)', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $existingReseller = Account::factory()->create();
    Account::factory()->create(['reseller_account_id' => $existingReseller->id]);
    $candidateNewReseller = Account::factory()->create();

    app(AssignAccountToReseller::class)->handle($admin, $existingReseller, $candidateNewReseller);
})->throws(ValidationException::class);

test('a platform admin can unassign an account from its reseller, with an audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $reseller = Account::factory()->create();
    $account = Account::factory()->create(['reseller_account_id' => $reseller->id]);

    app(UnassignAccountFromReseller::class)->handle($admin, $account);

    expect($account->fresh()->reseller_account_id)->toBeNull()
        ->and(AuditEvent::where('action', 'account.reseller_unassigned')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('unassigning an account with no reseller is a no-op, no audit event', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();

    app(UnassignAccountFromReseller::class)->handle($admin, $account);

    expect(AuditEvent::where('action', 'account.reseller_unassigned')->where('auditable_id', $account->id)->exists())->toBeFalse();
});

test('a non-platform-admin cannot unassign an account from its reseller', function () {
    $reseller = Account::factory()->create();
    $account = Account::factory()->create(['reseller_account_id' => $reseller->id]);
    $stranger = User::factory()->create();

    app(UnassignAccountFromReseller::class)->handle($stranger, $account);
})->throws(AuthorizationException::class);

test('reseller candidate search matches by name, excludes an already reseller-managed account, and still offers one already managing others', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create();
    $eligible = Account::factory()->create(['name' => 'acme-hosting']);
    $alreadyManaged = Account::factory()->create(['name' => 'acme-managed', 'reseller_account_id' => $eligible->id]);
    $alreadyAReseller = Account::factory()->create(['name' => 'acme-reseller']);
    Account::factory()->create(['reseller_account_id' => $alreadyAReseller->id]);

    $response = $this->actingAs($admin)
        ->getJson(route('accounts.reseller-candidates', $account).'?q=acme')
        ->assertOk();

    $names = collect($response->json('candidates'))->pluck('name');

    // acme-managed is itself reseller-managed (would fail AssignAccountToReseller's own
    // anti-chaining rule if picked), so it's excluded. acme-reseller already manages a different
    // account, which is a perfectly normal, still-eligible reseller -- one reseller can manage
    // several accounts at once.
    expect($names)->toContain('acme-hosting')
        ->and($names)->toContain('acme-reseller')
        ->and($names)->not->toContain('acme-managed');
});

test('reseller candidate search excludes the account itself', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $account = Account::factory()->create(['name' => 'self-account']);

    $response = $this->actingAs($admin)
        ->getJson(route('accounts.reseller-candidates', $account).'?q=self')
        ->assertOk();

    expect(collect($response->json('candidates'))->pluck('name'))->not->toContain('self-account');
});

test('a non-admin cannot search reseller candidates', function () {
    $account = Account::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('accounts.reseller-candidates', $account).'?q=x')
        ->assertForbidden();
});
