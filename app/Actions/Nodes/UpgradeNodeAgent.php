<?php

namespace App\Actions\Nodes;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AgentUpgradeKey;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeAgentUpgrade;
use App\Models\User;
use App\Services\AgentUpgrade\AgentRelease;
use App\Services\AgentUpgrade\AgentUpgradeMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asks a node to replace its agent binary with the one this control plane carries. The request
 * carries an administrator's Ed25519 signature, made in their browser with a private key the panel
 * never sees; the node applies it only if it verifies against the key the node itself trusts, so
 * the panel cannot make a node run code nobody signed. The panel checks the signature too, to give
 * a clear error early, but the node is the one that decides.
 */
class UpgradeNodeAgent
{
    /** The longest a signed request may stay valid, matching the node's own limit. */
    public const int MAX_LIFETIME_SECONDS = 86400;

    /**
     * What an administrator needs in order to sign: the release this panel carries, and what the
     * node trusts.
     *
     * @return array{node_uuid: string, current_version: string|null, release: array{version: string, sha256: string}|null, key: array{id: string, name: string, public_key: string}|null, upgradeable: bool, reason: string|null}
     */
    public function describe(User $actor, Node $node): array
    {
        Gate::forUser($actor)->authorize('update', $node);

        $release = AgentRelease::current();
        $key = $node->upgradeKey;
        $reason = match (true) {
            $release === null => Lang::string('This control plane carries no verified agent binary.'),
            $key === null => Lang::string('Record which upgrade key this node trusts first.'),
            $node->agent_version !== null && version_compare($release->version, $node->agent_version, '<=') => Lang::string('The node already runs this version or a newer one.'),
            $this->hasPendingUpgrade($node) => Lang::string('An upgrade of this node is already in progress.'),
            default => null,
        };

        return [
            'node_uuid' => $node->uuid,
            'current_version' => $node->agent_version,
            'release' => $release === null ? null : ['version' => $release->version, 'sha256' => $release->sha256],
            'key' => $key === null ? null : ['id' => $key->uuid, 'name' => $key->name, 'public_key' => $key->public_key],
            'upgradeable' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{version: string, sha256: string, expires_at: int, signature: string, next_key?: string|null}  $input
     */
    public function handle(User $actor, Node $node, array $input): NodeAgentUpgrade
    {
        Gate::forUser($actor)->authorize('update', $node);

        $details = $this->describe($actor, $node);

        $trustedKey = $node->upgradeKey;

        if (! $details['upgradeable'] || $trustedKey === null) {
            throw ValidationException::withMessages(['upgrade' => $details['reason'] ?? Lang::string('Record which upgrade key this node trusts first.')]);
        }

        $release = AgentRelease::current();

        if ($release === null || $input['version'] !== $release->version || $input['sha256'] !== $release->sha256) {
            throw ValidationException::withMessages(['upgrade' => __('The release changed while you were signing. Open the upgrade again.')]);
        }

        $now = now()->getTimestamp();

        if ($input['expires_at'] <= $now || $input['expires_at'] > $now + self::MAX_LIFETIME_SECONDS) {
            throw ValidationException::withMessages(['upgrade' => __('The signed request has expired or is valid for too long. Sign it again.')]);
        }

        $nextKey = isset($input['next_key']) && $input['next_key'] !== ''
            ? AgentUpgradeKey::query()->where('uuid', $input['next_key'])->firstOrFail()
            : null;

        $signature = base64_decode($input['signature'], true);
        $publicKey = base64_decode($trustedKey->public_key, true);
        $message = AgentUpgradeMessage::build($node->uuid, $release->version, $release->sha256, $input['expires_at'], $nextKey->public_key ?? '');

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $publicKey === false || $publicKey === ''
            || ! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw ValidationException::withMessages(['upgrade' => __('The signature does not match the key this node trusts. Use the private key that belongs to it.')]);
        }

        return DB::transaction(function () use ($actor, $node, $release, $input, $nextKey): NodeAgentUpgrade {
            $token = bin2hex(random_bytes(32));

            $upgrade = NodeAgentUpgrade::create([
                'node_id' => $node->id,
                'user_id' => $actor->id,
                'from_version' => $node->agent_version,
                'to_version' => $release->version,
                'sha256' => $release->sha256,
                'next_key_id' => $nextKey?->id,
                'token_hash' => NodeAgentUpgrade::hashToken($token),
                'expires_at' => now()->setTimestamp($input['expires_at']),
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::record($upgrade, 'node.agent_upgrade', $actor, $correlationId);

            app(RecordsProvisioningOperation::class)->record(
                $upgrade,
                NodeAgentUpgrade::CAPABILITY,
                ProvisioningVerb::Update,
                [
                    'version' => $release->version,
                    'sha256' => $release->sha256,
                    'node_uuid' => $node->uuid,
                    'expires_at' => $input['expires_at'],
                    'next_public_key' => $nextKey->public_key ?? '',
                    'signature' => $input['signature'],
                    'download_url' => route('agent.agent-binary'),
                    'download_token' => $token,
                ],
                $correlationId,
            );

            return $upgrade;
        });
    }

    private function hasPendingUpgrade(Node $node): bool
    {
        return NodeAgentUpgrade::query()
            ->where('node_id', $node->id)
            ->whereHas('latestProvisioningOperation', fn ($query) => $query->whereIn('status', [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched]))
            ->exists();
    }
}
