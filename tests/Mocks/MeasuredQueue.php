<?php

namespace LaraBug\Tests\Mocks;

/**
 * A queue as a recent Laravel has it, able to tell pending, delayed and
 * reserved jobs apart and to say when the oldest pending one was queued.
 */
class MeasuredQueue extends SizedQueue
{
    public function __construct(
        array $sizes = [],
        public int $pending = 0,
        public int $delayed = 0,
        public int $reserved = 0,
        public ?int $oldestPendingAt = null,
    ) {
        parent::__construct($sizes);
    }

    public function pendingSize($queue = null): int
    {
        $this->size($queue);

        return $this->pending;
    }

    public function delayedSize($queue = null): int
    {
        return $this->delayed;
    }

    public function reservedSize($queue = null): int
    {
        return $this->reserved;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return $this->oldestPendingAt;
    }
}
