<?php

use App\Actions\Mail\DeleteMailAccount;
use App\Models\AuditEvent;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;

test('an owner can delete a mail account, and the domain is re-provisioned without it', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.smtp-imap.v1']);
    $mailDomain = MailDomain::factory()->for($node)->create();
    $mailAccount = MailAccount::factory()->for($mailDomain)->create();
    $owner = Membership::factory()->for($mailDomain->account)->owner()->create()->user;
    $id = $mailAccount->id;

    app(DeleteMailAccount::class)->handle($owner, $mailAccount);

    expect(MailAccount::find($id))->toBeNull()
        ->and($mailDomain->refresh()->desired_state_version)->toBe(2)
        ->and($mailDomain->toProvisioningPayload()['accounts'])->toBe([])
        ->and(AuditEvent::where('action', 'mail_account.deleted')->where('auditable_id', $id)->exists())->toBeTrue();
});
