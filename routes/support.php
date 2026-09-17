<?php

use App\Http\Controllers\Support\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('impersonation/{membership}', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');
});
