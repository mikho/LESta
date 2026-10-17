<?php

namespace App\Actions\Webhooks;

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Sends a "ping" to one webhook, so its owner can check the receiver and its signature check. It
 * carries no customer data. Also switches a webhook that had switched itself off back on.
 */
class SendWebhookTest
{
    public function __construct(private QueuesWebhookEvent $queue) {}

    public function handle(User $actor, Webhook $webhook): void
    {
        Gate::forUser($actor)->authorize('manage', $webhook);

        $webhook->forceFill(['disabled_at' => null, 'consecutive_failures' => 0])->save();

        $this->queue->handle('ping', 'ping:'.Str::uuid(), $webhook->account_id, ['webhook' => ['id' => $webhook->uuid, 'url' => $webhook->url]], $webhook);
    }
}
