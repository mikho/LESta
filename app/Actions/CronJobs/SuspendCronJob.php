<?php

namespace App\Actions\CronJobs;

use App\Actions\Accounts\ResolvesSuspensionAuthority;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesCronCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendCronJob
{
    public function handle(User $actor, CronJob $cronJob, ?SuspensionSource $source = null): void
    {
        Gate::forUser($actor)->authorize('suspend', $cronJob);

        $source ??= app(ResolvesSuspensionAuthority::class)->suspendingLevel($actor, $cronJob->account);

        if ($cronJob->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $cronJob, $source): void {
            $cronJob->suspend($source);
            $cronJob->forceFill(['desired_state_version' => $cronJob->desired_state_version + 1])->save();

            $capability = app(ResolvesCronCapableNode::class)->resolveFor($cronJob->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::record($cronJob, 'cron_job.suspended', $actor, $correlationId, ['source' => $source->value]);

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Suspend,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                $cronJob->desired_state_version,
            );
        });
    }
}
