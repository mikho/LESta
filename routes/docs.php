<?php

use App\Http\Controllers\Docs\DocsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('docs/{guide}', [DocsController::class, 'show'])->name('docs.show');
    Route::get('docs/{guide}/{chapter}', [DocsController::class, 'chapter'])->name('docs.chapter');
});
