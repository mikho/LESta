<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the real, decrypted plaintext archive PreparesBackupDownload wrote to local storage
 * once its own download_expires_at window has passed, whether or not the admin ever actually
 * downloaded it. This is the backstop for that window: BackupController::download() already
 * deletes the file itself (deleteFileAfterSend) on a real download, and clears these same
 * columns before streaming, so this command only ever finds the ones nobody downloaded in time.
 */
class PruneExpiredBackupDownloads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups:prune-expired-downloads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete decrypted backup download copies past their expiry, and clear their download fields.';

    public function handle(): int
    {
        $expired = Backup::query()
            ->whereNotNull('download_path')
            ->where('download_expires_at', '<', now())
            ->get();

        foreach ($expired as $backup) {
            if ($backup->download_path !== null) {
                Storage::disk('local')->delete($backup->download_path);
            }

            $backup->forceFill([
                'download_path' => null,
                'download_ready_at' => null,
                'download_expires_at' => null,
            ])->save();
        }

        $this->info("Pruned {$expired->count()} expired backup download(s).");

        return self::SUCCESS;
    }
}
