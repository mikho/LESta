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
     * @param  array<string, mixed>  $data  Expected shape: array{antivirus_enabled?: bool, antispam_enabled?: bool, dkim_enabled?: bool, catchall_email?: string|null}
     *
     * dkim_enabled is now accepted: the real mail.smtp-imap.v1 Go capability generates and
     * rotates a real, node-only DKIM key the moment this flag turns true (see
     * agent/internal/capability/mail's own ensureDKIMKey), closing the gap that previously made
     * this a UX lie rather than a harmless desired-state placeholder. Turning it back off does
     * not delete existing key material (see ensureDKIMKey's own doc comment); it is simply
     * omitted from the active signing lookup, the same "suspended/disabled = absent from
     * rendered live config" idiom every other capability in this project already uses.
     */
    public function handle(User $actor, MailDomain $mailDomain, array $data): MailDomain
    {
        Gate::forUser($actor)->authorize('update', $mailDomain);

        return DB::transaction(function () use ($actor, $mailDomain, $data): MailDomain {
            $dkimEnabled = $data['dkim_enabled'] ?? $mailDomain->dkim_enabled;
            $dkimJustEnabled = $dkimEnabled && ! $mailDomain->dkim_enabled;

            $mailDomain->forceFill([
                'antivirus_enabled' => $data['antivirus_enabled'] ?? $mailDomain->antivirus_enabled,
                'antispam_enabled' => $data['antispam_enabled'] ?? $mailDomain->antispam_enabled,
                'dkim_enabled' => $dkimEnabled,
                // A domain re-enabling DKIM after having it off starts its own rotation clock
                // fresh (see App\Console\Commands\RotateDkimSelectors), the same as a brand new
                // domain: there is no meaningful "resume the old schedule" here since the key
                // itself, if it still exists on the node, has been sitting unused the whole time
                // it was disabled.
                'dkim_selector_activated_at' => $dkimJustEnabled ? now() : $mailDomain->dkim_selector_activated_at,
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
