<?php

use App\Http\Controllers\Accounts\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
    Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::post('accounts/{account}/reseller', [AccountController::class, 'assignReseller'])->name('accounts.reseller.store');
    Route::delete('accounts/{account}/reseller', [AccountController::class, 'unassignReseller'])->name('accounts.reseller.destroy');
});
