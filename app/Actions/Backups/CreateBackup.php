<?php

namespace App\Actions\Backups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateBackup
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{label?: string|null}
     */
    public function handle(User $actor, Node $node, array $data): Backup
    {
        Gate::forUser($actor)->authorize('create', Backup::class);

        return DB::transaction(function () use ($actor, $node, $data): Backup {
            $capability = app(ResolvesBackupCapableNode::class)->resolveFor($node);

            $encryptionKey = bin2hex(random_bytes(32));

            $backup = Backup::query()->create([
                'node_id' => $node->id,
                'label' => $data['label'] ?? null,
                'encryption_key' => $encryptionKey,
                'desired_state_version' => 1,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $backup->getMorphClass(),
                'auditable_id' => $backup->getKey(),
                'action' => 'backup.created',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                $capability,
                ProvisioningVerb::Create,
                $backup->toProvisioningPayload($encryptionKey),
                $correlationId,
                1,
            );

            return $backup;
        });
    }
}
