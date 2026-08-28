<?php

use App\Models\Account;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;

test('a full list, create, edit, record, suspend, unsuspend, delete workflow works in a real browser', function () {
    $package = Package::factory()->withLimit('dns_zones', 5)->withLimit('dns_records', 5)->create();
    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'dns.bind9.v1']);

    $this->actingAs($owner);

    $page = visit('/dns');

    $page->assertNoJavaScriptErrors()
        ->assertSee('DNS')
        ->assertSee('No DNS zones yet.')
        ->click('Add zone')
        ->assertNoJavaScriptErrors()
        ->fill('domain', 'browser-test.example.com')
        ->click('[data-test="create-dns-zone-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('browser-test.example.com');

    $dnsZone = DnsZone::where('domain', 'browser-test.example.com')->sole();

    $page->click('Edit')
        ->assertNoJavaScriptErrors()
        ->assertSee('Edit DNS zone')
        ->click('[data-test="add-dns-record-button"]')
        ->assertNoJavaScriptErrors()
        ->fill('name', 'www')
        ->fill('value', '203.0.113.5')
        ->click('[data-test="create-dns-record-button"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('www');

    $dnsRecord = DnsRecord::where('dns_zone_id', $dnsZone->id)->where('name', 'www')->sole();

    $page->click('[data-test="toggle-suspend-dns-record-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-toggle-suspend-dns-record-button"]')
        ->assertNoJavaScriptErrors();

    expect($dnsRecord->refresh()->isSuspended())->toBeTrue();

    $page->click('[data-test="toggle-suspend-dns-record-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-toggle-suspend-dns-record-button"]')
        ->assertNoJavaScriptErrors();

    expect($dnsRecord->refresh()->isSuspended())->toBeFalse();

    $page->click('[data-test="delete-dns-record-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-delete-dns-record-button"]')
        ->assertNoJavaScriptErrors();

    expect(DnsRecord::find($dnsRecord->id))->toBeNull();

    $page->click('[data-test="toggle-suspend-dns-zone-button"]')
        ->assertNoJavaScriptErrors();

    expect($dnsZone->refresh()->isSuspended())->toBeTrue();

    $page->click('[data-test="toggle-suspend-dns-zone-button"]')
        ->assertNoJavaScriptErrors();

    expect($dnsZone->refresh()->isSuspended())->toBeFalse();

    $page->click('[data-test="delete-dns-zone-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="confirm-delete-dns-zone-button"]')
        ->assertNoJavaScriptErrors();

    expect(DnsZone::find($dnsZone->id))->toBeNull();
});
