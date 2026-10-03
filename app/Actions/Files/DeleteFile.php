<?php

namespace App\Actions\Files;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\ProvisioningOperation;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Removes a file, or a directory (recursively only when $recursive is explicitly true).
 */
class DeleteFile
{
    public function handle(User $actor, WebDomain $webDomain, string $path, bool $recursive): ProvisioningOperation
    {
        Gate::forUser($actor)->authorize('update', $webDomain);

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'auditable_type' => $webDomain->getMorphClass(),
            'auditable_id' => $webDomain->getKey(),
            'action' => 'web_domain.file_deleted',
            'correlation_id' => $correlationId,
        ]);

        return app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'files.manager.v1',
            ProvisioningVerb::Delete,
            $webDomain->toFilesManagerProvisioningPayload([
                'path' => $path,
                'recursive' => $recursive,
            ]),
            $correlationId,
            $webDomain->desired_state_version,
        );
    }
}
