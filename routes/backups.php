<?php

use App\Http\Controllers\Backups\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('backups/create', [BackupController::class, 'create'])->name('backups.create');
    Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
    Route::delete('backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
    Route::post('backups/{backup}/prepare-download', [BackupController::class, 'prepareDownload'])->name('backups.prepare-download');
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');
});
