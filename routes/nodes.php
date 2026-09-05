<?php

use App\Http\Controllers\Nodes\NodeCapabilityController;
use App\Http\Controllers\Nodes\NodeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('nodes', [NodeController::class, 'index'])->name('nodes.index');
    Route::get('nodes/create', [NodeController::class, 'create'])->name('nodes.create');
    Route::post('nodes', [NodeController::class, 'store'])->name('nodes.store');
    Route::get('nodes/{node}/edit', [NodeController::class, 'edit'])->name('nodes.edit');
    Route::put('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
    Route::delete('nodes/{node}', [NodeController::class, 'destroy'])->name('nodes.destroy');
    Route::post('nodes/{node}/suspend', [NodeController::class, 'suspend'])->name('nodes.suspend');
    Route::post('nodes/{node}/unsuspend', [NodeController::class, 'unsuspend'])->name('nodes.unsuspend');
    Route::post('nodes/{node}/enrollment-token', [NodeController::class, 'issueEnrollmentToken'])->name('nodes.enrollment-token');

    Route::post('nodes/{node}/capabilities', [NodeCapabilityController::class, 'store'])->name('nodes.capabilities.store');
    Route::post('nodes/{node}/capabilities/{nodeCapability}/suspend', [NodeCapabilityController::class, 'suspend'])->name('nodes.capabilities.suspend');
    Route::post('nodes/{node}/capabilities/{nodeCapability}/unsuspend', [NodeCapabilityController::class, 'unsuspend'])->name('nodes.capabilities.unsuspend');
});
