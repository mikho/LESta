<?php

use App\Actions\Mail\CreateMailDomain;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

function setUpMailCapableAccount(?int $limitValue): array
{
    $package = $limitValue === -1
        ? Package::factory()->create()
        : Package::factory()->withLimit('mail_domains', $limitValue)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    return [$account, $owner];
}

test('a package with no PackageLimit row at all blocks mail domain creation', function () {
    [$account, $owner] = setUpMailCapableAccount(-1);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('an explicit PackageLimit row with a null limit value means unlimited', function () {
    [$account, $owner] = setUpMailCapableAccount(null);

    MailDomain::factory()->for($account)->count(10)->create();

    $mailDomain = app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($mailDomain)->toBeInstanceOf(MailDomain::class);
});

test('a configured and exceeded limit blocks further creation', function () {
    [$account, $owner] = setUpMailCapableAccount(1);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation under a configured limit is allowed', function () {
    [$account, $owner] = setUpMailCapableAccount(2);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);
    $second = app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);

    expect($second)->toBeInstanceOf(MailDomain::class)
        ->and($account->mailDomains()->count())->toBe(2);
});
