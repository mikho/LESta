<?php

use App\Actions\Mail\DeleteMailDomain;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('deleting a suspended mail domain force-unsuspends then deletes as one action', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;
    $id = $mailDomain->id;

    app(DeleteMailDomain::class)->handle($owner, $mailDomain);

    expect(MailDomain::find($id))->toBeNull();
});

test('deleting a mail domain removes every account beneath it via the FK cascade', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $account = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;
    $accountId = $account->id;

    app(DeleteMailDomain::class)->handle($owner, $mailDomain);

    expect(MailAccount::find($accountId))->toBeNull();
});
