<?php

namespace App\Models;

use App\Contracts\ProviderAdminManaged;
use App\Enums\IpAllocationStatus;
use Database\Factories\IpAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $node_id
 * @property string $ip_address
 * @property IpAllocationStatus $status
 * @property int|null $account_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'ip_address', 'status', 'account_id'])]
class IpAllocation extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<IpAllocationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IpAllocationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
