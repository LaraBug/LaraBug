<?php

namespace LaraBug\Requests;

use Illuminate\Support\Str;

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
            // Ordered rather than random: ids that sort by creation make a
            // range scan possible later, and cost nothing now.
            self::$traceId = (string) Str::orderedUuid();
        }

        return self::$traceId;
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
