<?php

namespace App\Services\Acme;

use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\SecureHttpClientFactory;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Ssl\KeyPair;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\PrivateKey;
use AcmePhp\Ssl\Signer\DataSigner;
use App\Models\AcmeAccount;
use GuzzleHttp\Client;

/**
 * Builds a real AcmePhp\Core\AcmeClient wired to an ACME account's own key
 * pair. The account's private key material never leaves this process except
 * as the signing material for outbound HTTPS requests AcmeClient itself
 * makes directly to the ACME Certificate Authority; it is never included in
 * any ProvisioningOperation payload and never sent to any node.
 */
class AcmeClientFactory
{
    /**
     * Build a client for an already-persisted AcmeAccount, reconstructing its
     * key pair from the encrypted account_key column.
     */
    public function forAccount(AcmeAccount $account): AcmeClient
    {
        return $this->forKeyPair($this->keyPairFromPem($account->account_key), $account->directory_url);
    }

    /**
     * Build a client for a freshly-generated key pair that has not been
     * persisted anywhere yet. Used by EnsuresAcmeAccountExists to register a
     * brand-new account with the CA *before* persisting its key, so a
     * registration failure never leaves behind an AcmeAccount row whose key
     * the CA never actually accepted.
     */
    public function forKeyPair(KeyPair $keyPair, string $directoryUrl): AcmeClient
    {
        // 'verify' defaults to true (Guzzle's own default: verify against
        // the system's real CA bundle, exactly what production talking to a
        // real Let's Encrypt directory needs). acme.ca_bundle lets a test
        // trust one specific, additional self-signed CA (a real disposable
        // Pebble instance's own certificate) without ever disabling
        // verification outright -- there is deliberately no "just don't
        // verify" toggle here, to keep that footgun out of reach of a
        // production .env.
        $caBundle = config('acme.ca_bundle');

        $httpClientFactory = new SecureHttpClientFactory(
            new Client(['verify' => $caBundle ?: true]),
            new Base64SafeEncoder,
            new KeyParser,
            new DataSigner,
            new ServerErrorHandler,
        );

        return new AcmeClient($httpClientFactory->createSecureHttpClient($keyPair), $directoryUrl);
    }

    /**
     * Reconstruct a KeyPair from a private key's own PEM material: the
     * public key is always fully recoverable from a private key, so only the
     * private key ever needs to be persisted.
     */
    public function keyPairFromPem(string $pem): KeyPair
    {
        $privateKey = new PrivateKey($pem);

        return new KeyPair($privateKey->getPublicKey(), $privateKey);
    }
}
