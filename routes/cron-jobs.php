<?php

use App\Http\Controllers\CronJobs\CronJobController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('cron-jobs', [CronJobController::class, 'index'])->name('cron-jobs.index');
    Route::get('cron-jobs/create', [CronJobController::class, 'create'])->name('cron-jobs.create');
    Route::post('cron-jobs', [CronJobController::class, 'store'])->name('cron-jobs.store');
    Route::get('cron-jobs/{cronJob}/edit', [CronJobController::class, 'edit'])->name('cron-jobs.edit');
    Route::put('cron-jobs/{cronJob}', [CronJobController::class, 'update'])->name('cron-jobs.update');
    Route::delete('cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->name('cron-jobs.destroy');
    Route::post('cron-jobs/{cronJob}/suspend', [CronJobController::class, 'suspend'])->name('cron-jobs.suspend');
    Route::post('cron-jobs/{cronJob}/unsuspend', [CronJobController::class, 'unsuspend'])->name('cron-jobs.unsuspend');
});
