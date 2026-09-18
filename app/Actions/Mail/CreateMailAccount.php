<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Concerns\EnforcesPackageQuota;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateMailAccount
{
    use EnforcesPackageQuota;

    /**
     * @param  array<string, mixed>  $data  Expected shape: array{local_part: string, quota_mb?: int|null, forward_to?: string|null, forward_only?: bool, autoreply_enabled?: bool, autoreply_message?: string|null}
     * @return array{0: MailAccount, 1: string} The created row and its one-time plaintext
     *                                          password (never stored anywhere in cleartext
     *                                          past this call: it is encrypted at rest on the
     *                                          row itself, and this is the only place it is
     *                                          ever handed back to a caller).
     */
    public function handle(User $actor, MailDomain $mailDomain, array $data): array
    {
        Gate::forUser($actor)->authorize('create', [MailAccount::class, $mailDomain]);

        return DB::transaction(function () use ($actor, $mailDomain, $data): array {
            // Locks the domain, not the account: mail_accounts is a per-domain quota
            // ($mailDomain->accounts()->count()), so the domain row is the real concurrency
            // boundary -- two concurrent inserts into two DIFFERENT domains on the same account
            // were never counted together in the first place and don't need to serialize against
            // each other.
            $this->assertPackageQuotaAvailable($mailDomain, $mailDomain->account, 'mail_accounts', fn () => $mailDomain->accounts()->count());

            $password = bin2hex(random_bytes(24));

            $mailAccount = MailAccount::query()->create([
                'mail_domain_id' => $mailDomain->id,
                'local_part' => mb_strtolower(trim($data['local_part'])),
                'password' => $password,
                'quota_mb' => $data['quota_mb'] ?? null,
                'forward_to' => $data['forward_to'] ?? null,
                'forward_only' => $data['forward_only'] ?? false,
                'autoreply_enabled' => $data['autoreply_enabled'] ?? false,
                'autoreply_message' => $data['autoreply_message'] ?? null,
            ]);

            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $capability = app(ResolvesMailCapableNode::class)->resolveFor($mailDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailAccount->getMorphClass(),
                'auditable_id' => $mailAccount->getKey(),
                'action' => 'mail_account.created',
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
