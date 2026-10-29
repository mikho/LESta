<?php

namespace App\Rules;

use App\Models\Node;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host name a web domain may answer on: not taken by another web domain, as its domain or as
 * an alias (the two are kept in separate tables, and either would put a second vhost on the same
 * name), and not a node's own host name or mail host name, which belong to the platform (the
 * node's tools vhost serves webmail and Adminer there, and other customers' one-time login
 * tokens are posted to it).
 */
class AvailableWebHostname implements ValidationRule
{
    /**
     * @param  int|null  $webDomainId  The web domain being edited, whose own names stay allowed.
     * @param  bool  $platformOwned  Whether that domain is the platform's own (a node's tools
     *                               domain), which answers on the node's host name by design.
     */
    public function __construct(private ?int $webDomainId = null, private bool $platformOwned = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $name = WebDomain::normalizeDomain($value);

        $reserved = Node::query()
            ->whereRaw('LOWER(hostname) = ?', [$name])
            ->orWhereRaw('LOWER(mail_hostname) = ?', [$name])
            ->exists();

        if ($reserved && ! $this->platformOwned) {
            $fail(__(':value is a server name of this platform and cannot be used.', ['value' => $name]));

            return;
        }

        $takenAsDomain = WebDomain::query()
            ->where('domain', $name)
            ->when($this->webDomainId !== null, fn ($query) => $query->whereKeyNot($this->webDomainId))
            ->exists();

        $takenAsAlias = WebDomainAlias::query()
            ->where('alias', $name)
            ->when($this->webDomainId !== null, fn ($query) => $query->where('web_domain_id', '!=', $this->webDomainId))
            ->exists();

        if ($takenAsDomain || $takenAsAlias) {
            $fail(__(':value is already in use.', ['value' => $name]));
        }
    }
}
