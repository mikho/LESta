<?php

use App\Actions\Support\StartImpersonation;
use App\Actions\Support\StopImpersonation;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Auth\Access\AuthorizationException;

test('starting impersonation writes an audit event and swaps identity', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);
    app('request')->setLaravelSession(app('session')->driver());
    app(StartImpersonation::class)->handle($admin, $membership, 'support ticket #123');

    expect(auth()->id())->toBe($membership->user_id)
        ->and(AuditEvent::where('action', 'impersonation.started')->where('auditable_id', $membership->id)->exists())->toBeTrue();
});

test('stopping impersonation writes an audit event and restores the admin session', function () {
    $account = Account::factory()->create();
    $membership = Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin);
    app('request')->setLaravelSession(app('session')->driver());
    app(StartImpersonation::class)->handle($admin, $membership, 'support ticket #123');

    app(StopImpersonation::class)->handle(request());

    expect(auth()->id())->toBe($admin->id)
        ->and(AuditEvent::where('action', 'impersonation.ended')->exists())->toBeTrue();
});

test('impersonation of a platform-scope membership is denied', function () {
    $platformMembership = Membership::factory()->providerAdmin()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(StartImpersonation::class)->handle($admin, $platformMembership, 'invalid target');
})->throws(AuthorizationException::class);
