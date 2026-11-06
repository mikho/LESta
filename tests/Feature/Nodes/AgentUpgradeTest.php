<?php

use App\Enums\ProvisioningStatus;
use App\Models\AgentUpgradeKey;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeAgentUpgrade;
use App\Models\ProvisioningOperation;
use App\Services\AgentUpgrade\AgentRelease;
use App\Services\AgentUpgrade\AgentUpgradeMessage;

/**
 * @return array{0: string, 1: string} a public key (base64) and its secret key
 */
function upgradeKeypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [base64_encode(sodium_crypto_sign_publickey($pair)), sodium_crypto_sign_secretkey($pair)];
}

/**
 * A node that trusts $publicKey and runs an older agent than the release this checkout carries.
 */
function upgradeableNode(string $publicKey): Node
{
    $key = AgentUpgradeKey::factory()->create(['public_key' => $publicKey]);

    return tap(Node::factory()->create(['agent_version' => '1.0.1']), fn (Node $node) => $node->forceFill(['upgrade_key_id' => $key->id])->save());
}

/**
 * The request an administrator's browser would send.
 *
 * @return array<string, mixed>
 */
function signedUpgrade(Node $node, string $secretKey, ?string $nextKey = null, ?int $expiresAt = null): array
{
    $release = AgentRelease::current();
    $expiresAt ??= now()->addHour()->getTimestamp();
    $message = AgentUpgradeMessage::build($node->uuid, $release->version, $release->sha256, $expiresAt, $nextKey ?? '');

    return [
        'version' => $release->version,
        'sha256' => $release->sha256,
        'expires_at' => $expiresAt,
        'signature' => base64_encode(sodium_crypto_sign_detached($message, $secretKey)),
    ];
}

test('this checkout carries a verified agent binary', function () {
    expect(AgentRelease::current())->not->toBeNull();
});

test('a valid signature queues an upgrade that carries the signature and a one-time download', function () {
    [$public, $secret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret))->assertRedirect()->assertSessionHasNoErrors();

    $upgrade = NodeAgentUpgrade::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_id', $upgrade->id)->where('provisionable_type', $upgrade->getMorphClass())->sole();
    $payload = $operation->payload;

    expect($operation->capability)->toBe('node.agent-upgrade.v1')
        ->and($operation->operation->value)->toBe('update')
        ->and($payload['node_uuid'])->toBe($node->uuid)
        ->and($payload['version'])->toBe(AgentRelease::current()->version)
        ->and($payload['download_url'])->toBe(route('agent.agent-binary'))
        ->and(NodeAgentUpgrade::hashToken($payload['download_token']))->toBe($upgrade->token_hash)
        ->and($upgrade->from_version)->toBe('1.0.1')
        ->and(AuditEvent::where('action', 'node.agent_upgrade')->exists())->toBeTrue();
});

test('a signature from a key the node does not trust is refused', function () {
    [$public] = upgradeKeypair();
    [, $otherSecret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $otherSecret))->assertSessionHasErrors('upgrade');

    expect(NodeAgentUpgrade::count())->toBe(0);
});

test('a request signed for another node, an expired one and one valid too long are refused', function () {
    [$public, $secret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $other = Node::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($other, $secret))->assertSessionHasErrors('upgrade');
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret, null, now()->subMinute()->getTimestamp()))->assertSessionHasErrors('upgrade');
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret, null, now()->addDays(3)->getTimestamp()))->assertSessionHasErrors('upgrade');

    expect(NodeAgentUpgrade::count())->toBe(0);
});

test('a node that runs the release already, has no key or has an upgrade under way is not upgradeable', function () {
    [$public, $secret] = upgradeKeypair();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $current = upgradeableNode($public);
    $current->forceFill(['agent_version' => AgentRelease::current()->version])->save();
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $current), signedUpgrade($current, $secret))->assertSessionHasErrors('upgrade');

    $keyless = Node::factory()->create(['agent_version' => '1.0.1']);
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $keyless), signedUpgrade($keyless, $secret))->assertSessionHasErrors('upgrade');

    [$busyPublic, $busySecret] = upgradeKeypair();
    $busy = upgradeableNode($busyPublic);

    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $busy), signedUpgrade($busy, $busySecret))->assertSessionHasNoErrors();
    ProvisioningOperation::query()->where('node_id', $busy->id)->update(['status' => ProvisioningStatus::Pending]);
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $busy), signedUpgrade($busy, $busySecret))->assertSessionHasErrors('upgrade');

    expect(NodeAgentUpgrade::count())->toBe(1);
});

