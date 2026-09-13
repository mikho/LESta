<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

/**
 * Backup implements ProviderAdminManaged, but AuthorizationServiceProvider's
 * PERMISSION_BACKED_MODELS excludes it from the blanket Gate::before bypass:
 * every ability below is a real Permission::CATALOG check instead, mirroring
 * PackagePolicy/NodePolicy exactly. There is no owner/member OR-clause the
 * way MailDomain's suspend/unsuspend/delete gained in Phase 30: backups are
 * deliberately never tenant-facing at all (see the Backups design decision),
 * since a real backup artifact today commingles every account hosted on its
 * own node. There is no update/suspend/unsuspend ability at all, matching
 * the legacy system's own precedent: a backup is created or deleted, never
 * mutated in between.
 */
class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('backups.view_any');
    }

    public function view(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backups.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('backups.create');
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backups.delete');
    }

    /**
     * Gates preparing a decrypted, downloadable copy of a completed backup's own artifact (see
     * PrepareBackupDownload/PreparesBackupDownload) and streaming it once ready. A separate
     * permission from view: viewing a backup's own metadata (size, checksum, status) is a much
     * lower-stakes ability than actually recovering its real decrypted config-plane contents.
     */
    public function download(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backups.download');
    }
}
