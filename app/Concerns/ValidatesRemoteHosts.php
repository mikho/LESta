<?php

namespace App\Concerns;

use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use App\Rules\ValidRemoteHost;
use Illuminate\Validation\Validator;

/**
 * An extra database user's remote hosts, shared by the create and update requests: a list or (from
 * the panel's form) text with one address per line or separated by commas. Entries are trimmed,
 * written in canonical form (so 2001:DB8::0:5 and 2001:db8::5 are the same address) and
 * de-duplicated. Remote access needs the node hostname's certificate, which the node presents to
 * remote clients, so it is refused until the node has one.
 */
trait ValidatesRemoteHosts
{
    protected function normalizeRemoteHosts(): void
    {
        if (! $this->has('remote_hosts')) {
            return;
        }

        $value = $this->input('remote_hosts');

        if ($value === null) {
            $this->merge(['remote_hosts' => []]);

            return;
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_array($value)) {
            $value = array_values(array_unique(array_map(function (mixed $entry): mixed {
                if (! is_string($entry)) {
                    return $entry;
                }

                $entry = trim($entry);
                $packed = filter_var($entry, FILTER_VALIDATE_IP) !== false ? inet_pton($entry) : false;

                return $packed === false ? $entry : (inet_ntop($packed) ?: $entry);
            }, array_filter($value, fn (mixed $entry): bool => ! is_string($entry) || trim($entry) !== '')), SORT_REGULAR));
        }

        $this->merge(['remote_hosts' => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function remoteHostRules(): array
    {
        return [
            'remote_hosts' => ['sometimes', 'array', 'max:'.TenantDatabaseUser::MAX_REMOTE_HOSTS],
            'remote_hosts.*' => [new ValidRemoteHost],
        ];
    }

    /**
     * Refuses remote hosts on a node without the certificate for its own hostname.
     */
    protected function requireNodeCertificateForRemoteHosts(Validator $validator, TenantDatabase $tenantDatabase): void
    {
        $hosts = $this->input('remote_hosts');

        if (! is_array($hosts) || $hosts === [] || $validator->errors()->has('remote_hosts')) {
            return;
        }

        if ($tenantDatabase->node->toolsWebDomain() === null) {
            $validator->errors()->add('remote_hosts', __('Remote access needs a certificate for the node hostname :hostname. Ask your administrator to set it up.', ['hostname' => $tenantDatabase->node->hostname]));
        }
    }
}
