<?php

use Tests\ApiReferenceGenerator;

/**
 * resources/docs/api-reference.md is generated from docs/api/openapi.yaml. After changing the
 * description, rewrite it with: UPDATE_API_REFERENCE=1 php artisan test --filter=ApiReferenceTest
 */
test('the API Reference page matches the OpenAPI description', function () {
    $markdown = (new ApiReferenceGenerator)->markdown();

    if (getenv('UPDATE_API_REFERENCE') === '1') {
        file_put_contents(ApiReferenceGenerator::target(), $markdown);
    }

    expect(file_exists(ApiReferenceGenerator::target()) ? file_get_contents(ApiReferenceGenerator::target()) : '')
        ->toBe($markdown, 'resources/docs/api-reference.md is out of date: run UPDATE_API_REFERENCE=1 php artisan test --filter=ApiReferenceTest');
});

test('every operation and schema is on the API Reference page', function () {
    $markdown = (new ApiReferenceGenerator)->markdown();

    expect($markdown)->toContain('### `POST /accounts/{account}/web-domains`')
        ->toContain('### `GET /admin/nodes/{node}`')
        ->toContain('<a id="problem"></a>Problem')
        ->and(substr_count($markdown, "\n### `"))->toBe(88);
});
