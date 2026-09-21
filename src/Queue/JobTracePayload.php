<?php

namespace LaraBug\Queue;

use Throwable;
use Illuminate\Queue\Queue;
use LaraBug\Requests\TraceContext;
use Illuminate\Contracts\Queue\Job;

/**
 * The trace a job was dispatched from, written into the job's payload and read
 * back out of it.
 *
 * A job is usually run by another process, minutes later, with the request that
 * dispatched it long gone. Nothing in the worker can look the parent up, so the
 * dispatching trace id travels with the job itself, next to the payload keys
 * Laravel writes.
 */
class JobTracePayload
{
    private static string $key = 'larabug_parent_trace_id';

    public static function register(): void
    {
        Queue::createPayloadUsing(fn () => [self::$key => TraceContext::id()]);
    }

    /**
     * The trace that dispatched this job, or null for a job pushed before this
     * version of the package, by another application, or by hand.
     */
    public static function parentIdFor(Job $job): ?string
    {
        try {
            $payload = json_decode($job->getRawBody(), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $parentTraceId = $payload[self::$key] ?? null;

        return is_string($parentTraceId) && $parentTraceId !== '' ? $parentTraceId : null;
    }
}
