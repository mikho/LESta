<?php

namespace App\Actions\Accounts;

use App\Actions\Domains\SuspendWebDomain;
use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\AuditEvent;
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
