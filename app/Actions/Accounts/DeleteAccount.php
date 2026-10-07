<?php

namespace App\Actions\Accounts;

use App\Actions\CronJobs\DeleteCronJob;
use App\Actions\Dns\DeleteDnsZone;
use App\Actions\Domains\DeleteWebDomain;
use App\Actions\Mail\DeleteMailDomain;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('delete', $account);

        if ($account->is_platform) {
            throw ValidationException::withMessages([
                'account' => 'The platform account owns node-level resources and cannot be suspended or deleted.',
            ]);
        }

        DB::transaction(function () use ($actor, $account): void {
            if ($account->isSuspended()) {
                $account->unsuspend();
            }

            $account->webDomains()->get()->each(fn (WebDomain $d) => app(DeleteWebDomain::class)->handle($actor, $d));

            $account->dnsZones()->get()->each(fn (DnsZone $z) => app(DeleteDnsZone::class)->handle($actor, $z));

            $account->mailDomains()->get()->each(fn (MailDomain $d) => app(DeleteMailDomain::class)->handle($actor, $d));

            // Both tenant_databases.account_id and cron_jobs.account_id are restrictOnDelete,
            // not cascadeOnDelete (see their own migrations): without deleting these first, the
            // final $account->delete() call below throws a real, uncaught
            // Illuminate\Database\QueryException (foreign key constraint violation) for any
            // account that still has either -- confirmed directly, not a hypothetical, before
            // this fix.
            $account->tenantDatabases()->get()->each(fn (TenantDatabase $d) => app(DeleteTenantDatabase::class)->handle($actor, $d));

            $account->cronJobs()->get()->each(fn (CronJob $j) => app(DeleteCronJob::class)->handle($actor, $j));

            // account_node_identities.account_id is restrictOnDelete too. Deleted last: every
            // resource above resolves its node-side username from this identity while being
            // deleted. Each records its own delete so the node removes the system user, the same
            // operation DeleteOrphanedAccountNodeIdentity records.
            AccountNodeIdentity::where('account_id', $account->id)->get()->each(function (AccountNodeIdentity $identity): void {
                app(RecordsProvisioningOperation::class)->record(
                    $identity,
                    'system.account-identity.v1',
                    ProvisioningVerb::Delete,
                    $identity->toProvisioningPayload(),
                    (string) Str::uuid(),
                    $identity->desired_state_version,
                );

                $identity->delete();
            });

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.deleted',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $account->delete();
        });
    }
}
