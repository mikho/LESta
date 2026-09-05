<?php

namespace App\Actions\Cron;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsuresAccountNodeIdentity
{
    private const CAPABILITY = 'system.account-identity.v1';

    /**
     * Return $account's own AccountNodeIdentity on $node, creating it (and dispatching its own
     * system.account-identity.v1 create ProvisioningOperation) the first time this pair is ever
     * seen. Called from CreateCronJob before it records the cron job's own provisioning
     * operation: the two dispatch independently (eventual consistency by design, per this
     * phase's own explicit scope boundary; see this method's own doc comment on why no blocking
     * dependency between the two operations is built), but are always issued in this order, so
     * the identity operation's own dispatched_at is always at or before the cron job's own.
     */
    public function handle(Account $account, Node $node): AccountNodeIdentity
    {
        return DB::transaction(function () use ($account, $node): AccountNodeIdentity {
            $existing = $this->find($account, $node);

            if ($existing !== null) {
                return $existing;
            }

            try {
                $identity = AccountNodeIdentity::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'account_id' => $account->id,
                    'node_id' => $node->id,
                    'system_username' => $this->deterministicUsername($account),
                    'desired_state_version' => 1,
                ]);
            } catch (QueryException $e) {
                // Lost a race against a concurrent create for the same (account_id, node_id)
                // pair (the unique index on that pair is the real guard; this transaction's own
                // read-then-create above is only a fast path). The winning row is already there.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                return $this->find($account, $node) ?? throw $e;
            }

            app(RecordsProvisioningOperation::class)->record(
                $identity,
                self::CAPABILITY,
                ProvisioningVerb::Create,
                $identity->toProvisioningPayload(),
                (string) Str::uuid(),
                1,
            );

            return $identity;
        });
    }

    private function find(Account $account, Node $node): ?AccountNodeIdentity
    {
        return AccountNodeIdentity::query()
            ->where('account_id', $account->id)
            ->where('node_id', $node->id)
            ->first();
    }

    /**
     * Derive this account's own deterministic per-node system username: "lesta-t{id}", the
     * account's numeric primary key ONLY, never $account->name or any other tenant-editable
     * field. A tenant-editable value would let an account rename its way into colliding with
     * (or spoofing the shape of) another account's own system username, or into a username this
     * capability's own charset validation rejects outright at apply time; the immutable, always-
     * unique auto-increment id has neither hazard.
     */
    private function deterministicUsername(Account $account): string
    {
        return 'lesta-t'.$account->id;
    }
}
