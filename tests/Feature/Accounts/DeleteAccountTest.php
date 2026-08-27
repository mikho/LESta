<?php

use App\Actions\Accounts\DeleteAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;

test('deleting a suspended account force-unsuspends then deletes as one action', function () {
    $account = Account::factory()->suspended()->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $id = $account->id;

    app(DeleteAccount::class)->handle($owner, $account);

    expect(Account::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'account.deleted')->where('auditable_id', $id)->exists())->toBeTrue();
});
