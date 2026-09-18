<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Concerns\EnforcesPackageQuota;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateMailDomain
{
    use EnforcesPackageQuota;

    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, antivirus_enabled?: bool, antispam_enabled?: bool, dkim_enabled?: bool, catchall_email?: string|null}
     */
    public function handle(User $actor, Account $account, array $data): MailDomain
    {
        Gate::forUser($actor)->authorize('create', [MailDomain::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): MailDomain {
            $this->assertPackageQuotaAvailable($account, $account, 'mail_domains', fn () => $account->mailDomains()->count());

            [$node, $capability] = app(ResolvesMailCapableNode::class)->resolve();

            $dkimEnabled = $data['dkim_enabled'] ?? false;

            $mailDomain = MailDomain::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'domain' => MailDomain::normalizeDomain($data['domain']),
                'antivirus_enabled' => $data['antivirus_enabled'] ?? true,
                'antispam_enabled' => $data['antispam_enabled'] ?? true,
                'dkim_enabled' => $dkimEnabled,
                'dkim_selector' => 'lesta1',
                'dkim_selector_activated_at' => $dkimEnabled ? now() : null,
                'catchall_email' => $data['catchall_email'] ?? null,
                'desired_state_version' => 1,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $mailDomain->getMorphClass(),
                'auditable_id' => $mailDomain->getKey(),
                'action' => 'mail_domain.created',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Create,
                $mailDomain->toProvisioningPayload(),
                $correlationId,
                1,
            );

            return $mailDomain;
        });
    }
}
