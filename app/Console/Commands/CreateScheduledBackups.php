<?php

namespace App\Console\Commands;

use App\Actions\Backups\CreateBackup;
use App\Models\Node;
use Illuminate\Console\Command;

/**
 * Dispatches a new backup for every node an admin has explicitly opted into scheduled backups
 * for (Node::backups_scheduled, toggled via UpdateNodeScheduledBackups), mirroring exactly what
 * clicking "New backup" against that node would do. Deliberately opt-in per node, never every
 * backup-capable node by default: a scheduled backup consumes real disk on that node, which an
 * admin should choose, not inherit silently. A node whose own backup.encrypted-artifacts.v1
 * capability has since been suspended or removed is skipped, matching CreateBackup's own
 * ResolvesBackupCapableNode check (a stale backups_scheduled flag on such a node produces no
 * op, not a failure needing separate handling here).
 */
class CreateScheduledBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups:create-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a new backup for every node with scheduled backups enabled.';

    public function handle(CreateBackup $createBackup): int
    {
        $nodes = Node::query()
            ->where('backups_scheduled', true)
            ->whereNull('suspended_at')
            ->whereHas('capabilities', fn ($query) => $query->where('capability', 'backup.encrypted-artifacts.v1')->whereNull('suspended_at'))
            ->get();

        foreach ($nodes as $node) {
            $createBackup->handleSystemInitiated($node, ['label' => 'scheduled-'.now()->toDateString()]);
        }

        $this->info("Dispatched {$nodes->count()} scheduled backup(s).");

        return self::SUCCESS;
    }
}
