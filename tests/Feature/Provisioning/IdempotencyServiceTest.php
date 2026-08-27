<?php

use App\Enums\IdempotencyReceiptStatus;
use App\Exceptions\ConcurrentIdempotentRequestException;
use App\Models\IdempotencyReceipt;
use App\Services\IdempotencyService;
use Illuminate\Support\Str;

test('the same key replays the cached result without re-running the callback', function () {
    $service = app(IdempotencyService::class);
    $calls = 0;

    $first = $service->handle('scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['value' => 'first'];
    });
    $second = $service->handle('scope', 'key-1', function () use (&$calls) {
        $calls++;

        return ['value' => 'second'];
    });

    expect($first)->toBe(['value' => 'first'])
        ->and($second)->toBe(['value' => 'first'])
        ->and($calls)->toBe(1);
});

test('a different key runs the callback normally', function () {
    $service = app(IdempotencyService::class);

    $result = $service->handle('scope', 'key-2', fn () => ['value' => 'ran']);

    expect($result)->toBe(['value' => 'ran']);
});

test('a key already processing throws a concurrency exception', function () {
    IdempotencyReceipt::create([
        'scope' => 'scope',
        'idempotency_key' => 'key-3',
        'status' => IdempotencyReceiptStatus::Processing,
        'correlation_id' => (string) Str::uuid(),
    ]);

    app(IdempotencyService::class)->handle('scope', 'key-3', fn () => 'unreachable');
})->throws(ConcurrentIdempotentRequestException::class);
