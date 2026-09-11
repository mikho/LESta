<?php

use App\Actions\Accounts\DeleteAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('deleting a suspended account force-unsuspends then deletes as one action', function () {
    $account = Account::factory()->suspended()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $id = $account->id;

    app(DeleteAccount::class)->handle($owner, $account);

    expect(Account::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'account.deleted')->where('auditable_id', $id)->exists())->toBeTrue();
});

test('a provider admin with accounts.delete can delete an account directly, cascading into its own web domains', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $account = Account::factory()->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $accountId = $account->id;
    $webDomainId = $webDomain->id;

    app(DeleteAccount::class)->handle($admin, $account);

    expect(Account::find($accountId))->toBeNull()
        // The cascade into WebDomainPolicy::delete must not throw for this same admin: it
        // recognizes the identical accounts.delete permission that already authorized the
        // top-level action, not a second, independent grant.
        ->and(WebDomain::find($webDomainId))->toBeNull()
        ->and(AuditEvent::where('action', 'account.deleted')->where('auditable_id', $accountId)->exists())->toBeTrue();
});
