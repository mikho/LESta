<?php

namespace App\Policies;

class CronJobPolicy extends AccountOwnedResourcePolicy
{
    protected function viewAnyPermission(): string
    {
        return 'cron_jobs.view_any';
    }
}
