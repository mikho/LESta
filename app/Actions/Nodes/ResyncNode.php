<?php

namespace App\Actions\Nodes;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesCronCapableNode;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Actions\Provisioning\ResolvesMailCapableNode;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real disaster recovery for everything on a node EXCEPT the two things a backup archive alone
 * can protect (mail's own maildir content, each database capability's own mysqldump output --
 * see App\Actions\Backups\RestoreBackup / backup.encrypted-artifacts.v1's own restore operation
 * for those): every web domain, DNS zone, cron job, and mail domain's own structural config is
 * always fully, losslessly re-derivable from Laravel's own database via a normal update, so this
 * action just re-dispatches exactly that -- current desired state, unchanged -- forcing the node
 * to physically re-render and re-activate every resource's own config from scratch. This is what
 * actually brings a node back to a working state after a disk loss, cheaper and safer than
 * replaying old generation content the way a "restore" naively imagined would.
 *
 * A real, disclosed scope boundary: only resources that still exist in this database today are
 * resynced. A resource deleted after a backup was taken is never resurrected -- see the vault
 * decision log's own "full automated restore-to-node" entry for why that boundary was chosen.
 *
 * System-initiated only for this pass (see handleSystemInitiated): triggered exclusively by
 * CascadesRestoreIntoResync once a restore operation completes, not by a direct HTTP route of
 * its own yet.
 */
class ResyncNode
{
    public function handleSystemInitiated(Node $node): void
    {
        DB::transaction(function () use ($node): void {
            $correlationId = (string) Str::uuid();

            $this->resyncWebDomains($node, $correlationId);
            $this->resyncDnsZones($node, $correlationId);
            $this->resyncCronJobs($node, $correlationId);
            $this->resyncMailDomains($node, $correlationId);

            AuditEvent::create([
                'actor_type' => null,
                'actor_id' => null,
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.resynced',
                'correlation_id' => $correlationId,
            ]);
        });
    }

    private function resyncWebDomains(Node $node, string $correlationId): void
    {
        foreach ($node->webDomains()->get() as $webDomain) {
            $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($node, $webDomain->web_server->value);

            $webDomain->forceFill(['desired_state_version' => $webDomain->desired_state_version + 1])->save();

            foreach ($capabilities as $capability) {
                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    $capability,
                    ProvisioningVerb::Update,
                    $webDomain->toProvisioningPayload($capability),
                    $correlationId,
                    $webDomain->desired_state_version,
                );
            }
        }
    }

    private function resyncDnsZones(Node $node, string $correlationId): void
    {
        foreach ($node->dnsZones()->get() as $dnsZone) {
            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($node);

            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Update,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );
        }
    }

    private function resyncCronJobs(Node $node, string $correlationId): void
    {
        foreach ($node->cronJobs()->get() as $cronJob) {
            $capability = app(ResolvesCronCapableNode::class)->resolveFor($node);

            $cronJob->forceFill(['desired_state_version' => $cronJob->desired_state_version + 1])->save();

            app(RecordsProvisioningOperation::class)->record(
                $cronJob,
                $capability,
                ProvisioningVerb::Update,
                $cronJob->toProvisioningPayload(),
                $correlationId,
                $cronJob->desired_state_version,
            );
        }
    }

    /**
     * Reuses every account's own already-known current password (MailAccount::password, stored
     * with Laravel's own recoverable `encrypted` cast, never a one-way hash) rather than forcing
     * a reset: a mailbox owner keeps using the same password they already have, and Dovecot's own
     * passwd entries come back working with zero disruption. See MailDomain::toProvisioningPayload's
     * own $resyncPasswordsByAccountId parameter doc comment for why this is a distinct, explicit
     * inclusion mode from the normal single-account rotation one.
     */
    private function resyncMailDomains(Node $node, string $correlationId): void
    {
        foreach ($node->mailDomains()->get() as $mailDomain) {
            $capability = app(ResolvesMailCapableNode::class)->resolveFor($node);

            $mailDomain->forceFill(['desired_state_version' => $mailDomain->desired_state_version + 1])->save();

            $passwordsByAccountId = $mailDomain->accounts()->get()
                ->mapWithKeys(fn (MailAccount $account): array => [$account->id => $account->password])
                ->all();

            app(RecordsProvisioningOperation::class)->record(
                $mailDomain,
                $capability,
                ProvisioningVerb::Update,
                $mailDomain->toProvisioningPayload(resyncPasswordsByAccountId: $passwordsByAccountId),
                $correlationId,
                $mailDomain->desired_state_version,
            );
        }
    }
}
