<?php

use App\Models\DnsZone;

test('bumping the desired state version raises it by one and saves it', function () {
    $zone = DnsZone::factory()->create(['desired_state_version' => 4]);

    $zone->bumpDesiredStateVersion();

    expect($zone->desired_state_version)->toBe(5)
        ->and($zone->fresh()->desired_state_version)->toBe(5);
});
