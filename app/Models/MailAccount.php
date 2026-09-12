<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Concerns\Suspendable;
use App\Enums\SuspensionSource;
use Database\Factories\MailAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single mailbox within a MailDomain. Never its own provisioning resource: every mutation here
 * bumps the owning MailDomain's desired_state_version and re-dispatches an Update against the
 * domain (mirroring DnsRecord's own "standalone record suspension... recorded as an Update
 * against the zone" precedent), since a real Exim/Dovecot virtual-domain config renders one
 * artifact per domain, not per account.
 *
 * @property int $id
 * @property string $uuid
 * @property int $mail_domain_id
 * @property string $local_part
 * @property string $password
 * @property int|null $quota_mb
 * @property string|null $forward_to
 * @property bool $forward_only
 * @property bool $autoreply_enabled
 * @property string|null $autoreply_message
 * @property Carbon|null $suspended_at
 * @property SuspensionSource|null $suspension_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['mail_domain_id', 'local_part', 'password', 'quota_mb', 'forward_to', 'forward_only', 'autoreply_enabled', 'autoreply_message'])]
class MailAccount extends Model
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory, HasUuid, Suspendable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'forward_only' => 'boolean',
            'autoreply_enabled' => 'boolean',
            'suspended_at' => 'datetime',
            'suspension_source' => SuspensionSource::class,
        ];
    }

    /**
     * Route model binding resolves by uuid, not the internal auto-increment id.
     */
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
}
