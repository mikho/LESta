<?php

namespace App\Actions\Mail;

use App\Models\DnsZone;
use App\Models\MailDomain;
use Carbon\CarbonInterface;

/**
 * Shared by App\Console\Commands\PromotePendingDkimSelectors and
 * App\Console\Commands\RetireOldDkimSelectors: both need to know "how long ago must a DNS change
 * for this domain have happened for it to be safe to act on now", the real TTL-bounded propagation
 * window the Mail Threat Model's own "DKIM rotation" gate item calls for, not a guessed fixed
 * duration. Applied identically to both the "publish, then wait before signing" half and the
 * "stop signing, then wait before deleting the old record/key" half of a rotation: both represent
 * the same real question (has enough time passed for a resolver to have refreshed its own cache
 * since the corresponding DNS record changed).
 */
class ResolvesDkimPropagationWindow
{
    /**
     * Used when this mail domain's own domain has no matching DnsZone in this control plane at
     * all: DNS for it is managed somewhere else entirely, so there is no real TTL to reason about,
     * and a long, conservative wait is the safe default rather than a short one.
     */
    private const FALLBACK_HOURS = 24;

    /**
     * A floor under a real zone's own configured TTL, in case an operator set it unrealistically
     * low: rotation should never move faster than this regardless of what a zone's own TTL says.
     */
    private const MINIMUM_HOURS = 1;

    /**
     * Returns the cutoff instant: a DNS-affecting change for this domain is safe to act on once
     * its own recorded timestamp is at or before this value.
     */
    public function cutoffFor(MailDomain $mailDomain): CarbonInterface
    {
        $dnsZone = DnsZone::query()
            ->where('account_id', $mailDomain->account_id)
            ->where('domain', $mailDomain->domain)
            ->first();

        $hours = $dnsZone !== null
            ? max(self::MINIMUM_HOURS, (int) ceil($dnsZone->ttl / 3600))
            : self::FALLBACK_HOURS;

        return now()->subHours($hours);
    }
}
