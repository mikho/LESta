<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cron_job_id
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property int $exit_code
 * @property string $output
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cron_job_id', 'started_at', 'finished_at', 'exit_code', 'output'])]
class CronJobExecution extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CronJob, $this>
     */
    public function cronJob(): BelongsTo
    {
        return $this->belongsTo(CronJob::class);
    }
}
