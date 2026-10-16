<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MailDomain;
use Illuminate\Http\Request;

/**
 * @mixin MailDomain
 */
class MailDomainResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'domain' => $this->domain,
            'antivirus_enabled' => $this->antivirus_enabled,
            'antispam_enabled' => $this->antispam_enabled,
            'dkim_enabled' => $this->dkim_enabled,
            'dkim_selector' => $this->dkim_selector,
            'catchall_email' => $this->catchall_email,
            'accounts_count' => $this->whenCounted('accounts'),
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'provisioning' => $this->provisioning($this->latestProvisioningOperation),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
