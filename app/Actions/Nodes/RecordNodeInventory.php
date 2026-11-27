<?php

namespace App\Actions\Nodes;

use App\Models\Node;

/**
 * Stores the disk usage per file system and the installed stack packages a heartbeat reports. Every
 * value comes from the node and is checked again here with the agent's own patterns: an entry that
 * fails is dropped alone, never the whole heartbeat. An older agent sends neither list and leaves the
 * last ones in place.
 */
class RecordNodeInventory
{
    public const MAX_FILESYSTEMS = 16;

    public const MAX_PACKAGES = 64;

    private const MOUNT_POINT = '/^\/[A-Za-z0-9._\/-]{0,254}$/';

    private const PACKAGE_NAME = '/^[a-z0-9][a-z0-9.+-]{0,63}$/';

    private const PACKAGE_VERSION = '/^[A-Za-z0-9.+~:-]{1,64}$/';

    /**
     * @param  array<string, mixed>  $heartbeat  the validated heartbeat body
     */
    public function handle(Node $node, array $heartbeat): void
    {
        if (! array_key_exists('filesystems', $heartbeat) && ! array_key_exists('packages', $heartbeat)) {
            return;
        }

        $node->forceFill([
            'filesystems' => $this->filesystems($heartbeat['filesystems'] ?? []),
            'packages' => $this->packages($heartbeat['packages'] ?? []),
            'inventory_reported_at' => now(),
        ])->save();
    }

    /**
     * @return list<array{mount_point: string, total_bytes: int, free_bytes: int, available_bytes: int}>
     */
    private function filesystems(mixed $reported): array
    {
        $filesystems = [];

        foreach (is_array($reported) ? $reported : [] as $entry) {
            $mountPoint = is_array($entry) ? ($entry['mount_point'] ?? null) : null;

            if (! is_string($mountPoint) || preg_match(self::MOUNT_POINT, $mountPoint) !== 1 || str_contains($mountPoint, '..') || isset($filesystems[$mountPoint])) {
                continue;
            }

            $total = $this->byteCount($entry['total_bytes'] ?? null);
            $free = $this->byteCount($entry['free_bytes'] ?? null);
            $available = $this->byteCount($entry['available_bytes'] ?? null);

            if ($total === null || $free === null || $available === null) {
                continue;
            }

            $filesystems[$mountPoint] = [
                'mount_point' => $mountPoint,
                'total_bytes' => $total,
                'free_bytes' => min($free, $total),
                'available_bytes' => min($available, $total),
            ];
        }

        ksort($filesystems, SORT_STRING);

        return array_slice(array_values($filesystems), 0, self::MAX_FILESYSTEMS);
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function packages(mixed $reported): array
    {
        $packages = [];

        foreach (is_array($reported) ? $reported : [] as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $version = is_array($entry) ? ($entry['version'] ?? null) : null;

            if (! is_string($name) || ! is_string($version) || isset($packages[$name])
                || preg_match(self::PACKAGE_NAME, $name) !== 1 || preg_match(self::PACKAGE_VERSION, $version) !== 1) {
                continue;
            }

            $packages[$name] = ['name' => $name, 'version' => $version];
        }

        ksort($packages, SORT_STRING);

        return array_slice(array_values($packages), 0, self::MAX_PACKAGES);
    }

    /**
     * A whole, non-negative byte count, capped at PHP_INT_MAX (JSON numbers above it decode as
     * floats). Anything else is null.
     */
    private function byteCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value) && is_finite($value) && $value >= 0 && floor($value) === $value) {
            return $value >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $value;
        }

        return null;
    }
}
