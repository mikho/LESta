<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\WebDomainRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A URL redirect on one web domain: an exact path, or with $prefix everything under it (the rest
 * of the path is appended to the target). Rendered by nginx before any other location.
 *
 * @property int $id
 * @property string $uuid
 * @property int $web_domain_id
 * @property string $source
 * @property string $target
 * @property int $status
 * @property bool $prefix
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['web_domain_id', 'source', 'target', 'status', 'prefix'])]
class WebDomainRedirect extends Model
{
    /** @use HasFactory<WebDomainRedirectFactory> */
    use HasFactory, HasUuid;

    public const int MAX_PER_DOMAIN = 100;

    /** Same patterns the node agent enforces; a value the agent would reject is refused here first. */
    public const string SOURCE_PATTERN = '/^\/[A-Za-z0-9._~%+\/-]{0,198}$/';

    public const string TARGET_PATTERN = '/^(?:https?:\/\/[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?)?(?:\/[A-Za-z0-9._~%+=&?#:@!*,\/-]{0,498})?$/';

    protected function casts(): array
    {
        return ['prefix' => 'boolean', 'status' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<WebDomain, $this>
     */
    public function webDomain(): BelongsTo
    {
        return $this->belongsTo(WebDomain::class);
    }
}
