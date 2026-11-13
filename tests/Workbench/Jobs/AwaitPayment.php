<?php

namespace Workbench\App\Jobs;

use RuntimeException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Finds the payment missing on its first attempt and puts itself back on the
 * queue to look again later.
 */
class AwaitPayment implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        public int $releasesFor = 60,
        public bool $throwsAfterwards = false,
    ) {
    }

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release($this->releasesFor);
        }

        if ($this->throwsAfterwards) {
            throw new RuntimeException('Payment provider did not answer');
        }
    }
}
