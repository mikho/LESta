<?php

use App\Jobs\UpdateWebCapabilityCertificate;
use App\Models\AccountNodeIdentity;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

function issuedWebDomain(): WebDomain
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    return WebDomain::factory()->for($node)->create([
        'domain' => 'issued.example.com',
        'certificate_issued_at' => now(),
    ]);
}

test('it records the certificate update on the public web capability and clears a stale not-served error', function () {
    $webDomain = issuedWebDomain();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($webDomain->node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $webDomain->forceFill(['last_certificate_error' => UpdateWebCapabilityCertificate::ERROR_PREFIX.'earlier failure'])->save();

    UpdateWebCapabilityCertificate::dispatch($webDomain);

    $update = ProvisioningOperation::where('provisionable_id', $webDomain->id)
        ->where('provisionable_type', $webDomain->getMorphClass())
        ->where('capability', 'web.nginx.v1')
        ->where('operation', 'update')
        ->first();

    expect($update)->not->toBeNull()
        ->and($update->payload['ssl']['certificate_path'])->toBe('/var/lib/lesta/acme/certs/issued.example.com/fullchain.pem')
        ->and($webDomain->refresh()->last_certificate_error)->toBeNull();
});

test('a failure leaves the certificate issued but records that it is not being served', function () {
    // No AccountNodeIdentity: building the web payload throws.
    $webDomain = issuedWebDomain();

    expect(fn () => UpdateWebCapabilityCertificate::dispatch($webDomain))->toThrow(RuntimeException::class);

    $webDomain->refresh();

    expect($webDomain->certificate_issued_at)->not->toBeNull()
        ->and($webDomain->last_certificate_error)->toStartWith(UpdateWebCapabilityCertificate::ERROR_PREFIX);
});

test('it retries with backoff rather than re-issuing the certificate', function () {
    $job = new UpdateWebCapabilityCertificate(issuedWebDomain());

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 60, 300, 900]);
});
