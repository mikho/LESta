<?php

namespace App\Http\Middleware;

use App\Models\WebmailAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the one-time {token} route parameter against webmail_access_tokens.token_hash
 * and stores the resolved MailAccount on the request's attribute bag for
 * WebmailCredentialController to read. Mirrors AuthenticateAdminerToken exactly: 404 (never 401)
 * on any failure, since the token rides a public-shaped URL
 * (https://{mail_hostname}/?_lesta_token=...), and the token is marked used by a single
 * conditional UPDATE whose affected-row count decides the one winner of a concurrent redemption.
 *
 * @param  Closure(Request): (Response)  $next
 */
class AuthenticateWebmailToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        if ($token === '') {
            abort(404);
        }

        $tokenHash = hash('sha256', $token);

        $record = WebmailAccessToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            abort(404);
        }

        $affected = DB::table('webmail_access_tokens')
            ->where('id', $record->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);

        if ($affected === 0) {
            // Lost the race to a concurrent redemption of the exact same token.
            abort(404);
        }

        $mailAccount = $record->mailAccount()->with('mailDomain')->first();

        if ($mailAccount === null) {
            abort(404);
        }

        $request->attributes->set('mailAccount', $mailAccount);

        return $next($request);
    }
}
