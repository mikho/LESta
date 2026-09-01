<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Enums\SslMode;
use App\Jobs\IssueAcmeCertificate;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

/**
 * The one and only place ACME-specific knowledge lives on the "a web
 * capability just applied" side: DispatchProvisioningOperation itself stays
 * fully provisionable-agnostic (it just calls this action's handle() after
 * writing its own result), matching how RecordsProvisioningOperation is
 * provisionable-agnostic elsewhere in this codebase. This codebase has no
 * existing Eloquent observer/model-event precedent (every side effect lives
 * explicitly in an Action class), so this follows that same house
 * convention rather than introducing the first observer.
 */
class TriggersAcmeCertificateIssuance
{
    /**
     * Dispatch IssueAcmeCertificate once $operation reaches a successful
     * terminal status for a WebDomain's own create/update against its
     * PUBLIC-facing web capability (web.nginx.v1 when the node has it
     * active -- nginx always fronts the public listener, per
     * ResolvesWebCapableNode's own nginx-over-apache precedence -- otherwise
     * web.apache.v1), with ssl_mode lets_encrypt and no certificate issued
     * yet. The vhost must genuinely exist before HTTP-01 can validate
     * against it, which is exactly what "this operation just applied" means.
     */
    public function handle(ProvisioningOperation $operation): void
    {
        if (! in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            return;
        }

        if (! in_array($operation->operation, [ProvisioningVerb::Create, ProvisioningVerb::Update], true)) {
            return;
        }

        $webDomain = $operation->provisionable;
        if (! $webDomain instanceof WebDomain) {
            return;
        }

        if ($webDomain->ssl_mode !== SslMode::LetsEncrypt || $webDomain->certificate_issued_at !== null) {
            return;
        }

        $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $webDomain->web_server->value);
        $publicCapability = in_array('web.nginx.v1', $capabilities, true) ? 'web.nginx.v1' : 'web.apache.v1';

        if ($operation->capability !== $publicCapability) {
            return;
        }

        IssueAcmeCertificate::dispatch($webDomain)->afterCommit();
    }
}
