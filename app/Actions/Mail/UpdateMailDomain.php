<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateMailDomain
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{antivirus_enabled?: bool, antispam_enabled?: bool, catchall_email?: string|null}
     *
     * dkim_enabled is deliberately never accepted here: per the Mail Threat Model's own
     * "Explicitly Deferred" section, there is no real key-generation/rotation mechanism yet to
     * back the intent, so letting a tenant toggle it on now would be a UX lie rather than a
     * harmless desired-state placeholder (unlike WebDomain.ssl_mode, which was settable ahead of
     * ACME because "none" was still a genuinely complete state requiring no async work).
     */
    public function handle(User $actor, MailDomain $mailDomain, array $data): MailDomain
    {
        Gate::forUser($actor)->authorize('update', $mailDomain);

        return DB::transaction(function () use ($actor, $mailDomain, $data): MailDomain {
            $mailDomain->forceFill([
                'antivirus_enabled' => $data['antivirus_enabled'] ?? $mailDomain->antivirus_enabled,
                'antispam_enabled' => $data['antispam_enabled'] ?? $mailDomain->antispam_enabled,
                'catchall_email' => array_key_exists('catchall_email', $data) ? $data['catchall_email'] : $mailDomain->catchall_email,
                'desired_state_version' => $mailDomain->desired_state_version + 1,
            ])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.updated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Update,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                $mailDomain->desired_state_version,
            );

            return $mailDomain;
        });
    }
}
