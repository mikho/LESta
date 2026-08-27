<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Models\ProvisioningOperation;

final class FakeProvisioner implements Provisioner
{
    public function apply(ProvisioningOperation $operation): ProvisioningResult
    {
        return new ProvisioningResult(
            status: ProvisioningStatus::Applied,
            observedStateVersion: $operation->desired_state_version,
            observedStateDigest: 'sha256:'.hash('sha256', 'fake:'.$operation->idempotency_key),
            generationId: 'fake-'.$operation->idempotency_key,
            errors: [],
            completedAt: now(),
        );
    }
}
