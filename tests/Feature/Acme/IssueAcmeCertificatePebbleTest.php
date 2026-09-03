<?php

use App\Actions\Acme\EnsuresAcmeAccountExists;
use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Enums\SslMode;
use App\Jobs\IssueAcmeCertificate;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Services\Acme\AcmeClientFactory;
use App\Services\Provisioning\ProvisioningResult;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Real Pebble-backed IssueAcmeCertificate tests
|--------------------------------------------------------------------------
|
| These tests drive the REAL App\Jobs\IssueAcmeCertificate against a real,
| disposable Pebble instance (github.com/letsencrypt/pebble/v2, `go install`ed
| on PATH -- never a live third-party service), proving the real
| acmephp/core-based protocol client genuinely works, not just that its call
| sequence type-checks.
|
| Requires `pebble@v2.5.0` specifically on PATH, NOT `@latest`: newer Pebble
| versions advertise additional challenge types (dns-account-01,
| dns-persist-01) whose JSON response intentionally omits a `token` field;
| acmephp/core 2.1.0 accesses `$response['token']` unconditionally over every
| offered challenge and throws an "Undefined array key" error instead of
| skipping the type it doesn't understand. Confirmed directly: `@latest`
| makes both tests in this file fail with exactly that error, not skip --
| there is no way to detect this from `pebble -version` at runtime (it
| reports a bare "dev" string, not a real version), so the fix is this pinned
| install version, not a runtime check. The agent module's own Go-side
| Pebble test (agent/acme_end_to_end_test.go) is unaffected: it decodes JSON
| into plain Go structs, which tolerate an absent field, so it runs fine
| against any Pebble version, `@latest` included.
|
| Two real processes are started once for this whole file (beforeAll/
| afterAll, since spinning them up per-test would be needless overhead for
| OS-level fixtures independent of RefreshDatabase's per-test transaction):
|
|   - A real disposable Pebble instance, with a self-signed certificate
|     generated on the fly via PHP's own openssl_* functions (never fetching
|     or vendoring a real Let's Encrypt certificate, matching the agent
|     module's own "generate on the fly" precedent), and
|     config('acme.ca_bundle') pointed at it -- AcmeClientFactory's own
|     Guzzle client trusts that one specific CA, on top of (never instead
|     of) the system's real CA bundle.
|   - A real disposable nginx instance, dual-listening on 127.0.0.1 AND ::1
|     on Pebble's own configured httpPort (so "localhost" resolves correctly
|     regardless of which loopback address this machine's resolver returns
|     first for it -- the exact same non-determinism the agent module's own
|     Pebble-backed Go test works around by using a real disposable `named`
|     instead; dual-listening is the simpler fix available here since this
|     file has no equivalent real DNS server harness of its own).
|
| A RecordingTestProvisioner (defined in this file) stands in for
| App\Contracts\Provisioner's real Go-agent-backed implementation (which
| doesn't exist yet -- config('provisioning.driver') only ever supports
| 'fake' today): for tls.acme.v1 http01_challenge operations it actually
| writes/removes the real challenge file nginx serves, mirroring
| internal/capability/acme's own file-writing exactly (in miniature, in
| PHP); every other capability it just records and reports Applied. This
| matches the plan's own "FakeProvisioner swapped for a small test double
| that actually writes challenge files" guidance precisely.
|
*/

/**
 * Generates a self-signed EC certificate for 127.0.0.1 (an IP SAN, not a DNS
 * SAN: Pebble's own directory is always dialed by IP in this file, never by
 * hostname) entirely on the fly via PHP's own openssl_* functions.
 *
 * @return array{0: string, 1: string} [certPath, keyPath]
 */
