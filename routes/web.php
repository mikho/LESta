<?php

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/domains.php';
require __DIR__.'/dns.php';
require __DIR__.'/mail.php';
require __DIR__.'/tenant-databases.php';
require __DIR__.'/cron-jobs.php';
require __DIR__.'/nodes.php';
require __DIR__.'/packages.php';
require __DIR__.'/backups.php';
require __DIR__.'/usage.php';
require __DIR__.'/accounts.php';
require __DIR__.'/roles.php';
