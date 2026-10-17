<?php

namespace App\Actions\Webhooks;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateWebhook
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{url: string, events: list<string>, description?: string|null}
     * @return array{0: Webhook, 1: string} the webhook and its signing secret, shown once
     */
    public function handle(User $actor, ?Account $account, array $data): array
    {
        Gate::forUser($actor)->authorize('create', [Webhook::class, $account]);

        $secret = Webhook::generateSecret();

        $webhook = DB::transaction(function () use ($actor, $account, $data, $secret): Webhook {
            $webhook = Webhook::query()->create([
                'account_id' => $account?->id,
                'created_by' => $actor->id,
                'url' => $data['url'],
                'secret' => $secret,
                'events' => array_values(array_unique($data['events'])),
                'description' => $data['description'] ?? null,
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webhook->getMorphClass(),
                'auditable_id' => $webhook->getKey(),
                'action' => 'webhook.created',
                'correlation_id' => (string) Str::uuid(),
                'metadata' => ['url' => $webhook->url, 'events' => $webhook->events],
            ]);

            return $webhook;
        });

        return [$webhook, $secret];
    }
}
