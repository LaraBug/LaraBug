<?php

namespace LaraBug\Logger;

use Throwable;
use Monolog\Logger;
use LaraBug\LaraBug;
use Monolog\Handler\AbstractProcessingHandler;

class LaraBugHandler extends AbstractProcessingHandler
{
    /** @var LaraBug */
    protected $laraBug;

    /**
     * @param LaraBug $laraBug
     * @param int $level
     * @param bool $bubble
     */
    public function __construct(LaraBug $laraBug, $level = Logger::ERROR, bool $bubble = true)
    {
        $this->laraBug = $laraBug;

        parent::__construct($level, $bubble);
    }

    /**
     * @param array $record
     */
    protected function write(array $record): void
    {
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof Throwable) {
            $this->laraBug->handle(
                $record['context']['exception']
            );

            return;
        }
    }
}
