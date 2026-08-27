<?php

namespace App\Contracts;

use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;

interface Provisioner
{
    public function apply(ProvisioningOperation $operation): ProvisioningResult;
}
