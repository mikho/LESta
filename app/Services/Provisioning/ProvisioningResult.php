<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStatus;
use Carbon\CarbonInterface;

final readonly class ProvisioningResult
{
    /**
     * @param  array<int, array{code: string, message: string, field?: string|null}>  $errors
     * @param  array<string, mixed>|null  $data  Optional, capability-specific derived non-secret
     *                                           data (see docs/protocol/result-envelope.schema.json's
     *                                           own "data" property). Defaults to null: every
     *                                           existing capability/provisioner (fake, and every
     *                                           real Go capability that predates this field) never
     *                                           populates it, and that stays a real, correct
     *                                           no-op, not a temporary gap to fill in later.
     */
    public function __construct(
        public ProvisioningStatus $status,
        public ?int $observedStateVersion,
        public ?string $observedStateDigest,
        public ?string $generationId,
        public array $errors,
        public CarbonInterface $completedAt,
        public ?array $data = null,
    ) {}
}
