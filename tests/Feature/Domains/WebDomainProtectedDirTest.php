<?php

use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use App\Models\WebDomainProtectedDir;
use App\Models\WebDomainProtectedDirUser;

function domainForProtectedDirs(): array
{
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    return [$webDomain, $owner];
}

test('hashing produces a SHA-512 crypt hash that verifies and that the node accepts', function () {
    $hash = WebDomainProtectedDirUser::hashPassword('correct horse battery');

    expect($hash)->toMatch(WebDomainProtectedDirUser::HASH_PATTERN)
        ->and(hash_equals($hash, crypt('correct horse battery', $hash)))->toBeTrue()
        ->and(hash_equals($hash, crypt('wrong password', $hash)))->toBeFalse()
        ->and(WebDomainProtectedDirUser::hashPassword('correct horse battery'))->not->toBe($hash);
});

test('an owner protects a directory with a first login, only the hash is stored, and the vhost is re-rendered', function () {
    [$webDomain, $owner] = domainForProtectedDirs();

    $this->actingAs($owner)
        ->post(route('domains.protected-dirs.store', $webDomain), ['path' => 'members/', 'realm' => 'Members area', 'username' => 'alice', 'password' => 'a-good-long-password'])
        ->assertSessionHasNoErrors();

    $directory = $webDomain->protectedDirs()->with('users')->sole();
    $user = $directory->users->sole();

    expect($directory->path)->toBe('/members')
        ->and($user->username)->toBe('alice')
        ->and($user->password_hash)->not->toContain('a-good-long-password')
        ->and($user->password_hash)->toMatch(WebDomainProtectedDirUser::HASH_PATTERN)
        ->and(AuditEvent::where('action', 'web_domain.protected_dir_created')->count())->toBe(1);

    $payload = ProvisioningOperation::where('capability', 'web.nginx.v1')->latest('id')->first()->payload;

    expect($payload['protected_dirs'])->toBe([['path' => '/members', 'realm' => 'Members area', 'users' => [['username' => 'alice', 'hash' => $user->password_hash]]]]);
});

