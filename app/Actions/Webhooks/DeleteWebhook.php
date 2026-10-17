<?php

namespace App\Actions\Webhooks;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeleteWebhook
{
    public function handle(User $actor, Webhook $webhook): void
    {
        Gate::forUser($actor)->authorize('manage', $webhook);

        DB::transaction(function () use ($actor, $webhook): void {
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webhook->getMorphClass(),
                'auditable_id' => $webhook->getKey(),
                'action' => 'webhook.deleted',
                'correlation_id' => (string) Str::uuid(),
                'metadata' => ['url' => $webhook->url],
            ]);

            $webhook->delete();
        });
    }
}
