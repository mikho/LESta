<?php

use App\Http\Controllers\Dns\DnsRecordController;
use App\Http\Controllers\Dns\DnsZoneController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dns', [DnsZoneController::class, 'index'])->name('dns.index');
    Route::get('dns/create', [DnsZoneController::class, 'create'])->name('dns.create');
    Route::post('dns', [DnsZoneController::class, 'store'])->name('dns.store');
    Route::get('dns/{dnsZone}/edit', [DnsZoneController::class, 'edit'])->name('dns.edit');
    Route::put('dns/{dnsZone}', [DnsZoneController::class, 'update'])->name('dns.update');
    Route::delete('dns/{dnsZone}', [DnsZoneController::class, 'destroy'])->name('dns.destroy');
    Route::post('dns/{dnsZone}/suspend', [DnsZoneController::class, 'suspend'])->name('dns.suspend');
    Route::post('dns/{dnsZone}/unsuspend', [DnsZoneController::class, 'unsuspend'])->name('dns.unsuspend');

    Route::post('dns/{dnsZone}/records', [DnsRecordController::class, 'store'])->name('dns.records.store');
    Route::put('dns/{dnsZone}/records/{dnsRecord}', [DnsRecordController::class, 'update'])->name('dns.records.update');
    Route::delete('dns/{dnsZone}/records/{dnsRecord}', [DnsRecordController::class, 'destroy'])->name('dns.records.destroy');
    Route::post('dns/{dnsZone}/records/{dnsRecord}/suspend', [DnsRecordController::class, 'suspend'])->name('dns.records.suspend');
    Route::post('dns/{dnsZone}/records/{dnsRecord}/unsuspend', [DnsRecordController::class, 'unsuspend'])->name('dns.records.unsuspend');
});
