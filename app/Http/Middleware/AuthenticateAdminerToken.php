<?php

namespace App\Http\Middleware;

use App\Models\AdminerAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the one-time {token} route parameter against
 * adminer_access_tokens.token_hash and stores the resolved TenantDatabase on the request's
 * attribute bag for AdminerCredentialController to read -- the same "resolve a credential, attach
 * the model, let the controller read it" shape AuthenticateNodeCredential already uses.
 *
 * Deliberately returns 404, not 401, on any failure (unknown token, expired, already used):
 * unlike AuthenticateNodeCredential's bearer token (a real credential presented by a trusted,
 * already-enrolled node), this token rides a public-shaped URL
 * (https://{domain}/__lesta-adminer__?token=...) a scanner could hit blind. A 401 would confirm
 * "a token-shaped value was expected here"; 404 reveals nothing about why the request failed.
 *
 * Marks the token used atomically inside the same request via a single conditional UPDATE
 * (token_hash match AND used_at IS NULL), checking the affected-row count: two near-simultaneous
 * redemptions of the same token can only ever have one winner, closing the double-redemption
 * race without a separate lockForUpdate() transaction.
 *
 * @param  Closure(Request): (Response)  $next
 */
class AuthenticateAdminerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        if ($token === '') {
            abort(404);
        }

        $tokenHash = hash('sha256', $token);

        $record = AdminerAccessToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            abort(404);
        }

        $affected = DB::table('adminer_access_tokens')
            ->where('id', $record->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);

        if ($affected === 0) {
            // Lost the race to a concurrent redemption of the exact same token.
            abort(404);
        }

        $tenantDatabase = $record->tenantDatabase()->first();

        if ($tenantDatabase === null) {
            abort(404);
        }

        $request->attributes->set('tenantDatabase', $tenantDatabase);

        return $next($request);
    }
}
