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
use InvalidArgumentException;

/**
 * Overwrites an existing file's own content, or renames/moves it -- exactly one of
 * $contentBase64 or $newPath is expected, enforced here before this ever reaches the agent.
 */
class UpdateFile
{
    public function handle(User $actor, WebDomain $webDomain, string $path, ?string $newPath, ?string $contentBase64): ProvisioningOperation
    {
        if (($newPath === null) === ($contentBase64 === null)) {
            throw new InvalidArgumentException('UpdateFile::handle() requires exactly one of $newPath or $contentBase64.');
        }

        Gate::forUser($actor)->authorize('update', $webDomain);

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'auditable_type' => $webDomain->getMorphClass(),
            'auditable_id' => $webDomain->getKey(),
            'action' => $newPath !== null ? 'web_domain.file_renamed' : 'web_domain.file_updated',
            'correlation_id' => $correlationId,
        ]);

        return app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'files.manager.v1',
            ProvisioningVerb::Update,
            $webDomain->toFilesManagerProvisioningPayload([
                'path' => $path,
                'new_path' => $newPath ?? '',
                'content_base64' => $contentBase64 ?? '',
            ]),
            $correlationId,
            $webDomain->desired_state_version,
        );
    }
}
