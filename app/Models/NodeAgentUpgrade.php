<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\NodeAgentUpgradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * One request, signed by an administrator, to replace a node's agent binary (node.agent-upgrade.v1).
 * The node fetches the binary with a one-time token (only its hash is kept here) and applies it only
 * if the signature verifies against the key it trusts.
 *
 * @property int $id
 * @property string $uuid
 * @property int $node_id
 * @property int|null $user_id
 * @property string|null $from_version
 * @property string $to_version
 * @property string $sha256
 * @property int|null $next_key_id the key the node switches to after the upgrade, when rotating
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'user_id', 'from_version', 'to_version', 'sha256', 'next_key_id', 'token_hash', 'expires_at'])]
class NodeAgentUpgrade extends Model
{
    /** @use HasFactory<NodeAgentUpgradeFactory> */
    use HasFactory, HasUuid;

    public const string CAPABILITY = 'node.agent-upgrade.v1';

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'downloaded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AgentUpgradeKey, $this>
     */
    public function nextKey(): BelongsTo
    {
        return $this->belongsTo(AgentUpgradeKey::class, 'next_key_id');
    }

    /**
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }
}
