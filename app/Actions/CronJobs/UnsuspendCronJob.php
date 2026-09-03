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

class UnsuspendCronJob
{
    public function handle(User $actor, CronJob $cronJob): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $cronJob);

        if (! $cronJob->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $cronJob): void {
            $cronJob->unsuspend();
            $cronJob->forceFill(['desired_state_version' => $cronJob->desired_state_version + 1])->save();

            $capability = app(ResolvesCronCapableNode::class)->resolveFor($cronJob->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $cronJob->getMorphClass(),
                'auditable_id' => $cronJob->getKey(),
                'action' => 'cron_job.unsuspended',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Unsuspend,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                $cronJob->desired_state_version,
            );
        });
    }
}
