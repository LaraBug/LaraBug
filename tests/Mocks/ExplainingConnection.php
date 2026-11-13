<?php

namespace LaraBug\Tests\Mocks;

use PDO;
use Throwable;
use Illuminate\Database\SQLiteConnection;

/**
 * An in-memory connection that answers to the name of another driver, and
 * answers an EXPLAIN with a fixture instead of asking SQLite.
 *
 * The EXPLAIN still goes through run(), so it fires its own QueryExecuted
 * the way a real one would.
 */
class ExplainingConnection extends SQLiteConnection
{
    /** @var array<int, array{query: string, bindings: array<int|string, mixed>}> */
    public array $explained = [];

    /** @var array<int, object> */
    public array $plan = [];

    public ?Throwable $failure = null;

    public function __construct(string $driver, string $name = 'fake')
    {
        parent::__construct(new PDO('sqlite::memory:'), ':memory:', '', [
            'driver' => $driver,
            'name' => $name,
        ]);
    }

    /**
     * The fourth parameter is Laravel 13's. Declared optional, it fits the
     * signature of every version this package supports.
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (! str_contains($query, 'EXPLAIN')) {
            return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            $this->explained[] = ['query' => $query, 'bindings' => $bindings];

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->plan;
        });
    }
}
