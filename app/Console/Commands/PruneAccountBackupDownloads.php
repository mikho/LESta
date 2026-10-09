<?php

namespace App\Console\Commands;

use App\Models\AccountBackupDownload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the decrypted copies prepared for download once they have expired, together with their
 * rows, and any download that never finished uploading.
 */
class PruneAccountBackupDownloads extends Command
{
    protected $signature = 'account-backups:prune-downloads';

    protected $description = 'Delete expired account backup downloads and their files.';

    public function handle(): int
    {
        $removed = 0;

        AccountBackupDownload::query()->where('expires_at', '<=', now())->each(function (AccountBackupDownload $download) use (&$removed): void {
            if ($download->path !== null) {
                Storage::disk('local')->delete($download->path);
            }

            $download->delete();
            $removed++;
        });

        $this->info("Removed {$removed} expired download(s).");

        return self::SUCCESS;
    }
}
