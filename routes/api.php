<?php

use App\Http\Controllers\Api\V1\AccountBackupController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CronJobController;
use App\Http\Controllers\Api\V1\DnsRecordController;
use App\Http\Controllers\Api\V1\DnsZoneController;
use App\Http\Controllers\Api\V1\MailAccountController;
use App\Http\Controllers\Api\V1\MailDomainController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\TenantDatabaseController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebDomainController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The public API, version 1 (the vault's "Tenant and Admin API Design"). Stateless: a personal API
 * token in the Authorization header, no session, no cookies. Each endpoint calls the same Action or
 * policy the panel does; the ability middleware (api.ability:<family>:<read|write>) only narrows.
 */
Route::prefix('api/v1')->name('api.v1.')->middleware([AuthenticateApiToken::class, 'throttle:api'])->group(function () {
    Route::get('user', [UserController::class, 'show'])->name('user');
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::get('operations/{operation}', [OperationController::class, 'show'])->whereUuid('operation')->name('operations.show');

    // One account's resources. Scoped bindings: a domain, zone or backup is found only within the
    // account in the path, so another account's id is a 404.
    Route::prefix('accounts/{account}')->name('accounts.')->scopeBindings()->group(function () {
        Route::middleware('api.ability:web:read')->group(function () {
            Route::get('web-domains', [WebDomainController::class, 'index'])->name('web-domains.index');
            Route::get('web-domains/{webDomain}', [WebDomainController::class, 'show'])->name('web-domains.show');
        });

        Route::middleware('api.ability:web:write')->group(function () {
            Route::post('web-domains', [WebDomainController::class, 'store'])->middleware('api.idempotent')->name('web-domains.store');
            Route::patch('web-domains/{webDomain}', [WebDomainController::class, 'update'])->name('web-domains.update');
            Route::delete('web-domains/{webDomain}', [WebDomainController::class, 'destroy'])->name('web-domains.destroy');
            Route::post('web-domains/{webDomain}/suspend', [WebDomainController::class, 'suspend'])->middleware('api.idempotent')->name('web-domains.suspend');
            Route::post('web-domains/{webDomain}/unsuspend', [WebDomainController::class, 'unsuspend'])->middleware('api.idempotent')->name('web-domains.unsuspend');
        });

        Route::middleware('api.ability:dns:read')->group(function () {
            Route::get('dns-zones', [DnsZoneController::class, 'index'])->name('dns-zones.index');
            Route::get('dns-zones/{dnsZone}', [DnsZoneController::class, 'show'])->name('dns-zones.show');
            Route::get('dns-zones/{dnsZone}/records', [DnsRecordController::class, 'index'])->name('dns-zones.records.index');
            Route::get('dns-zones/{dnsZone}/records/{record}', [DnsRecordController::class, 'show'])->name('dns-zones.records.show');
        });

        Route::middleware('api.ability:dns:write')->group(function () {
            Route::post('dns-zones', [DnsZoneController::class, 'store'])->middleware('api.idempotent')->name('dns-zones.store');
            Route::patch('dns-zones/{dnsZone}', [DnsZoneController::class, 'update'])->name('dns-zones.update');
            Route::delete('dns-zones/{dnsZone}', [DnsZoneController::class, 'destroy'])->name('dns-zones.destroy');
            Route::post('dns-zones/{dnsZone}/suspend', [DnsZoneController::class, 'suspend'])->middleware('api.idempotent')->name('dns-zones.suspend');
            Route::post('dns-zones/{dnsZone}/unsuspend', [DnsZoneController::class, 'unsuspend'])->middleware('api.idempotent')->name('dns-zones.unsuspend');
            Route::post('dns-zones/{dnsZone}/records', [DnsRecordController::class, 'store'])->middleware('api.idempotent')->name('dns-zones.records.store');
            Route::patch('dns-zones/{dnsZone}/records/{record}', [DnsRecordController::class, 'update'])->name('dns-zones.records.update');
            Route::delete('dns-zones/{dnsZone}/records/{record}', [DnsRecordController::class, 'destroy'])->name('dns-zones.records.destroy');
            Route::post('dns-zones/{dnsZone}/records/{record}/suspend', [DnsRecordController::class, 'suspend'])->middleware('api.idempotent')->name('dns-zones.records.suspend');
            Route::post('dns-zones/{dnsZone}/records/{record}/unsuspend', [DnsRecordController::class, 'unsuspend'])->middleware('api.idempotent')->name('dns-zones.records.unsuspend');
        });

        Route::middleware('api.ability:mail:read')->group(function () {
            Route::get('mail-domains', [MailDomainController::class, 'index'])->name('mail-domains.index');
            Route::get('mail-domains/{mailDomain}', [MailDomainController::class, 'show'])->name('mail-domains.show');
            Route::get('mail-domains/{mailDomain}/mailboxes', [MailAccountController::class, 'index'])->name('mail-domains.mailboxes.index');
        });

        Route::middleware('api.ability:databases:read')->group(function () {
            Route::get('databases', [TenantDatabaseController::class, 'index'])->name('databases.index');
            Route::get('databases/{tenantDatabase}', [TenantDatabaseController::class, 'show'])->name('databases.show');
        });

        Route::middleware('api.ability:cron:read')->group(function () {
            Route::get('cron-jobs', [CronJobController::class, 'index'])->name('cron-jobs.index');
            Route::get('cron-jobs/{cronJob}', [CronJobController::class, 'show'])->name('cron-jobs.show');
        });

        Route::middleware('api.ability:backups:read')->group(function () {
            Route::get('backups', [AccountBackupController::class, 'index'])->name('backups.index');
            Route::get('backups/{accountBackup}', [AccountBackupController::class, 'show'])->name('backups.show');
        });

        Route::middleware('api.ability:usage:read')->group(function () {
            Route::get('usage', [UsageController::class, 'index'])->name('usage.index');
            Route::get('usage/monthly', [UsageController::class, 'monthly'])->name('usage.monthly');
        });
    });
});
