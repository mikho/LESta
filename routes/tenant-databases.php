<?php

use App\Http\Controllers\TenantDatabases\TenantDatabaseController;
use App\Http\Controllers\TenantDatabases\TenantDatabaseUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tenant-databases', [TenantDatabaseController::class, 'index'])->name('tenant-databases.index');
    Route::get('tenant-databases/create', [TenantDatabaseController::class, 'create'])->name('tenant-databases.create');
    Route::post('tenant-databases', [TenantDatabaseController::class, 'store'])->name('tenant-databases.store');
    Route::get('tenant-databases/{tenantDatabase}/edit', [TenantDatabaseController::class, 'edit'])->name('tenant-databases.edit');
    Route::delete('tenant-databases/{tenantDatabase}', [TenantDatabaseController::class, 'destroy'])->name('tenant-databases.destroy');
    Route::post('tenant-databases/{tenantDatabase}/suspend', [TenantDatabaseController::class, 'suspend'])->name('tenant-databases.suspend');
    Route::post('tenant-databases/{tenantDatabase}/unsuspend', [TenantDatabaseController::class, 'unsuspend'])->name('tenant-databases.unsuspend');
    Route::post('tenant-databases/{tenantDatabase}/rotate-password', [TenantDatabaseController::class, 'rotatePassword'])->name('tenant-databases.rotate-password');
    Route::post('tenant-databases/{tenantDatabase}/open-adminer', [TenantDatabaseController::class, 'openAdminer'])->name('tenant-databases.open-adminer');

    Route::scopeBindings()->group(function () {
        Route::post('tenant-databases/{tenantDatabase}/users', [TenantDatabaseUserController::class, 'store'])->name('tenant-databases.users.store');
        Route::patch('tenant-databases/{tenantDatabase}/users/{user}', [TenantDatabaseUserController::class, 'update'])->name('tenant-databases.users.update');
        Route::post('tenant-databases/{tenantDatabase}/users/{user}/rotate-password', [TenantDatabaseUserController::class, 'rotatePassword'])->name('tenant-databases.users.rotate-password');
        Route::delete('tenant-databases/{tenantDatabase}/users/{user}', [TenantDatabaseUserController::class, 'destroy'])->name('tenant-databases.users.destroy');
    });
});
