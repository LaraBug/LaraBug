<?php

namespace LaraBug\Requests;

use Throwable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the database for the plan of a slow SELECT, so a statement that scans a
 * whole table for want of an index can be told apart from one that is merely
 * slow. Laravel reports how long a query took, never how many rows it read.
 *
 * This is LaraBug running a statement of its own against the customer's
 * database, so everything about it leans towards not doing it: opt-in, only
 * for a read-only SELECT on a sampled request, only once per statement per
 * interval, never EXPLAIN ANALYZE, and never while the request is still being
 * served. A statement worth explaining is noted when it runs and explained
 * when the record is assembled, which is after the response has gone.
 */
class QueryExplainer
{
    protected readonly bool $enabled;

    protected readonly float $minDurationMs;

    protected readonly int $interval;

    /**
     * The statements this request will explain, one per hash.
     *
     * @var array<string, array{index: int, connection: Connection, sql: string, bindings: array<int|string, mixed>}>
     */
    protected array $pending = [];

    /** @var array<string, int> When this process last claimed a statement, keyed by hash. */
    protected array $claimedAt = [];

    protected bool $explaining = false;

    public function __construct(protected readonly RequestMonitor $monitor)
    {
        $this->enabled = (bool) config('larabug.queries.explain.enabled', false);
        $this->minDurationMs = (float) config('larabug.queries.explain.min_duration_ms', 100);
        $this->interval = max(1, (int) config('larabug.queries.explain.interval', 3600));

        $monitor->beforeFlush($this->explainPending(...));
    }

    /**
     * True while the SDK's own statements run. Whatever they cause, the
     * EXPLAIN's own QueryExecuted or a cache write claiming the interval, is
     * not the application's and is not recorded.
     */
    public function explaining(): bool
    {
        return $this->explaining;
    }

    /**
     * Note a statement the request just ran and kept at $index, if it is one
     * worth explaining. Cheapest checks first: this runs for every kept query.
     */
    public function consider(int $index, object $event): void
    {
        if (! $this->enabled) {
            return;
        }

        if ((float) ($event->time ?? 0) < $this->minDurationMs) {
            return;
        }

        $connection = $event->connection ?? null;

        if (! $connection instanceof Connection) {
            return;
        }

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        // Inside DB::pretend() nothing ran, and the bindings may describe rows
        // that will never exist.
        if ($connection->pretending()) {
            return;
        }

        $sql = (string) $event->sql;

        if (! $this->isReadOnlySelect($sql)) {
            return;
        }

        $hash = QueryNormaliser::hash((string) $event->connectionName, QueryNormaliser::normalise($sql));

        $this->pending[$hash] ??= [
            'index' => $index,
            'connection' => $connection,
            'sql' => $sql,
            'bindings' => (array) ($event->bindings ?? []),
        ];
    }

    /**
     * Run once the request is over, from the monitor's beforeFlush.
     */
    public function explainPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        $this->explaining = true;

        try {
            foreach ($pending as $hash => $query) {
                $this->explainOne($hash, $query);
            }
        } finally {
            $this->explaining = false;
        }
    }

    /**
     * @param  array{index: int, connection: Connection, sql: string, bindings: array<int|string, mixed>}  $query
     */
    protected function explainOne(string $hash, array $query): void
    {
        try {
            if (! $this->claim($hash)) {
                return;
            }

            $plan = $this->explain($query['connection'], $query['sql'], $query['bindings']);

            if ($plan === null) {
                return;
            }

            $this->monitor->recordQueryPlan($query['index'], $hash, $plan);
        } catch (Throwable $e) {
            // Resolved only here: the buffer brings an HTTP client with it,
            // which a process that never fails an EXPLAIN has no need for.
            app(RequestBuffer::class)->reportFailure($e);
        }
    }

    /**
     * Whether this process may explain the statement now. The cache makes the
     * interval hold across every process sharing it. The process keeps its own
     * note as well, so a long-lived worker does not ask the cache again for
     * every request, and so the interval still holds within one process when
     * there is no cache store or it fails.
     */
    protected function claim(string $hash): bool
    {
        $now = now()->getTimestamp();

        if (($this->claimedAt[$hash] ?? 0) > $now - $this->interval) {
            return false;
        }

        $this->claimedAt[$hash] = $now;

        if (! config('cache.default')) {
            return true;
        }

        try {
            return Cache::add("larabug:explain:{$hash}", 1, $this->interval);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Plain EXPLAIN, never ANALYZE: the statement is planned, not run.
     *
     * Only MariaDB is capped, because only MariaDB can cap one statement on its
     * own. MySQL's max_execution_time governs SELECT statements and not an
     * EXPLAIN of one. Postgres scopes a statement_timeout to one statement only
     * with SET LOCAL inside a transaction, which would outlive the EXPLAIN in a
     * transaction the application left open, and a session level SET would
     * stay behind on a pooled connection.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<string, mixed>|null
     */
    protected function explain(Connection $connection, string $sql, array $bindings): ?array
    {
        $driver = $connection->getDriverName();
        $statement = rtrim(trim($sql), "; \t\n\r");

        if ($driver === 'pgsql') {
            return QueryPlan::fromPostgres($connection->select("EXPLAIN (FORMAT JSON) {$statement}", $bindings));
        }

        $explain = $driver === 'mariadb'
            ? "SET STATEMENT max_statement_time = 1 FOR EXPLAIN {$statement}"
            : "EXPLAIN {$statement}";

        return QueryPlan::fromMySql($driver, $connection->select($explain, $bindings));
    }

    /**
     * A single statement that only reads: it starts with SELECT, or with WITH
     * for a CTE, once leading comments are skipped.
     *
     * Deliberately blunt. A write keyword anywhere, a locking read or a second
     * statement rules it out, even where it is only a column alias or sits in
     * a string. A plan missed costs nothing; an EXPLAIN of the wrong statement
     * is not ours to risk.
     */
    protected function isReadOnlySelect(string $sql): bool
    {
        $statement = preg_replace('/^(\s*(\/\*.*?\*\/|(--|#)[^\n]*))+/s', '', $sql) ?? '';
        $statement = rtrim(trim($statement), "; \t\n\r");

        if (str_contains($statement, ';')) {
            return false;
        }

        if (! preg_match('/^(select|with)\b/i', $statement)) {
            return false;
        }

        return ! preg_match('/\b(insert|update|delete|merge|into)\b|\bfor\s+(key\s+)?share\b|\block\s+in\s+share\s+mode\b/i', $statement);
    }
}
