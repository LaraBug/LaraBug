<?php

namespace LaraBug\Logger;

use LaraBug\LaraBug;
use Monolog\Handler\AbstractProcessingHandler;

class LaraBugHandler extends AbstractProcessingHandler
{
    /** @var LaraBug */
    protected $larabug;

    /**
     * @param LaraBug $larabug
     * @param int $level
     * @param bool $bubble
     */
    public function __construct(LaraBug $larabug, $level = \Monolog\Logger::ERROR, bool $bubble = true)
    {
        $this->larabug = $larabug;

        parent::__construct($level, $bubble);
    }

    /**
     * @param array $record
     */
    protected function write(array $record): void
    {
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
            $this->larabug->handle(
                $record['context']['exception']
            );

            return;
        }
    }
}
