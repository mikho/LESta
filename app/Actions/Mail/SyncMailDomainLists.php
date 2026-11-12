<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Sends the mail domain's current payload, including its mailing lists, to its node after a list
 * or one of its members changed, with the same desired-state bump and audit event every other
 * mail change records.
 */
class SyncMailDomainLists
{
    public function handle(User $actor, MailDomain $mailDomain, Model $subject, string $auditAction): void
    {
        $mailDomain->bumpDesiredStateVersion();

        $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
        $correlationId = (string) Str::uuid();

        AuditEvent::record($subject, $auditAction, $actor, $correlationId);

        app(RecordsProvisioningOperation::class)->record(
            $mailDomain,
            $capability,
            ProvisioningVerb::Update,
            $mailDomain->toProvisioningPayload(),
            $correlationId,
            $mailDomain->desired_state_version,
        );
    }
}
