<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebDomain;
use Illuminate\Http\Request;

/**
 * @mixin WebDomain
 */
class WebDomainResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'account_id' => $this->whenLoaded('account', fn (): string => $this->account->public_id),
            'domain' => $this->domain,
            'aliases' => $this->aliases->pluck('alias')->all(),
            'web_template' => $this->web_template,
            'web_server' => $this->web_server->value,
            'php_version' => $this->php_version?->value,
            'ssl_mode' => $this->ssl_mode->value,
            'certificate' => [
                'issued_at' => $this->timestamp($this->certificate_issued_at),
                'expires_at' => $this->timestamp($this->certificate_expires_at),
                'last_error' => $this->last_certificate_error,
            ],
            'waf_mode' => $this->waf_mode,
            'waf_preset' => $this->waf_preset,
            'waf_excluded_rules' => $this->waf_excluded_rules ?? [],
            'hotlink_protection' => $this->hotlink_protection,
            'hotlink_allowed_hosts' => $this->hotlink_allowed_hosts ?? [],
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'provisioning' => $this->provisioning($this->latestProvisioningOperation),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
