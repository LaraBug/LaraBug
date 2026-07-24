<?php

namespace LaraBug\Fakes;

use Throwable;
use LaraBug\LaraBug;
use PHPUnit\Framework\Assert as PHPUnit;

class LaraBugFake extends LaraBug
{
    /** @var array<class-string, array<int, Throwable>> */
    public array $exceptions = [];

    public function assertRequestsSent(int $expectedCount): void
    {
        PHPUnit::assertCount($expectedCount, $this->exceptions);
    }

    public function assertNotSent(mixed $throwable, ?callable $callback = null): void
    {
        $callback = $callback ?: fn () => true;

        $filtered = collect($this->exceptions[$throwable] ?? [])
            ->filter(fn ($arguments) => $callback($arguments));

        PHPUnit::assertTrue($filtered->count() == 0);
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertCount(0, $this->exceptions);
    }

    public function assertSent(mixed $throwable, ?callable $callback = null): void
    {
        $callback = $callback ?: fn () => true;

        $filtered = collect($this->exceptions[$throwable] ?? [])
            ->filter(fn ($arguments) => $callback($arguments));

        PHPUnit::assertTrue($filtered->count() > 0);
    }

    public function handle(Throwable $exception, string $fileType = 'php', array $customData = []): mixed
    {
        $this->exceptions[$exception::class][] = $exception;

        return null;
    }
}
