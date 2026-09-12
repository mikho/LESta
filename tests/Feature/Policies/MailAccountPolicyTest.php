<?php

use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('mail account authorization matrix: owner, member, stranger, admin without impersonation', function () {
    $node = Node::factory()->create();
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $account = $mailDomain->account;
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $member = Membership::factory()->for($account)->member()->create()->user;
    $stranger = User::factory()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    expect(Gate::forUser($owner)->allows('view', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('suspend', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('unsuspend', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', [MailAccount::class, $mailDomain]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', [MailAccount::class, $mailDomain]))->toBeTrue()

        ->and(Gate::forUser($member)->allows('view', $mailAccount))->toBeTrue()
        ->and(Gate::forUser($member)->allows('viewAny', [MailAccount::class, $mailDomain]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $mailAccount))->toBeFalse()
        ->and(Gate::forUser($member)->allows('suspend', $mailAccount))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', [MailAccount::class, $mailDomain]))->toBeFalse()

        ->and(Gate::forUser($stranger)->allows('view', $mailAccount))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [MailAccount::class, $mailDomain]))->toBeFalse()

        // Unlike MailDomain, MailAccount is never its own Account-level cascade target (a
        // domain-level suspend flips its accounts via a raw model mutation, never this policy),
        // so no admin permission applies here at all.
        ->and(Gate::forUser($admin)->allows('view', $mailAccount))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('suspend', $mailAccount))->toBeFalse();
});
