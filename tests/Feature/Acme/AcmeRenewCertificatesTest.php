<?php

use App\Enums\SslMode;
use App\Jobs\IssueAcmeCertificate;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Queue;

function acmeRenewalWebDomain(array $overrides = []): WebDomain
{
    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create();

    return WebDomain::factory()->for($node)->for($allocation)->create(array_merge([
        'ssl_mode' => SslMode::LetsEncrypt,
    ], $overrides));
}

test('only lets_encrypt domains within the renewal window (or never issued) are re-dispatched', function () {
    Queue::fake([IssueAcmeCertificate::class]);
    config(['acme.renew_within_days' => 30]);

    $expiringSoon = acmeRenewalWebDomain(['certificate_issued_at' => now()->subDays(60), 'certificate_expires_at' => now()->addDays(10)]);
    $neverIssued = acmeRenewalWebDomain(['certificate_issued_at' => null, 'certificate_expires_at' => null]);
    $freshlyIssued = acmeRenewalWebDomain(['certificate_issued_at' => now(), 'certificate_expires_at' => now()->addDays(90)]);
    $notLetsEncrypt = acmeRenewalWebDomain(['ssl_mode' => SslMode::Manual, 'certificate_issued_at' => null, 'certificate_expires_at' => null]);

    $this->artisan('acme:renew-certificates')->assertExitCode(0);

    Queue::assertPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($expiringSoon));
    Queue::assertPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($neverIssued));
    Queue::assertNotPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($freshlyIssued));
    Queue::assertNotPushed(IssueAcmeCertificate::class, fn ($job) => $job->webDomain->is($notLetsEncrypt));
});
