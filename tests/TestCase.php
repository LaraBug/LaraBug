<?php

namespace LaraBug\Tests;

use LaraBug\ServiceProvider;
use LaraBug\Support\AllowanceBackoff;
use Illuminate\Foundation\Application;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // The backoff is process wide on purpose, so a test that earns itself a
        // 402 would otherwise mute every test that runs after it.
        AllowanceBackoff::clear();
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
