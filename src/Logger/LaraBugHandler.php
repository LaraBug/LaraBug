<?php

namespace LaraBug\Logger;

use Throwable;
use Monolog\Logger;
use LaraBug\LaraBug;
use Monolog\Handler\AbstractProcessingHandler;

class LaraBugHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected readonly LaraBug $laraBug,
        $level = Logger::ERROR,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write($record): void
    {
        $exception = $record['context']['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            return;
        }

        $this->laraBug->handle($exception);
    }
}
