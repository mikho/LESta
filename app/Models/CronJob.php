<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\CronJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $minute
 * @property string $hour
 * @property string $day_of_month
 * @property string $month
 * @property string $day_of_week
 * @property string $command
 * @property int $desired_state_version
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'minute', 'hour', 'day_of_month', 'month', 'day_of_week', 'command', 'desired_state_version'])]
class CronJob extends Model
{
    /** @use HasFactory<CronJobFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
        ];
    }

    /**
     * Route model binding resolves by uuid, not the internal auto-increment id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * The single most recent provisioning operation for this cron job.
     * `MorphMany::latestOfMany()` does not exist in this Laravel version
     * (only `HasOne`/`MorphOne`/`HasOneThrough` support the "of many"
     * relation subquery); `morphOne()->latestOfMany()` is the idiomatic
     * equivalent.
     *
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Shape the desired-state payload sent to a provisioner. The raw command text is included
     * here (it never appears in the crontab fragment file itself, only in this payload and the
     * node-local JSON sidecar the agent's cron-run wrapper reads at execution time; see
     * agent/internal/capability/cron's own package doc comment for the full security design).
     *
     * @return array{minute: string, hour: string, day_of_month: string, month: string, day_of_week: string, command: string, suspended: bool}
     */
    public function toProvisioningPayload(): array
    {
        return [
            'minute' => $this->minute,
            'hour' => $this->hour,
            'day_of_month' => $this->day_of_month,
            'month' => $this->month,
            'day_of_week' => $this->day_of_week,
            'command' => $this->command,
            'suspended' => $this->isSuspended(),
        ];
    }
}
