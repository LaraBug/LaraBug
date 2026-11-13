<?php

namespace Workbench\App\Jobs;

use RuntimeException;
use Workbench\App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendInvoice implements ShouldQueue
{
    public function __construct(
        public string $mailer = 'array',
        public bool $sends = true,
        public bool $throwsAfterwards = false,
    ) {
    }

    public function handle(): void
    {
        if ($this->sends) {
            Mail::mailer($this->mailer)->to('alice@example.com')->send(new InvoiceMail());
        }

        if ($this->throwsAfterwards) {
            throw new RuntimeException('Invoice could not be archived');
        }
    }
}
