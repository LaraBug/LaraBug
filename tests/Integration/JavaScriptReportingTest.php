<?php

namespace LaraBug\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * The route the bundled JavaScript client posts to.
 *
 * It is registered by the package itself, with the controller named as a
 * string inside a namespaced route group, and nothing checked that the
 * current Laravel still resolves that. An application only finds out when a
 * browser error is dropped, which is the one failure nobody sees.
 */
class JavaScriptReportingTest extends IntegrationTestCase
{
    protected bool $servesHttpRequests = true;

    #[Test]
    public function the_package_registers_the_route_the_javascript_client_posts_to(): void
    {
        $response = $this->postJson('/larabug-api/javascript-report', [
            'message' => 'Cannot read properties of undefined',
            'exception' => 'TypeError: Cannot read properties of undefined',
            'file' => __FILE__,
            'line' => 21,
            'column' => 9,
            'stack' => "TypeError\n    at checkout.js:21:9",
            'url' => 'https://example.test/checkout',
        ]);

        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    #[Test]
    public function a_browser_error_arrives_as_a_javascript_report(): void
    {
        $this->postJson('/larabug-api/javascript-report', [
            'message' => 'Cannot read properties of undefined',
            'file' => __FILE__,
            'line' => 21,
            'stack' => "TypeError\n    at checkout.js:21:9",
            'url' => 'https://example.test/checkout',
        ])->assertOk();

        $sent = $this->transport->first();

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('javascript', $sent->payload('exception.file_type'));
        $this->assertSame('Cannot read properties of undefined', $sent->payload('exception.error'));
        $this->assertSame('https://example.test/checkout', $sent->payload('exception.fullUrl'));
        $this->assertSame(21, $sent->payload('exception.line'));
        $this->assertNull($sent->payload('exception.class'));

        // The PHP stack that carried the report says nothing about where the
        // browser error was.
        $this->assertSame([], $sent->payload('exception.frames'));
    }

    #[Test]
    public function the_javascript_route_reports_nothing_from_an_environment_that_is_not_reporting(): void
    {
        $this->app['config']->set('larabug.environments', ['production']);

        $this->postJson('/larabug-api/javascript-report', [
            'message' => 'quiet',
            'file' => __FILE__,
            'line' => 1,
            'stack' => '',
            'url' => 'https://example.test/',
        ])->assertOk();

        $this->transport->assertNothingSent();
    }
}
