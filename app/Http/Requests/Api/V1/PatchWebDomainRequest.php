<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Domains\UpdateWebDomainRequest;
use App\Models\WebDomain;

/**
 * The panel's update rules, for a PATCH: every field left out keeps its current value.
 */
class PatchWebDomainRequest extends UpdateWebDomainRequest
{
    protected function prepareForValidation(): void
    {
        /** @var WebDomain $webDomain */
        $webDomain = $this->route('webDomain');

        $this->merge(array_diff_key([
            'domain' => $webDomain->domain,
            'web_template' => $webDomain->web_template,
            'web_server' => $webDomain->web_server->value,
            'php_version' => $webDomain->php_version?->value,
            'ssl_mode' => $webDomain->ssl_mode->value,
            'waf_mode' => $webDomain->waf_mode,
            'waf_preset' => $webDomain->waf_preset,
            'hotlink_protection' => $webDomain->hotlink_protection,
            'hotlink_allowed_hosts' => $webDomain->hotlink_allowed_hosts ?? [],
            'waf_excluded_rules' => $webDomain->waf_excluded_rules ?? [],
            'aliases' => $webDomain->aliases()->pluck('alias')->all(),
        ], $this->all()));

        parent::prepareForValidation();
    }
}
