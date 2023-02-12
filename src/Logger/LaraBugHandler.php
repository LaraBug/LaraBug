<?php

declare(strict_types=1);

namespace LaraBug\Logger;

use Throwable;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class LaraBugHandler extends AbstractProcessingHandler
{
    public function __construct(protected $laraBug, $level = Logger::ERROR, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof Throwable) {
            $this->laraBug->handle(
                $record['context']['exception']
            );
        }
    }
}