test('logins can be added, their password changed, and removed, but never the last one', function () {
    [$webDomain, $owner] = domainForProtectedDirs();
    $directory = WebDomainProtectedDir::factory()->for($webDomain)->create(['path' => '/members']);
    $alice = WebDomainProtectedDirUser::factory()->for($directory, 'directory')->create(['username' => 'alice']);
    $oldHash = $alice->password_hash;

    $this->actingAs($owner)->post(route('domains.protected-dirs.users.store', [$webDomain, $directory]), ['username' => 'bob', 'password' => 'another-long-password'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('domains.protected-dirs.users.store', [$webDomain, $directory]), ['username' => 'alice', 'password' => 'a-brand-new-password'])->assertSessionHasNoErrors();

    expect($directory->users()->count())->toBe(2)
        ->and($alice->fresh()->password_hash)->not->toBe($oldHash)
        ->and(AuditEvent::where('action', 'web_domain.protected_dir_user_password_changed')->count())->toBe(1);

    $bob = $directory->users()->where('username', 'bob')->sole();

    $this->actingAs($owner)->delete(route('domains.protected-dirs.users.destroy', [$webDomain, $directory, $bob]))->assertSessionHasNoErrors();
    $this->actingAs($owner)->delete(route('domains.protected-dirs.users.destroy', [$webDomain, $directory, $alice]))->assertSessionHasErrors('username');

    expect($directory->users()->count())->toBe(1);
});

test('removing the protection removes the directory and its logins and re-renders', function () {
    [$webDomain, $owner] = domainForProtectedDirs();
    $directory = WebDomainProtectedDir::factory()->for($webDomain)->create();
    WebDomainProtectedDirUser::factory()->for($directory, 'directory')->create();

    $this->actingAs($owner)->delete(route('domains.protected-dirs.destroy', [$webDomain, $directory]))->assertRedirect();

    expect(WebDomainProtectedDir::count())->toBe(0)
        ->and(WebDomainProtectedDirUser::count())->toBe(0)
        ->and(ProvisioningOperation::where('capability', 'web.nginx.v1')->latest('id')->first()->payload['protected_dirs'])->toBe([]);
});

test('unsafe or invalid input is refused', function (array $input) {
    [$webDomain, $owner] = domainForProtectedDirs();

    $this->actingAs($owner)
        ->post(route('domains.protected-dirs.store', $webDomain), $input + ['path' => '/members', 'realm' => 'Members', 'username' => 'alice', 'password' => 'a-good-long-password'])
        ->assertSessionHasErrors();

    expect($webDomain->protectedDirs()->count())->toBe(0);
})->with([
    'config injection in the path' => [['path' => '/a; return 200']],
    'path traversal' => [['path' => '/a/../b']],
    'double slash' => [['path' => '/a//b']],
    'certificate challenge path' => [['path' => '/.well-known/acme-challenge']],
    'the health path' => [['path' => '/__lesta-health__']],
    'quote in the realm' => [['realm' => "Members';}"]],
    'colon in the username' => [['username' => 'a:b']],
    'short password' => [['password' => 'short']],
    'no password' => [['password' => '']],
]);

test('a directory can only be protected once', function () {
    [$webDomain, $owner] = domainForProtectedDirs();
    WebDomainProtectedDir::factory()->for($webDomain)->create(['path' => '/members']);

    $this->actingAs($owner)
        ->post(route('domains.protected-dirs.store', $webDomain), ['path' => '/members/', 'realm' => 'Members', 'username' => 'alice', 'password' => 'a-good-long-password'])
        ->assertSessionHasErrors('path');
});

test('only an owner of the domain can change protection, and ids from another domain are not honoured', function () {
    [$webDomain, $owner] = domainForProtectedDirs();
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;
    [, $stranger] = domainForProtectedDirs();
    $directory = WebDomainProtectedDir::factory()->for($webDomain)->create();
    $user = WebDomainProtectedDirUser::factory()->for($directory, 'directory')->create();
    $other = WebDomain::factory()->for($webDomain->account)->for($webDomain->node)->create();
    $foreign = WebDomainProtectedDir::factory()->for($other)->create();

    $input = ['path' => '/x', 'realm' => 'R', 'username' => 'alice', 'password' => 'a-good-long-password'];

    $this->actingAs($member)->post(route('domains.protected-dirs.store', $webDomain), $input)->assertForbidden();
    $this->actingAs($stranger)->post(route('domains.protected-dirs.store', $webDomain), $input)->assertForbidden();
    $this->actingAs($stranger)->delete(route('domains.protected-dirs.destroy', [$webDomain, $directory]))->assertForbidden();
    $this->actingAs($owner)->delete(route('domains.protected-dirs.destroy', [$webDomain, $foreign]))->assertNotFound();
    $this->actingAs($owner)->delete(route('domains.protected-dirs.users.destroy', [$webDomain, $foreign, $user]))->assertNotFound();

    expect($webDomain->protectedDirs()->count())->toBe(1)
        ->and($other->protectedDirs()->count())->toBe(1);
});

test('the edit page lists directories and logins but never a hash', function () {
    [$webDomain, $owner] = domainForProtectedDirs();
    $directory = WebDomainProtectedDir::factory()->for($webDomain)->create(['path' => '/members']);
    $user = WebDomainProtectedDirUser::factory()->for($directory, 'directory')->create(['username' => 'alice']);

    $response = $this->actingAs($owner)->get(route('domains.edit', $webDomain));

    $response->assertInertia(fn ($page) => $page->has('protectedDirs', 1)->where('protectedDirs.0.path', '/members')->where('protectedDirs.0.users.0.username', 'alice'));

    expect($response->getContent())->not->toContain($user->password_hash);
});
