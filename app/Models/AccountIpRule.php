<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\AccountIpRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of an account's IP access list: an address or CIDR range that is allowed or denied
 * on every one of the account's nginx-served domains. Allow entries are rendered before deny
 * entries and nginx takes the first match, so an allow carves an exception out of a broader
 * deny; an address matching neither is allowed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property string $action
 * @property string $cidr
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'action', 'cidr', 'note'])]
class AccountIpRule extends Model
{
    /** @use HasFactory<AccountIpRuleFactory> */
    use HasFactory, HasUuid;

    public const int MAX_PER_ACCOUNT = 200;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The canonical form of an IP address or CIDR range, or null when it is neither. IPv6 is
     * lower-cased and compressed, a range's address is masked down to its network, so
     * "203.0.113.9/24" and "203.0.113.0/24" are the same rule.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if (str_contains($value, '/')) {
            [$address, $prefix] = explode('/', $value, 2);
            $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

            if ($packed === false || ! ctype_digit($prefix)) {
                return null;
            }

            $bits = strlen($packed) * 8;

            if ((int) $prefix > $bits) {
                return null;
            }

            $masked = '';

            for ($i = 0; $i < strlen($packed); $i++) {
                $keep = max(0, min(8, (int) $prefix - $i * 8));
                $masked .= chr(ord($packed[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
            }

            return inet_ntop($masked).'/'.(int) $prefix;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return inet_ntop((string) inet_pton($value)) ?: null;
    }
}
