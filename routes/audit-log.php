<?php

use App\Http\Controllers\AuditLog\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
});
