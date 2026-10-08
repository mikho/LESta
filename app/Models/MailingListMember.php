<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\MailingListMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One address subscribed to a mailing list, stored lower-case.
 *
 * @property int $id
 * @property string $uuid
 * @property int $mailing_list_id
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['mailing_list_id', 'email'])]
class MailingListMember extends Model
{
    /** @use HasFactory<MailingListMemberFactory> */
    use HasFactory, HasUuid;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<MailingList, $this>
     */
    public function mailingList(): BelongsTo
    {
        return $this->belongsTo(MailingList::class);
    }
}
