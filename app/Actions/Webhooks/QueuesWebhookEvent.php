<?php

namespace App\Actions\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Queues one event for every webhook that wants it: the account's own webhooks and the
 * platform-wide ones (account null: only platform-wide). $key identifies the event, so the same
 * event is queued once per webhook however often it is reported. Each delivery is sent by
 * DeliverWebhook; webhooks:deliver-due retries the ones still due.
 */
class QueuesWebhookEvent
{
    /** How long a delivery is left to its job before the scheduled sweep tries it again. */
    public const int CLAIM_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(string $event, string $key, ?int $accountId, array $data, ?Webhook $only = null): void
    {
        $webhooks = $only !== null ? collect([$only]) : Webhook::query()
            ->whereNull('disabled_at')
            ->where(fn ($query) => $query->whereNull('account_id')->when($accountId !== null, fn ($query) => $query->orWhere('account_id', $accountId)))
            ->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event, $webhook->events, true));

        $accountPublicId = $accountId === null ? null : Account::query()->whereKey($accountId)->value('public_id');

        foreach ($webhooks as $webhook) {
            $uuid = (string) Str::uuid();

            try {
                $delivery = WebhookDelivery::query()->create([
                    'uuid' => $uuid,
                    'webhook_id' => $webhook->id,
                    'event' => $event,
                    'event_key' => $key,
                    'payload' => [
                        'id' => $uuid,
                        'event' => $event,
                        'created_at' => now()->toIso8601ZuluString(),
                        'account_id' => $accountPublicId,
                        'data' => $data,
                    ],
                    'status' => 'pending',
                    'next_attempt_at' => now()->addMinutes(self::CLAIM_MINUTES),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }
    }
}
