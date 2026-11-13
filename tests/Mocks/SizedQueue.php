<?php

namespace LaraBug\Tests\Mocks;

use RuntimeException;
use Illuminate\Container\Container;

/**
 * A queue as an older Laravel has it: size() and nothing finer.
 *
 * Sizes are keyed by queue name. A queue it was not given one for throws, the
 * way an unreachable broker does.
 */
class SizedQueue
{
    /** @var array<int, string> The queues asked about, in order. */
    public array $asked = [];

    protected ?string $connectionName = null;

    /**
     * @param  array<string, int>  $sizes
     */
    public function __construct(public array $sizes = [])
    {
    }

    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function setContainer(Container $container): static
    {
        return $this;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    public function size($queue = null): int
    {
        $this->asked[] = $queue;

        if (! array_key_exists($queue, $this->sizes)) {
            throw new RuntimeException("Could not reach the broker for [{$queue}].");
        }

        return $this->sizes[$queue];
    }
}
