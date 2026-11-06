/**
 * Ed25519 keys for signing agent upgrades, entirely in the browser (WebCrypto). The private key is
 * made here, kept by the administrator and read back here to sign; it is never sent to the panel.
 */

const PREFIX = 'lesta-agent-upgrade/v1';

const toBase64 = (bytes: ArrayBuffer | Uint8Array): string => {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);

    return btoa(String.fromCharCode(...view));
};

const fromBase64 = (value: string): Uint8Array<ArrayBuffer> => {
    const binary = atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
};

const fromBase64Url = (value: string): string =>
    value.replace(/-/g, '+').replace(/_/g, '/') +
    '='.repeat((4 - (value.length % 4)) % 4);

/** The exact bytes the node and the panel expect signed (AgentUpgradeMessage, agentupgrade.SignedMessage). */
export const upgradeMessage = (
    nodeUuid: string,
    version: string,
    sha256: string,
    expiresAt: number,
    nextPublicKey: string,
): Uint8Array<ArrayBuffer> =>
    new TextEncoder().encode(
        [
            PREFIX,
            nodeUuid,
            version,
            sha256,
            String(expiresAt),
            nextPublicKey,
        ].join('\n'),
    );

export const supportsEd25519 = async (): Promise<boolean> => {
    try {
        await crypto.subtle.generateKey({ name: 'Ed25519' }, false, ['sign']);

        return true;
    } catch {
        return false;
    }
};

const pemWrap = (der: ArrayBuffer): string =>
    '-----BEGIN PRIVATE KEY-----\n' +
    (toBase64(der).match(/.{1,64}/g) ?? []).join('\n') +
    '\n-----END PRIVATE KEY-----\n';

const pemUnwrap = (pem: string): ArrayBuffer => {
    const body = pem
        .replace(/-----(BEGIN|END) PRIVATE KEY-----/g, '')
        .replace(/\s+/g, '');

    return fromBase64(body).buffer;
};

/** A new key pair: the public key as base64 of its 32 raw bytes, the private key as a PKCS#8 PEM. */
export const generateUpgradeKeyPair = async (): Promise<{
    publicKey: string;
    privateKeyPem: string;
}> => {
    const pair = (await crypto.subtle.generateKey({ name: 'Ed25519' }, true, [
        'sign',
        'verify',
    ])) as CryptoKeyPair;

    return {
        publicKey: toBase64(
            await crypto.subtle.exportKey('raw', pair.publicKey),
        ),
        privateKeyPem: pemWrap(
            await crypto.subtle.exportKey('pkcs8', pair.privateKey),
        ),
    };
};

/** Reads a PEM private key, and the public key (base64) it belongs to. */
export const importUpgradePrivateKey = async (
    pem: string,
): Promise<{ key: CryptoKey; publicKey: string }> => {
    const key = await crypto.subtle.importKey(
        'pkcs8',
        pemUnwrap(pem),
        { name: 'Ed25519' },
        true,
        ['sign'],
    );

    const jwk = await crypto.subtle.exportKey('jwk', key);

    if (typeof jwk.x !== 'string') {
        throw new Error('The key has no public half.');
    }

    return { key, publicKey: fromBase64Url(jwk.x) };
};

export const signUpgrade = async (
    key: CryptoKey,
    message: Uint8Array<ArrayBuffer>,
): Promise<string> =>
    toBase64(await crypto.subtle.sign({ name: 'Ed25519' }, key, message));
