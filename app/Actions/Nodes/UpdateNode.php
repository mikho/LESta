<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateNode
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name?: string, hostname?: string, mail_hostname?: string|null}
     */
    public function handle(User $actor, Node $node, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $node);

        DB::transaction(function () use ($actor, $node, $data): void {
            $node->fill([
                'name' => $data['name'] ?? $node->name,
                'hostname' => $data['hostname'] ?? $node->hostname,
                'mail_hostname' => array_key_exists('mail_hostname', $data) ? $data['mail_hostname'] : $node->mail_hostname,
            ])->save();

            AuditEvent::record($node, 'node.updated', $actor);
        });
    }
}
