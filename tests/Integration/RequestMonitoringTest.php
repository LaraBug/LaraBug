<?php

namespace LaraBug\Tests\Integration;

use RuntimeException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use LaraBug\Requests\RequestBuffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Request monitoring, driven by actually serving requests.
 *
 * The middleware is only installed outside the console, which is why none of
 * this could be covered before: a test process is the console. The base class
 * tells Laravel otherwise for the tests that need it.
 */
#[DefineEnvironment('trackRequests')]
class RequestMonitoringTest extends IntegrationTestCase
{
    protected bool $servesHttpRequests = true;

    #[Test]
    public function a_served_request_is_reported_with_what_it_did(): void
    {
        Http::fake(['larabug-app.test/*' => Http::response('{}')]);

        $this->get('/example/orders?status=open&page=2')->assertOk();

        $this->flushRequests();

        $sent = $this->transport->first('requests_batch');

        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame(1, $sent->payload('count'));

        $record = $sent->payload('requests')[0];

        $this->assertSame('GET', $record['method']);
        $this->assertSame('/example/orders', $record['path']);
        $this->assertSame('/example/orders', $record['route_path']);
        $this->assertSame('example.orders', $record['route_name']);
        $this->assertSame(200, $record['status_code']);
        $this->assertSame('testing', $record['environment']);

        // Only the names of the query parameters, never their values.
        $this->assertSame('status,page', $record['query_keys']);

        $this->assertNotEmpty($record['sql'], 'The queries the request ran should travel with it.');
        $this->assertNotEmpty($record['outgoing'], 'The calls the request made should travel with it.');
    }

    #[Test]
    public function a_request_that_throws_carries_the_same_trace_as_its_exception(): void
    {
        $this->get('/example/boom')->assertStatus(500);

        $this->flushRequests();

        $report = $this->transport->first('report');
        $request = $this->transport->first('requests_batch')->payload('requests')[0];

        $this->assertSame(500, $request['status_code']);
        $this->assertNotSame('', $request['trace_id']);
        $this->assertSame(
            $request['trace_id'],
            $report->payload('exception.trace_id'),
            'A failed request and the issue it caused have to be joinable.'
        );
    }

    #[Test]
    #[DefineEnvironment('sampleNothing')]
    public function nothing_is_collected_while_the_sample_rate_says_no(): void
    {
        $this->get('/example/orders')->assertOk();

        $this->flushRequests();

        $this->transport->assertSentCount(0, 'requests_batch');
    }

    #[Test]
    #[DefineEnvironment('ignoreTheExamplePaths')]
    public function an_ignored_path_is_never_collected(): void
    {
        $this->get('/example/orders')->assertOk();

        $this->flushRequests();

        $this->transport->assertSentCount(0, 'requests_batch');
    }

    /**
     * The sampler reads its rates once, when it is built, so both of these
     * have to be in place before the application exists.
     *
     * @param  Application  $app
     */
    protected function sampleNothing($app): void
    {
        $app['config']->set('larabug.requests.sample_rate', 0.0);
        $app['config']->set('larabug.requests.exception_sample_rate', 0.0);
    }

    /**
     * @param  Application  $app
     */
    protected function ignoreTheExamplePaths($app): void
    {
        $app['config']->set('larabug.requests.ignore_paths', ['/example/*']);
    }

    /**
     * @param  Application  $app
     */
    protected function trackRequests($app): void
    {
        $app['config']->set('larabug.requests.track_requests', true);
        $app['config']->set('larabug.requests.sample_rate', 1.0);
        $app['config']->set('larabug.requests.exception_sample_rate', 1.0);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/example/orders', function () {
            DB::select('select 1 as one');

            Http::get('https://larabug-app.test/warm');

            return response('orders');
        })->name('example.orders');

        $router->get('/example/boom', function () {
            throw new RuntimeException('the order page is broken');
        })->name('example.boom');
    }

    /**
     * The buffer sends on shutdown, which a test process does not reach.
     */
    protected function flushRequests(): void
    {
        $this->app->make(RequestBuffer::class)->flush();
    }
}
