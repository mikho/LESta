<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Contracts\ProviderAdminManaged;
use Database\Factories\NodeAdminGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Delegates full admin of exactly one node to a user who is not a platform-wide provider_admin:
 * infrastructure-only for this pass (see App\Policies\NodePolicy/NodeCapabilityPolicy), never a
 * reach into any tenant's own resources hosted on that node (a WebDomain/MailDomain/etc. stays
 * owned purely by its account, with no node dimension in its own policy at all). One grant means
 * full admin of that one node, the whole node -- there is no narrower per-capability grant; only
 * a real platform admin (nodes.update) can create or revoke a grant, so a delegated admin can
 * never create a further delegated admin of their own.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $node_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'node_id'])]
class NodeAdminGrant extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<NodeAdminGrantFactory> */
    use HasFactory, HasUuid;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
