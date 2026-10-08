<?php

use App\Http\Controllers\IpRules\IpRuleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('ip-rules', [IpRuleController::class, 'index'])->name('ip-rules.index');
    Route::post('ip-rules', [IpRuleController::class, 'store'])->name('ip-rules.store');
    Route::delete('ip-rules/{ipRule}', [IpRuleController::class, 'destroy'])->name('ip-rules.destroy');
});
