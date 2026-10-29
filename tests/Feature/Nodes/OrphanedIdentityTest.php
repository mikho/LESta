<?php

use App\Models\AccountNodeIdentity;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\WebDomain;

test('an identity that still owns a web domain is not orphaned and cannot be deleted', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    $identity = AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect($identity->isOrphaned())->toBeFalse()
        ->and(AccountNodeIdentity::query()->orphanedOn($node)->pluck('id')->all())->toBe([]);

    $this->actingAs($admin)->delete(route('nodes.identities.destroy', [$node, $identity]))->assertSessionHasErrors('identity');
    expect($identity->fresh())->not->toBeNull();
});

test('an identity with nothing left on its node is orphaned, and only on its own node', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $cronJob = CronJob::factory()->for($otherNode)->create();
    $identity = AccountNodeIdentity::factory()->for($cronJob->account)->for($node)->create(['system_username' => 'lesta-t'.$cronJob->account_id]);
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect($identity->isOrphaned())->toBeTrue()
        ->and(AccountNodeIdentity::query()->orphanedOn($node)->pluck('id')->all())->toBe([$identity->id]);

    $this->actingAs($admin)->delete(route('nodes.identities.destroy', [$otherNode, $identity]))->assertNotFound();
    expect($identity->fresh())->not->toBeNull();
});
