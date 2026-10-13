<?php

namespace Workbench\App;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A mail server that refuses every message, the way an unreachable SMTP host
 * does: the sending event fires, the sent event never comes.
 */
class FailingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('Connection refused');
    }

    public function __toString(): string
    {
        return 'failing';
    }
}
