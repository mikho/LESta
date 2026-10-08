<?php

use App\Http\Controllers\Customers\CustomerResourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('customer-resources/{type}/{uuid}', [CustomerResourceController::class, 'show'])
        ->whereIn('type', ['domains', 'dns', 'mail', 'databases', 'cron-jobs'])
        ->whereUuid('uuid')
        ->name('customer-resources.show');
});
