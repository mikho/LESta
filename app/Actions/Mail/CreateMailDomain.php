<?php

namespace App\Actions\Mail;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\PackageLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateMailDomain
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, antivirus_enabled?: bool, antispam_enabled?: bool, dkim_enabled?: bool, catchall_email?: string|null}
     */
    public function handle(User $actor, Account $account, array $data): MailDomain
    {
        Gate::forUser($actor)->authorize('create', [MailDomain::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): MailDomain {
            $limit = PackageLimit::query()
                ->where('package_id', $account->package_id)
                ->where('resource_type', 'mail_domains')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('mail_domains');
            }

            if ($limit->limit_value !== null && $account->mailDomains()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('mail_domains', $limit->limit_value);
            }

            [$node, $capability] = app(ResolvesMailCapableNode::class)->resolve();

            $mailDomain = MailDomain::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'domain' => MailDomain::normalizeDomain($data['domain']),
                'antivirus_enabled' => $data['antivirus_enabled'] ?? true,
                'antispam_enabled' => $data['antispam_enabled'] ?? true,
                'dkim_enabled' => $data['dkim_enabled'] ?? false,
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
