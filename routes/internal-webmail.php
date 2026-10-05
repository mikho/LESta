<?php

use App\Http\Controllers\Internal\WebmailCredentialController;
use App\Http\Middleware\AuthenticateWebmailToken;
use Illuminate\Support\Facades\Route;

// Token-only-authenticated, registered exactly like routes/internal-adminer.php (bootstrap/app.php's
// own api: array, the stateless 'api' middleware group, no session or CSRF). Called directly by a
// node's own Roundcube pool (.install/services/webmail/vendor/lesta_autologin/lesta_autologin.php),
// never a browser session of this application's own.
Route::middleware(['throttle:webmail-credentials', AuthenticateWebmailToken::class])
    ->get('internal/webmail-credentials/{token}', [WebmailCredentialController::class, 'show']);
