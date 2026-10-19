<?php

namespace Workbench\App\Jobs;

use RuntimeException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Finds the warehouse unreachable on its first attempt and throws, to be
 * retried.
 */
class ReserveStock implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            throw new RuntimeException('Warehouse unreachable');
        }
    }
}
