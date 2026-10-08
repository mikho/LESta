<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\MailingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mailing list of a mail domain: mail sent to <local_part>@<domain> is delivered to every
 * member. The owner receives the list's bounces and mail sent to <local_part>-owner@<domain>.
 * Rendered into the domain's mail.smtp-imap.v1 payload; the node refuses a name that clashes with
 * a mailbox or another list.
 *
 * @property int $id
 * @property string $uuid
 * @property int $mail_domain_id
 * @property string $local_part
 * @property string $owner_email
 * @property string $post_policy members, anyone or owner
 * @property string|null $subject_prefix
 * @property bool $reply_to_list
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['mail_domain_id', 'local_part', 'owner_email', 'post_policy', 'subject_prefix', 'reply_to_list'])]
class MailingList extends Model
{
    /** @use HasFactory<MailingListFactory> */
    use HasFactory, HasUuid;

    public const int MAX_PER_DOMAIN = 100;

    public const int MAX_MEMBERS = 500;

    public const array POST_POLICIES = ['members', 'anyone', 'owner'];

    /** The same patterns the node agent enforces. */
    public const string LOCAL_PART_PATTERN = '/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$/';

    public const string EMAIL_PATTERN = '/^[a-z0-9][a-z0-9._%+-]{0,63}@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/';

    public const string SUBJECT_PREFIX_PATTERN = '/^[A-Za-z0-9 ._\\[\\]-]{0,40}$/';

    protected function casts(): array
    {
        return ['reply_to_list' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<MailDomain, $this>
     */
    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    /**
     * @return HasMany<MailingListMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(MailingListMember::class);
    }
}
