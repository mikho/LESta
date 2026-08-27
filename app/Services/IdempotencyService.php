<?php

namespace App\Services;

use App\Enums\IdempotencyReceiptStatus;
use App\Exceptions\ConcurrentIdempotentRequestException;
use App\Models\IdempotencyReceipt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IdempotencyService
{
    /**
     * Run $callback at most once for a given scope/key pair, replaying the cached result
     * (persisted as the receipt's `response`, which is JSON-array-shaped) on any later call
     * with the same key. A concurrent call for a still-processing key throws instead of
     * running $callback a second time.
     *
     * @param  Closure(): mixed  $callback
     */
    public function handle(string $scope, string $key, Closure $callback): mixed
    {
        return DB::transaction(function () use ($scope, $key, $callback): mixed {
            $receipt = IdempotencyReceipt::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($receipt !== null) {
                if ($receipt->status === IdempotencyReceiptStatus::Processing) {
                    throw new ConcurrentIdempotentRequestException($scope, $key);
                }

                return $receipt->response;
            }

            $receipt = IdempotencyReceipt::create([
                'scope' => $scope,
                'idempotency_key' => $key,
                'status' => IdempotencyReceiptStatus::Processing,
                'correlation_id' => (string) Str::uuid(),
            ]);

            try {
                $result = $callback();
            } catch (Throwable $e) {
                $receipt->forceFill(['status' => IdempotencyReceiptStatus::Failed])->save();
                throw $e;
            }

            $receipt->forceFill([
                'status' => IdempotencyReceiptStatus::Completed,
                'response' => $result,
            ])->save();

            return $result;
        });
    }
}
