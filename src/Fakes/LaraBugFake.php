<?php

declare(strict_types=1);

namespace LaraBug\Fakes;

use PHPUnit\Framework\Assert as PHPUnit;

class LaraBugFake extends \LaraBug\LaraBug
{
    /** @var array */
    public $exceptions = [];

    public function assertRequestsSent(int $expectedCount)
    {
        PHPUnit::assertCount($expectedCount, $this->exceptions);
    }

    /**
     * @param callable|null $callback
     */
    public function assertNotSent(mixed $throwable, $callback = null)
    {
        $collect = collect($this->exceptions[$throwable] ?? []);

        $callback = $callback ?: function () {
            return true;
        };

        $filtered = $collect->filter(function ($arguments) use ($callback) {
            return $callback($arguments);
        });

        PHPUnit::assertTrue($filtered->count() == 0);
    }

    public function assertNothingSent()
    {
        PHPUnit::assertCount(0, $this->exceptions);
    }

    /**
     * @param callable|null $callback
     */
    public function assertSent(mixed $throwable, $callback = null)
    {
        $collect = collect($this->exceptions[$throwable] ?? []);

        $callback = $callback ?: function () {
            return true;
        };

        $filtered = $collect->filter(function ($arguments) use ($callback) {
            return $callback($arguments);
        });

        PHPUnit::assertTrue($filtered->count() > 0);
    }

    public function handle(\Throwable $exception, $fileType = 'php', array $customData = [])
    {
        $this->exceptions[$exception::class][] = $exception;
    }
}
