<?php

namespace App\Actions\Acme;

use AcmePhp\Ssl\Generator\KeyPairGenerator;
use App\Models\AcmeAccount;
use App\Services\Acme\AcmeClientFactory;

/**
 * Lazily creates and registers the one system-wide AcmeAccount for the
 * currently-configured ACME directory, the first time any issuance job needs
 * one. The account key pair is generated and registered with the Certificate
 * Authority *before* anything is persisted: a registration failure (network
 * error, CA rejection) must never leave behind an AcmeAccount row whose key
 * the CA never actually accepted, since every subsequent request signed with
 * an unregistered key would fail forever after.
 */
class EnsuresAcmeAccountExists
{
    public function __construct(private readonly AcmeClientFactory $clientFactory) {}

    public function handle(): AcmeAccount
    {
        $directoryUrl = (string) config('acme.directory_url');

        $existing = AcmeAccount::query()->where('directory_url', $directoryUrl)->first();
        if ($existing !== null) {
            return $existing;
        }

        $keyPair = (new KeyPairGenerator)->generateKeyPair();
        $contactEmail = config('acme.contact_email');

        $this->clientFactory->forKeyPair($keyPair, $directoryUrl)->registerAccount($contactEmail ?: null);

        return AcmeAccount::query()->create([
            'contact_email' => $contactEmail,
            'directory_url' => $directoryUrl,
            'account_key' => $keyPair->getPrivateKey()->getPEM(),
        ]);
    }
}
