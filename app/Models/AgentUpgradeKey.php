<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\AgentUpgradeKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A public Ed25519 key nodes trust for agent upgrades. The private half is made in an
 * administrator's browser and kept by that administrator; the panel never sees it. A key may be
 * trusted by one node or by many.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $public_key base64 of the 32 raw bytes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'public_key', 'created_by'])]
class AgentUpgradeKey extends Model
{
    /** @use HasFactory<AgentUpgradeKeyFactory> */
    use HasFactory, HasUuid;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Whether $value is the base64 of a 32 byte Ed25519 public key.
     */
    public static function isValidPublicKey(string $value): bool
    {
        $raw = base64_decode($value, true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && base64_encode($raw) === $value;
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class, 'upgrade_key_id');
    }
}
