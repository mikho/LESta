<?php

namespace App\Actions\Webhooks;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteWebhook
{
    public function handle(User $actor, Webhook $webhook): void
    {
        Gate::forUser($actor)->authorize('manage', $webhook);

        DB::transaction(function () use ($actor, $webhook): void {
            AuditEvent::record($webhook, 'webhook.deleted', $actor, null, ['url' => $webhook->url]);

            $webhook->delete();
        });
    }
}
