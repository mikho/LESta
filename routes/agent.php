<?php

use App\Http\Controllers\Agent\AgentAccountBackupImportController;
use App\Http\Controllers\Agent\AgentAccountBackupUploadController;
use App\Http\Controllers\Agent\AgentCronExecutionController;
use App\Http\Controllers\Agent\AgentEnrollmentController;
use App\Http\Controllers\Agent\AgentFileOperationController;
use App\Http\Controllers\Agent\AgentHeartbeatController;
use App\Http\Controllers\Agent\AgentOperationResultController;
use App\Http\Middleware\AuthenticateNodeCredential;
use Illuminate\Support\Facades\Route;

Route::prefix('agent/v1')->middleware('throttle:agent-enroll')->group(function () {
    Route::post('enroll', [AgentEnrollmentController::class, 'store']);
});

Route::prefix('agent/v1')->middleware([AuthenticateNodeCredential::class, 'throttle:agent'])->group(function () {
    Route::post('heartbeat', [AgentHeartbeatController::class, 'store']);
    Route::post('cron-executions', [AgentCronExecutionController::class, 'store']);
    Route::post('operation-results', [AgentOperationResultController::class, 'store']);
});

// files.manager.v1's own dedicated fast lane (see agent/internal/daemon's own fileops.go doc
// comment for why): a separate, much tighter rate limit than the general 'agent' bucket, since
// this is polled every 1-2 seconds, not once a minute. Results still land on the exact same
// capability-agnostic agent/v1/operation-results endpoint above -- no separate results route or
// controller needed for this lane.
Route::prefix('agent/v1')->middleware([AuthenticateNodeCredential::class, 'throttle:agent-files'])->group(function () {
    Route::post('file-operations/poll', [AgentFileOperationController::class, 'poll']);
});

// A node streams a decrypted account backup here, in chunks, for the owner to download (see
// PrepareAccountBackupDownload). No node credential: the one-time token in the address authorizes it.
Route::put('agent/v1/account-backup-uploads/{token}', [AgentAccountBackupUploadController::class, 'store'])
    ->middleware('throttle:600,1')
    ->name('agent.account-backup-uploads');

// A node fetches an uploaded account backup here, to seal it as a backup of the account (see
// ImportAccountBackup). No node credential: the token in the address authorizes it.
Route::get('agent/v1/account-backup-imports/{token}', [AgentAccountBackupImportController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('agent.account-backup-imports');
