<?php

namespace LaraBug\Tests;

use Closure;
use LaraBug\ServiceProvider;
use LaraBug\Http\RetryBuffer;
use LaraBug\Logger\LogBuffer;
use LaraBug\Support\LimitBackoff;
use LaraBug\Requests\TraceContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected string $retryBufferPath;

    public function setUp(): void
    {
        parent::setUp();

        // The backoff is process wide on purpose, so a test that earns itself a
        // 402 would otherwise mute every test that runs after it.
        LimitBackoff::clear();

        RetryBuffer::reset();

        // The trace is process wide too, so a parent one test continued would
        // otherwise be the parent of whatever the next test reports.
        TraceContext::reset();

        // A directory of its own per test, so an event one test failed to send
        // is never flushed by the next one, nor left in the testbench skeleton.
        $this->retryBufferPath = sys_get_temp_dir().'/larabug-retry-buffer-'.bin2hex(random_bytes(6));

        config(['larabug.retry_buffer.path' => $this->retryBufferPath]);
    }

    protected function tearDown(): void
    {
        $this->discardBufferedLogLines();

        (new Filesystem())->deleteDirectory($this->retryBufferPath);

        RetryBuffer::reset();

        parent::tearDown();
    }

    /**
     * Drop the log lines a test left in the buffer without sending them.
     *
     * The package adds its channel to the default stack, so a test that
     * reports an exception leaves a line behind. Monolog closes the handler
     * when the garbage collector gets to it, which is during some later test,
     * and close() sends the line. That send cannot reach a server, and the
     * global config by then is the later test's, so the line landed in its
     * retry buffer and paused it. When that happened depended on the
     * collector, so the suite failed in some orders and on some PHP and
     * Laravel versions only.
     */
    protected function discardBufferedLogLines(): void
    {
        if (! $this->app?->resolved(LogBuffer::class)) {
            return;
        }

        Closure::bind(fn () => $this->buffer = [], $this->app[LogBuffer::class], LogBuffer::class)();
    }

    /**
     * @param Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }
}
