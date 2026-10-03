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
 * Creates a new file (optionally with content) or a new directory in a WebDomain's own
 * per-domain docroot, dispatched as a real files.manager.v1 Create operation -- the real second
 * front door onto the exact same files the account's own SFTP session already reaches, never a
 * second write mechanism with its own permission model (Web Application Hosting Threat Model and
 * Isolation Design.md).
 */
class CreateFile
{
    public function handle(User $actor, WebDomain $webDomain, string $path, bool $isDirectory, ?string $contentBase64): ProvisioningOperation
    {
        Gate::forUser($actor)->authorize('update', $webDomain);

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'auditable_type' => $webDomain->getMorphClass(),
            'auditable_id' => $webDomain->getKey(),
            'action' => $isDirectory ? 'web_domain.file_directory_created' : 'web_domain.file_created',
            'correlation_id' => $correlationId,
        ]);

        return app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'files.manager.v1',
            ProvisioningVerb::Create,
            $webDomain->toFilesManagerProvisioningPayload([
                'path' => $path,
                'is_directory' => $isDirectory,
                'content_base64' => $contentBase64 ?? '',
            ]),
            $correlationId,
            $webDomain->desired_state_version,
        );
    }
}
