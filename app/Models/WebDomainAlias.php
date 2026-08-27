<?php

namespace App\Models;

use Database\Factories\WebDomainAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $web_domain_id
 * @property string $alias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['web_domain_id', 'alias'])]
class WebDomainAlias extends Model
{
    /** @use HasFactory<WebDomainAliasFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WebDomain, $this>
     */
    public function webDomain(): BelongsTo
    {
        return $this->belongsTo(WebDomain::class);
    }
}
