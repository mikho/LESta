<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateAccount
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name?: string, contact_email?: string|null, package_id?: int}
     *
     * @throws AuthorizationException when someone without accounts.update tries to change the package
     */
    public function handle(User $actor, Account $account, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $account);

        // An owner may rename the account and change its contact address, but the package is the
        // provider's to set: it is what the account may use, so an owner who could pick it could
        // lift every quota.
        if (isset($data['package_id']) && (int) $data['package_id'] !== $account->package_id && ! $actor->hasPermission('accounts.update')) {
            throw new AuthorizationException(__('Only your provider can change the package.'));
        }

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