function acmeGenerateSelfSignedCert(string $dir): array
{
    $confPath = $dir.'/openssl.cnf';
    file_put_contents($confPath, <<<'CNF'
        [req]
        distinguished_name = req_distinguished_name
        x509_extensions = v3_req
        prompt = no

        [req_distinguished_name]
        CN = 127.0.0.1

        [v3_req]
        subjectAltName = IP:127.0.0.1
        basicConstraints = critical,CA:true
        keyUsage = critical,digitalSignature,keyCertSign
        CNF);

    $configArgs = [
        'config' => $confPath,
        'x509_extensions' => 'v3_req',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];

    $privateKey = openssl_pkey_new($configArgs);
    if ($privateKey === false) {
        throw new RuntimeException('generating pebble test certificate key: '.openssl_error_string());
    }

    $csr = openssl_csr_new(['CN' => '127.0.0.1'], $privateKey, $configArgs);
    if ($csr === false) {
        throw new RuntimeException('generating pebble test certificate CSR: '.openssl_error_string());
    }

    $cert = openssl_csr_sign($csr, null, $privateKey, 365, $configArgs);
    if ($cert === false) {
        throw new RuntimeException('signing pebble test certificate: '.openssl_error_string());
    }

    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($privateKey, $keyPem);

    $certPath = $dir.'/pebble-cert.pem';
    $keyPath = $dir.'/pebble-key.pem';
    file_put_contents($certPath, $certPem);
    file_put_contents($keyPath, $keyPem);

    return [$certPath, $keyPath];
}

function acmeFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException("finding a free port: {$errstr}");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) explode(':', $name)[1];
}

/**
 * Holds the shared, real, disposable Pebble + nginx fixtures for this whole
 * file. A plain static-state class, not a test itself: Pest's own
 * beforeAll/afterAll hooks drive it.
 */
class AcmePebbleHarness
{
    public static ?Process $pebble = null;

    public static ?string $prefix = null;

    public static string $directoryUrl = '';

    public static string $caBundlePath = '';

    public static string $challengeDir = '';

    public static int $nginxPort = 0;

    public static bool $available = false;

    public static string $unavailableReason = '';

    public static function start(): void
    {
        if (! self::binaryExists('pebble')) {
            self::$unavailableReason = 'pebble is not installed on PATH (try: go install github.com/letsencrypt/pebble/v2/cmd/pebble@v2.5.0 -- see this file\'s own top comment for why that exact version, not @latest, is required)';

            return;
        }
        if (! self::binaryExists('nginx')) {
            self::$unavailableReason = 'nginx is not installed on PATH';

            return;
        }

        $prefix = sys_get_temp_dir().'/lesta-acme-pebble-'.bin2hex(random_bytes(8));
        mkdir($prefix, 0755, true);
        self::$prefix = $prefix;
        self::$challengeDir = $prefix.'/acme-http-01';
        mkdir(self::$challengeDir, 0755, true);

        [$certPath, $keyPath] = acmeGenerateSelfSignedCert($prefix);
        self::$caBundlePath = $certPath;

        self::$nginxPort = acmeFreePort();
        self::startNginx($prefix, self::$nginxPort, self::$challengeDir);

        $dirPort = acmeFreePort();
        $tlsPort = acmeFreePort();

        $config = [
            'pebble' => [
                'listenAddress' => "127.0.0.1:{$dirPort}",
                'certificate' => $certPath,
                'privateKey' => $keyPath,
                'httpPort' => self::$nginxPort,
                'tlsPort' => $tlsPort,
                'ocspResponderURL' => '',
                'externalAccountBindingRequired' => false,
                'domainBlocklist' => [],
                'retryAfter' => ['authz' => 1, 'order' => 1],
                'keyAlgorithm' => 'ecdsa',
                'profiles' => [
                    'default' => ['description' => 'default', 'validityPeriod' => 7776000],
                ],
            ],
        ];

        $configPath = $prefix.'/pebble-config.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        self::$directoryUrl = "https://127.0.0.1:{$dirPort}/dir";

        self::$pebble = new Process(['pebble', '-config', $configPath]);
        self::$pebble->setTimeout(null);
        // Pebble deliberately injects chaos by default (undocumented until
        // you hit it): "reject 5% of good nonces" and "reuse authorizations
        // ~50% of the time", specifically to stress-test real ACME clients'
        // own retry/reuse handling. acmephp/core's own retry-on-badNonce
        // logic (SecureHttpClient::rawRequest) is unfortunately broken --
        // it resends the exact same already-signed request body rather than
        // re-signing with a fresh nonce, so a chaos-rejected nonce fails the
        // retry too -- which surfaced here as an intermittent (~1-in-3 test
        // runs) BadNonceServerException with no code change involved.
        // PEBBLE_WFE_NONCEREJECT=0/PEBBLE_AUTHZREUSE=0 make this file's own
        // runs deterministic; they do not touch the real ACME protocol
        // surface being tested (challenge validation, order finalization,
        // certificate download all still happen for real).
        // Symfony Process merges these on top of the inherited environment
        // (PATH etc.) by default; it does not replace it.
        self::$pebble->setEnv([
            'PEBBLE_WFE_NONCEREJECT' => '0',
            'PEBBLE_AUTHZREUSE' => '0',
        ]);
        self::$pebble->start();

        self::waitForPebbleReady();

        self::$available = true;
    }

