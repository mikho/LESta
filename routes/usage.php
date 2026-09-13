<?php

use App\Http\Controllers\Usage\UsageSnapshotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('usage', [UsageSnapshotController::class, 'index'])->name('usage.index');
});
