<?php

declare(strict_types=1);

namespace LaraBug\Fakes;

use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertCount;

class LaraBugFake extends \LaraBug\LaraBug
{
    public array $exceptions = [];

    public function assertRequestsSent(int $expectedCount): void
    {
        assertCount($expectedCount, $this->exceptions);
    }

    public function assertNotSent(mixed $throwable, callable $callback = null): void
    {
        $collect = collect($this->exceptions[$throwable] ?? []);

        $callback = $callback ?: function () {
            return true;
        };

        $filtered = $collect->filter(fn ($arguments) => $callback($arguments));

        assertTrue($filtered->count() == 0);
    }

    public function assertNothingSent(): void
    {
        assertCount(0, $this->exceptions);
    }

    public function assertSent(mixed $throwable, callable $callback = null): void
    {
        $collect = collect($this->exceptions[$throwable] ?? []);

        $callback = $callback ?: fn () => true;

        $filtered = $collect->filter(fn ($arguments) => $callback($arguments));

        assertTrue($filtered->count() > 0);
    }

    public function handle(\Throwable $exception, string $fileType = 'php', array $customData = [])
    {
        $this->exceptions[$exception::class][] = $exception;
    }
}
