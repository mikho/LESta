<?php

use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\ApiToken;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Browser Tests
|--------------------------------------------------------------------------
|
| Browser tests (tests/Browser/) require Playwright: run
| `npm install playwright && npx playwright install` locally, then opt them
| into a test run (e.g. `vendor/bin/pest tests/Browser`). They are not part
| of the default test suite in phpunit.xml, see the Phase 2 plan's own note
| about CI needing a browser-binary setup step first.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An account owner whose account can place resources on one node, with an API token holding
 * $abilities: the setup every API write test shares.
 *
 * @param  list<string>  $abilities
 * @param  list<string>  $capabilities  the node's capabilities
 * @param  array<string, int>  $limits  the account package's limits
 * @return array{0: Account, 1: ApiToken, 2: array<string, string>, 3: Node}
 */
function apiWriteOwner(array $abilities, array $capabilities, array $limits): array
{
    $package = Package::factory();

    foreach ($limits as $resource => $limit) {
        $package = $package->withLimit($resource, $limit);
    }

    $account = Account::factory()->for($package)->create();
    $owner = Membership::factory()->for($account)->owner()->create()->user;
    $node = Node::factory()->create();

    foreach ($capabilities as $capability) {
        NodeCapability::factory()->for($node)->create(['capability' => $capability]);
    }

    IpAllocation::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);

    $plaintext = ApiToken::PREFIX.'write'.fake()->unique()->numerify('########');
    $token = ApiToken::factory()->for($owner)->withPlaintext($plaintext)->create(['abilities' => $abilities]);

    return [$account, $token, ['Authorization' => 'Bearer '.$plaintext], $node];
}
