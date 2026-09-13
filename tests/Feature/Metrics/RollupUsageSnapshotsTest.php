<?php

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\Node;
use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use App\Models\WebDomain;
use Carbon\CarbonInterface;

test('metrics:rollup aggregates a completed month into one rollup row per resource', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailAccount = MailAccount::factory()->create();

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'disk_bytes' => 1000,
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);
    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'disk_bytes' => 2500,
        'collected_at' => $lastMonth->copy()->addDays(15),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);

    expect(UsageSnapshotRollup::count())->toBe(1);

    $rollup = UsageSnapshotRollup::first();
    expect($rollup->account_id)->toBe($account->id)
        ->and($rollup->node_id)->toBe($node->id)
        ->and($rollup->snapshotable_id)->toBe($mailAccount->id)
        ->and($rollup->period->toDateString())->toBe($lastMonth->toDateString())
        ->and($rollup->disk_bytes_last)->toBe(2500)
        ->and($rollup->request_count_sum)->toBeNull()
        ->and($rollup->bytes_sent_sum)->toBeNull();
});

test('metrics:rollup sums request_count and bytes_sent across the month for a web domain', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->create();

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    UsageSnapshot::factory()->for($account)->for($node)->for($webDomain, 'snapshotable')->create([
        'disk_bytes' => null,
        'request_count' => 100,
        'bytes_sent' => 5000,
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);
    UsageSnapshot::factory()->for($account)->for($node)->for($webDomain, 'snapshotable')->create([
        'disk_bytes' => null,
        'request_count' => 250,
        'bytes_sent' => 9000,
        'collected_at' => $lastMonth->copy()->addDays(2),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);

    $rollup = UsageSnapshotRollup::first();
    expect($rollup->request_count_sum)->toBe(350)
        ->and($rollup->bytes_sent_sum)->toBe(14000)
        ->and($rollup->disk_bytes_last)->toBeNull();
});

test('metrics:rollup never aggregates the current, still-in-progress month', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailAccount = MailAccount::factory()->create();

    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'collected_at' => now(),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);

    expect(UsageSnapshotRollup::count())->toBe(0);
});

test('metrics:rollup is idempotent: running it twice for the same month does not duplicate or change the result', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailAccount = MailAccount::factory()->create();

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'disk_bytes' => 4242,
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);
    $this->artisan('metrics:rollup')->assertExitCode(0);

    expect(UsageSnapshotRollup::count())->toBe(1)
        ->and(UsageSnapshotRollup::first()->disk_bytes_last)->toBe(4242);
});

test('metrics:rollup keeps two different resources in the same month as separate rollup rows', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailAccountA = MailAccount::factory()->create();
    $mailAccountB = MailAccount::factory()->create();

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccountA, 'snapshotable')->create([
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);
    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccountB, 'snapshotable')->create([
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);

    expect(UsageSnapshotRollup::count())->toBe(2);
});

test('metrics:rollup keeps two different months for the same resource as separate rollup rows', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $mailAccount = MailAccount::factory()->create();

    $twoMonthsAgo = now()->subMonthsNoOverflow(2)->startOfMonth();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'collected_at' => $twoMonthsAgo->copy()->addDays(1),
    ]);
    UsageSnapshot::factory()->for($account)->for($node)->for($mailAccount, 'snapshotable')->create([
        'collected_at' => $lastMonth->copy()->addDays(1),
    ]);

    $this->artisan('metrics:rollup')->assertExitCode(0);

    expect(UsageSnapshotRollup::count())->toBe(2)
        ->and(UsageSnapshotRollup::pluck('period')->map(fn (CarbonInterface $period) => $period->toDateString())->sort()->values()->all())
        ->toBe([$twoMonthsAgo->toDateString(), $lastMonth->toDateString()]);
});
