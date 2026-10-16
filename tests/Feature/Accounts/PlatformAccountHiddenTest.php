<?php

use App\Actions\Nodes\EnsureNodeToolsDomain;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use App\Models\WebDomain;
use Inertia\Testing\AssertableInertia as Assert;

test('the platform package is not listed and cannot be edited or deleted', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $platform = app(EnsureNodeToolsDomain::class)->platformAccount();
    $customer = Package::factory()->create(['name' => 'Customer package']);

    $this->actingAs($admin)->get(route('packages.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('packages', fn ($packages) => collect($packages)->pluck('name')->doesntContain('LESta platform')
            && collect($packages)->pluck('name')->contains('Customer package')));

    expect($admin->can('update', $platform->package))->toBeFalse()
        ->and($admin->can('delete', $platform->package))->toBeFalse()
        ->and($admin->can('update', $customer))->toBeTrue();
});

test('the platform account is left out of the cross-account lists', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    $platform = app(EnsureNodeToolsDomain::class)->platformAccount();
    WebDomain::factory()->for($platform)->for($node)->create(['domain' => 'tools.example.test']);
    WebDomain::factory()->for($node)->create(['domain' => 'customer.example.test']);

    $this->actingAs($admin)->get(route('domains.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('customers', fn ($customers) => str_contains(json_encode($customers), 'customer.example.test')
            && ! str_contains(json_encode($customers), 'tools.example.test')));
});
