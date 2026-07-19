<?php

namespace LaraBug\Tests;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class ExceptionHandlerRegistrationTest extends TestCase
{
    /** @test */
    public function it_reports_exceptions_through_the_applications_exception_handler()
    {
        $recorder = $this->swapLaraBugForRecorder();

        $this->app[ExceptionHandler::class]->report(new Exception('reported through the handler'));

        $this->assertCount(1, $recorder->handled);
        $this->assertSame('reported through the handler', $recorder->handled[0]->getMessage());
    }

    /** @test */
    public function it_does_not_report_twice_when_the_same_handler_is_resolved_again()
    {
        $recorder = $this->swapLaraBugForRecorder();

        $handler = $this->app[ExceptionHandler::class];

        // A wrapping handler, Collision's for one, causes the container to
        // resolve the same handler a second time.
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
     *
     * @return object
     */
    protected function swapLaraBugForRecorder()
    {
        $recorder = new class {
            /** @var array<int, Throwable> */
            public $handled = [];

            public function handle(Throwable $exception, $fileType = 'php', array $customData = [])
            {
                $this->handled[] = $exception;

                return false;
            }
        };

        $this->app->instance('larabug', $recorder);

        return $recorder;
    }
}
