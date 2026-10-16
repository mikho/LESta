<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The public API, version 1 (the vault's "Tenant and Admin API Design"). Stateless: a personal API
 * token in the Authorization header, no session, no cookies. Each endpoint calls the same Action or
 * policy the panel does; the ability middleware (api.ability:<family>:<read|write>) only narrows.
 */
Route::prefix('api/v1')->name('api.v1.')->middleware([AuthenticateApiToken::class, 'throttle:api'])->group(function () {
    Route::get('user', [UserController::class, 'show'])->name('user');
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
});
