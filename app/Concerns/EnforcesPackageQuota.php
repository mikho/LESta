<?php

namespace App\Concerns;

use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\PackageLimit;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Every Create* Action enforcing a Package quota used to do so as a plain `count() >= limit`
 * check inside its own DB::transaction, with no row lock -- two concurrent requests for the same
 * account could both read the same pre-increment count before either commit, letting a package
 * limited to N resources end up with more than N. This trait closes that race by locking the real
 * concurrency boundary for the resource first, so a second concurrent request blocks until the
 * first's transaction commits (or rolls back) and re-reads a now-accurate count.
 */
trait EnforcesPackageQuota
{
    /**
     * $lockable must be whichever row is the actual concurrency boundary for the resource being
     * counted: the Account itself for an account-wide resource (web domains, mail domains, DNS
     * zones, tenant databases, cron jobs), or the specific parent row (a DnsZone for DNS records,
     * a MailDomain for mailboxes) for a resource counted per-parent rather than per-account.
     * Locking the wrong row would leave two concurrent requests under the same real parent free
     * to race each other. $currentCount is a closure, not an already-evaluated int, because it
     * must run AFTER the lock is acquired -- evaluating the count first and passing it in would
     * just be the original race with extra steps.
     */
    protected function assertPackageQuotaAvailable(Model $lockable, Account $account, string $resourceType, Closure $currentCount): void
    {
        $lockable::query()->whereKey($lockable->getKey())->lockForUpdate()->first();

        $limit = PackageLimit::query()
            ->where('package_id', $account->package_id)
            ->where('resource_type', $resourceType)
            ->first();

        if ($limit === null) {
            throw ResourceQuotaExceededException::notConfigured($resourceType);
        }

        if ($limit->limit_value !== null && $currentCount() >= $limit->limit_value) {
            throw ResourceQuotaExceededException::limitReached($resourceType, $limit->limit_value);
        }
    }
}
