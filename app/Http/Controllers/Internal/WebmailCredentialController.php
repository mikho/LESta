<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A live, synchronous, node-initiated HTTPS read of a mailbox's own login credentials, called by
 * .install/services/webmail/vendor/lesta_autologin/lesta_autologin.php from inside the node's
 * own Roundcube pool. Same shape and same reasoning as AdminerCredentialController: nothing is
 * mutated here (the token was already marked used by App\Http\Middleware\
 * AuthenticateWebmailToken), and it is never routed through ProvisioningOperation.
 *
 * The username is the full address (local_part@domain): Dovecot's passdb is passwd-file keyed
 * by the full address. The IMAP host is deliberately absent: Roundcube takes it from its own
 * config (imap_host), so a token can only ever log into that node's own Dovecot.
 */
class WebmailCredentialController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var MailAccount $mailAccount */
        $mailAccount = $request->attributes->get('mailAccount');

        if ($mailAccount->isSuspended() || $mailAccount->mailDomain->isSuspended()) {
            abort(404);
        }

        return response()->json([
            'username' => "{$mailAccount->local_part}@{$mailAccount->mailDomain->domain}",
            'password' => $mailAccount->password,
        ]);
    }
}
