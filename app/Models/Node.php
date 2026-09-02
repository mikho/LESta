<?php

namespace App\Models;

use App\Concerns\Suspendable;
use App\Contracts\ProviderAdminManaged;
use App\Enums\SuspensionSource;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $hostname
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['uuid', 'name', 'hostname'])]
class Node extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
        ];
    }

    /**
     * @return HasMany<NodeCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(NodeCapability::class);
    }

    /**
     * @return HasMany<WebDomain, $this>
     */
    public function webDomains(): HasMany
    {
        return $this->hasMany(WebDomain::class);
    }

    /**
     * @return HasMany<DnsZone, $this>
     */
    public function dnsZones(): HasMany
    {
        return $this->hasMany(DnsZone::class);
    }

    /**
     * @return HasMany<TenantDatabase, $this>
     */
    public function tenantDatabases(): HasMany
    {
        return $this->hasMany(TenantDatabase::class);
    }
}
