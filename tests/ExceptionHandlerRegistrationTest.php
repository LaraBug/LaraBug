<?php

namespace LaraBug\Tests;

use Exception;
use Throwable;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Debug\ExceptionHandler;

class ExceptionHandlerRegistrationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Below Laravel 8 the provider leaves the handler alone and an application calls handle() itself.
        if (! method_exists($this->app[ExceptionHandler::class], 'reportable')) {
            $this->markTestSkipped('The exception handler accepts reportable callbacks from Laravel 8 onwards.');
        }
    }

    #[Test]
    public function it_reports_exceptions_through_the_applications_exception_handler()
    {
        $recorder = $this->swapLaraBugForRecorder();

        $this->app[ExceptionHandler::class]->report(new Exception('reported through the handler'));

        $this->assertCount(1, $recorder->handled);
        $this->assertSame('reported through the handler', $recorder->handled[0]->getMessage());
    }

    #[Test]
    public function it_does_not_report_twice_when_the_same_handler_is_resolved_again()
    {
        $recorder = $this->swapLaraBugForRecorder();

        $handler = $this->app[ExceptionHandler::class];

        // A wrapping handler, Collision's for one, makes the container resolve the same handler twice.
        $this->app->forgetInstance(ExceptionHandler::class);
        $this->app->bind(ExceptionHandler::class, function () use ($handler) {
            return $handler;
        });
        $this->app->make(ExceptionHandler::class);

        $handler->report(new Exception('reported once'));

        $this->assertCount(1, $recorder->handled);
    }

    /**
     * Swap the container binding so reporting records instead of sending.
     */
    protected function swapLaraBugForRecorder(): object
    {
        $recorder = new class () {
            /** @var array<int, Throwable> */
            public array $handled = [];

            public function handle(Throwable $exception, $fileType = 'php', array $customData = []): bool
            {
                $this->handled[] = $exception;

                return false;
            }
        };

        $this->app->instance('larabug', $recorder);

        return $recorder;
    }
}
