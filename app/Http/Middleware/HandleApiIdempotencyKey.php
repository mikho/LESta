<?php

namespace App\Http\Middleware;

use App\Enums\IdempotencyReceiptStatus;
use App\Models\ApiToken;
use App\Models\IdempotencyReceipt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An API write sent with an Idempotency-Key header runs once per token and key. The first
 * successful response is kept for 24 hours and replayed to a retry with the same request; the
 * same key with another request is refused (409), as is a retry while the first is still running.
 * A response that is not a success is not kept, so the client may fix the request and retry with
 * the same key. Requests without the header are not affected.
 *
 * A route whose answer holds a secret (a new mailbox's password) names those fields as middleware
 * parameters ("api.idempotent:data.password"): they are blanked in the kept copy, so a replay
 * never repeats the secret and no secret is stored.
 */
class HandleApiIdempotencyKey
{
    private const int KEEP_HOURS = 24;

    /** A receipt still "processing" after this long belongs to a request that died. */
    private const int STALE_MINUTES = 10;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$secretFields): Response
    {
        $key = $request->header('Idempotency-Key');
        $token = $request->attributes->get('api_token');

        if ($key === null || ! $token instanceof ApiToken) {
            return $next($request);
        }

        if (! preg_match('/^[\x21-\x7E]{1,255}$/', $key)) {
            abort(400, 'The Idempotency-Key header must be 1 to 255 printable characters.');
        }

        $scope = 'api-token:'.$token->id;
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $receipt = $this->claim($scope, $key, $hash);

        if ($receipt !== null) {
            return $this->answerExisting($receipt, $hash);
        }

        $response = $next($request);

        $claimed = IdempotencyReceipt::query()->where('scope', $scope)->where('idempotency_key', $key)->first();

        if ($response->isSuccessful() && $claimed !== null) {
            $body = json_decode((string) $response->getContent(), true);

            foreach ($secretFields as $field) {
                if (is_array($body) && Arr::has($body, $field)) {
                    Arr::set($body, $field, null);
                }
            }

            $claimed->forceFill([
                'status' => IdempotencyReceiptStatus::Completed,
                'response' => [
                    'status' => $response->getStatusCode(),
                    'body' => $body,
                    'location' => $response->headers->get('Location'),
                ],
            ])->save();
        } else {
            $claimed?->delete();
        }

        return $response;
    }

    /**
     * Records the key as being processed and returns null, or returns the receipt that already
     * holds it.
     */
    private function claim(string $scope, string $key, string $hash): ?IdempotencyReceipt
    {
        $existing = IdempotencyReceipt::query()->where('scope', $scope)->where('idempotency_key', $key)->first();

        if ($existing !== null && ($existing->expires_at?->isPast() || ($existing->status === IdempotencyReceiptStatus::Processing && $existing->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES))))) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            return $existing;
        }

        try {
            IdempotencyReceipt::query()->create([
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'status' => IdempotencyReceiptStatus::Processing,
                'correlation_id' => (string) Str::uuid(),
                'expires_at' => now()->addHours(self::KEEP_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return IdempotencyReceipt::query()->where('scope', $scope)->where('idempotency_key', $key)->firstOrFail();
        }

        return null;
    }

    private function answerExisting(IdempotencyReceipt $receipt, string $hash): Response
    {
        if ($receipt->request_hash !== $hash) {
            abort(409, 'This Idempotency-Key was already used with a different request.');
        }

        if ($receipt->status !== IdempotencyReceiptStatus::Completed || ! is_array($receipt->response)) {
            abort(409, 'A request with this Idempotency-Key is still being processed.');
        }

        $response = new JsonResponse($receipt->response['body'], $receipt->response['status']);
        $response->headers->set('Idempotent-Replayed', 'true');

        if (is_string($receipt->response['location'] ?? null)) {
            $response->headers->set('Location', $receipt->response['location']);
        }

        return $response;
    }
}
