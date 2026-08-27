<?php

use App\Http\Controllers\Domains\WebDomainController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('domains', [WebDomainController::class, 'index'])->name('domains.index');
    Route::get('domains/create', [WebDomainController::class, 'create'])->name('domains.create');
    Route::post('domains', [WebDomainController::class, 'store'])->name('domains.store');
    Route::get('domains/{webDomain}/edit', [WebDomainController::class, 'edit'])->name('domains.edit');
    Route::put('domains/{webDomain}', [WebDomainController::class, 'update'])->name('domains.update');
    Route::delete('domains/{webDomain}', [WebDomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('domains/{webDomain}/suspend', [WebDomainController::class, 'suspend'])->name('domains.suspend');
    Route::post('domains/{webDomain}/unsuspend', [WebDomainController::class, 'unsuspend'])->name('domains.unsuspend');
});
