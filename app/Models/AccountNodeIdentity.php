<?php

namespace App\Models;

use App\Contracts\ProviderAdminManaged;
use Database\Factories\AccountNodeIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant account's own dedicated, per-node Linux system user
 * (system.account-identity.v1), created lazily the first time that account
 * gets a cron job on a node (see App\Actions\Cron\EnsuresAccountNodeIdentity).
 * Only a provider admin may ever manage this resource directly (see
 * AccountNodeIdentityPolicy): a tenant account never sees or controls its
 * own OS-level identity, it is purely provisioning infrastructure.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $system_username
 * @property int $desired_state_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['uuid', 'account_id', 'node_id', 'system_username', 'desired_state_version'])]
class AccountNodeIdentity extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<AccountNodeIdentityFactory> */
    use HasFactory;

    /**
     * Route model binding resolves by uuid, not the internal auto-increment id, matching every
     * other admin-managed resource's own route key convention (Node, DnsZone, CronJob).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * Scope to identities on $node whose (account_id, node_id) pair has zero remaining CronJob
     * rows: the only real consumer of a per-account identity today. An orphaned identity's own
     * job is done, but it is never auto-deleted (see App\Actions\Cron\DeleteOrphanedAccountNodeIdentity's
     * own doc comment for why); this scope only ever powers the manual admin list/delete flow.
     *
     * @param  Builder<AccountNodeIdentity>  $query
     * @return Builder<AccountNodeIdentity>
     */
    public function scopeOrphanedOn(Builder $query, Node $node): Builder
    {
        return $query
            ->where('node_id', $node->id)
            ->whereNotIn('account_id', function ($subQuery) use ($node): void {
                $subQuery->select('account_id')
                    ->from('cron_jobs')
                    ->where('node_id', $node->id);
            });
    }

    /**
     * Whether this identity is currently orphaned: its own (account_id, node_id) pair has no
     * remaining CronJob rows. Re-checked live, never cached, since DeleteOrphanedAccountNodeIdentity
     * must never trust a client-supplied assumption about this.
     */
    public function isOrphaned(): bool
    {
        return ! CronJob::query()
            ->where('account_id', $this->account_id)
            ->where('node_id', $this->node_id)
            ->exists();
    }

    /**
     * Shape the desired-state payload sent to a provisioner, matching
     * system.account-identity.v1's own Go payload shape exactly.
     *
     * @return array{username: string}
     */
    public function toProvisioningPayload(): array
    {
        return [
            'username' => $this->system_username,
        ];
    }
}
