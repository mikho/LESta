<?php

namespace App\Actions\Accounts;

use App\Actions\CronJobs\SuspendCronJob;
use App\Actions\Dns\SuspendDnsZone;
use App\Actions\Domains\SuspendWebDomain;
use App\Actions\Mail\SuspendMailDomain;
use App\Actions\TenantDatabases\SuspendTenantDatabase;
use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendAccount
{
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('suspend', $account);

        if ($account->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $account): void {
            $account->suspend(SuspensionSource::Manual);

            $account->webDomains()->whereNull('suspended_at')->get()
                ->each(fn (WebDomain $d) => app(SuspendWebDomain::class)->handle($actor, $d, SuspensionSource::Cascade));

            $account->dnsZones()->whereNull('suspended_at')->get()
                ->each(fn (DnsZone $z) => app(SuspendDnsZone::class)->handle($actor, $z, SuspensionSource::Cascade));

            $account->mailDomains()->whereNull('suspended_at')->get()
                ->each(fn (MailDomain $d) => app(SuspendMailDomain::class)->handle($actor, $d, SuspensionSource::Cascade));

            // Confirmed directly: without this, a "suspended" account's own tenant database and
            // cron jobs stayed fully active and usable -- a real security/business-logic gap,
            // not just a missing audit trail.
            $account->tenantDatabases()->whereNull('suspended_at')->get()
                ->each(fn (TenantDatabase $d) => app(SuspendTenantDatabase::class)->handle($actor, $d, SuspensionSource::Cascade));

            $account->cronJobs()->whereNull('suspended_at')->get()
                ->each(fn (CronJob $j) => app(SuspendCronJob::class)->handle($actor, $j, SuspensionSource::Cascade));

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.suspended',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
