<?php

namespace App\Models;

use App\Contracts\ProviderAdminManaged;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class Permission extends Model implements ProviderAdminManaged
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    /**
     * The full permission catalog: one entry per real, admin-gateable ability
     * on the resources that have moved off a blanket role-name check (see
     * AuthorizationServiceProvider's own PERMISSION_BACKED_MODELS and
     * Account/Membership/Package/Node/BackupPolicy). The single source of
     * truth for both PermissionSeeder (the real catalog) and
     * MembershipFactory::providerAdmin() (so a factory-built provider admin
     * in tests keeps exactly today's blanket-everything behavior without
     * duplicating this list a second time and risking drift).
     *
     * Deliberately excludes Account's own `view` ability: that stays
     * member-scoped forever, never permission-gated, per the Foundations
     * decision log's explicit "read-only support view, distinct from and
     * logged separately" design -- `accounts.view_as_support` is the
     * permission-gated ability instead, never `view` itself.
     *
     * backups.* is deliberately never tenant-facing at all (no `Account`
     * scoping, no owner/member OR-clause the way MailDomain's suspend/
     * unsuspend/delete gained in Phase 30): a real backup artifact captures
     * an entire node's own state today, commingling every account hosted on
     * it, since no capability yet tags its own on-disk state by account
     * (disclosed, deferred -- see the Backups design decision this catalog
     * entry belongs to). Making backups admin-only rather than pretending
     * per-account isolation exists is the actual safety property this
     * scoping protects.
     *
     * @var list<string>
     */
    public const array CATALOG = [
        'accounts.view_as_support', 'accounts.update', 'accounts.suspend', 'accounts.unsuspend', 'accounts.delete',
        'memberships.view', 'memberships.create', 'memberships.update', 'memberships.delete', 'memberships.impersonate',
        'packages.view_any', 'packages.view', 'packages.create', 'packages.update', 'packages.delete',
        'nodes.view_any', 'nodes.view', 'nodes.create', 'nodes.update', 'nodes.delete', 'nodes.suspend', 'nodes.unsuspend',
        'backups.view_any', 'backups.view', 'backups.create', 'backups.delete', 'backups.download',
        'usage.view_any',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
