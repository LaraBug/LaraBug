<?php

namespace LaraBug\Support;

use Throwable;

/**
 * The "do not send until" clock, kept per stream.
 *
 * The server meters two limits apart: issues (exceptions and CVE findings)
 * and telemetry (requests, queries, logs, cache, mail, queue jobs, commands and
 * scheduled tasks). It refuses them independently with a 402, because an
 * application that has reached its telemetry limit must still be able to
 * report the exception that took it down. A backoff on one stream therefore
 * never touches the other.
 *
 * A 402 is remembered as a timestamp rather than a flag, because "over this
 * month's limit" stops being true on its own: the cycle rolls over, or the
 * customer upgrades. Under PHP-FPM the difference is invisible, since the
 * process ends with the request either way, but under Octane, a queue worker
 * or Horizon a flag would mute the application for as long as the worker lives.
 * Noticing that there is room again must not require a deploy.
 *
 * The scheduled heartbeat is the one sender left out. It runs as its own
 * short-lived process per invocation, so a window held here could never reach
 * it: quietening that one needs a backoff the whole application shares rather
 * than one each process keeps to itself.
 *
 * The state is static because the senders are several objects with several
 * lifetimes — a log buffer, a request buffer, a job buffer, the exception
 * reporter — and they are all spending against the same two limits.
 */
class LimitBackoff
{
    /** Exceptions and CVE findings. */
    public const ISSUES = 'issues';

    /** Requests, queries, logs, cache, mail, queue jobs, commands, scheduled tasks. */
    public const TELEMETRY = 'telemetry';

    /** How long to stay quiet when the server does not say how long. */
    public const DEFAULT_COOLDOWN = 300;

    /**
     * The longest window we will sit out, however long the server asks for.
     * A stray Retry-After must not mute a worker for the rest of its life:
     * that is the failure this class exists to remove.
     */
    public const MAX_COOLDOWN = 3600;

    /** @var array<string, int> Stream name => epoch second at which sending may resume. */
    protected static array $resumeAt = [];

    /**
     * May this stream be sent right now?
     */
    public static function allows(string $stream): bool
    {
        $resumeAt = static::$resumeAt[$stream] ?? null;

        if ($resumeAt === null) {
            return true;
        }

        if (time() < $resumeAt) {
            return false;
        }

        // The window has passed. Forgotten rather than kept around, so the next
        // refusal starts its own window from scratch.
        unset(static::$resumeAt[$stream]);

        return true;
    }

    /**
     * Note a refusal, if that is what this response is.
     *
     * Returns true when the response was a 402, so a caller can tell a limit
     * that has been reached apart from the answers it already handles. That
     * stays the
     * answer even when the server asked for no wait at all, because the batch
     * in hand was refused either way. Every other status is somebody else's
     * business.
     *
     * @param  mixed  $response  The response as the senders hold it: a PSR-7
     *                           response, or null when the request never got off
     *                           the ground.
     * @param  string  $stream  The stream that was being sent. Used when the body
     *                          does not name one, since the only limit we know
     *                          was reached is the one we were sending against.
     */
    public static function record(mixed $response, string $stream): bool
    {
        if (! $response || ! method_exists($response, 'getStatusCode')) {
            return false;
        }

        try {
            if ($response->getStatusCode() !== 402) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $cooldown = static::cooldown($response);

        // A Retry-After of zero is the server asking for us back right away.
        // There is no window to sit out, only this batch to give up on.
        if ($cooldown > 0) {
            static::hold(static::refusedStream($response, $stream), $cooldown);
        }

        return true;
    }

    /**
     * Stop sending this stream for the given number of seconds, never less
     * than one and never more than MAX_COOLDOWN.
     */
    public static function hold(string $stream, int $seconds): void
    {
        static::$resumeAt[$stream] = time() + min(self::MAX_COOLDOWN, max(1, $seconds));
    }

    /**
     * The epoch second at which this stream resumes, or null when it is not
     * being held back at all.
     */
    public static function resumesAt(string $stream): ?int
    {
        return static::$resumeAt[$stream] ?? null;
    }

    /**
     * Forget every backoff.
     *
     * @internal For tests, and for anything that recycles a worker and wants it
     * to start out as trusting as a fresh one.
     */
    public static function clear(): void
    {
        static::$resumeAt = [];
    }

    /**
     * Which limit the server says has been reached.
     *
     * The 402 body carries a "stream" key. A body that names neither stream, or
     * no body at all, leaves the one that was being sent: better to mute the
     * stream we know was refused than to guess at the other one.
     */
    protected static function refusedStream(object $response, string $fallback): string
    {
        $body = static::body($response);
        $named = is_array($body) ? ($body['stream'] ?? null) : null;

        return in_array($named, [self::ISSUES, self::TELEMETRY], true) ? $named : $fallback;
    }

    /**
     * How long the server asked for, in seconds. Zero means it asked for no
     * wait at all, which is not the same as it having asked for nothing.
     *
     * Retry-After carries either a count of seconds or an HTTP date, and both
     * forms are read here. A date already past reads as zero, the same as the
     * server sending one. Anything we cannot make sense of falls back to five
     * minutes, since a limit that has been reached does not lift within the second.
     */
    protected static function cooldown(object $response): int
    {
        try {
            if (! method_exists($response, 'getHeaderLine')) {
                return self::DEFAULT_COOLDOWN;
            }

            $retryAfter = trim($response->getHeaderLine('Retry-After'));

            if ($retryAfter === '') {
                return self::DEFAULT_COOLDOWN;
            }

            if (ctype_digit($retryAfter)) {
                return (int) $retryAfter;
            }

            $until = strtotime($retryAfter);

            if ($until === false) {
                return self::DEFAULT_COOLDOWN;
            }

            return max(0, $until - time());
        } catch (Throwable) {
            // A header we could not read is a header the server did not send.
        }

        return self::DEFAULT_COOLDOWN;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function body(object $response): ?array
    {
        try {
            if (! method_exists($response, 'getBody')) {
                return null;
            }

            $stream = $response->getBody();
            $contents = (string) $stream;

            // Wound back to the start: the caller may read this same body after
            // us, and a stream left at its end hands them an empty string.
            if (method_exists($stream, 'isSeekable') && method_exists($stream, 'rewind') && $stream->isSeekable()) {
                $stream->rewind();
            }

            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            // A body we cannot read tells us nothing, which is what null means.
            return null;
        }
    }
}
