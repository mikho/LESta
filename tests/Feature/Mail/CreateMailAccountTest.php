<?php

use App\Actions\Mail\CreateMailAccount;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can create a mail account and the domain is re-provisioned after commit', function () {
    $package = Package::factory()->withLimit('mail_accounts', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    [$mailAccount, $password] = app(CreateMailAccount::class)->handle($owner, $mailDomain, [
        'local_part' => 'Sales',
        'quota_mb' => 256,
    ]);

    expect($mailAccount->local_part)->toBe('sales')
        ->and($mailAccount->quota_mb)->toBe(256)
        ->and($mailAccount->password)->toBe($password)
        ->and($password)->toMatch('/^[0-9a-f]{48}$/')
        ->and(AuditEvent::where('action', 'mail_account.created')
            ->where('auditable_id', $mailAccount->id)
            ->where('auditable_type', $mailAccount->getMorphClass())
            ->exists())->toBeTrue();

    expect($mailDomain->refresh()->desired_state_version)->toBe(2);

    $operation = ProvisioningOperation::where('provisionable_type', $mailDomain->getMorphClass())
        ->where('provisionable_id', $mailDomain->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->desired_state_version)->toBe(2)
        ->and($operation->payload['accounts'][0]['password'])->toBe($password);
});

test('a non-owner member cannot create a mail account', function () {
    $package = Package::factory()->withLimit('mail_accounts', 5)->create();
    $account = Account::factory()->for($package)->create();
    $member = Membership::factory()->for($account)->member()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($member, $mailDomain, ['local_part' => 'sales']);
})->throws(AuthorizationException::class);

test('a package with no mail_accounts limit row blocks account creation entirely', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'sales']);
})->throws(ResourceQuotaExceededException::class);

test('a package with an explicit mail_accounts limit already reached blocks creation', function () {
    $package = Package::factory()->withLimit('mail_accounts', 1)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'first']);

    app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'second']);
})->throws(ResourceQuotaExceededException::class);

test('a rolled-back account creation leaves no partial rows', function () {
    $package = Package::factory()->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($account)->for($node)->create();

    try {
        app(CreateMailAccount::class)->handle($owner, $mailDomain, ['local_part' => 'sales']);
    } catch (ResourceQuotaExceededException) {
        // expected
    }

    expect(MailAccount::count())->toBe(0)
        ->and(AuditEvent::where('action', 'mail_account.created')->count())->toBe(0);
});
