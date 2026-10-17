<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function auditEventFor(object $subject, ?User $actor = null, array $extra = []): AuditEvent
{
    return AuditEvent::query()->create([
        'actor_type' => $actor?->getMorphClass(),
        'actor_id' => $actor?->getKey(),
        'auditable_type' => $subject->getMorphClass(),
        'auditable_id' => $subject->getKey(),
        'action' => $extra['action'] ?? 'thing.changed',
        'correlation_id' => (string) Str::uuid(),
    ]);
}

test('an event knows its account, also through a parent and after the resource is gone', function () {
    $zone = DnsZone::factory()->create();
    $record = DnsRecord::factory()->for($zone)->create();

    expect(auditEventFor($zone->account)->account_id)->toBe($zone->account_id)
        ->and(auditEventFor($zone)->account_id)->toBe($zone->account_id)
        ->and(auditEventFor($record)->account_id)->toBe($zone->account_id);

    $record->delete();

    expect(auditEventFor($record)->account_id)->toBe($zone->account_id);
});

test('API token and platform events belong to no account', function () {
    $owner = Membership::factory()->owner()->create();
    $token = ApiToken::factory()->for($owner->user)->create(['account_id' => $owner->account_id]);

    expect(auditEventFor($token)->account_id)->toBeNull();
});

test('a member reads their own account log, with how each change was made, and nothing else', function () {
    $membership = Membership::factory()->member()->create();
    $owner = Membership::factory()->owner()->for($membership->account)->create()->user;
    $site = WebDomain::factory()->for($membership->account)->create();
    $token = ApiToken::factory()->for($owner)->create(['name' => 'Deploy script']);

    auditEventFor($site, $owner, ['action' => 'web_domain.updated']);
    $viaApi = auditEventFor($site, $owner, ['action' => 'web_domain.created']);
    AuditEvent::query()->whereKey($viaApi->id)->update(['api_token_id' => $token->id]);
    auditEventFor(WebDomain::factory()->create(), $owner, ['action' => 'web_domain.elsewhere']);

    $this->actingAs($membership->user)->get(route('audit-log.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('audit-log/index')
        ->where('viewingAll', false)
        ->has('events.data', 2)
        ->where('events.data.0.action', 'web_domain.created')
        ->where('events.data.0.via', ['kind' => 'api', 'token' => 'Deploy script'])
        ->where('events.data.0.actor.email', $owner->email)
        ->where('events.data.1.via.kind', 'panel'));
});

test('a revoked token still shows the change came through the API', function () {
    $membership = Membership::factory()->owner()->create();
    $token = ApiToken::factory()->for($membership->user)->create();
    $event = auditEventFor($membership->account, $membership->user);
    AuditEvent::query()->whereKey($event->id)->update(['api_token_id' => $token->id]);
    $token->delete();

    $this->actingAs($membership->user)->get(route('audit-log.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('events.data.0.via', ['kind' => 'api', 'token' => null]));
});

test('a customer sees that platform staff acted, not who', function () {
    $membership = Membership::factory()->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    auditEventFor($membership->account, $admin, ['action' => 'account.suspended']);

    $this->actingAs($membership->user)->get(route('audit-log.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('events.data.0.actor', ['name' => 'Platform administrator', 'email' => null]));

    $this->actingAs($admin)->get(route('audit-log.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('viewingAll', true)->where('events.data.0.actor.email', $admin->email));
});

test('a platform admin reads every event and filters them', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $one = Account::factory()->create();
    $two = Account::factory()->create();
    auditEventFor($one, null, ['action' => 'account.created']);
    auditEventFor($two, $admin, ['action' => 'account.updated']);
    auditEventFor(WebDomain::factory()->for($two)->create(), $admin, ['action' => 'web_domain.created']);

    $this->actingAs($admin)->get(route('audit-log.index'))->assertInertia(fn (Assert $page) => $page->has('events.data', 3));
    $this->actingAs($admin)->get(route('audit-log.index', ['account' => $two->public_id]))->assertInertia(fn (Assert $page) => $page->has('events.data', 2));
    $this->actingAs($admin)->get(route('audit-log.index', ['action' => 'web_domain.']))->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
    $this->actingAs($admin)->get(route('audit-log.index', ['via' => 'system']))->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.via.kind', 'system'));
});

test('a member cannot read another account by naming it', function () {
    $membership = Membership::factory()->member()->create();
    $other = Account::factory()->create();

    $this->actingAs($membership->user)->get(route('audit-log.index', ['account' => $other->public_id]))->assertForbidden();
});

test('a user with no account and no permission sees an empty log, and the sidebar knows', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('audit-log.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 0)->where('auth.can_view_audit_log', false));
});

test('the migration gave provider_admin the new permission, and the chain still verifies', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    auditEventFor(Account::factory()->create(), $admin);

    expect(Permission::query()->where('name', 'audit_events.view_any')->exists())->toBeTrue()
        ->and($admin->hasPermission('audit_events.view_any'))->toBeTrue()
        ->and(Artisan::call('audit:verify-chain'))->toBe(0);
});
