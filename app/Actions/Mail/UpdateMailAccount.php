<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * There is no password field here: mirroring TenantDatabase's own precedent (see
 * app/Actions/TenantDatabases's package doc comment), password is the only field a dedicated
 * verb handles instead of a generic update -- RotateMailAccountPassword.
 */
class UpdateMailAccount
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{quota_mb?: int|null, forward_to?: string|null, forward_only?: bool, autoreply_enabled?: bool, autoreply_message?: string|null}
     */
    public function handle(User $actor, MailAccount $mailAccount, array $data): MailAccount
    {
        Gate::forUser($actor)->authorize('update', $mailAccount);

        return DB::transaction(function () use ($actor, $mailAccount, $data): MailAccount {
            $mailAccount->forceFill([
                'quota_mb' => array_key_exists('quota_mb', $data) ? $data['quota_mb'] : $mailAccount->quota_mb,
                'forward_to' => array_key_exists('forward_to', $data) ? $data['forward_to'] : $mailAccount->forward_to,
                'forward_only' => $data['forward_only'] ?? $mailAccount->forward_only,
                'autoreply_enabled' => $data['autoreply_enabled'] ?? $mailAccount->autoreply_enabled,
                'autoreply_message' => array_key_exists('autoreply_message', $data) ? $data['autoreply_message'] : $mailAccount->autoreply_message,
            ])->save();

            $mailDomain = $mailAccount->mailDomain;
            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailAccount->getMorphClass(),
                'auditable_id' => $mailAccount->getKey(),
                'action' => 'mail_account.updated',
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

            return $mailAccount;
        });
    }
}
