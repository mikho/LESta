<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WebhookController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Creating a token asks for the password again, like the security page.
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware([RequirePassword::class, 'throttle:10,1'])
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Adding a webhook shows its signing secret once, so it asks for the password again too.
    Route::get('settings/webhooks', [WebhookController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('webhooks.index');
    Route::post('settings/webhooks', [WebhookController::class, 'store'])
        ->middleware([RequirePassword::class, 'throttle:10,1'])
        ->name('webhooks.store');
    Route::post('settings/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
    Route::delete('settings/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
