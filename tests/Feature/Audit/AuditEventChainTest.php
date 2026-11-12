<?php

use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('each new audit event chains to the previous one', function () {
    $first = AuditEvent::create([
        'auditable_type' => 'test',
        'auditable_id' => 1,
        'action' => 'test.first',
        'correlation_id' => (string) Str::uuid(),
    ]);

    $second = AuditEvent::create([
        'auditable_type' => 'test',
        'auditable_id' => 2,
        'action' => 'test.second',
        'correlation_id' => (string) Str::uuid(),
    ]);

    expect($first->previous_hash)->toBeNull()
        ->and($first->hash)->not->toBeNull()
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash);
});

test('the chain verifies intact after normal creation', function () {
    AuditEvent::factory()->count(5)->create();

    $this->artisan('audit:verify-chain')
        ->assertExitCode(0)
        ->expectsOutputToContain('verified intact');
});

test('tampering with a row is detected by the chain verification command', function () {
    AuditEvent::factory()->count(3)->create();

    $middleRow = AuditEvent::query()->orderBy('id')->get()->get(1);

    DB::table('audit_events')->where('id', $middleRow->id)->update(['action' => 'tampered.action']);

    $this->artisan('audit:verify-chain')
        ->assertExitCode(1)
        ->expectsOutputToContain((string) $middleRow->id);
});

test('deleting a row is detected by the chain verification command', function () {
    AuditEvent::factory()->count(3)->create();

    $middleRow = AuditEvent::query()->orderBy('id')->get()->get(1);
    $lastRow = AuditEvent::query()->orderBy('id')->get()->last();

    DB::table('audit_events')->where('id', $middleRow->id)->delete();

    $this->artisan('audit:verify-chain')
        ->assertExitCode(1)
        ->expectsOutputToContain((string) $lastRow->id);
});

test('record names the actor, the audited record and the action', function () {
    $actor = User::factory()->create();
    $package = Package::factory()->create();

    $correlationId = (string) Str::uuid();

    $event = AuditEvent::record($package, 'package.updated', $actor, $correlationId, ['field' => 'name']);

    expect($event->actor_type)->toBe($actor->getMorphClass())
        ->and((int) $event->actor_id)->toBe($actor->id)
        ->and($event->auditable_type)->toBe($package->getMorphClass())
        ->and((int) $event->auditable_id)->toBe($package->id)
        ->and($event->action)->toBe('package.updated')
        ->and($event->correlation_id)->toBe($correlationId)
        ->and($event->metadata)->toBe(['field' => 'name']);
});

test('record without an actor or correlation id writes a system event with a fresh correlation id', function () {
    $event = AuditEvent::record(Package::factory()->create(), 'package.created', null);

    expect($event->actor_type)->toBeNull()
        ->and($event->actor_id)->toBeNull()
        ->and(Str::isUuid($event->correlation_id))->toBeTrue()
        ->and($event->metadata)->toBeNull();
});
