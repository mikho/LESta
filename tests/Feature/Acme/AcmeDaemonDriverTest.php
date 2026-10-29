<?php

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Jobs\IssueAcmeCertificate;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Services\Provisioning\DaemonProvisioner;
use Illuminate\Support\Sleep;

/**
 * Runs IssueAcmeCertificate's private applyDirect with the production (daemon) provisioner, which
 * never answers at once: the operation must carry its node and wait for the node's report.
 */
function applyDirectWithDaemon(WebDomain $webDomain): mixed
{
    $job = new IssueAcmeCertificate($webDomain);
    $call = function (WebDomain $webDomain) {
        $this->provisioner = new DaemonProvisioner;

        return $this->applyDirect($webDomain, 'tls.acme.v1', ProvisioningVerb::Create, ['kind' => 'http01_challenge', 'token' => 't', 'key_authorization' => 'k'], 1);
    };

    return Closure::bind($call, $job, IssueAcmeCertificate::class)($webDomain);
}

test('with the daemon driver a step is dispatched to the domain\'s node and its result is awaited', function () {
    $webDomain = WebDomain::factory()->create();
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($webDomain): void {
        ProvisioningOperation::query()->where('provisionable_id', $webDomain->id)->update([
            'status' => ProvisioningStatus::Applied, 'completed_at' => now(),
        ]);
    });

    $result = applyDirectWithDaemon($webDomain);

    $operation = ProvisioningOperation::query()->where('provisionable_id', $webDomain->id)->sole();
    expect($result->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->node_id)->toBe($webDomain->node_id)
        ->and($operation->capability)->toBe('tls.acme.v1');
});

test('a step the node never reports fails with a clear message after the timeout', function () {
    $webDomain = WebDomain::factory()->create();
    config(['acme.operation_timeout' => 3]);
    Sleep::fake(syncWithCarbon: true);

    expect(fn () => applyDirectWithDaemon($webDomain))
        ->toThrow(RuntimeException::class, 'The node did not apply the tls.acme.v1 create within 3 seconds.');
});
