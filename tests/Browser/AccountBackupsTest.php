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

    // The fake node cannot upload anything, so a requested download ends up reported as failed:
    // the button, the request and the status line are what is checked here.
    $page = visit(route('account-backups.index'));

    $page->click('[aria-label="Prepare a download of the backup from '.$backup->created_at->toIso8601String().'"]')
        ->assertSee('The download could not be prepared')
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

    $page = visit(route('account-backups.index'));

    $page->fill('endpoint', 'http://insecure.example.com')
        ->fill('region', 'eu-west-1')
        ->fill('bucket', 'my-backups')
        ->fill('access_key', 'AKIAEXAMPLEKEY')
        ->fill('secret_key', 'example-secret-key/1234')
        ->click('[data-test="save-storage-button"]')
        ->assertSee('Use an https address')
        ->fill('endpoint', 'https://s3.eu-west-1.amazonaws.com')
        ->click('[data-test="save-storage-button"]')
        ->assertSee('Remove my storage')
        ->assertNoJavaScriptErrors();

    expect($account->backupDestination()->first()->bucket)->toBe('my-backups');
});

test('an owner restores from a file on their computer: it is uploaded and added to the backups', function () {
    $node = Node::factory()->create(['name' => 'alpha']);
    NodeCapability::factory()->for($node)->create(['capability' => 'backup.encrypted-artifacts.v1']);
    $site = WebDomain::factory()->for($node)->create();
    $account = $site->account;
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $owner = Membership::factory()->for($account)->owner()->create()->user;

    $file = sys_get_temp_dir().'/lesta-import-'.uniqid().'.tar.gz';
    file_put_contents($file, gzencode(str_repeat('backup data ', 1000)));

    $this->actingAs($owner);

    visit(route('account-backups.index'))
        ->assertSee('Restore from a copy')
        ->assertButtonDisabled('[data-test="import-upload-button"]')
        ->attach('#import_file', $file)
        ->fill('#import_label', 'From my laptop')
        ->click('[data-test="import-upload-button"]')
        ->assertSee('From my laptop')
        ->assertNoJavaScriptErrors();

    unlink($file);

    $backup = AccountBackup::sole();

    expect($backup->kind)->toBe('imported')
        ->and($backup->label)->toBe('From my laptop');
});
