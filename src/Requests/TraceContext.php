<?php

namespace LaraBug\Requests;

/**
 * One id shared by everything this execution reports.
 *
 * The request record, the log lines written during it and the exception it
 * threw all carry the same trace id, which is what lets the panel show the
 * lines leading up to an error rather than three unrelated lists.
 *
 * Static because the things that need it cannot reach each other: a Monolog
 * handler, an exception reporter and a middleware share no object, and passing
 * an id between them would mean touching every one of their signatures.
 */
class TraceContext
{
    /** @var string|null */
    protected static $traceId = null;

    public static function id(): string
    {
        if (self::$traceId === null) {
            self::$traceId = self::generate();
        }

        return self::$traceId;
    }

    /**
     * A time-ordered id, built here rather than taken from Str::orderedUuid().
     *
     * The framework helper routes through ramsey/uuid's COMB generator, which
     * on the versions Laravel 6 and 7 resolve to needs moontoast/math to
     * convert a 128 bit integer. This package supports those releases and is
     * not going to add a dependency to make an id.
     *
     * The layout is the same bargain orderedUuid strikes: 48 bits of
     * millisecond timestamp in front, random after, so ids sort by creation
     * and a range scan stays possible later.
     */
    protected static function generate(): string
    {
        $milliseconds = (int) round(microtime(true) * 1000);
        $hex = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);

        $random = bin2hex(random_bytes(10));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 4),
            substr($random, 4, 4),
            substr($random, 8, 12)
        );
    }

    /**
     * Starts a new trace. Called when a queued job or a console command begins,
     * because those are separate executions from the request that dispatched
     * them and sharing an id would merge two stories into one.
     */
    public static function reset(): void
    {
        self::$traceId = null;
    }
}
