<?php

namespace LaraBug\Requests;

/**
 * What a database's EXPLAIN says about a statement, cut down to the few
 * fields that tell a missing index apart: whether a table is scanned whole,
 * which one, how many rows the planner expects to read, and which index it
 * used or could have used.
 *
 * Built from the output rather than sent as it: a Postgres plan spells the
 * bound values out in its filters, and the raw plan of a large query is
 * bigger than the request record carrying it.
 */
class QueryPlan
{
    /**
     * A MySQL or MariaDB plan: one row per table the statement reads.
     *
     * `ALL` is a table scan. `index` reads a whole index instead, which is the
     * same problem when no index was usable to narrow it down. The row that
     * describes the plan is the one expected to read the most, among the
     * scanning rows when there are any.
     *
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public static function fromMySql(string $driver, array $rows): ?array
    {
        $rows = array_map(fn ($row) => (array) $row, $rows);

        if ($rows === []) {
            return null;
        }

        $scanning = array_filter($rows, fn (array $row) => self::scansWholeTable($row));

        $worst = self::largest($scanning === [] ? $rows : $scanning, 'rows');

        return self::summary(
            driver: $driver,
            fullScan: $scanning !== [],
            table: $worst['table'] ?? null,
            rowsEstimate: $worst['rows'] ?? null,
            key: $worst['key'] ?? null,
            possibleKeys: $worst['possible_keys'] ?? null,
        );
    }

    /**
     * A Postgres plan, asked for as JSON: one row holding a tree of nodes.
     *
     * A `Seq Scan` node is a table scan, parallel or not. Postgres names no
     * index it could have used, so the summary carries neither key.
     *
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public static function fromPostgres(array $rows): ?array
    {
        $root = self::postgresRoot($rows);

        if ($root === null) {
            return null;
        }

        $relations = array_filter(
            self::postgresNodes($root),
            fn (array $node) => isset($node['Relation Name'])
        );

        $seqScans = array_filter($relations, fn (array $node) => ($node['Node Type'] ?? null) === 'Seq Scan');

        $worst = self::largest($seqScans === [] ? $relations : $seqScans, 'Plan Rows');

        return self::summary(
            driver: 'pgsql',
            fullScan: $seqScans !== [],
            table: $worst['Relation Name'] ?? null,
            rowsEstimate: $worst['Plan Rows'] ?? null,
            key: null,
            possibleKeys: null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function scansWholeTable(array $row): bool
    {
        $type = strtoupper((string) ($row['type'] ?? ''));

        if ($type === 'ALL') {
            return true;
        }

        return $type === 'INDEX' && empty($row['possible_keys']);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected static function postgresRoot(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $columns = (array) reset($rows);
        $plan = reset($columns);

        // pdo_pgsql hands the json column back as a string. A connection that
        // decodes it already is just as welcome.
        if (is_string($plan)) {
            $plan = json_decode($plan, true);
        }

        $root = is_array($plan) ? ($plan[0]['Plan'] ?? null) : null;

        return is_array($root) ? $root : null;
    }

    /**
     * Every node in the tree, its subplans and init plans included.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    protected static function postgresNodes(array $node): array
    {
        $nodes = [$node];

        foreach ($node['Plans'] ?? [] as $child) {
            if (is_array($child)) {
                array_push($nodes, ...self::postgresNodes($child));
            }
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected static function largest(array $rows, string $column): array
    {
        usort($rows, fn (array $a, array $b) => (float) ($b[$column] ?? 0) <=> (float) ($a[$column] ?? 0));

        return $rows[0] ?? [];
    }

    /**
     * The shape agreed with the server. Nothing in it is a value from the
     * statement: a table, an index name and an estimate.
     *
     * @return array<string, mixed>
     */
    protected static function summary(
        string $driver,
        bool $fullScan,
        mixed $table,
        mixed $rowsEstimate,
        mixed $key,
        mixed $possibleKeys,
    ): array {
        return [
            'driver' => $driver,
            'full_scan' => $fullScan,
            'table' => $table === null ? null : (string) $table,
            'rows_estimate' => $rowsEstimate === null ? null : (int) $rowsEstimate,
            'key' => $key === null || $key === '' ? null : (string) $key,
            'possible_keys' => $possibleKeys === null || $possibleKeys === '' ? null : (string) $possibleKeys,
            'explained_at' => now('UTC')->toIso8601String(),
        ];
    }
}
