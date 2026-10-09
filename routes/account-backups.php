<?php

use App\Http\Controllers\Backups\AccountBackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('account-backups', [AccountBackupController::class, 'index'])->name('account-backups.index');
    Route::post('account-backups', [AccountBackupController::class, 'store'])->name('account-backups.store');
    Route::put('account-backups/schedule', [AccountBackupController::class, 'updateSchedule'])->name('account-backups.schedule');
    Route::put('account-backups/destination', [AccountBackupController::class, 'updateDestination'])->name('account-backups.destination');
    Route::delete('account-backups/destination', [AccountBackupController::class, 'destroyDestination'])->name('account-backups.destination.destroy');
    Route::post('account-backups/import/storage', [AccountBackupController::class, 'importFromStorage'])->name('account-backups.import.storage');
    Route::post('account-backups/import/uploads', [AccountBackupController::class, 'beginUpload'])->name('account-backups.import.begin');
    Route::put('account-backups/import/uploads/{import}/chunk', [AccountBackupController::class, 'uploadChunk'])->middleware('throttle:1200,1')->name('account-backups.import.chunk');
    Route::post('account-backups/{backup}/copy', [AccountBackupController::class, 'copy'])->name('account-backups.copy');
    Route::post('account-backups/{backup}/restore', [AccountBackupController::class, 'restore'])->name('account-backups.restore');
    Route::post('account-backups/{backup}/download', [AccountBackupController::class, 'prepareDownload'])->name('account-backups.prepare-download');
    Route::get('account-backups/{backup}/downloads/{download}', [AccountBackupController::class, 'download'])->name('account-backups.download');
    Route::delete('account-backups/{backup}', [AccountBackupController::class, 'destroy'])->name('account-backups.destroy');
});
