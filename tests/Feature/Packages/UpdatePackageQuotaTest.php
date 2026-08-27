<?php

use App\Actions\Packages\UpdatePackageQuota;
use App\Exceptions\PackageQuotaExceededException;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use App\Models\PackageLimit;

test('a quota edit that would put an existing subscriber over the new limit is rejected', function () {
    $package = Package::factory()->withLimit('memberships', 10)->create();
    $account = Account::factory()->for($package)->create();
    Membership::factory()->for($account)->count(5)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    app(UpdatePackageQuota::class)->handle($admin, $package, 'memberships', 3);
})->throws(PackageQuotaExceededException::class);

test('a quota edit that does not put any subscriber over the new limit is allowed', function () {
    $package = Package::factory()->withLimit('memberships', 10)->create();
    $account = Account::factory()->for($package)->create();
    Membership::factory()->for($account)->count(5)->create();
    $admin = Membership::factory()->providerAdmin()->create()->user;

    $limit = app(UpdatePackageQuota::class)->handle($admin, $package, 'memberships', 20);

    expect($limit)->toBeInstanceOf(PackageLimit::class)
        ->and($limit->limit_value)->toBe(20);
});
