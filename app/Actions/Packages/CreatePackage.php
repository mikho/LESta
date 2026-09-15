<?php

namespace App\Actions\Packages;

use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreatePackage
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, description: string|null, is_active: bool}
     */
    public function handle(User $actor, array $data): Package
    {
        Gate::forUser($actor)->authorize('create', Package::class);

        return DB::transaction(function () use ($actor, $data): Package {
            $package = Package::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $package->getMorphClass(),
                'auditable_id' => $package->getKey(),
                'action' => 'package.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $package;
        });
    }
}
