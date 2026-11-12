<?php

namespace App\Actions\Packages;

use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeletePackage
{
    public function handle(User $actor, Package $package): void
    {
        Gate::forUser($actor)->authorize('delete', $package);

        if ($package->accounts()->exists()) {
            throw ValidationException::withMessages([
                'package' => 'This package still has accounts on it and cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($actor, $package): void {
            AuditEvent::record($package, 'package.deleted', $actor);

            $package->delete();
        });
    }
}
