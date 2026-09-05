<?php

use App\Http\Controllers\Agent\AgentCronExecutionController;
use App\Http\Controllers\Agent\AgentEnrollmentController;
use App\Http\Controllers\Agent\AgentHeartbeatController;
use App\Http\Middleware\AuthenticateNodeCredential;
use Illuminate\Support\Facades\Route;

Route::prefix('agent/v1')->middleware('throttle:agent-enroll')->group(function () {
    Route::post('enroll', [AgentEnrollmentController::class, 'store']);
});

Route::prefix('agent/v1')->middleware([AuthenticateNodeCredential::class, 'throttle:agent'])->group(function () {
    Route::post('heartbeat', [AgentHeartbeatController::class, 'store']);
    Route::post('cron-executions', [AgentCronExecutionController::class, 'store']);
});
