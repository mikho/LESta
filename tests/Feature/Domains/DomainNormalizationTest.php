<?php

use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;
use Illuminate\Database\QueryException;

test('normalizeDomain lowercases and trims whitespace', function () {
    expect(WebDomain::normalizeDomain('  Example.COM  '))->toBe('example.com');
});

test('normalizeDomain converts unicode domains to their punycode form', function () {
    expect(WebDomain::normalizeDomain('münchen.de'))->toBe('xn--mnchen-3ya.de');
});

test('normalizeDomain falls back to the trimmed, lowercased input when conversion fails', function () {
    expect(WebDomain::normalizeDomain('Already-ASCII.example.com'))->toBe('already-ascii.example.com');
});

test('two domains that normalize to the same canonical form collide on the unique constraint', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    WebDomain::factory()->for($node)->create(['domain' => WebDomain::normalizeDomain('Example.com')]);

    WebDomain::factory()->for($node)->create(['domain' => WebDomain::normalizeDomain('EXAMPLE.COM')]);
})->throws(QueryException::class);
