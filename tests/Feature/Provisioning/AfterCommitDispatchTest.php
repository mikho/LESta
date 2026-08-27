<?php

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a provisioning operation stays pending inside an open transaction and applies only after commit', function () {
    $account = Account::factory()->create();
    $operationId = null;

    DB::transaction(function () use ($account, &$operationId) {
        $operation = app(RecordsProvisioningOperation::class)->record(
            $account, 'web.nginx.v1', ProvisioningVerb::Create, [], (string) Str::uuid(),
        );
        $operationId = $operation->id;

        // Still inside the open transaction: the afterCommit-deferred job must not have run yet.
        expect(ProvisioningOperation::find($operationId)->status)->toBe(ProvisioningStatus::Pending);
    });

    // QUEUE_CONNECTION=sync in phpunit.xml means afterCommit-deferred sync jobs run the instant
    // the enclosing transaction actually commits — no manual dispatch or Bus::fake() involved.
    expect(ProvisioningOperation::find($operationId)->status)->toBe(ProvisioningStatus::Applied);
});

test('a rolled-back transaction never dispatches the provisioning operation', function () {
    $account = Account::factory()->create();

    try {
        DB::transaction(function () use ($account) {
            app(RecordsProvisioningOperation::class)->record(
                $account, 'web.nginx.v1', ProvisioningVerb::Create, [], (string) Str::uuid(),
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ProvisioningOperation::count())->toBe(0);
});
