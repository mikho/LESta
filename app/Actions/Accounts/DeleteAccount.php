<?php

namespace App\Actions\Accounts;

use App\Actions\Dns\DeleteDnsZone;
use App\Actions\Domains\DeleteWebDomain;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeleteAccount
{
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('delete', $account);

        DB::transaction(function () use ($actor, $account): void {
            if ($account->isSuspended()) {
                $account->unsuspend();
            }

            $account->webDomains()->get()->each(fn (WebDomain $d) => app(DeleteWebDomain::class)->handle($actor, $d));

            $account->dnsZones()->get()->each(fn (DnsZone $z) => app(DeleteDnsZone::class)->handle($actor, $z));

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.deleted',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $account->delete();
        });
    }
}
