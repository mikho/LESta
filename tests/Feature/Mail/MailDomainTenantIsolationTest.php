<?php

use App\Actions\Mail\DeleteMailDomain;
use App\Actions\Mail\UpdateMailDomain;
use App\Models\Account;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Auth\Access\AuthorizationException;

test('a member of another account cannot update a foreign mail domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(UpdateMailDomain::class)->handle($stranger, $mailDomain, ['catchall_email' => 'hijacked@example.com']);
})->throws(AuthorizationException::class);

test('a member of another account cannot delete a foreign mail domain', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();

    $otherAccount = Account::factory()->create();
    $stranger = Membership::factory()->for($otherAccount)->owner()->create()->user;

    app(DeleteMailDomain::class)->handle($stranger, $mailDomain);
})->throws(AuthorizationException::class);
