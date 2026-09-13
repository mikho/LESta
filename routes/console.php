<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('provisioning:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('acme:renew-certificates')->daily()->withoutOverlapping();
Schedule::command('metrics:collect')->daily()->withoutOverlapping();
Schedule::command('metrics:prune')->daily()->withoutOverlapping();
Schedule::command('backups:prune-expired-downloads')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('backups:create-scheduled')->daily()->withoutOverlapping();
