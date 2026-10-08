<?php

use App\Http\Controllers\Domains\FileManagerController;
use App\Http\Controllers\Domains\WebDomainController;
use App\Http\Controllers\Domains\WebDomainRedirectController;
use App\Http\Controllers\Domains\WebLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('domains', [WebDomainController::class, 'index'])->name('domains.index');
    Route::get('domains/create', [WebDomainController::class, 'create'])->name('domains.create');
    Route::post('domains', [WebDomainController::class, 'store'])->name('domains.store');
    Route::get('domains/{webDomain}/edit', [WebDomainController::class, 'edit'])->name('domains.edit');
    Route::put('domains/{webDomain}', [WebDomainController::class, 'update'])->name('domains.update');
    Route::put('domains/{webDomain}/ssh-key', [WebDomainController::class, 'updateSshKey'])->name('domains.update-ssh-key');
    Route::delete('domains/{webDomain}', [WebDomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('domains/{webDomain}/redirects', [WebDomainRedirectController::class, 'store'])->name('domains.redirects.store');
    Route::delete('domains/{webDomain}/redirects/{redirect}', [WebDomainRedirectController::class, 'destroy'])->name('domains.redirects.destroy');
    Route::get('domains/{webDomain}/logs', [WebLogController::class, 'index'])->name('domains.logs.index');
    Route::post('domains/{webDomain}/logs/observe', [WebLogController::class, 'observe'])->name('domains.logs.observe');
    Route::get('domains/{webDomain}/logs/download/{operation}', [WebLogController::class, 'download'])->name('domains.logs.download');
    Route::post('domains/{webDomain}/suspend', [WebDomainController::class, 'suspend'])->name('domains.suspend');
    Route::post('domains/{webDomain}/unsuspend', [WebDomainController::class, 'unsuspend'])->name('domains.unsuspend');

    // The browser file manager: a single stateful page (files.index), never the usual
    // index/create/edit three-page pattern -- the rest of these are plain JSON endpoints that
    // page's own React code polls/calls directly, never an Inertia::render response, since every
    // operation here is dispatched then completed asynchronously (see
    // App\Actions\Files\* and files.manager.v1's own dedicated fast lane).
    Route::get('domains/{webDomain}/files', [FileManagerController::class, 'index'])->name('domains.files.index');
    Route::post('domains/{webDomain}/files/observe', [FileManagerController::class, 'observe'])->name('domains.files.observe');
    Route::post('domains/{webDomain}/files', [FileManagerController::class, 'store'])->name('domains.files.store');
    Route::put('domains/{webDomain}/files', [FileManagerController::class, 'update'])->name('domains.files.update');
    Route::delete('domains/{webDomain}/files', [FileManagerController::class, 'destroy'])->name('domains.files.destroy');
    Route::get('domains/{webDomain}/files/operations/{operation}', [FileManagerController::class, 'operationStatus'])->name('domains.files.operation-status');
});
