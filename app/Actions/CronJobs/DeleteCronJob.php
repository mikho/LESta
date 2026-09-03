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

class DeleteCronJob
{
    public function handle(User $actor, CronJob $cronJob): void
    {
        Gate::forUser($actor)->authorize('delete', $cronJob);

        DB::transaction(function () use ($actor, $cronJob): void {
            if ($cronJob->isSuspended()) {
                $cronJob->unsuspend();
            }

            $capability = app(ResolvesCronCapableNode::class)->resolveFor($cronJob->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $cronJob->getMorphClass(),
                'auditable_id' => $cronJob->getKey(),
                'action' => 'cron_job.deleted',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Delete,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                $cronJob->desired_state_version,
            );

            $cronJob->delete();
        });
    }
}
