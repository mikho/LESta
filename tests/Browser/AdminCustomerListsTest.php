<?php

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;

function adminWithCustomerDomains(): array
{
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $acme = Account::factory()->create(['name' => 'Acme Corp']);
    $globex = Account::factory()->create(['name' => 'Globex']);

    $broken = WebDomain::factory()->for($acme)->for($node)->create(['domain' => 'broken.example']);
    WebDomain::factory()->for($acme)->for($node)->create(['domain' => 'zeta.example']);
    WebDomain::factory()->for($globex)->for($node)->create(['domain' => 'alpha.example']);

    ProvisioningOperation::factory()->create([
        'provisionable_type' => $broken->getMorphClass(),
        'provisionable_id' => $broken->id,
        'resource_id' => $broken->uuid,
        'status' => ProvisioningStatus::Failed,
        'errors' => [['code' => 'nginx_test_failed', 'message' => 'nginx -t rejected the configuration']],
    ]);

    return [$admin, $broken];
}

test('the admin domain list groups by node, shows why something failed, and narrows by problems only', function () {
    [$admin] = adminWithCustomerDomains();
    $this->actingAs($admin);

    $page = visit('/domains');

    $page->assertNoJavaScriptErrors()
        ->assertSee('alpha')
        ->assertSee('3 domains in 2 accounts on 1 node')
        ->assertSee('Acme Corp')
        ->assertSee('Globex')
        // The failure and its reason are visible without leaving the list.
        ->assertSee('nginx -t rejected the configuration');

    $page->click('[data-test="customer-status-filter"]')
        ->click('Problems only')
        ->waitForText('1 domain in 1 account on 1 node')
        ->assertSee('broken.example')
        ->assertDontSee('zeta.example')
        ->assertQueryStringHas('status', 'problems');
});

test('a column header sorts within the node and the choice is kept in the address', function () {
    [$admin] = adminWithCustomerDomains();
    $this->actingAs($admin);

    $page = visit('/domains');

    $page->click('[aria-label="Sort by Domain"]')
        ->assertQueryStringHas('sort', 'domain')
        ->click('[aria-label="Sort by Domain"]')
        ->assertQueryStringHas('sort', '-domain')
        ->assertNoJavaScriptErrors();
});

test('a resource name opens its read-only detail page', function () {
    [$admin, $broken] = adminWithCustomerDomains();
    $this->actingAs($admin);

    visit('/domains')
        ->click('a[href$="/customer-resources/domains/'.$broken->uuid.'"]')
        ->assertPathContains('/customer-resources/domains/'.$broken->uuid)
        ->assertSee('Read-only')
        ->assertSee('Recent operations')
        ->assertSee('nginx -t rejected the configuration')
        ->assertNoJavaScriptErrors();
});

test('the admin lists have no accessibility issues', function () {
    [$admin, $broken] = adminWithCustomerDomains();
    $this->actingAs($admin);

    visit('/domains')->assertNoAccessibilityIssues();
    visit('/customer-resources/domains/'.$broken->uuid)->assertNoAccessibilityIssues();
});

test('the impersonate dialog gives the reason box room and keeps it clear of the buttons', function () {
    $account = Account::factory()->create(['name' => 'acme-hosting']);
    Membership::factory()->for($account)->owner()->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $this->actingAs($admin);

    $page = visit(route('accounts.show', $account))
        ->click('[data-test="impersonate-member-button"]')
        ->waitForText('Reason');

    $geometry = $page->script('(() => { const box = document.getElementById("reason").getBoundingClientRect(); const confirm = document.querySelector("[data-test=\"confirm-impersonate-button\"]").getBoundingClientRect(); return { height: box.height, gap: confirm.top - box.bottom }; })()');

    expect($geometry['height'])->toBeGreaterThanOrEqual(120)
        ->and($geometry['gap'])->toBeGreaterThanOrEqual(8);
});

test('the admin usage list renders its times and links an account to its usage', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['name' => 'alpha']);
    $account = Account::factory()->create(['name' => 'Acme Corp']);
    $mailbox = MailAccount::factory()->for(MailDomain::factory()->for($account)->for($node)->create())->create();
    UsageSnapshot::factory()->for($account)->for($node)->for($mailbox, 'snapshotable')->create([
        'disk_bytes' => 2048, 'request_count' => 12345, 'bytes_sent' => 4096, 'collected_at' => now()->subHour(),
    ]);
    $this->actingAs($admin);

    // A TypeError while formatting a time would blank the whole page, so the assertion that
    // matters is that content renders and no script error is thrown.
    visit('/usage')
        ->assertSee('Acme Corp')
        ->assertSee('12,345')
        ->assertSee('1 account on 1 node')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
});
