<?php

use App\Actions\Mail\CreateMailDomain;
use App\Enums\ProvisioningStatus;
use App\Exceptions\NoMailCapableNodeAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a mail domain and it is provisioned after commit', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = app(CreateMailDomain::class)->handle($owner, $account, [
        'domain' => 'Example.COM',
    ]);

    expect($mailDomain->domain)->toBe('example.com')
        ->and($mailDomain->account_id)->toBe($account->id)
        ->and($mailDomain->node_id)->toBe($node->id)
        ->and($mailDomain->antivirus_enabled)->toBeTrue()
        ->and($mailDomain->antispam_enabled)->toBeTrue()
        ->and($mailDomain->dkim_enabled)->toBeFalse()
        ->and($mailDomain->desired_state_version)->toBe(1)
        ->and(AuditEvent::where('action', 'mail_domain.created')->where('auditable_id', $mailDomain->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_type', $mailDomain->getMorphClass())
        ->where('provisionable_id', $mailDomain->id)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->capability)->toBe('mail.smtp-imap.v1')
        ->and($operation->operation->value)->toBe('create');
});

test('dkim_enabled can be turned on at creation, now that the real capability backs it', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = app(CreateMailDomain::class)->handle($owner, $account, [
        'domain' => 'example.com',
        'dkim_enabled' => true,
    ]);

    expect($mailDomain->dkim_enabled)->toBeTrue()
        ->and($mailDomain->dkim_selector)->toBe('lesta1')
        ->and($mailDomain->dkim_selector_activated_at)->not->toBeNull();
});

test('dkim_selector_activated_at stays null when dkim is not enabled at creation', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($mailDomain->dkim_selector_activated_at)->toBeNull();
});

test('dkim_enabled defaults to false when not requested', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    $mailDomain = app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);

    expect($mailDomain->dkim_enabled)->toBeFalse();
});

test('a non-owner member cannot create a mail domain', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;

    app(CreateMailDomain::class)->handle($member, $account, ['domain' => 'example.com']);
})->throws(AuthorizationException::class);

test('a package with no mail_domains limit row blocks creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('mail_domains', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'first.example.com']);

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'second.example.com']);
})->throws(ResourceQuotaExceededException::class);

test('creation fails when no mail-capable node is available', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
})->throws(NoMailCapableNodeAvailableException::class);

test('a rolled-back creation leaves no partial rows', function () {
    $package = Package::factory()->withLimit('mail_domains', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    try {
        app(CreateMailDomain::class)->handle($owner, $account, ['domain' => 'example.com']);
    } catch (NoMailCapableNodeAvailableException) {
        // expected
    }

    expect(MailDomain::count())->toBe(0)
        ->and(AuditEvent::where('action', 'mail_domain.created')->count())->toBe(0)
        ->and(ProvisioningOperation::count())->toBe(0);
});
