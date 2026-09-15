<?php

use App\Http\Controllers\Mail\MailAccountController;
use App\Http\Controllers\Mail\MailDomainController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('mail', [MailDomainController::class, 'index'])->name('mail.index');
    Route::get('mail/create', [MailDomainController::class, 'create'])->name('mail.create');
    Route::post('mail', [MailDomainController::class, 'store'])->name('mail.store');
    Route::get('mail/{mailDomain}/edit', [MailDomainController::class, 'edit'])->name('mail.edit');
    Route::put('mail/{mailDomain}', [MailDomainController::class, 'update'])->name('mail.update');
    Route::delete('mail/{mailDomain}', [MailDomainController::class, 'destroy'])->name('mail.destroy');
    Route::post('mail/{mailDomain}/suspend', [MailDomainController::class, 'suspend'])->name('mail.suspend');
    Route::post('mail/{mailDomain}/unsuspend', [MailDomainController::class, 'unsuspend'])->name('mail.unsuspend');

    Route::post('mail/{mailDomain}/accounts', [MailAccountController::class, 'store'])->name('mail.accounts.store');
    Route::put('mail/{mailDomain}/accounts/{mailAccount}', [MailAccountController::class, 'update'])->name('mail.accounts.update');
    Route::delete('mail/{mailDomain}/accounts/{mailAccount}', [MailAccountController::class, 'destroy'])->name('mail.accounts.destroy');
    Route::post('mail/{mailDomain}/accounts/{mailAccount}/suspend', [MailAccountController::class, 'suspend'])->name('mail.accounts.suspend');
    Route::post('mail/{mailDomain}/accounts/{mailAccount}/unsuspend', [MailAccountController::class, 'unsuspend'])->name('mail.accounts.unsuspend');
    Route::post('mail/{mailDomain}/accounts/{mailAccount}/rotate-password', [MailAccountController::class, 'rotatePassword'])->name('mail.accounts.rotate-password');
});
