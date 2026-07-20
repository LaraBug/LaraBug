<?php

namespace LaraBug\Tests;

use Illuminate\Support\Facades\Log;
use LaraBug\Http\Client;
use LaraBug\Logger\LogBuffer;
use LaraBug\Tests\Mocks\LaraBugClient;
use RuntimeException;

class LogShippingTest extends TestCase
{
    /** @var LaraBugClient */
    protected $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new LaraBugClient('login', 'project');
        $this->app->instance(Client::class, $this->client);

        $this->app['config']['larabug.project_key'] = 'project';

        // Deliberately no logging.channels.larabug-logs here: the package
        // defines the channel, and naming it is all an application should have
        // to do.
        $this->app['config']['logging.default'] = 'larabug-logs';
    }

    /** @test */
    public function it_defines_the_channel_without_any_logging_config()
    {
        $this->assertSame(
            'larabug-logs',
            $this->app['config']['logging.channels.larabug-logs.driver']
        );

        Log::info('Shipped through a channel nobody declared');

        $this->buffer()->flush();

        $this->client->assertRequestsSent(1);
    }

    /** @test */
    public function an_application_defined_channel_wins()
    {
        $this->app['config']['logging.channels.larabug-logs'] = [
            'driver' => 'larabug-logs',
            'level' => 'warning',
        ];

        Log::info('Below the threshold');
        Log::warning('At the threshold');

        $this->buffer()->flush();

        $logs = $this->client->requests()[0]['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame('At the threshold', $logs[0]['message']);
    }

    /** @test */
    public function it_ships_log_lines_as_a_batch()
    {
        Log::info('First line');
        Log::error('Second line');

        $this->client->assertRequestsSent(0);

        $this->buffer()->flush();

        $this->client->assertRequestsSent(1);

        $payload = $this->client->requests()[0];

        $this->assertSame('logs_batch', $payload['type']);
        $this->assertSame('project', $payload['project']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame('First line', $payload['logs'][0]['message']);
        $this->assertSame('info', $payload['logs'][0]['level']);
        $this->assertSame('error', $payload['logs'][1]['level']);
    }

    /** @test */
    public function it_sends_automatically_once_the_batch_is_full()
    {
        $this->app['config']['larabug.logs.batch_size'] = 2;

        Log::info('One');
        Log::info('Two');

        $this->client->assertRequestsSent(1);
    }

    /** @test */
    public function it_strips_the_exception_from_the_context()
    {
        // The Throwable is what LaraBugHandler reports separately, and left in
        // place it would be the largest thing in the payload.
        Log::error('Something broke', ['exception' => new RuntimeException('boom'), 'order' => 7]);

        $this->buffer()->flush();

        $context = $this->client->requests()[0]['logs'][0]['context'];

        $this->assertArrayNotHasKey('exception', $context);
        $this->assertSame(7, $context['order']);
    }

    /** @test */
    public function it_carries_correlation_ids_from_the_context()
    {
        Log::info('Correlated', ['trace_id' => 'trace-1', 'user_identifier' => 'user-2']);

        $this->buffer()->flush();

        $log = $this->client->requests()[0]['logs'][0];

        $this->assertSame('trace-1', $log['trace_id']);
        $this->assertSame('user-2', $log['user_identifier']);
    }

    /** @test */
    public function it_bounds_what_a_single_context_may_carry()
    {
        $this->app['config']['larabug.logs.max_context_keys'] = 2;

        Log::info('Wide context', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

        $this->buffer()->flush();

        $context = $this->client->requests()[0]['logs'][0]['context'];

        $this->assertSame([1, 2], [$context['a'], $context['b']]);
        $this->assertArrayNotHasKey('c', $context);
        $this->assertTrue($context['_truncated']);
    }

    /** @test */
    public function it_sends_nothing_once_the_server_refuses_the_batch()
    {
        $this->app['config']['larabug.logs.enabled'] = false;

        Log::info('Not going anywhere');

        $this->buffer()->flush();

        $this->client->assertRequestsSent(0);
    }

    /**
     * @return LogBuffer
     */
    protected function buffer()
    {
        return $this->app[LogBuffer::class];
    }
}
