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
 * Reads one of a web domain's own logs over files.manager.v1's fast lane: a bounded tail of the
 * access or error log, a traffic summary built from the recent access log, or the log's last
 * megabytes for download. The node builds the log path from the domain's own resource id and a
 * fixed kind, never from anything the caller sends, and the caller must be allowed to view the
 * domain, so a tenant can only ever read their own domain's logs. A read, never a mutation.
 */
class ObserveWebLog
{
    public const array KINDS = ['access', 'error'];

    public const array MODES = ['tail', 'summary', 'download'];

    public function handle(User $actor, WebDomain $webDomain, string $kind, string $mode, int $lines = 100): ProvisioningOperation
    {
        Gate::forUser($actor)->authorize('view', $webDomain);

        return app(RecordsProvisioningOperation::class)->record(
            $webDomain,
            'files.manager.v1',
            ProvisioningVerb::Observe,
            $webDomain->toFilesManagerProvisioningPayload(['log_kind' => $kind, 'log_mode' => $mode, 'log_lines' => $lines]),
            (string) Str::uuid(),
            $webDomain->desired_state_version,
        );
    }
}
