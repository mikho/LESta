<?php

namespace App\Console\Commands;

use App\Actions\AccountBackups\CreateAccountBackup;
use App\Actions\AccountBackups\ResolvesAccountBackupScope;
use App\Models\AccountBackupSchedule;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts the backups accounts have scheduled (daily or weekly, at an account-specific night hour):
 * for every schedule that is due, one backup per node where the account has data, skipping a node
 * that is busy or cannot back up, and recording what happened on the schedule so the account's
 * owner can see it. Run hourly by the scheduler; a schedule's next run is set whatever the result,
 * so a node that is down for a night is retried at the next slot, not every hour.
 */
class RunScheduledAccountBackups extends Command
{
    protected $signature = 'account-backups:run-scheduled';

    protected $description = 'Start the automatic backups that accounts have scheduled.';

    public function handle(CreateAccountBackup $create, ResolvesAccountBackupScope $resolver): int
    {
        $started = 0;

        AccountBackupSchedule::query()
            ->where('frequency', '!=', 'off')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with('account')
            ->orderBy('id')
            ->each(function (AccountBackupSchedule $schedule) use ($create, $resolver, &$started): void {
                $notes = [];

                foreach ($resolver->nodesWithData($schedule->account) as $node) {
                    $available = $resolver->availableParts($resolver->for($schedule->account, $node));
                    $parts = array_values(array_intersect($schedule->parts, $available));

                    if ($parts === []) {
                        continue;
                    }

                    try {
                        $create->handleScheduled($schedule->account, $node, $parts);
                        $started++;
                        $notes[] = __('Started on :node.', ['node' => $node->name]);
                    } catch (ValidationException $e) {
                        $notes[] = __('Skipped on :node: :reason', ['node' => $node->name, 'reason' => collect($e->errors())->flatten()->first()]);
                    } catch (Throwable $e) {
                        $notes[] = __('Skipped on :node: backups are not available.', ['node' => $node->name]);
                    }
                }

                $schedule->forceFill([
                    'last_run_at' => now(),
                    'last_message' => $notes === [] ? __('Nothing to back up.') : implode(' ', $notes),
                    'next_run_at' => $schedule->nextRunAfter(now()),
                ])->save();
            });

        $this->info("Started {$started} scheduled account backup(s).");

        return self::SUCCESS;
    }
}
