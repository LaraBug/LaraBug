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

    /**
     * md5, not xxh128: this package supports PHP 7.4 and xxh128 arrived in 8.1,
     * so the faster algorithm would have thrown on half the versions we claim
     * to run on. It is a grouping key, not a signature — collisions cost a
     * merged row, not a vulnerability.
     */
    public static function hash(string $connection, string $normalisedSql): string
    {
        return md5($connection.','.$normalisedSql);
    }
}
