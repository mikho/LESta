<?php

namespace App\Services\Webhooks;

/**
 * Where a webhook may be sent: HTTPS to a host whose every address is public. Checked when the
 * webhook is saved and again at every send (DNS can change in between), and the send is then
 * pinned to the address that was checked, so the panel cannot be used to reach its own network.
 */
class WebhookTarget
{
    /** IPv4 ranges PHP's FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE let through. */
    private const array EXTRA_BLOCKED_V4 = [
        '100.64.0.0/10',  // carrier-grade NAT
        '192.0.0.0/24',   // IETF protocol assignments
        '198.18.0.0/15',  // benchmarking
        '224.0.0.0/4',    // multicast
    ];

    /**
     * Null when $url may be used, otherwise why not.
     */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return __('The address must start with https:// and name a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return __('The address may not contain a user name or password.');
        }

        $addresses = $this->addresses($parts['host']);

        if ($addresses === []) {
            return __('The host :host could not be found.', ['host' => $parts['host']]);
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return __('The host :host has a private or reserved address; webhooks go to public addresses only.', ['host' => $parts['host']]);
            }
        }

        return null;
    }

    /**
     * The host's addresses: the address itself for an IP literal, otherwise its A and AAAA records.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    public function isPublic(string $address): bool
    {
        // An IPv4 address written as IPv6 (::ffff:10.0.0.1) is judged as the IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped)) {
            $address = $mapped[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_BLOCKED_V4 as $range) {
                [$network, $bits] = explode('/', $range);
                $mask = -1 << (32 - (int) $bits);

                if ((ip2long($address) & $mask) === (ip2long($network) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6: multicast (ff00::/8) and the documentation range are not covered by the flags.
        $lower = strtolower($address);

        return ! str_starts_with($lower, 'ff') && ! str_starts_with($lower, '2001:db8');
    }
}
