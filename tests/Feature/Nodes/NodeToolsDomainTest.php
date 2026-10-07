<?php

use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Memberships\InviteMember;
use App\Actions\Nodes\EnsureNodeToolsDomain;
use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Validation\ValidationException;

function nodeReadyForToolsDomain(): Node
{
    $node = Node::factory()->create(['hostname' => 'node.example.test']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    return $node;
}

test('the node hostname web domain is created under a hidden platform account, once', function () {
    $node = nodeReadyForToolsDomain();

    $first = app(EnsureNodeToolsDomain::class)->handle($node);
    $second = app(EnsureNodeToolsDomain::class)->handle($node);

    expect($first->domain)->toBe('node.example.test')
        ->and($first->node_id)->toBe($node->id)
        ->and($second->id)->toBe($first->id)
        ->and(Account::where('is_platform', true)->count())->toBe(1)
        ->and($first->account->is_platform)->toBeTrue()
        ->and($first->account->package->is_active)->toBeFalse()
        ->and(WebDomain::where('domain', 'node.example.test')->count())->toBe(1);
});

test('the platform account is never offered as a package-less customer, a reseller, or in the accounts list', function () {
    $node = nodeReadyForToolsDomain();
    $platform = app(EnsureNodeToolsDomain::class)->handle($node)->account;
    $customer = Account::factory()->create(['name' => 'Acme']);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $this->actingAs($admin)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->has('accounts.data', 1)->where('accounts.data.0.name', 'Acme'));

    $this->actingAs($admin)
        ->get(route('accounts.index', ['search' => 'platform']))
        ->assertInertia(fn ($page) => $page->has('accounts.data', 0));

    $this->actingAs($admin)
        ->getJson(route('accounts.reseller-candidates', ['account' => $customer->public_id, 'q' => 'LESta']))
        ->assertOk()
        ->assertJsonMissing(['public_id' => $platform->public_id]);

    expect(Package::where('is_active', true)->where('name', EnsureNodeToolsDomain::PLATFORM_ACCOUNT_NAME)->exists())->toBeFalse();
});

test('the platform account cannot be suspended, deleted, or given members', function () {
    $platform = app(EnsureNodeToolsDomain::class)->handle(nodeReadyForToolsDomain())->account;
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $user = User::factory()->create();

    expect(fn () => app(SuspendAccount::class)->handle($admin, $platform))->toThrow(ValidationException::class)
        ->and(fn () => app(DeleteAccount::class)->handle($admin, $platform))->toThrow(ValidationException::class)
        ->and(fn () => app(InviteMember::class)->handle($admin, $platform, $user->name, $user->email, 'member'))->toThrow(ValidationException::class);

    expect(fn () => Membership::factory()->for($platform)->member()->create())->toThrow(LogicException::class);

    expect($platform->refresh()->isSuspended())->toBeFalse();
});

test('the artisan command creates the node hostname domain by uuid or by name', function () {
    $node = nodeReadyForToolsDomain();

    $this->artisan('lesta:nodes:ensure-tools-domain', ['node' => $node->name, '--ssl-mode' => 'manual'])
        ->expectsOutputToContain('node.example.test')
        ->assertSuccessful();

    $this->artisan('lesta:nodes:ensure-tools-domain', ['node' => $node->uuid])->assertSuccessful();

    expect(WebDomain::where('domain', 'node.example.test')->count())->toBe(1);

    $this->artisan('lesta:nodes:ensure-tools-domain', ['node' => $node->uuid, '--ssl-mode' => 'bogus'])->assertFailed();
});
