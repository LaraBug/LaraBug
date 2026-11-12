<?php

namespace LaraBug\Tests;

use LaraBug\ServiceProvider;
use LaraBug\Http\RetryBuffer;
use LaraBug\Support\LimitBackoff;
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

        // A directory of its own per test, so an event one test failed to send
        // is never flushed by the next one, nor left in the testbench skeleton.
        $this->retryBufferPath = sys_get_temp_dir().'/larabug-retry-buffer-'.bin2hex(random_bytes(6));

        config(['larabug.retry_buffer.path' => $this->retryBufferPath]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->retryBufferPath);

        RetryBuffer::reset();

        parent::tearDown();
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
