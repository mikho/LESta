<?php

namespace App\Providers;

use App\Contracts\Provisioner;
use App\Services\Provisioning\DaemonProvisioner;
use App\Services\Provisioning\FakeProvisioner;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ProvisioningServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Provisioner::class, function (): Provisioner {
            /** @var string $driver */
            $driver = config('provisioning.driver');

            return match ($driver) {
                'fake' => new FakeProvisioner,
                'daemon' => new DaemonProvisioner,
                default => throw new InvalidArgumentException("Unsupported provisioning driver [{$driver}]."),
            };
        });
    }
}
