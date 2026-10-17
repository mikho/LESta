<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CronJob;
use App\Models\CronJobExecution;
use Illuminate\Http\Request;

/**
 * @mixin CronJob
 */
class CronJobResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'account_id' => $this->whenLoaded('account', fn (): string => $this->account->public_id),
            'minute' => $this->minute,
            'hour' => $this->hour,
            'day_of_month' => $this->day_of_month,
            'month' => $this->month,
            'day_of_week' => $this->day_of_week,
            'command' => $this->command,
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'provisioning' => $this->provisioning($this->latestProvisioningOperation),
            'executions' => $this->whenLoaded('executions', fn (): array => $this->executions->map(fn (CronJobExecution $execution): array => [
                'started_at' => $this->timestamp($execution->started_at),
                'finished_at' => $this->timestamp($execution->finished_at),
                'exit_code' => $execution->exit_code,
                'output' => $execution->output,
            ])->all()),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
