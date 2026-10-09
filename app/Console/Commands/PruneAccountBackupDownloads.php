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

        // A file no row refers to (left by a crash, say) is removed once it is a few hours old.
        $referenced = AccountBackupDownload::query()->whereNotNull('path')->pluck('path')->all();
        $disk = Storage::disk('local');

        foreach ($disk->files('account-backup-downloads') as $file) {
            if (! in_array($file, $referenced, true) && $disk->lastModified($file) < now()->subHours(3)->getTimestamp()) {
                $disk->delete($file);
                $removed++;
            }
        }

        $this->info("Removed {$removed} expired download(s) and stray file(s).");

        return self::SUCCESS;
    }
}
