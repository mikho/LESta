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
 * One start, stop or restart of a service on a node, asked for in the panel and carried out by the
 * node's root applier (node.service-control.v1). Only the customer-facing services are on the list;
 * the node's own LESta units, ssh and the control plane's database never are.
 *
 * @property int $id
 * @property string $uuid
 * @property int $node_id
 * @property int|null $user_id
 * @property string $unit
 * @property string $action start, stop or restart
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'user_id', 'unit', 'action'])]
class NodeServiceAction extends Model
{
    /** @use HasFactory<NodeServiceActionFactory> */
    use HasFactory, HasUuid;

    public const string CAPABILITY = 'node.service-control.v1';

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
