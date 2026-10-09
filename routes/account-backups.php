<?php

use App\Http\Controllers\Backups\AccountBackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('account-backups', [AccountBackupController::class, 'index'])->name('account-backups.index');
    Route::post('account-backups', [AccountBackupController::class, 'store'])->name('account-backups.store');
    Route::put('account-backups/schedule', [AccountBackupController::class, 'updateSchedule'])->name('account-backups.schedule');
    Route::post('account-backups/{backup}/restore', [AccountBackupController::class, 'restore'])->name('account-backups.restore');
    Route::post('account-backups/{backup}/download', [AccountBackupController::class, 'prepareDownload'])->name('account-backups.prepare-download');
    Route::get('account-backups/{backup}/downloads/{download}', [AccountBackupController::class, 'download'])->name('account-backups.download');
    Route::delete('account-backups/{backup}', [AccountBackupController::class, 'destroy'])->name('account-backups.destroy');
});
