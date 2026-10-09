<?php

use App\Models\User;

test('signing in keeps the previous sign-in alongside the latest', function () {
    $user = User::factory()->create();

    $this->travelTo(now()->subDay());
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $first = $user->fresh()->last_login_at;
    auth()->logout();

    $this->travelBack();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $user = $user->fresh();
    expect($first)->not->toBeNull()
        ->and($user->previous_login_at?->equalTo($first))->toBeTrue()
        ->and($user->last_login_at?->isAfter($first))->toBeTrue()
        ->and($user->last_login_ip)->toBe('127.0.0.1');
});
