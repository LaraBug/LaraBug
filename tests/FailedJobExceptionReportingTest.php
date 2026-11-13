<?php

namespace LaraBug\Tests;

use Throwable;
use RuntimeException;
use LaraBug\Http\Client;
use LaraBug\Requests\TraceContext;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\DeliverReceipt;
use LaraBug\Support\ExceptionReporting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Exceptions\Handler;
use Workbench\App\Exceptions\InactiveRecipient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Orchestra\Testbench\Attributes\WithMigration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * A failed job's record carries the exception it failed with, and the server
 * opens an issue for it. An exception the application chose not to report
 * must not come in that way either: the job is still recorded as failed, but
 * without the exception.
 */
#[WithMigration('queue')]
class FailedJobExceptionReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected MeteredClient $client;

    protected function getEnvironmentSetUp($app): void
    {
        $this->client = new MeteredClient();

        $app->extend(Client::class, fn () => $this->client);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'testing');
        $app['config']->set('queue.failed.database', 'testing');
        $app['config']->set('larabug.project_key', 'project');
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_job_that_fails_with_a_reported_exception_carries_it()
    {
        Queue::push(new DeliverReceipt());

        $this->work();

        $record = $this->failedRecord();

        $this->assertSame(RuntimeException::class, $record['exception']['class']);
        $this->assertSame('Receipt could not be delivered', $record['exception']['message']);
    }

    #[Test]
    public function a_job_that_fails_with_an_exception_excluded_by_dont_report_when_carries_no_exception()
    {
        if (! method_exists(Handler::class, 'dontReportWhen')) {
            $this->markTestSkipped('dontReportWhen() arrived in Laravel 12.');
        }

        $this->handler()->dontReportWhen(fn ($exception) => $exception instanceof InactiveRecipient);

        Queue::push(new DeliverReceipt(InactiveRecipient::class, 'Recipient is inactive'));

        $this->work();

        $this->assertFailedWithoutException($this->failedRecord());
    }

    #[Test]
    public function a_job_that_fails_with_an_exception_on_the_dont_report_list_carries_no_exception()
    {
        $this->handler()->dontReport(InactiveRecipient::class);

        Queue::push(new DeliverReceipt(InactiveRecipient::class));

        $this->work();

        $this->assertFailedWithoutException($this->failedRecord());
    }

    #[Test]
    public function a_job_that_fails_with_an_exception_larabug_is_told_to_skip_carries_no_exception()
    {
        config(['larabug.except' => [InactiveRecipient::class]]);

        Queue::push(new DeliverReceipt(InactiveRecipient::class));

        $this->work();

        $this->assertFailedWithoutException($this->failedRecord());
    }

    #[Test]
    public function a_handler_that_throws_while_deciding_leaves_the_exception_on()
    {
        $this->app->instance(ExceptionHandler::class, new class ($this->app) extends Handler {
            public function shouldReport(Throwable $e)
            {
                throw new RuntimeException('Handler is broken');
            }
        });

        $this->assertTrue(ExceptionReporting::wouldReport(new InactiveRecipient()));
    }

    #[Test]
    public function asking_spends_none_of_the_applications_throttle()
    {
        $this->handler()->throttleUsing(fn (InactiveRecipient $exception) => Limit::perMinute(1));

        $exception = new InactiveRecipient();

        $this->assertTrue(ExceptionReporting::wouldReport($exception));
        $this->assertTrue(ExceptionReporting::wouldReport($exception));

        // The one report a minute is still there for the application itself.
        $this->assertTrue($this->handler()->shouldReport($exception));
    }

    #[Test]
    public function an_exception_the_handler_already_reported_is_still_one_it_reports()
    {
        $handler = $this->handler();
        $handler->dontReportDuplicates();

        $exception = new InactiveRecipient();
        $handler->report($exception);

        $this->assertTrue(ExceptionReporting::wouldReport($exception));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function assertFailedWithoutException(array $record): void
    {
        $this->assertSame('failed', $record['status']);
        $this->assertArrayNotHasKey('exception', $record);
        $this->assertSame(1, $record['attempts']);
        $this->assertArrayHasKey('duration_ms', $record);
        $this->assertNotNull($record['duration_ms']);
        $this->assertArrayHasKey('failed_at', $record);
    }

    protected function handler(): Handler
    {
        return $this->app->make(ExceptionHandler::class);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ])->run();
    }

    /** @return array<string, mixed> */
    protected function failedRecord(): array
    {
        $records = array_column($this->client->requests, 'job');

        $this->assertSame(['failed'], array_column($records, 'status'));

        return $records[0];
    }
}
