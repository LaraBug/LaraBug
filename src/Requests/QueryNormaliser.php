<?php

namespace LaraBug\Requests;

/**
 * Turns one statement into the shape it shares with every other run of itself.
 *
 * Two things blow up query cardinality in practice, and both are cheap to
 * collapse: an IN list whose length follows the data, and a multi-row INSERT
 * whose tuple count follows the batch. Left alone, one logical query becomes
 * thousands of distinct strings and the grouped view is useless.
 *
 * Normalising here rather than on ingest means the server never parses SQL at
 * request volume, and the hash the grouping relies on cannot drift between the
 * two sides.
 */
class QueryNormaliser
{
    public static function normalise(string $sql): string
    {
        // in (?, ?, ?) and in (1, 2, 3) both become in (...?)
        $sql = preg_replace('/\bin\s*\([\d?\s,\'"]+\)/i', 'in (...?)', $sql);

        // A multi-row insert collapses to a single tuple.
        $sql = preg_replace('/\bvalues\s*(\([^()]*\)\s*,\s*)+\([^()]*\)/i', 'values (...?)', $sql);

        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    public static function hash(string $connection, string $normalisedSql): string
    {
        return hash('xxh128', $connection.','.$normalisedSql);
    }
}
