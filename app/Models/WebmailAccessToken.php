<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, short-lived (60 second) token minted by App\Actions\Mail\PrepareWebmailSession
 * and redeemed by App\Http\Middleware\AuthenticateWebmailToken over the internal
 * /internal/webmail-credentials/{token} endpoint. Looked up by hashed-token equality only, never
 * via route-model binding, for the same reason as AdminerAccessToken: a raw token must never
 * appear as a literal route parameter name a stack trace or log line could echo back.
 *
 * @property int $id
 * @property string $token_hash
 * @property int $mail_account_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token_hash', 'mail_account_id', 'expires_at', 'used_at'])]
class WebmailAccessToken extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MailAccount, $this>
     */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }
}
