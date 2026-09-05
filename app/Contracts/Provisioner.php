<?php

namespace App\Contracts;

use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;

interface Provisioner
{
    /**
     * Apply $operation and return its terminal result, or null when the operation has
     * merely been enqueued for real asynchronous delivery to a node's agent daemon: no
     * terminal result is available yet, and the operation stays Dispatched until the
     * daemon reports back over agent/v1/operation-results.
     */
    public function apply(ProvisioningOperation $operation): ?ProvisioningResult;
}
