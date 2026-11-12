<?php

namespace App\Services\Provisioning;

use InvalidArgumentException;

/**
 * The request_digest of an operation envelope: sha256 over a canonical JSON serialization of the
 * fields that say what is asked for. The agent recomputes it (agent/internal/protocol/digest.go)
 * and rejects an operation whose digest differs, so a payload damaged on the way to the node is
 * never applied. Both sides are tested against docs/protocol/request-digest-vectors.json.
 *
 * Canonical form: an object with the keys capability, desired_state_version, operation, payload and
 * resource_id, written with every object's keys in byte order, no whitespace, "/" unescaped, and
 * every non-ASCII character as a lower-case \uXXXX escape. A number is written as PHP writes it.
 * The digest guards against corruption, not against forgery: it carries no secret.
 */
final class RequestDigest
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function for(string $capability, string $operation, string $resourceId, int $desiredStateVersion, array $payload): string
    {
        $canonical = self::canonicalJson([
            'capability' => $capability,
            'desired_state_version' => $desiredStateVersion,
            'operation' => $operation,
            'payload' => $payload,
            'resource_id' => $resourceId,
        ]);

        return 'sha256:'.hash('sha256', $canonical);
    }

    /**
     * Canonical JSON for a decoded JSON value: object keys in byte order at every depth.
     */
    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::canonicalJson(...), $value)).']';
            }

            $keys = array_map('strval', array_keys($value));
            sort($keys, SORT_STRING);

            $members = [];
            foreach ($keys as $key) {
                $members[] = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).':'.self::canonicalJson($value[$key]);
            }

            return '{'.implode(',', $members).'}';
        }

        if ($value === null || is_scalar($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        throw new InvalidArgumentException('A request digest covers only JSON values, got '.get_debug_type($value).'.');
    }
}
