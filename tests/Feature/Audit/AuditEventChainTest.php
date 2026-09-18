<?php

use App\Models\AuditEvent;
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