    public static function stop(): void
    {
        if (self::$pebble !== null) {
            self::$pebble->stop(5);
        }

        if (self::$prefix !== null) {
            self::stopNginx(self::$prefix);
        }
    }

    private static function binaryExists(string $bin): bool
    {
        $result = Process::fromShellCommandline('command -v '.escapeshellarg($bin))->run();

        return $result === 0;
    }

    private static function startNginx(string $prefix, int $port, string $challengeDir): void
    {
        $logsDir = $prefix.'/nginx-logs';
        mkdir($logsDir, 0755, true);

        $confPath = $prefix.'/nginx.conf';
        $confBody = <<<CONF
            pid {$prefix}/nginx.pid;
            error_log {$logsDir}/error.log;
            events { worker_connections 64; }
            http {
                default_type application/octet-stream;
                access_log {$logsDir}/access.log;
                server {
                    listen 127.0.0.1:{$port};
                    listen [::1]:{$port};
                    server_name localhost;

                    location /.well-known/acme-challenge/ {
                        alias {$challengeDir}/;
                    }

                    location / {
                        return 404;
                    }
                }
            }
            CONF;

        file_put_contents($confPath, $confBody);

        $result = new Process(['nginx', '-p', $prefix, '-c', $confPath]);
        $result->run();
        if (! $result->isSuccessful()) {
            throw new RuntimeException('starting disposable nginx: '.$result->getErrorOutput());
        }
    }

    private static function stopNginx(string $prefix): void
    {
        $confPath = $prefix.'/nginx.conf';
        if (is_file($confPath)) {
            (new Process(['nginx', '-p', $prefix, '-c', $confPath, '-s', 'stop']))->run();
        }
    }

    private static function waitForPebbleReady(): void
    {
        $client = new Client(['verify' => self::$caBundlePath]);
        $deadline = microtime(true) + 10;
        $lastError = null;

        while (microtime(true) < $deadline) {
            try {
                $response = $client->get(self::$directoryUrl);
                if ($response->getStatusCode() === 200) {
                    return;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }

            usleep(100_000);
        }

        throw new RuntimeException('pebble never became ready at '.self::$directoryUrl.': '.($lastError?->getMessage() ?? 'unknown'));
    }
}

/**
 * Stands in for the real Go-agent-backed Provisioner (which doesn't exist
 * yet). For tls.acme.v1 http01_challenge operations, it actually writes/
 * removes the real challenge file nginx serves -- everything else is
 * recorded and reported Applied without any real side effect.
 */
class RecordingTestProvisioner implements Provisioner
{
    /** @var array<int, ProvisioningOperation> */
    public array $applied = [];

