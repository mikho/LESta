<?php

use App\Actions\Mail\DeleteMailAccount;
use App\Actions\Mail\UpdateMailAccount;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Auth\Access\AuthorizationException;

test('a member of another account cannot update a foreign mail account', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(UpdateMailAccount::class)->handle($stranger, $mailAccount, ['quota_mb' => 1]);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign mail account', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteMailAccount::class)->handle($stranger, $mailAccount);
})->throws(AuthorizationException::class);
