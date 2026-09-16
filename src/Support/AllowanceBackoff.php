<?php

namespace LaraBug\Support;

use Throwable;

/**
 * The "do not send until" clock, kept per stream.
 *
 * The server meters two allowances apart: issues (exceptions and CVE findings)
 * and telemetry (requests, queries, logs, cache, mail, queue jobs, commands and
 * scheduled tasks). It refuses them independently with a 402, because an
 * application that has spent its telemetry allowance must still be able to
 * report the exception that took it down. A backoff on one stream therefore
 * never touches the other.
 *
 * A 402 is remembered as a timestamp rather than a flag, because "over this
 * month's allowance" stops being true on its own: the cycle rolls over, or the
 * customer upgrades. Under PHP-FPM the difference is invisible, since the
 * process ends with the request either way, but under Octane, a queue worker
 * or Horizon a flag would mute the application for as long as the worker lives.
 * Noticing that the allowance came back must not require a deploy.
 *
 * The state is static because the senders are several objects with several
 * lifetimes — a log buffer, a request buffer, a job buffer, the exception
 * reporter — and they are all spending the same two allowances.
 */
class AllowanceBackoff
{
    /** Exceptions and CVE findings. */
    public const ISSUES = 'issues';

    /** Requests, queries, logs, cache, mail, queue jobs, commands, scheduled tasks. */
    public const TELEMETRY = 'telemetry';

    /** How long to stay quiet when the server does not say how long. */
    public const DEFAULT_COOLDOWN = 300;

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
     * Returns true when the response was a 402 and a stream is now backed off,
     * so a caller can tell a spent allowance apart from the answers it already
     * handles. Every other status is somebody else's business.
     *
     * @param  mixed  $response  The response as the senders hold it: a PSR-7
     *                           response, or null when the request never got off
     *                           the ground.
     * @param  string  $stream  The stream that was being sent. Used when the body
     *                          does not name one, since the only allowance we
     *                          know was being spent is the one we were spending.
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

        static::hold(static::refusedStream($response, $stream), static::cooldown($response));

        return true;
    }

    /**
     * Stop sending this stream for the given number of seconds.
     */
    public static function hold(string $stream, int $seconds): void
    {
        static::$resumeAt[$stream] = time() + max(1, $seconds);
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
     * Which allowance the server says is spent.
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
     * How long the server asked for, in seconds.
     *
     * Retry-After may also carry an HTTP date by the letter of the spec. Ours
     * sends seconds, and a date we failed to parse would be indistinguishable
     * from one we parsed wrongly, so anything that is not a plain positive
     * integer falls back to five minutes.
     */
    protected static function cooldown(object $response): int
    {
        try {
            if (method_exists($response, 'getHeaderLine')) {
                $retryAfter = trim($response->getHeaderLine('Retry-After'));

                if ($retryAfter !== '' && ctype_digit($retryAfter) && (int) $retryAfter > 0) {
                    return (int) $retryAfter;
                }
            }
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
