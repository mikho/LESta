<?php

use App\Enums\ProvisioningStatus;
use App\Models\ProvisioningOperation;
use App\Services\Provisioning\RequestDigest;
use Illuminate\Database\Migrations\Migration;

/**
 * The agent now refuses an operation whose request_digest is not the canonical one (see
 * RequestDigest). An operation still waiting for its node was sealed with the old digest, so it is
 * sealed again; one that has finished is never sent again and keeps what it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProvisioningOperation::query()
            ->whereIn('status', [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched])
            ->each(function (ProvisioningOperation $operation): void {
                $operation->forceFill([
                    'request_digest' => RequestDigest::for(
                        $operation->capability,
                        $operation->operation->value,
                        $operation->resource_id,
                        $operation->desired_state_version,
                        $operation->payload,
                    ),
                ])->saveQuietly();
            });
    }
};
