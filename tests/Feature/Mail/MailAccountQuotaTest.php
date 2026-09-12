<?php

use App\Actions\Mail\CreateMailAccount;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function setUpMailAccountCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('mail_accounts', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    return [$account, $owner, $node];
}

test('a package with no PackageLimit row at all blocks mail account creation', function () {
    [$account, $owner, $node] = setUpMailAccountCapableAccount(-1);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'sales']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner, $node] = setUpMailAccountCapableAccount(null);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();
    MailAccount::factory()->for($mailDomain)->count(10)->create();

    $mailAccount = app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'sales'])[0];

    expect($mailAccount)->toBeInstanceOf(MailAccount::class);
});

test('a configured and exceeded limit blocks further account creation', function () {
    [$account, $owner, $node] = setUpMailAccountCapableAccount(1);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'first']);

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('the per-domain limit is enforced independently for each domain under the same account', function () {
    [$account, $owner, $node] = setUpMailAccountCapableAccount(1);
    $fullDomain = MailDomain::factory()->for($account)->for($node)->create();
    $otherDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($owner, $fullDomain, ['local_part' => 'first']);

    // The first domain is now at capacity (limit of 1), but the second domain's own capacity is
    // untouched: the limit applies per domain, not to the account's total mailbox count.
    [$mailAccount] = app(CreateMailAccount::class)->handle($owner, $otherDomain, ['local_part' => 'first']);

    expect($mailAccount)->toBeInstanceOf(MailAccount::class)
        ->and($fullDomain->accounts()->count())->toBe(1)
        ->and($otherDomain->accounts()->count())->toBe(1);
});
