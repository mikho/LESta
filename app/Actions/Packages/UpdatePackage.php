<?php

namespace App\Actions\Packages;

use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdatePackage
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, description: string|null, is_active: bool}
     */
    public function handle(User $actor, Package $package, array $data): Package
    {
        Gate::forUser($actor)->authorize('update', $package);

        return DB::transaction(function () use ($actor, $package, $data): Package {
            $package->forceFill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ])->save();

            AuditEvent::record($package, 'package.updated', $actor);

            return $package;
        });
    }
}
