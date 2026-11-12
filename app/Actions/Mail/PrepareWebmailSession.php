<?php

namespace App\Actions\Mail;

use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\User;
use App\Models\WebmailAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints a single-use, 60-second token and returns the redirect URL a browser follows straight
 * onto the mail domain's own node's address (its own hostname, the node tools vhost), where Roundcube's lesta_autologin plugin
 * (.install/services/webmail/vendor/lesta_autologin/lesta_autologin.php) redeems it via the
 * internal, token-only-authenticated /internal/webmail-credentials/{token} endpoint. Mirrors
 * App\Actions\TenantDatabases\PrepareAdminerSession: this action never talks to a node directly,
 * it only prepares the hand-off.
 */
class PrepareWebmailSession
{
    /**
     * @return array{url: string, field: string, token: string} where to POST the token, the form
     *                                                          field to put it in, and the token: it is never placed in a URL
     */
    public function handle(User $actor, MailAccount $mailAccount): array
    {
        Gate::forUser($actor)->authorize('update', $mailAccount);

        $mailDomain = $mailAccount->mailDomain;

        if ($mailAccount->isSuspended() || $mailDomain->isSuspended()) {
            throw ValidationException::withMessages([
                'mail_account' => 'A suspended mailbox cannot be opened in webmail.',
            ]);
        }

        $node = $mailDomain->node;

        if (! $node->hasWebmailAvailable()) {
            throw ValidationException::withMessages([
                'mail_account' => 'Webmail is not available on this mail domain\'s node: its own hostname needs a web domain with an issued certificate, and mail.webmail.v1 must be installed.',
            ]);
        }

        return DB::transaction(function () use ($actor, $mailAccount, $node): array {
            $raw = Str::random(64);

            WebmailAccessToken::create([
                'token_hash' => hash('sha256', $raw),
                'mail_account_id' => $mailAccount->id,
                'expires_at' => now()->addSeconds(60),
            ]);

            AuditEvent::record($mailAccount, 'mail_account.webmail_session_prepared', $actor);

            return ['url' => "https://{$node->hostname}/", 'field' => '_lesta_token', 'token' => $raw];
        });
    }
}
