<?php

namespace App\Actions\CronJobs;

use App\Actions\Cron\EnsuresAccountNodeIdentity;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesCronCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\PackageLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateCronJob
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{minute?: string, hour?: string, day_of_month?: string, month?: string, day_of_week?: string, command: string}
     */
    public function handle(User $actor, Account $account, array $data): CronJob
    {
        Gate::forUser($actor)->authorize('create', [CronJob::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): CronJob {
            $limit = PackageLimit::query()
                ->where('package_id', $account->package_id)
                ->where('resource_type', 'cron_jobs')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('cron_jobs');
            }

            if ($limit->limit_value !== null && $account->cronJobs()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('cron_jobs', $limit->limit_value);
            }

            [$node, $capability] = app(ResolvesCronCapableNode::class)->resolve();

            // Lazily ensure this account's own dedicated, per-node Linux system user exists
            // before this cron job's own provisioning operation is recorded below. The two
            // dispatch independently (accepted eventual consistency, per this phase's own
            // explicit scope boundary: no cross-operation dependency blocking is built), but
            // are always issued in this order, so the identity operation's own dispatched_at is
            // always at or before this cron job's own.
            app(EnsuresAccountNodeIdentity::class)->handle($account, $node);

            $cronJob = CronJob::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'minute' => $data['minute'] ?? '*',
                'hour' => $data['hour'] ?? '*',
                'day_of_month' => $data['day_of_month'] ?? '*',
                'month' => $data['month'] ?? '*',
                'day_of_week' => $data['day_of_week'] ?? '*',
                'command' => $data['command'],
                'desired_state_version' => 1,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $cronJob->getMorphClass(),
                'auditable_id' => $cronJob->getKey(),
                'action' => 'cron_job.created',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Create,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                1,
            );

            return $cronJob;
        });
    }
}
