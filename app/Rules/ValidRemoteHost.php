<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One address a database user may connect from remotely: a single IPv4 or IPv6 address in the
 * canonical form ValidatesRemoteHosts writes (inet_ntop's), never a range, a hostname, or an
 * address no remote client can have. The same shape the node accepts (mariadb payload.go's
 * validateRemoteHosts), since each becomes a MariaDB account host and a firewall set entry.
 */
class ValidRemoteHost implements ValidationRule
{
    /**
     * Ranges no remote client connects from: loopback, unspecified, link-local, multicast, and
     * IPv4 written as IPv6.
     *
     * @var list<array{string, int}>
     */
    private const array REFUSED_RANGES = [
        ['127.0.0.0', 8], ['0.0.0.0', 32], ['169.254.0.0', 16], ['224.0.0.0', 4],
        ['::1', 128], ['::', 128], ['fe80::', 10], ['ff00::', 8], ['::ffff:0:0', 96],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $packed = is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? inet_pton($value) : false;

        if ($packed === false || inet_ntop($packed) !== $value || $this->isRefused($packed)) {
            $fail(__('":value" is not a single public or private IPv4 or IPv6 address.', ['value' => is_string($value) ? $value : '']));
        }
    }

    private function isRefused(string $packed): bool
    {
        foreach (self::REFUSED_RANGES as [$network, $prefix]) {
            $range = inet_pton($network);

            if ($range === false || strlen($range) !== strlen($packed)) {
                continue;
            }

            $bytes = intdiv($prefix, 8);
            $bits = $prefix % 8;

            if (substr($packed, 0, $bytes) !== substr($range, 0, $bytes)) {
                continue;
            }

            if ($bits === 0 || ((ord($packed[$bytes]) ^ ord($range[$bytes])) & (0xFF << (8 - $bits)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }
}
