<?php

namespace LaraBug\Tests;

use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;

class JavascriptReportTest extends TestCase
{
    /** @var LaraBugClient */
    protected $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['larabug.sleep'] = 0;

        $this->app->instance('larabug', new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        )));
    }

    /** @test */
    public function it_does_not_read_a_server_side_file_named_by_a_javascript_report()
    {
        $path = tempnam(sys_get_temp_dir(), 'larabug');
        file_put_contents($path, str_repeat("the-contents-of-a-local-file\n", 40));

        $response = $this->postJson('/larabug-api/javascript-report', $this->payload([
            'file' => $path,
        ]));

        unlink($path);

        $response->assertStatus(200);

        $this->client->assertRequestsSent(1);
        $this->assertSame([], $this->client->getRequests()[0]['exception']['executor']);
        $this->assertStringNotContainsString('the-contents-of-a-local-file', $this->sentPayload());
    }

    /** @test */
    public function it_does_not_read_the_applications_own_composer_file_named_by_a_javascript_report()
    {
        $response = $this->postJson('/larabug-api/javascript-report', $this->payload([
            'file' => __DIR__ . '/../composer.json',
            'line' => 1,
        ]));

        $response->assertStatus(200);

        $this->client->assertRequestsSent(1);
        $this->assertSame([], $this->client->getRequests()[0]['exception']['executor']);
        $this->assertStringNotContainsString('larabug/larabug', $this->sentPayload());
    }

    /** @test */
    public function it_reports_a_javascript_error_without_any_source_context()
    {
        $response = $this->postJson('/larabug-api/javascript-report', $this->payload());

        $response->assertStatus(200);

        $this->client->assertRequestsSent(1);

        $exception = $this->client->getRequests()[0]['exception'];

        $this->assertSame('javascript', $exception['file_type']);
        $this->assertSame('https://example.com/js/app.js', $exception['file']);
        $this->assertSame(20, $exception['line']);
        $this->assertSame('Uncaught TypeError: window.foo is not a function', $exception['error']);
        $this->assertSame("TypeError: window.foo is not a function\n    at app.js:20:9", $exception['exception']);
        $this->assertSame('https://example.com/checkout', $exception['fullUrl']);
        $this->assertNull($exception['class']);
        $this->assertSame([], $exception['executor']);
    }

    /** @test */
    public function it_rejects_a_report_that_does_not_look_like_a_javascript_error()
    {
        $response = $this->postJson('/larabug-api/javascript-report', [
            'message' => ['an', 'array'],
            'file' => ['another', 'array'],
            'line' => 'not a line',
            'stack' => str_repeat('a', 20001),
            'url' => str_repeat('b', 2049),
        ]);

        $response->assertStatus(422);

        $this->client->assertRequestsSent(0);
    }

    /**
     * @return string
     */
    protected function sentPayload()
    {
        return json_encode($this->client->getRequests(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array $overrides
     * @return array
     */
    protected function payload(array $overrides = [])
    {
        return array_merge([
            'message' => 'Uncaught TypeError: window.foo is not a function',
            'file' => 'https://example.com/js/app.js',
            'line' => 20,
            'stack' => "TypeError: window.foo is not a function\n    at app.js:20:9",
            'url' => 'https://example.com/checkout',
        ], $overrides);
    }
}
