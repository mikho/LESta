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

class RotateMailAccountPassword
{
    /**
     * @return array{0: MailAccount, 1: string} The row and its new one-time plaintext password.
     */
    public function handle(User $actor, MailAccount $mailAccount): array
    {
        Gate::forUser($actor)->authorize('update', $mailAccount);

        return DB::transaction(function () use ($actor, $mailAccount): array {
            $password = bin2hex(random_bytes(24));

            $mailAccount->forceFill(['password' => $password])->save();

            $mailDomain = $mailAccount->mailDomain;
            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            // Deliberately never logs the password itself, only that a rotation happened.
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailAccount->getMorphClass(),
                'auditable_id' => $mailAccount->getKey(),
                'action' => 'mail_account.password_rotated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Update,
                $mailDomain->toProvisioningPayload(includePasswordForAccountId: $mailAccount->id, plaintextPassword: $password),
                $correlationId,
                $mailDomain->desired_state_version,
            );

            return [$mailAccount, $password];
        });
    }
}
