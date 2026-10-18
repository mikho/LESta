<?php

use App\Http\Controllers\Api\V1\AccountBackupController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\V1\Admin\CustomerResourceController;
use App\Http\Controllers\Api\V1\Admin\NodeBackupController;
use App\Http\Controllers\Api\V1\Admin\NodeController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\CronJobController;
use App\Http\Controllers\Api\V1\DnsRecordController;
use App\Http\Controllers\Api\V1\DnsZoneController;
use App\Http\Controllers\Api\V1\MailAccountController;
use App\Http\Controllers\Api\V1\MailDomainController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\TenantDatabaseController;
use App\Http\Controllers\Api\V1\TenantDatabaseUserController;
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
            Route::get('mail-domains/{mailDomain}/mailboxes/{mailbox}', [MailAccountController::class, 'show'])->name('mail-domains.mailboxes.show');
        });

        Route::middleware('api.ability:mail:write')->group(function () {
            Route::post('mail-domains', [MailDomainController::class, 'store'])->middleware('api.idempotent')->name('mail-domains.store');
            Route::patch('mail-domains/{mailDomain}', [MailDomainController::class, 'update'])->name('mail-domains.update');
            Route::delete('mail-domains/{mailDomain}', [MailDomainController::class, 'destroy'])->name('mail-domains.destroy');
            Route::post('mail-domains/{mailDomain}/suspend', [MailDomainController::class, 'suspend'])->middleware('api.idempotent')->name('mail-domains.suspend');
            Route::post('mail-domains/{mailDomain}/unsuspend', [MailDomainController::class, 'unsuspend'])->middleware('api.idempotent')->name('mail-domains.unsuspend');
            Route::post('mail-domains/{mailDomain}/mailboxes', [MailAccountController::class, 'store'])->middleware('api.idempotent:data.password')->name('mail-domains.mailboxes.store');
            Route::patch('mail-domains/{mailDomain}/mailboxes/{mailbox}', [MailAccountController::class, 'update'])->name('mail-domains.mailboxes.update');
            Route::delete('mail-domains/{mailDomain}/mailboxes/{mailbox}', [MailAccountController::class, 'destroy'])->name('mail-domains.mailboxes.destroy');
            Route::post('mail-domains/{mailDomain}/mailboxes/{mailbox}/suspend', [MailAccountController::class, 'suspend'])->middleware('api.idempotent')->name('mail-domains.mailboxes.suspend');
            Route::post('mail-domains/{mailDomain}/mailboxes/{mailbox}/unsuspend', [MailAccountController::class, 'unsuspend'])->middleware('api.idempotent')->name('mail-domains.mailboxes.unsuspend');
            Route::post('mail-domains/{mailDomain}/mailboxes/{mailbox}/rotate-password', [MailAccountController::class, 'rotatePassword'])->middleware('api.idempotent:data.password')->name('mail-domains.mailboxes.rotate-password');
        });

        Route::middleware('api.ability:databases:read')->group(function () {
            Route::get('databases', [TenantDatabaseController::class, 'index'])->name('databases.index');
            Route::get('databases/{tenantDatabase}', [TenantDatabaseController::class, 'show'])->name('databases.show');
            Route::get('databases/{tenantDatabase}/users', [TenantDatabaseUserController::class, 'index'])->name('databases.users.index');
            Route::get('databases/{tenantDatabase}/users/{user}', [TenantDatabaseUserController::class, 'show'])->name('databases.users.show');
        });

        Route::middleware('api.ability:databases:write')->group(function () {
            Route::post('databases', [TenantDatabaseController::class, 'store'])->middleware('api.idempotent:data.password')->name('databases.store');
            Route::delete('databases/{tenantDatabase}', [TenantDatabaseController::class, 'destroy'])->name('databases.destroy');
            Route::post('databases/{tenantDatabase}/suspend', [TenantDatabaseController::class, 'suspend'])->middleware('api.idempotent')->name('databases.suspend');
            Route::post('databases/{tenantDatabase}/unsuspend', [TenantDatabaseController::class, 'unsuspend'])->middleware('api.idempotent')->name('databases.unsuspend');
            Route::post('databases/{tenantDatabase}/rotate-password', [TenantDatabaseController::class, 'rotatePassword'])->middleware('api.idempotent:data.password')->name('databases.rotate-password');
            Route::post('databases/{tenantDatabase}/users', [TenantDatabaseUserController::class, 'store'])->middleware('api.idempotent:data.password')->name('databases.users.store');
            Route::patch('databases/{tenantDatabase}/users/{user}', [TenantDatabaseUserController::class, 'update'])->name('databases.users.update');
            Route::delete('databases/{tenantDatabase}/users/{user}', [TenantDatabaseUserController::class, 'destroy'])->name('databases.users.destroy');
            Route::post('databases/{tenantDatabase}/users/{user}/rotate-password', [TenantDatabaseUserController::class, 'rotatePassword'])->middleware('api.idempotent:data.password')->name('databases.users.rotate-password');
        });

        Route::middleware('api.ability:cron:read')->group(function () {
            Route::get('cron-jobs', [CronJobController::class, 'index'])->name('cron-jobs.index');
            Route::get('cron-jobs/{cronJob}', [CronJobController::class, 'show'])->name('cron-jobs.show');
        });

        Route::middleware('api.ability:cron:write')->group(function () {
            Route::post('cron-jobs', [CronJobController::class, 'store'])->middleware('api.idempotent')->name('cron-jobs.store');
            Route::patch('cron-jobs/{cronJob}', [CronJobController::class, 'update'])->name('cron-jobs.update');
            Route::delete('cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->name('cron-jobs.destroy');
            Route::post('cron-jobs/{cronJob}/suspend', [CronJobController::class, 'suspend'])->middleware('api.idempotent')->name('cron-jobs.suspend');
            Route::post('cron-jobs/{cronJob}/unsuspend', [CronJobController::class, 'unsuspend'])->middleware('api.idempotent')->name('cron-jobs.unsuspend');
        });

        Route::middleware('api.ability:backups:read')->group(function () {
            Route::get('backups', [AccountBackupController::class, 'index'])->name('backups.index');
            Route::get('backups/{accountBackup}', [AccountBackupController::class, 'show'])->name('backups.show');
        });

        Route::middleware('api.ability:backups:write')->group(function () {
            Route::post('backups', [AccountBackupController::class, 'store'])->middleware('api.idempotent')->name('backups.store');
            Route::post('backups/{accountBackup}/restore', [AccountBackupController::class, 'restore'])->middleware('api.idempotent')->name('backups.restore');
            Route::delete('backups/{accountBackup}', [AccountBackupController::class, 'destroy'])->name('backups.destroy');
        });

        Route::middleware('api.ability:usage:read')->group(function () {
            Route::get('usage', [UsageController::class, 'index'])->name('usage.index');
            Route::get('usage/monthly', [UsageController::class, 'monthly'])->name('usage.monthly');
        });
    });

    // Platform administration: tokens with admin:read or admin:write, which only a platform user can
    // hold, and on each route the same platform permission the panel page needs (api.permission).
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('api.ability:admin:read')->group(function () {
            Route::get('accounts', [AdminAccountController::class, 'index'])->middleware('api.permission:accounts.view_as_support')->name('accounts.index');
            Route::get('accounts/{account}', [AdminAccountController::class, 'show'])->middleware('api.permission:accounts.view_as_support')->name('accounts.show');
            Route::get('packages', [PackageController::class, 'index'])->middleware('api.permission:packages.view_any')->name('packages.index');
            Route::get('packages/{package}', [PackageController::class, 'show'])->middleware('api.permission:packages.view')->name('packages.show');
            Route::get('nodes', [NodeController::class, 'index'])->middleware('api.permission:nodes.view_any')->name('nodes.index');
            Route::get('nodes/{node}', [NodeController::class, 'show'])->middleware('api.permission:nodes.view')->name('nodes.show');
            Route::get('node-backups', [NodeBackupController::class, 'index'])->middleware('api.permission:backups.view_any')->name('node-backups.index');
            Route::get('node-backups/{backup}', [NodeBackupController::class, 'show'])->middleware('api.permission:backups.view')->name('node-backups.show');
            Route::get('web-domains', [CustomerResourceController::class, 'webDomains'])->middleware('api.permission:web_domains.view_any')->name('web-domains.index');
            Route::get('dns-zones', [CustomerResourceController::class, 'dnsZones'])->middleware('api.permission:dns_zones.view_any')->name('dns-zones.index');
            Route::get('mail-domains', [CustomerResourceController::class, 'mailDomains'])->middleware('api.permission:mail_domains.view_any')->name('mail-domains.index');
            Route::get('databases', [CustomerResourceController::class, 'databases'])->middleware('api.permission:tenant_databases.view_any')->name('databases.index');
            Route::get('cron-jobs', [CustomerResourceController::class, 'cronJobs'])->middleware('api.permission:cron_jobs.view_any')->name('cron-jobs.index');
        });

        Route::middleware('api.ability:admin:write')->group(function () {
            Route::post('accounts', [AdminAccountController::class, 'store'])->middleware(['api.permission:accounts.create', 'api.idempotent'])->name('accounts.store');
            Route::patch('accounts/{account}', [AdminAccountController::class, 'update'])->middleware('api.permission:accounts.update')->name('accounts.update');
            Route::delete('accounts/{account}', [AdminAccountController::class, 'destroy'])->middleware('api.permission:accounts.delete')->name('accounts.destroy');
            Route::post('accounts/{account}/suspend', [AdminAccountController::class, 'suspend'])->middleware(['api.permission:accounts.suspend', 'api.idempotent'])->name('accounts.suspend');
            Route::post('accounts/{account}/unsuspend', [AdminAccountController::class, 'unsuspend'])->middleware(['api.permission:accounts.unsuspend', 'api.idempotent'])->name('accounts.unsuspend');
            Route::put('accounts/{account}/reseller', [AdminAccountController::class, 'assignReseller'])->middleware('api.permission:accounts.update')->name('accounts.reseller.update');
            Route::delete('accounts/{account}/reseller', [AdminAccountController::class, 'unassignReseller'])->middleware('api.permission:accounts.update')->name('accounts.reseller.destroy');
            Route::post('packages', [PackageController::class, 'store'])->middleware(['api.permission:packages.create', 'api.idempotent'])->name('packages.store');
            Route::patch('packages/{package}', [PackageController::class, 'update'])->middleware('api.permission:packages.update')->name('packages.update');
            Route::put('packages/{package}/limits/{resourceType}', [PackageController::class, 'updateLimit'])->middleware('api.permission:packages.update')->name('packages.limits.update');
            Route::delete('packages/{package}', [PackageController::class, 'destroy'])->middleware('api.permission:packages.delete')->name('packages.destroy');
            Route::post('nodes/{node}/suspend', [NodeController::class, 'suspend'])->middleware(['api.permission:nodes.suspend', 'api.idempotent'])->name('nodes.suspend');
            Route::post('nodes/{node}/unsuspend', [NodeController::class, 'unsuspend'])->middleware(['api.permission:nodes.unsuspend', 'api.idempotent'])->name('nodes.unsuspend');
            Route::post('node-backups', [NodeBackupController::class, 'store'])->middleware(['api.permission:backups.create', 'api.idempotent'])->name('node-backups.store');
            Route::delete('node-backups/{backup}', [NodeBackupController::class, 'destroy'])->middleware('api.permission:backups.delete')->name('node-backups.destroy');
        });
    });
});
