<?php

namespace App\Actions\Accounts;

use App\Actions\CronJobs\UnsuspendCronJob;
use App\Actions\Dns\UnsuspendDnsZone;
use App\Actions\Domains\UnsuspendWebDomain;
use App\Actions\Mail\UnsuspendMailDomain;
use App\Actions\TenantDatabases\UnsuspendTenantDatabase;
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

class UnsuspendAccount
{
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $account);

        if (! $account->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $account): void {
            $account->unsuspend();

            $account->webDomains()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (WebDomain $d) => app(UnsuspendWebDomain::class)->handle($actor, $d));

            $account->dnsZones()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (DnsZone $z) => app(UnsuspendDnsZone::class)->handle($actor, $z));

            $account->mailDomains()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (MailDomain $d) => app(UnsuspendMailDomain::class)->handle($actor, $d));

            $account->tenantDatabases()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (TenantDatabase $d) => app(UnsuspendTenantDatabase::class)->handle($actor, $d));

            $account->cronJobs()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (CronJob $j) => app(UnsuspendCronJob::class)->handle($actor, $j));

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.unsuspended',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
