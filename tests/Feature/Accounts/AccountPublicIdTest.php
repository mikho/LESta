<?php

use App\Models\Account;

test('a new account gets a real, unique, 12-character public_id', function () {
    $account = Account::factory()->create();

    expect($account->public_id)->toHaveLength(12)
        ->and(Account::where('public_id', $account->public_id)->count())->toBe(1);
});

test('public_id is never derived from or correlated with the row auto-increment id', function () {
    $first = Account::factory()->create();
    $second = Account::factory()->create();

    expect($first->public_id)->not->toBe((string) $first->id)
        ->and($second->public_id)->not->toBe((string) $second->id)
        ->and($first->public_id)->not->toBe($second->public_id);
});

test('public_id is used as the route key, not the internal id', function () {
    $account = Account::factory()->create();

    expect($account->getRouteKeyName())->toBe('public_id')
        ->and($account->getRouteKey())->toBe($account->public_id);
});
