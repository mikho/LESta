<?php

namespace App\Concerns;

use App\Enums\SuspensionSource;

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

    public function wasSuspendedByCascade(): bool
    {
        return $this->suspension_source === SuspensionSource::Cascade;
    }
}
