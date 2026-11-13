<?php

namespace LaraBug\Tests;

use LaraBug\LaraBug;
use Illuminate\Support\Facades\Route;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;

class JavascriptReportTest extends TestCase
{
    protected LaraBugClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['larabug.sleep'] = 0;

        $this->client = new LaraBugClient('login_key', 'project_key');

        $this->app->instance('larabug', new LaraBug($this->client));
    }

    #[Test]
    public function it_reports_a_javascript_error()
    {
        $response = $this->postJson('/larabug-api/javascript-report', [
            'message' => 'Uncaught ReferenceError: doesNotExist is not defined',
            'file' => 'https://example.test/js/app.js',
            'line' => 42,
            'stack' => "ReferenceError: doesNotExist is not defined\n    at app.js:42:9",
            'url' => 'https://example.test/checkout',
        ]);

        $response->assertOk();

        $this->client->assertRequestsSent(1);

        $report = $this->client->requests()[0]['exception'];

        $this->assertSame('javascript', $report['file_type']);
        $this->assertSame('https://example.test/js/app.js', $report['file']);
        $this->assertSame(42, $report['line']);
        $this->assertSame('https://example.test/checkout', $report['fullUrl']);
        $this->assertSame('Uncaught ReferenceError: doesNotExist is not defined', $report['error']);
        $this->assertStringContainsString('at app.js:42:9', $report['exception']);
        $this->assertNull($report['class']);
    }

    #[Test]
    public function it_does_not_read_a_local_file_the_report_names()
    {
        $secret = tempnam(sys_get_temp_dir(), 'larabug');
        file_put_contents($secret, "first line\nDATABASE_PASSWORD=hunter2\nthird line\n");

        $response = $this->postJson('/larabug-api/javascript-report', [
            'message' => 'boom',
            'file' => $secret,
            'line' => 2,
            'stack' => 'Error: boom',
            'url' => 'https://example.test/checkout',
        ]);

        unlink($secret);

        $response->assertOk();

        $this->client->assertRequestsSent(1);

        $this->assertSame([], $this->client->requests()[0]['exception']['executor']);
        $this->assertStringNotContainsString('hunter2', json_encode($this->client->requests()[0]));
    }

    #[Test]
    public function it_does_not_read_the_applications_own_files()
    {
        $response = $this->postJson('/larabug-api/javascript-report', [
            'message' => 'boom',
            'file' => base_path('composer.json'),
            'line' => 2,
            'stack' => 'Error: boom',
            'url' => 'https://example.test/checkout',
        ]);

        $response->assertOk();

        $this->assertSame([], $this->client->requests()[0]['exception']['executor']);
        $this->assertStringNotContainsString('larabug/larabug', json_encode($this->client->requests()[0]));
    }

    #[Test]
    public function it_rejects_a_report_whose_fields_are_not_what_they_claim()
    {
        $reports = [
            ['message' => ['an', 'array']],
            ['message' => 'boom', 'file' => ['an', 'array']],
            ['message' => 'boom', 'line' => 'not a number'],
            ['message' => 'boom', 'stack' => str_repeat('a', 20001)],
            ['message' => str_repeat('a', 2001)],
            ['message' => 'boom', 'url' => str_repeat('a', 2001)],
            [],
        ];

        foreach ($reports as $report) {
            $this->postJson('/larabug-api/javascript-report', $report)->assertStatus(422);
        }

        $this->client->assertRequestsSent(0);
    }

    #[Test]
    public function the_report_route_is_throttled()
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'larabug-api/javascript-report');

        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    #[Test]
    public function it_stops_accepting_reports_once_the_limit_is_reached()
    {
        $this->app['config']['larabug.environments'] = [];

        foreach (range(1, 60) as $attempt) {
            $this->postJson('/larabug-api/javascript-report', ['message' => 'boom'])->assertOk();
        }

        $this->postJson('/larabug-api/javascript-report', ['message' => 'boom'])->assertStatus(429);
    }
}
