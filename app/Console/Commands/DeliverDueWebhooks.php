<?php

namespace App\Console\Commands;

use App\Actions\Webhooks\QueuesWebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Sends the webhook deliveries that are due: retries whose wait is over, and first tries whose
 * job was lost. Each is claimed for a while first, so a slow job and the next run do not both
 * send it.
 */
class DeliverDueWebhooks extends Command
{
    protected $signature = 'webhooks:deliver-due';

    protected $description = 'Send the webhook deliveries whose next attempt is due.';

    public function handle(): int
    {
        $due = WebhookDelivery::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(200)
            ->pluck('id');

        foreach ($due as $id) {
            $claimed = WebhookDelivery::query()->whereKey($id)->where('next_attempt_at', '<=', now())
                ->update(['next_attempt_at' => now()->addMinutes(QueuesWebhookEvent::CLAIM_MINUTES)]);

            if ($claimed === 1) {
                DeliverWebhook::dispatch($id);
            }
        }

        $this->info("Sent {$due->count()} due webhook deliveries to the queue.");

        return self::SUCCESS;
    }
}