test('someone who may not manage the node cannot upgrade it or read what to sign', function () {
    [$public, $secret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $member = Membership::factory()->member()->create()->user;

    $this->actingAs($member)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret))->assertForbidden();
    $this->actingAs($member)->getJson(route('nodes.agent-upgrade.show', $node))->assertForbidden();
});

test('the page for signing says what the release is and what the node trusts', function () {
    [$public] = upgradeKeypair();
    $node = upgradeableNode($public);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->getJson(route('nodes.agent-upgrade.show', $node))
        ->assertOk()
        ->assertJsonPath('node_uuid', $node->uuid)
        ->assertJsonPath('release.version', AgentRelease::current()->version)
        ->assertJsonPath('key.public_key', $public)
        ->assertJsonPath('upgradeable', true);
});

test('the node downloads the binary with its one-time token only while the request is valid', function () {
    [$public, $secret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret));

    $upgrade = NodeAgentUpgrade::query()->sole();
    $token = ProvisioningOperation::query()->where('provisionable_id', $upgrade->id)->where('provisionable_type', $upgrade->getMorphClass())->sole()->payload['download_token'];

    $this->get(route('agent.agent-binary'))->assertNotFound();
    $this->withToken(str_repeat('a', 64))->get(route('agent.agent-binary'))->assertNotFound();

    $response = $this->withToken($token)->get(route('agent.agent-binary'));
    $response->assertOk();
    expect(hash_file('sha256', $response->baseResponse->getFile()->getPathname()))->toBe($upgrade->sha256)
        ->and($upgrade->refresh()->downloaded_at)->not->toBeNull();

    $this->travel(2)->hours();
    $this->withToken($token)->get(route('agent.agent-binary'))->assertNotFound();
});

test('a rotated key becomes the node trusted key when the upgrade is reported applied', function () {
    [$public, $secret] = upgradeKeypair();
    $node = upgradeableNode($public);
    $next = AgentUpgradeKey::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)->post(route('nodes.agent-upgrade.store', $node), signedUpgrade($node, $secret, $next->public_key) + ['next_key' => $next->uuid])->assertSessionHasNoErrors();

    $upgrade = NodeAgentUpgrade::query()->sole();
    $operation = ProvisioningOperation::query()->where('provisionable_id', $upgrade->id)->where('provisionable_type', $upgrade->getMorphClass())->sole();

    // The test queue runs the fake provisioner at once, which reports the operation applied.
    expect($operation->payload['next_public_key'])->toBe($next->public_key)
        ->and($node->refresh()->upgrade_key_id)->toBe($next->id);
});

test('keys are registered, validated and only removed when no node trusts them', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    [$public] = upgradeKeypair();

    $this->actingAs($admin)->post(route('agent-upgrade-keys.store'), ['name' => 'Ops', 'public_key' => 'not-a-key'])->assertSessionHasErrors('public_key');
    $this->actingAs($admin)->post(route('agent-upgrade-keys.store'), ['name' => 'Ops', 'public_key' => $public])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('agent-upgrade-keys.store'), ['name' => 'Again', 'public_key' => $public])->assertSessionHasErrors('public_key');

    $key = AgentUpgradeKey::query()->sole();
    $node = Node::factory()->create();

    $this->actingAs($admin)->put(route('nodes.upgrade-key.update', $node), ['upgrade_key' => $key->uuid])->assertSessionHasNoErrors();
    expect($node->refresh()->upgrade_key_id)->toBe($key->id);

    $this->actingAs($admin)->delete(route('agent-upgrade-keys.destroy', $key))->assertSessionHasErrors('key');

    $this->actingAs($admin)->put(route('nodes.upgrade-key.update', $node), ['upgrade_key' => null]);
    $this->actingAs($admin)->delete(route('agent-upgrade-keys.destroy', $key))->assertSessionHasNoErrors();

    expect(AgentUpgradeKey::count())->toBe(0);
});

test('a node admin without nodes.update cannot manage keys', function () {
    $member = Membership::factory()->member()->create()->user;

    $this->actingAs($member)->post(route('agent-upgrade-keys.store'), ['name' => 'Ops', 'public_key' => upgradeKeypair()[0]])->assertForbidden();
});