    public function apply(ProvisioningOperation $operation): ProvisioningResult
    {
        $this->applied[] = $operation;

        if ($operation->capability === 'tls.acme.v1') {
            $payload = $operation->payload;

            if (($payload['kind'] ?? null) === 'http01_challenge') {
                $path = AcmePebbleHarness::$challengeDir.'/'.$payload['token'];

                if ($operation->operation->value === 'delete') {
                    @unlink($path);
                } else {
                    file_put_contents($path, $payload['key_authorization']);
                }
            }
        }

        return new ProvisioningResult(
            status: ProvisioningStatus::Applied,
            observedStateVersion: $operation->desired_state_version,
            observedStateDigest: 'sha256:'.hash('sha256', 'test-double:'.$operation->idempotency_key),
            generationId: 'test-double-'.$operation->idempotency_key,
            errors: [],
            completedAt: now(),
        );
    }
}

beforeAll(function () {
    AcmePebbleHarness::start();
});

afterAll(function () {
    AcmePebbleHarness::stop();
});

beforeEach(function () {
    if (! AcmePebbleHarness::$available) {
        $this->markTestSkipped('Pebble/nginx harness unavailable: '.AcmePebbleHarness::$unavailableReason);
    }

    config([
        'acme.directory_url' => AcmePebbleHarness::$directoryUrl,
        'acme.ca_bundle' => AcmePebbleHarness::$caBundlePath,
        'acme.challenge_timeout' => 30,
        'acme.order_timeout' => 30,
        'acme.contact_email' => null,
    ]);

    $this->provisioner = new RecordingTestProvisioner;
    $this->app->instance(Provisioner::class, $this->provisioner);

    // The 'array' cache driver (config/cache.php's testing default) persists
    // for the whole PHP process, not per-test; without this, RateLimiter's
    // own 'acme-issuance' hit count would accumulate across every test in
    // this whole file (and every other Acme test file run in the same
    // process) and could eventually, spuriously, trip the global rate limit.
    RateLimiter::clear('acme-issuance');
});

test('IssueAcmeCertificate completes the full real HTTP-01 happy path against a real Pebble instance', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $allocation = IpAllocation::factory()->for($node)->create();

    $webDomain = WebDomain::factory()
        ->for($node)
        ->for($allocation)
        ->create([
            'domain' => 'localhost',
            'ssl_mode' => SslMode::LetsEncrypt,
            'certificate_issued_at' => null,
        ]);

    (new IssueAcmeCertificate($webDomain))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        $this->provisioner,
    );

    $webDomain->refresh();

    expect($webDomain->last_certificate_error)->toBeNull()
        ->and($webDomain->certificate_issued_at)->not->toBeNull()
        ->and($webDomain->certificate_expires_at)->not->toBeNull()
        ->and($webDomain->certificate_expires_at->isFuture())->toBeTrue();

    $certificateInstalls = collect($this->provisioner->applied)
        ->filter(fn (ProvisioningOperation $op) => $op->capability === 'tls.acme.v1' && ($op->payload['kind'] ?? null) === 'certificate');

    expect($certificateInstalls)->not->toBeEmpty();

    $installedPayload = $certificateInstalls->last()->payload;
    expect($installedPayload['domain'])->toBe('localhost')
        ->and($installedPayload['full_chain_pem'])->toContain('-----BEGIN CERTIFICATE-----')
        ->and($installedPayload['private_key_pem'])->toContain('PRIVATE KEY');

    // The challenge file must have been cleaned up again once issuance
    // finished (the `finally` block's own cleanup closure).
    $challengeFiles = glob(AcmePebbleHarness::$challengeDir.'/*');
    expect($challengeFiles)->toBe([]);
});

