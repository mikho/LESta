<?php

namespace Tests;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> Responses this test received that docs/api/openapi.yaml does not describe. */
    private array $openApiMismatches = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every API response any test receives is checked against the OpenAPI description.
        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
            $mismatch = OpenApiResponseValidator::check($event->request, $event->response);

            if ($mismatch !== null) {
                $this->openApiMismatches[] = $mismatch;
            }
        });
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $this->assertSame([], $this->openApiMismatches, "API responses that docs/api/openapi.yaml does not describe:\n".implode("\n", $this->openApiMismatches));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
