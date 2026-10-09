<?php

use App\Models\AccountBackup;
use App\Models\AccountNodeIdentity;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;

test('an owner backs up, restores part of a backup with confirmation, and deletes it', function () {
    $node = Node::factory()->create(['name' => 'alpha']);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $site = WebDomain::factory()->for($node)->create();
    $account = $site->account;
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $this->actingAs($owner);

    $page = visit(route('account-backups.index'));

    $page->assertSee('Backups')
        ->assertSee('No backups yet.')
        ->assertSee('alpha')
        ->click('[data-test="back-up-now-button"]')
        ->assertSee('Website files')
        ->assertDontSee('No backups yet.')
        ->assertNoJavaScriptErrors();

    expect(AccountBackup::count())->toBe(1);

    // A finished backup (as the node would report it) can be restored.
    $backup = AccountBackup::factory()->completed()->for($account)->for($node)->create(['parts' => ['files']]);
    $backup->forceFill(['created_at' => now()->subDay()->startOfSecond()])->save();

    $page = visit(route('account-backups.index'));

    $page->click('[aria-label="Restore the backup from '.$backup->created_at->toIso8601String().'"]')
        ->assertSee('Restore this backup')
        ->click('[data-test="confirm-restore-button"]')
        ->assertSee('Confirm that you understand')
        ->click('Cancel')
        ->assertDontSee('Restore this backup')
        ->assertNoJavaScriptErrors();

    $page->click('[aria-label="Delete the backup from '.$backup->created_at->toIso8601String().'"]')
        ->assertNoJavaScriptErrors();

    $page = visit(route('account-backups.index'));

    $page->click('#schedule_frequency')
        ->click('[role="option"]:has-text("Every night")')
        ->click('[data-test="save-schedule-button"]')
        ->assertSee('Next run')
        ->assertNoJavaScriptErrors();

    expect($account->backupSchedule()->first()->frequency)->toBe('daily');
});
