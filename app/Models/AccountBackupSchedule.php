<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AccountBackupScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's automatic backup schedule: off, daily or weekly, with the parts to back up. Runs at
 * night, at an hour between 01:00 and 04:00 UTC derived from the account so accounts do not all
 * start at once. Scheduled backups are kept separately from manual ones (see AccountBackup).
 *
 * @property int $id
 * @property int $account_id
 * @property string $frequency off, daily or weekly
 * @property list<string> $parts
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property string|null $last_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'frequency', 'parts', 'next_run_at', 'last_run_at', 'last_message'])]
class AccountBackupSchedule extends Model
{
    /** @use HasFactory<AccountBackupScheduleFactory> */
    use HasFactory;

    public const array FREQUENCIES = ['off', 'daily', 'weekly'];

    protected function casts(): array
    {
        return ['parts' => 'array', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The next run after $from: the account's night slot of the next day (daily) or seven days on
     * (weekly), never earlier than the next slot after $from.
     */
    public function nextRunAfter(CarbonInterface $from): CarbonInterface
    {
        $slot = $from->toImmutable()->utc()->startOfDay()->addHours(1 + ($this->account_id % 4));

        if ($slot->lessThanOrEqualTo($from)) {
            $slot = $slot->addDay();
        }

        return $this->frequency === 'weekly' ? $slot->addDays(6) : $slot;
    }
}
