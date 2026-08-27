<?php

use App\Actions\Support\ViewAccountAsSupport;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Auth\Access\AuthorizationException;

test('a provider admin can view an account as support with no identity swap and an audit trail', function () {
    $account = Account::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);
    $result = app(ViewAccountAsSupport::class)->handle($admin, $account);

    expect($result->is($account))->toBeTrue()
        ->and(auth()->id())->toBe($admin->id) // no identity swap
        ->and(AuditEvent::where('action', 'account.viewed_as_support')->where('auditable_id', $account->id)->exists())->toBeTrue();
});

test('a non-admin cannot use the support view', function () {
    $account = Account::factory()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(ViewAccountAsSupport::class)->handle($owner, $account);
})->throws(AuthorizationException::class);
