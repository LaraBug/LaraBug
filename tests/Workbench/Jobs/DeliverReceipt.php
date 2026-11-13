<?php

namespace Workbench\App\Jobs;

use RuntimeException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Throws whichever exception it was dispatched with, so one job can fail
 * with an exception the application reports and with one it does not.
 */
class DeliverReceipt implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * @param  class-string<\Throwable>  $exception
     */
    public function __construct(
        public string $exception = RuntimeException::class,
        public string $message = 'Receipt could not be delivered',
    ) {
    }

    public function handle(): void
    {
        throw new $this->exception($this->message);
    }
}
