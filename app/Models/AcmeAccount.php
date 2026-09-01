<?php

namespace App\Models;

use Database\Factories\AcmeAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A system-wide (never per-tenant) ACME account: one row per ACME directory
 * URL (staging and production, if both are ever used, each get their own
 * lazily-registered account). Registered lazily by
 * App\Actions\Acme\EnsuresAcmeAccountExists the first time any issuance job
 * needs one.
 *
 * account_key is the account's own key pair PEM material, encrypted at rest.
 * It signs every outbound HTTPS request Laravel makes directly to the ACME
 * Certificate Authority and is never included in a ProvisioningOperation
 * payload or sent to any node -- a domain's own issued certificate/private
 * key is a different thing entirely, and does travel to nodes normally.
 *
 * @property int $id
 * @property string|null $contact_email
 * @property string $directory_url
 * @property string $account_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contact_email', 'directory_url', 'account_key'])]
class AcmeAccount extends Model
{
    /** @use HasFactory<AcmeAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_key' => 'encrypted',
        ];
    }
}
