<?php

namespace App\Models;

use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property int $role_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'account_id', 'role_id'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /**
     * A user is either a platform user (a membership with no account: the provider admin and any
     * custom platform role) or an account member, never both: a platform admin must not hold a
     * hosting account or package of their own. Actions turn this into a validation error before
     * they get here; this is the backstop for every other path (seeders, CLI, tinker).
     */
    protected static function booted(): void
    {
        static::creating(function (Membership $membership): void {
            $conflicts = static::query()
                ->where('user_id', $membership->user_id)
                ->when(
                    $membership->account_id === null,
                    fn ($query) => $query->whereNotNull('account_id'),
                    fn ($query) => $query->whereNull('account_id'),
                )
                ->exists();

            if ($conflicts) {
                throw new LogicException('A user cannot be both a platform administrator and a member of a hosting account.');
            }

            if ($membership->account_id !== null && Account::query()->whereKey($membership->account_id)->where('is_platform', true)->exists()) {
                throw new LogicException('The platform account has no members.');
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
