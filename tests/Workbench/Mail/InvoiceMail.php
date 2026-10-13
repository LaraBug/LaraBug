<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Outside the LaraBug namespace on purpose: the job monitor never records a
 * job named after one of its own classes, and a queued mailable's job is.
 */
class InvoiceMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your invoice');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your invoice</p>');
    }
}
