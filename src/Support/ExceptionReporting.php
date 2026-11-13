<?php

namespace LaraBug\Support;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Contracts\Debug\ExceptionHandler;

class ExceptionReporting
{
    /**
     * Whether an exception would reach LaraBug through the application's
     * exception handler, for a path that carries one to the server without
     * going through that handler, such as a failed job's record.
     *
     * Two things stand between an exception and LaraBug on the handler path:
     * the handler's own `shouldReport()`, which is where `$dontReport`,
     * `dontReportWhen()`, `ShouldntReport` and an overridden `shouldReport()`
     * all end up, and the SDK's `except` list, which LaraBug::handle() checks
     * after it. Both are asked here, the second through LaraBug itself so the
     * list keeps one reading.
     *
     * Laravel's handler also says no to an exception it has already reported
     * (`dontReportDuplicates()`) and to one past its throttle (`throttleUsing()`),
     * and the throttle spends an attempt of the application's rate limit each
     * time it is asked. Neither is the application excluding the exception, and
     * asking from here must not use up the one report a minute the application
     * allowed itself, so both are switched off for the duration of the question
     * on Laravel's handler. A handler of another kind is asked as it is.
     *
     * True when the answer cannot be had: a handler that throws while deciding
     * leaves things as they were before this was asked at all.
     */
    public static function wouldReport(Throwable $exception): bool
    {
        try {
            if (app('larabug')->isSkipException($exception::class)) {
                return false;
            }

            $handler = app(ExceptionHandler::class);

            if (! method_exists($handler, 'shouldReport')) {
                return true;
            }

            if (! $handler instanceof Handler) {
                return (bool) $handler->shouldReport($exception);
            }

            return (bool) static::askWithoutThrottleOrDuplicates($handler, $exception);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Laravel's handler, asked the way report() would ask it (on the exception
     * as mapped) with its duplicate guard and its throttle out of the way, and
     * both put back however the question ends.
     */
    protected static function askWithoutThrottleOrDuplicates(Handler $handler, Throwable $exception): bool
    {
        return (function () use ($exception): bool {
            // Looked up rather than assumed, so a Laravel without one of them
            // is asked as it is instead of growing a property it never had.
            $suspended = array_filter([
                'withoutDuplicates' => false,
                'throttleCallbacks' => [],
            ], fn (string $property) => property_exists($this, $property), ARRAY_FILTER_USE_KEY);

            $original = [];

            foreach ($suspended as $property => $value) {
                $original[$property] = $this->{$property};
                $this->{$property} = $value;
            }

            try {
                return $this->shouldReport(method_exists($this, 'mapException') ? $this->mapException($exception) : $exception);
            } finally {
                foreach ($original as $property => $value) {
                    $this->{$property} = $value;
                }
            }
        })->call($handler);
    }
}
