<?php

use App\Models\Membership;
use App\Models\Node;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;

test('the logs and traffic page shows the recorded daily history and switches between the views', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    UsageSnapshot::factory()->create([
        'account_id' => $webDomain->account_id,
        'node_id' => $node->id,
        'snapshotable_type' => $webDomain->getMorphClass(),
        'snapshotable_id' => $webDomain->id,
        'request_count' => 1234,
        'bytes_sent' => 5 * 1024 * 1024,
        'collected_at' => now()->subDay()->setTime(12, 0),
    ]);

    $this->actingAs($owner);

    $page = visit(route('domains.logs.index', $webDomain));

    $page->assertSee('Logs and traffic')
        ->assertSee('Last 30 days')
        ->assertSee('1,234 requests')
        ->assertSee('5.0 MB')
        ->click('Access log')
        ->assertSee('Download the log')
        ->assertPresent('#log-filter')
        ->click('Error log')
        ->assertSee('Filter these lines')
        ->click('Traffic')
        ->assertSee('Last 30 days')
        ->assertNoJavaScriptErrors();
});
