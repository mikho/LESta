<?php

namespace App\Concerns;

use App\Enums\SuspensionSource;
use Illuminate\Database\Eloquent\Builder;

trait Suspendable
{
    public function suspend(SuspensionSource $source = SuspensionSource::Manual): void
    {
        $this->forceFill(['suspended_at' => now(), 'suspension_source' => $source])->save();
    }

    public function unsuspend(): void
    {
        $this->forceFill(['suspended_at' => null, 'suspension_source' => null])->save();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Only what was suspended because its parent was (an account, a zone, a mail domain, a node),
     * which is what lifting the parent's suspension brings back.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSuspendedByCascade(Builder $query): void
    {
        $query->where('suspension_source', SuspensionSource::Cascade);
    }
}
