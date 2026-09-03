<?php

namespace App\Actions\CronJobs;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesCronCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateCronJob
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{minute?: string, hour?: string, day_of_month?: string, month?: string, day_of_week?: string, command?: string}
     */
    public function handle(User $actor, CronJob $cronJob, array $data): CronJob
    {
        Gate::forUser($actor)->authorize('update', $cronJob);

        return DB::transaction(function () use ($actor, $cronJob, $data): CronJob {
            $cronJob->forceFill([
                'minute' => $data['minute'] ?? $cronJob->minute,
                'hour' => $data['hour'] ?? $cronJob->hour,
                'day_of_month' => $data['day_of_month'] ?? $cronJob->day_of_month,
                'month' => $data['month'] ?? $cronJob->month,
                'day_of_week' => $data['day_of_week'] ?? $cronJob->day_of_week,
                'command' => $data['command'] ?? $cronJob->command,
                'desired_state_version' => $cronJob->desired_state_version + 1,
            ])->save();

            $capability = app(ResolvesCronCapableNode::class)->resolveFor($cronJob->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $cronJob->getMorphClass(),
                'auditable_id' => $cronJob->getKey(),
                'action' => 'cron_job.updated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Update,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                $cronJob->desired_state_version,
            );

            return $cronJob;
        });
    }
}
