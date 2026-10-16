<?php

namespace App\Concerns;

use App\Models\MailAccount;
use App\Models\TenantDatabase;
use App\Models\WebDomain;

/**
 * A readable type and label for a usage row's polymorphic snapshotable (a MailAccount,
 * TenantDatabase or WebDomain), since the raw morph type and id mean nothing to a user. Shared by
 * UsageSnapshot and UsageSnapshotRollup, and shown the same in the panel and the API.
 */
trait DescribesSnapshotable
{
    /**
     * @return 'mail_account'|'tenant_database'|'web_domain'|'unknown'
     */
    public function resourceType(): string
    {
        return match ($this->snapshotable_type) {
            (new MailAccount)->getMorphClass() => 'mail_account',
            (new TenantDatabase)->getMorphClass() => 'tenant_database',
            (new WebDomain)->getMorphClass() => 'web_domain',
            default => 'unknown',
        };
    }

    public function resourceLabel(): string
    {
        $resource = $this->snapshotable;

        if ($resource === null) {
            return '(deleted resource)';
        }

        return match (true) {
            $resource instanceof MailAccount => $resource->local_part.'@'.$resource->mailDomain?->domain,
            $resource instanceof TenantDatabase => $resource->label ?? $resource->database_name,
            $resource instanceof WebDomain => $resource->domain,
            default => '(unknown resource)',
        };
    }
}
