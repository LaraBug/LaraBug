<?php

namespace LaraBug\Requests;

/**
 * The W3C traceparent header: how a trace leaves this process and how one
 * arrives.
 *
 * `00-<32 hex trace id>-<16 hex span id>-01`. Our own trace ids are the same
 * 32 hex characters with dashes in them, so the two forms are the same value
 * written differently and are converted rather than mapped.
 *
 * The header is the standard one rather than something of ours because the
 * other end may not be a LaraBug application at all, and anything that already
 * speaks W3C trace context then continues the story for free.
 */
class Traceparent
{
    /**
     * The header value for the trace this execution is running in. The span id
     * is fresh per call: it names this one hop, not the trace.
     */
    public static function forCurrentTrace(): string
    {
        return sprintf(
            '00-%s-%s-01',
            str_replace('-', '', TraceContext::id()),
            bin2hex(random_bytes(8))
        );
    }

    /**
     * The trace id a caller sent, or null for anything that is not exactly the
     * header the spec describes.
     *
     * Strict on purpose. This value is supplied by whoever made the request, is
     * stored, and is joined against on the way out, so the only sane answer to
     * a header we do not fully recognise is to start a trace of our own. Only
     * version 00 is read: a later version may lay its fields out differently.
     */
    public static function parse(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $header, $matches) !== 1) {
            return null;
        }

        [, $traceId, $spanId] = $matches;

        // Both all-zero forms are invalid per the spec, and are what a caller
        // sends when it has no trace but sets the header anyway.
        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($traceId, 0, 8),
            substr($traceId, 8, 4),
            substr($traceId, 12, 4),
            substr($traceId, 16, 4),
            substr($traceId, 20, 12)
        );
    }
}
