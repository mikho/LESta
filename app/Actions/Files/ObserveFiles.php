<?php

namespace App\Actions\Files;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\ProvisioningOperation;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Lists a directory or reads a file's own content, whichever path actually resolves to on the
 * real node -- files.manager.v1's own Observe verb reports whichever shape back on
 * ResultEnvelope.Data. A read, never a mutation: no AuditEvent, matching how every other
 * capability's own Observe dispatch (e.g. metrics.usage.v1) never writes one either.
 */
class ObserveFiles
{
    public function handle(User $actor, WebDomain $webDomain, string $path): ProvisioningOperation
    {
        Gate::forUser($actor)->authorize('view', $webDomain);

        return app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'files.manager.v1',
            ProvisioningVerb::Observe,
            $webDomain->toFilesManagerProvisioningPayload(['path' => $path]),
            (string) Str::uuid(),
            $webDomain->desired_state_version,
        );
    }
}