test('IssueAcmeCertificate leaves dns_records byte-identical before and after a DNS-01 attempt', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);
    $allocation = IpAllocation::factory()->for($node)->create();

    $webDomain = WebDomain::factory()
        ->for($node)
        ->for($allocation)
        ->create([
            'domain' => 'dns01-fidelity.acme-test.invalid',
            'ssl_mode' => SslMode::LetsEncrypt,
            'certificate_issued_at' => null,
        ]);

    $dnsZone = DnsZone::factory()->for($node)->create(['domain' => 'dns01-fidelity.acme-test.invalid']);
    DnsRecord::factory()->for($dnsZone)->create(['name' => '@', 'type' => 'A', 'value' => '203.0.113.5']);
    DnsRecord::factory()->for($dnsZone)->create(['name' => 'www', 'type' => 'CNAME', 'value' => 'dns01-fidelity.acme-test.invalid']);

    $recordsBefore = DnsRecord::query()->where('dns_zone_id', $dnsZone->id)->orderBy('id')->get()->toArray();

    (new IssueAcmeCertificate($webDomain, preferDns01: true))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        $this->provisioner,
    );

    $webDomain->refresh();

    // DNS-01 validation is EXPECTED to fail here: no real nameserver
    // anywhere actually answers for this domain (this file's own harness
    // deliberately has no DNS server at all -- see this test's own comment
    // above and the file's own top comment for why a full real-named-backed
    // DNS-01 proof is out of this specific test's scope). What this test
    // asserts is unaffected by that: the ephemeral TXT record round trip
    // (inject via a direct `update`, then restore via a second direct
    // `update`) must leave the real, persisted dns_records table exactly as
    // it was, regardless of whether the CA's own validation of that
    // ephemeral record ultimately succeeds.
    expect($webDomain->last_certificate_error)->not->toBeNull();

    $dnsOperations = collect($this->provisioner->applied)->filter(fn (ProvisioningOperation $op) => $op->capability === 'dns.bind9.v1');
    expect($dnsOperations)->not->toBeEmpty();

    $injectedOperation = $dnsOperations->first();
    $injectedRecordNames = collect($injectedOperation->payload['records'])->pluck('name');
    expect($injectedRecordNames)->toContain('_acme-challenge');

    if ($dnsOperations->count() > 1) {
        $restoredOperation = $dnsOperations->last();
        $restoredRecordNames = collect($restoredOperation->payload['records'])->pluck('name');
        expect($restoredRecordNames)->not->toContain('_acme-challenge');
    }

    $recordsAfter = DnsRecord::query()->where('dns_zone_id', $dnsZone->id)->orderBy('id')->get()->toArray();
    expect($recordsAfter)->toBe($recordsBefore);
});

test('IssueAcmeCertificate dispatches the update to web.apache.v1 when apache is the domain\'s resolved public capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    $allocation = IpAllocation::factory()->for($node)->create();

    $webDomain = WebDomain::factory()
        ->for($node)
        ->for($allocation)
        ->create([
            'domain' => 'localhost',
            'web_server' => 'apache',
            'ssl_mode' => SslMode::LetsEncrypt,
            'certificate_issued_at' => null,
        ]);

    (new IssueAcmeCertificate($webDomain))->handle(
        app(EnsuresAcmeAccountExists::class),
        app(AcmeClientFactory::class),
        $this->provisioner,
    );

    $webDomain->refresh();

    expect($webDomain->last_certificate_error)->toBeNull()
        ->and($webDomain->certificate_issued_at)->not->toBeNull();

    // Unlike every other test in this file (whose node has web.nginx.v1
    // active, so the public-capability update lands there), this node's
    // only web capability is web.apache.v1: the resolved public capability
    // is apache, so the post-issuance update must be recorded against it,
    // carrying the freshly issued certificate's own paths.
    $apacheUpdate = ProvisioningOperation::query()
        ->where('provisionable_type', $webDomain->getMorphClass())
        ->where('provisionable_id', $webDomain->id)
        ->where('capability', 'web.apache.v1')
        ->where('operation', 'update')
        ->latest('id')
        ->first();

    expect($apacheUpdate)->not->toBeNull()
        ->and($apacheUpdate->payload['ssl'])->toBe([
            'mode' => 'lets_encrypt',
            'certificate_path' => '/var/lib/lesta/acme/certs/localhost/fullchain.pem',
            'private_key_path' => '/var/lib/lesta/acme/certs/localhost/privkey.pem',
        ]);
});
