<?php

use App\Http\Controllers\Internal\AdminerCredentialController;
use App\Http\Middleware\AuthenticateAdminerToken;
use Illuminate\Support\Facades\Route;

// Token-only-authenticated: no 'auth'/'web' session middleware at all (registered via
// bootstrap/app.php's own api: array, the same stateless 'api' middleware group routes/agent.php
// uses -- no EncryptCookies/StartSession/CSRF). Called directly by a node's own Adminer pool
// (.install/services/adminer/vendor/adminer-lesta-login.php), never a browser session of this
// application's own.
Route::middleware(['throttle:adminer-credentials', AuthenticateAdminerToken::class])
    ->get('internal/adminer-credentials/{token}', [AdminerCredentialController::class, 'show']);
