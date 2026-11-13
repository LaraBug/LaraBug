<?php

namespace Workbench\App\Jobs;

use RuntimeException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class RejectInvoice implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(): void
    {
        $this->fail(new RuntimeException('Invoice has no customer'));
    }
}
