<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\NodeServiceActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * One start, stop or restart of a service on a node (or one opening or closing of its ssh port), asked for in the panel and carried out by the
 * node's root applier (node.service-control.v1). Only the customer-facing services are on the list;
 * the node's own LESta units, ssh and the control plane's database never are.
 *
 * @property int $id
 * @property string $uuid
 * @property int $node_id
 * @property int|null $user_id
 * @property string $unit
 * @property string $action start, stop, restart, open or close (ssh port), or uninstall (unit is then an installer service)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'user_id', 'unit', 'action'])]
class NodeServiceAction extends Model
{
    /** @use HasFactory<NodeServiceActionFactory> */
    use HasFactory, HasUuid;

    public const string CAPABILITY = 'node.service-control.v1';

    /** Removing a whole LESta service (the node's maintenance applier); unit then holds the service. */
    public const string UNINSTALL_CAPABILITY = 'node.service-uninstall.v1';

    /**
     * Opening or closing the node's ssh port (node.ssh-access.v1). The action then is open or close
     * and the unit is SSH_UNIT: the ssh service itself is never started or stopped from here.
     */
    public const string SSH_CAPABILITY = 'node.ssh-access.v1';

    public const string SSH_UNIT = 'ssh';

    public const array ACTIONS = ['start', 'stop', 'restart'];

    /**
     * The units the panel may control. The node keeps the same list (servicecontrol.DefaultAllowedUnits)
     * and refuses anything else on its own.
     */
    public const array UNITS = [
        'nginx', 'apache2', 'named',
        'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm',
        'mariadb@tenant', 'cron', 'exim4', 'dovecot',
    ];

    public static function isControllable(string $unit): bool
    {
        return in_array($unit, self::UNITS, true);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }
}
