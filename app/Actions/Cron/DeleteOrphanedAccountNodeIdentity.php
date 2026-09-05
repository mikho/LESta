<?php

namespace App\Actions\Cron;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\AccountNodeIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The manual admin cleanup half of this phase's own explicit scope boundary: an
 * AccountNodeIdentity is never auto-deleted when an account's last cron job on a node is
 * removed (see App\Models\AccountNodeIdentity::isOrphaned's own doc comment), so a genuinely
 * orphaned identity accumulates until a provider admin explicitly reviews and deletes it here.
 */
class DeleteOrphanedAccountNodeIdentity
{
    private const CAPABILITY = 'system.account-identity.v1';

    public function handle(User $actor, AccountNodeIdentity $identity): void
    {
        Gate::forUser($actor)->authorize('delete', $identity);

        // Re-checked live, server-side: never trust a client-supplied assumption that this
        // identity is orphaned, matching App\Actions\Nodes\DeleteNode's own dependent-resource
        // guard pattern.
        if (! $identity->isOrphaned()) {
            throw ValidationException::withMessages([
                'identity' => 'This identity still has cron jobs on this node and cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($identity): void {
            $correlationId = (string) Str::uuid();

            app(RecordsProvisioningOperation::class)->record(
                $identity,
                self::CAPABILITY,
                ProvisioningVerb::Delete,
                $identity->toProvisioningPayload(),
                $correlationId,
                $identity->desired_state_version,
            );

            // This row's own job is done once the delete operation is enqueued, matching
            // App\Actions\Dns\DeleteDnsZone's own established pattern.
            $identity->delete();
        });
    }
}
