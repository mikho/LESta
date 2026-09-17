<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Memberships\MembershipController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    // create/store and mine must be registered before the {account} wildcard below, or route-
    // model binding would swallow "create"/"mine" as the {account} segment.
    Route::get('accounts/create', [AccountController::class, 'create'])->name('accounts.create');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/mine', [AccountController::class, 'mine'])->name('accounts.mine');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
    Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::get('accounts/{account}/reseller-candidates', [AccountController::class, 'resellerCandidates'])->name('accounts.reseller-candidates');
    Route::post('accounts/{account}/reseller', [AccountController::class, 'assignReseller'])->name('accounts.reseller.store');
    Route::delete('accounts/{account}/reseller', [AccountController::class, 'unassignReseller'])->name('accounts.reseller.destroy');
    Route::post('accounts/{account}/memberships', [MembershipController::class, 'store'])->name('accounts.memberships.store');
    Route::delete('accounts/{account}/memberships/{membership}', [MembershipController::class, 'destroy'])->name('accounts.memberships.destroy');
});
