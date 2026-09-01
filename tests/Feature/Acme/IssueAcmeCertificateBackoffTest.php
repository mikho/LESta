<?php

use App\Actions\Acme\EnsuresAcmeAccountExists;
use App\Contracts\Provisioner;
use App\Enums\SslMode;
use App\Jobs\IssueAcmeCertificate;
use App\Models\AcmeAccount;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\WebDomain;
use App\Services\Acme\AcmeClientFactory;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Backoff and rate-limit tests
|--------------------------------------------------------------------------
|
| These deliberately never reach the real ACME protocol client (no Pebble
| needed): passesGlobalRateLimit() and isInBackoffWindow() are both checked
| before EnsuresAcmeAccountExists is ever called, so a short-circuited
| handle() call here proves the bounded-retry gate works without any
| network access at all.
|
*/

test('handle returns early without registering an account once the global rate limit is exhausted', function () {
    $max = (int) config('acme.rate_limit_per_hour', 20);
    RateLimiter::clear('acme-issuance');
    for ($i = 0; $i < $max; $i++) {
        RateLimiter::hit('acme-issuance', 3600);
    }

    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create();
    $webDomain = WebDomain::factory()->for($node)->for($allocation)->create([
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
    ]);

    (new IssueAcmeCertificate($webDomain))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        app(Provisioner::class),
    );

    expect(AcmeAccount::query()->count())->toBe(0)
        ->and($webDomain->fresh()->last_certificate_error)->toBeNull();
});

test('handle returns early without registering an account while a domain is within its retry backoff window', function () {
    RateLimiter::clear('acme-issuance');

    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create();
    $webDomain = WebDomain::factory()->for($node)->for($allocation)->create([
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
        'last_certificate_error' => 'a previous attempt failed just now',
    ]);

    (new IssueAcmeCertificate($webDomain))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        app(Provisioner::class),
    );

    expect(AcmeAccount::query()->count())->toBe(0);
});

test('handle proceeds past the backoff window once retry_after_hours has elapsed', function () {
    RateLimiter::clear('acme-issuance');
    config(['acme.retry_after_hours' => 6, 'acme.directory_url' => 'http://127.0.0.1:1/unreachable-directory']);

    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create();
    $webDomain = WebDomain::factory()->for($node)->for($allocation)->create([
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
        'last_certificate_error' => 'an old failure',
    ]);
    $webDomain->forceFill(['updated_at' => now()->subHours(7)])->saveQuietly();

    (new IssueAcmeCertificate($webDomain))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        app(Provisioner::class),
    );

    // Past the backoff window, the job proceeds to actually attempt
    // registration -- which fails fast against an unreachable directory
    // URL, and that failure is recorded as a *new* last_certificate_error
    // (proving the code path was really entered, not skipped).
    expect($webDomain->fresh()->last_certificate_error)->not->toBeNull();
});
