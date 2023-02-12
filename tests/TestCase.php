<?php

declare(strict_types=1);

namespace LaraBug\Tests;

use LaraBug\ServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }
}
