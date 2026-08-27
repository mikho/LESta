<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStatus;
use Carbon\CarbonInterface;

final readonly class ProvisioningResult
{
    /**
     * @param  array<int, array{code: string, message: string, field?: string|null}>  $errors
     */
    public function __construct(
        public ProvisioningStatus $status,
        public ?int $observedStateVersion,
        public ?string $observedStateDigest,
        public ?string $generationId,
        public array $errors,
        public CarbonInterface $completedAt,
    ) {}
}
