<?php

use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use App\Models\User;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Builds a structurally real "ssh-ed25519" authorized_keys line: the actual RFC 4251 wire
 * format (a 4-byte big-endian length-prefixed "ssh-ed25519" string, then a 4-byte
 * length-prefixed 32-byte key), base64-encoded -- not a made-up string -- so
 * App\Rules\ValidSshPublicKey's own structural decode genuinely passes it.
 */
function validSshPublicKeyLine(): string
{
    $type = 'ssh-ed25519';
    $blob = pack('N', strlen($type)).$type.pack('N', 32).random_bytes(32);

    return "{$type} ".base64_encode($blob).' test@example.com';
}

function actingAsOwnerWithWebCapableAccount(): array
{
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    return [$account, $owner, $node];
}

test('the index page lists the account web domains', function () {
    [$account, $owner] = actingAsOwnerWithWebCapableAccount();
    WebDomain::factory()->for($account)->create(['domain' => 'example.com']);

    $this->actingAs($owner)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/index')
            ->has('webDomains.data', 1)
        );
});

test('a guest is redirected to login', function () {
    $this->get(route('domains.index'))->assertRedirect(route('login'));
});

test('a user with no account membership sees the index with no data instead of a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/index')
            ->where('webDomains', null)
        );
});

test('a user with no account membership is redirected away from the create page, not 404d', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('domains.create'))
        ->assertRedirect(route('domains.index'));
});

test('storing a web domain redirects to the index with a flash message', function () {
    [$account, $owner] = actingAsOwnerWithWebCapableAccount();

    $this->actingAs($owner)
        ->post(route('domains.store'), ['domain' => 'example.com'])
        ->assertRedirect(route('domains.index'));

    expect(WebDomain::where('account_id', $account->id)->where('domain', 'example.com')->exists())->toBeTrue();
});

test('storing a web domain over quota returns a validation error instead of a 500', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner)
        ->post(route('domains.store'), ['domain' => 'example.com'])
        ->assertSessionHasErrors('domain');
});

test('a non-owner member is forbidden from the create page', function () {
    $package = Package::factory()->withLimit('web_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)->get(route('domains.create'))->assertForbidden();
});

test('updating a web domain redirects back to the edit page', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->put(route('domains.update', $webDomain), ['domain' => 'updated.example.com'])
        ->assertRedirect(route('domains.edit', $webDomain));
});

test('suspending a web domain redirects back', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('domains.index'))
        ->post(route('domains.suspend', $webDomain))
        ->assertRedirect(route('domains.index'));

    expect($webDomain->refresh()->isSuspended())->toBeTrue();
});

test('destroying a web domain redirects to the index', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->delete(route('domains.destroy', $webDomain))
        ->assertRedirect(route('domains.index'));

    expect(WebDomain::find($webDomain->id))->toBeNull();
});

test('the edit page backfills a missing account node identity and reports its sftp username', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    expect(AccountNodeIdentity::query()->where('account_id', $account->id)->where('node_id', $node->id)->exists())->toBeFalse();

    $this->actingAs($owner)
        ->get(route('domains.edit', $webDomain))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/edit')
            ->where('sftp.username', 'lesta-t'.$account->id)
            ->where('sftp.hasSshPublicKey', false)
        );

    expect(AccountNodeIdentity::query()->where('account_id', $account->id)->where('node_id', $node->id)->exists())->toBeTrue();
});

test('an owner can set their own account\'s ssh public key for a web domain\'s node', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $key = validSshPublicKeyLine();

    $this->actingAs($owner)
        ->put(route('domains.update-ssh-key', $webDomain), ['ssh_public_key' => $key])
        ->assertRedirect(route('domains.edit', $webDomain));

    $identity = AccountNodeIdentity::query()->where('account_id', $account->id)->where('node_id', $node->id)->firstOrFail();

    expect($identity->ssh_public_key)->toBe($key);

    $updateOperation = ProvisioningOperation::query()
        ->where('provisionable_type', $identity->getMorphClass())
        ->where('provisionable_id', $identity->id)
        ->where('operation', 'update')
        ->first();

    expect($updateOperation)->not->toBeNull()
        ->and($updateOperation->capability)->toBe('system.account-identity.v1')
        ->and($updateOperation->payload['ssh_public_key'])->toBe($key);
});

test('submitting an empty ssh public key clears an existing one', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $identity = AccountNodeIdentity::factory()->for($account)->for($node)->create(['ssh_public_key' => validSshPublicKeyLine()]);

    $this->actingAs($owner)
        ->put(route('domains.update-ssh-key', $webDomain), ['ssh_public_key' => ''])
        ->assertRedirect(route('domains.edit', $webDomain));

    expect($identity->refresh()->ssh_public_key)->toBeNull();
});

test('an invalid ssh public key is rejected with a validation error', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    $this->actingAs($owner)
        ->from(route('domains.edit', $webDomain))
        ->put(route('domains.update-ssh-key', $webDomain), ['ssh_public_key' => 'not-a-real-key'])
        ->assertRedirect(route('domains.edit', $webDomain))
        ->assertSessionHasErrors('ssh_public_key');
});

test('an ssh key whose declared type does not match its own encoded blob is rejected', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();

    // Real base64, decodes cleanly, but claims "ssh-rsa" on the command line while the encoded
    // blob's own wire-format type string still says "ssh-ed25519" -- exactly the structural
    // mismatch App\Rules\ValidSshPublicKey exists to catch, not just "is this valid base64".
    $genuineEd25519 = validSshPublicKeyLine();
    [, $base64] = explode(' ', $genuineEd25519, 3);
    $spoofed = "ssh-rsa {$base64} test@example.com";

    $this->actingAs($owner)
        ->from(route('domains.edit', $webDomain))
        ->put(route('domains.update-ssh-key', $webDomain), ['ssh_public_key' => $spoofed])
        ->assertRedirect(route('domains.edit', $webDomain))
        ->assertSessionHasErrors('ssh_public_key');
});

test('a non-owner member cannot update the account\'s ssh public key', function () {
    [$account, $owner, $node] = actingAsOwnerWithWebCapableAccount();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    $this->actingAs($member)
        ->put(route('domains.update-ssh-key', $webDomain), ['ssh_public_key' => validSshPublicKeyLine()])
        ->assertForbidden();
});
