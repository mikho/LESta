<?php

namespace App\Models;

use Database\Factories\PackageLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $package_id
 * @property string $resource_type
 * @property int|null $limit_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['package_id', 'resource_type', 'limit_value'])]
class PackageLimit extends Model
{
    /**
     * The only resource types a real Create action anywhere in this app actually enforces a
     * quota against (confirmed by grepping every Actions/**\/Create*.php for its own
     * ->where('resource_type', ...) quota check) -- deliberately excludes 'memberships', which
     * App\Actions\Packages\UpdatePackageQuota has always had a special case for but which no real
     * membership-creation path has ever actually checked, so offering a quota field for it here
     * would imply an enforcement that does not exist.
     *
     * @var list<string>
     */
    public const array RESOURCE_TYPES = [
        'web_domains',
        'dns_zones',
        'dns_records',
        'mail_domains',
        'mail_accounts',
        'tenant_databases',
        'cron_jobs',
    ];

    /** @use HasFactory<PackageLimitFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
