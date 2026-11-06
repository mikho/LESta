<?php

namespace App\Services\AgentUpgrade;

/**
 * The agent binary this control plane carries (agent/dist) and the version and sha256 its release
 * manifest declares for it. Both are read from this checkout; the binary is only offered when its
 * bytes match the manifest, so what an administrator signs is what nodes would download.
 */
final readonly class AgentRelease
{
    public const string ARTIFACT = 'lesta-agent-linux-amd64';

    public function __construct(
        public string $version,
        public string $sha256,
        public string $path,
    ) {}

    public static function current(): ?self
    {
        $manifest = json_decode((string) @file_get_contents(base_path('.install/services/node-health/manifest.json')), true);
        $path = base_path('agent/dist/'.self::ARTIFACT);

        if (! is_array($manifest) || ! is_file($path)) {
            return null;
        }

        foreach ($manifest['artifacts'] ?? [] as $artifact) {
            if (($artifact['name'] ?? null) !== self::ARTIFACT) {
                continue;
            }

            $version = $artifact['version'] ?? null;
            $sha256 = $artifact['sha256'] ?? null;

            if (! is_string($version) || ! is_string($sha256) || hash_file('sha256', $path) !== $sha256) {
                return null;
            }

            return new self($version, $sha256, $path);
        }

        return null;
    }
}
