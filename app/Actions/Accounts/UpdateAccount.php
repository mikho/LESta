<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateAccount
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name?: string, contact_email?: string|null, package_id?: int}
     */
    public function handle(User $actor, Account $account, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $account);

        DB::transaction(function () use ($actor, $account, $data): void {
            $account->fill([
                'name' => $data['name'] ?? $account->name,
                'contact_email' => array_key_exists('contact_email', $data) ? $data['contact_email'] : $account->contact_email,
                'package_id' => $data['package_id'] ?? $account->package_id,
            ])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.updated',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
