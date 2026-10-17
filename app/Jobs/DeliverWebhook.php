<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends one webhook delivery: a JSON POST signed with the webhook's secret (the
 * Lesta-Signature header, "t=<unix time>,v1=<HMAC-SHA256 of '<time>.<body>'>"), to an address
 * checked again right now and pinned for the connection, with no redirects followed. A 2xx
 * answer delivers it; anything else is retried after WebhookDelivery::RETRY_AFTER_SECONDS, then
 * the delivery has failed, and a webhook that keeps failing switches itself off.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int TIMEOUT_SECONDS = 10;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookTarget $target): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $webhook = $delivery->webhook;

        if ($webhook->isDisabled()) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'The webhook is switched off.', 'next_attempt_at' => null])->save();

            return;
        }

        $delivery->attempts++;

        [$status, $error] = $this->send($delivery, $target);

        if ($error === null) {
            $delivery->forceFill(['status' => 'delivered', 'response_status' => $status, 'error' => null, 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            $webhook->forceFill(['consecutive_failures' => 0])->save();

            return;
        }

        $retryAfter = WebhookDelivery::RETRY_AFTER_SECONDS[$delivery->attempts - 1] ?? null;

        if ($retryAfter !== null) {
            $delivery->forceFill(['response_status' => $status, 'error' => $error, 'next_attempt_at' => now()->addSeconds($retryAfter)])->save();

            return;
        }

        $delivery->forceFill(['status' => 'failed', 'response_status' => $status, 'error' => $error, 'next_attempt_at' => null])->save();

        $webhook->consecutive_failures++;

        if ($webhook->consecutive_failures >= $webhook::DISABLE_AFTER_FAILURES) {
            $webhook->disabled_at = now();
        }

        $webhook->save();
    }

    /**
     * @return array{0: int|null, 1: string|null} the answer's status, and the error when it failed
     */
    private function send(WebhookDelivery $delivery, WebhookTarget $target): array
    {
        $url = $delivery->webhook->url;
        $problem = $target->problem($url);

        if ($problem !== null) {
            return [null, $problem];
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        $address = $target->addresses($host)[0];
        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'LESta-Webhooks/1',
                'Lesta-Event' => $delivery->event,
                'Lesta-Delivery' => $delivery->uuid,
                'Lesta-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $delivery->webhook->secret),
            ])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [trim($host, '[]').':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]],
                ])
                ->connectTimeout(5)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            return [null, Str::limit($e->getMessage(), 480)];
        }

        return $response->successful()
            ? [$response->status(), null]
            : [$response->status(), 'The receiver answered '.$response->status().'.'];
    }
}
