<?php

namespace LaraBug\Support;

use Throwable;

/**
 * The "do not send until" clock, kept per stream.
 *
 * The server meters two limits apart: issues (exceptions and CVE findings)
 * and telemetry (requests, queries, logs, cache, mail, queue jobs, queue depth,
 * commands and scheduled tasks). It refuses them independently with a 402,
 * because an application that has reached its telemetry limit must still be
 * able to report the exception that took it down. A backoff on one stream therefore
 * never touches the other.
 *
 * A 402 is remembered as a timestamp rather than a flag, because "over this
 * month's limit" stops being true on its own: the cycle rolls over, or the
 * customer upgrades. Under PHP-FPM the difference is invisible, since the
 * process ends with the request either way, but under Octane, a queue worker
 * or Horizon a flag would mute the application for as long as the worker lives.
 * Noticing that there is room again must not require a deploy.
 *
 * A 429 is the other refusal held here: the account sent faster than its plan's
 * rate limit allows. That lifts within the minute, so it is held for a short,
 * capped window and remembered as a rate limit. The senders keep what they
 * collect during one and send it once the window passes, where a 402 means
 * nothing collected in the meantime could be sent anyway.
 *
 * The scheduled queue depth sampler respects both, but runs as its own process
 * every minute, so a window it records ends with that process. Unlike the
 * buffers it keeps nothing back during a rate limit: the next minute measures
 * again, and a stale sample is worth less than a fresh one.
 *
 * The state is static because the senders are several objects with several
 * lifetimes — a log buffer, a request buffer, a job buffer, the exception
 * reporter — and they are all spending against the same two limits.
 */
class LimitBackoff
{
    /** Exceptions and CVE findings. */
    public const ISSUES = 'issues';

    /** Requests, queries, logs, cache, mail, queue jobs, queue depth, commands, scheduled tasks. */
    public const TELEMETRY = 'telemetry';

    /** How long to stay quiet when the server does not say how long. */
    public const DEFAULT_COOLDOWN = 300;

    /**
     * The longest window we will sit out, however long the server asks for.
     * A stray Retry-After must not mute a worker for the rest of its life:
     * that is the failure this class exists to remove.
     */
    public const MAX_COOLDOWN = 3600;

    /** How long to wait out a rate limit when the server does not say how long. */
    public const DEFAULT_RATE_LIMIT_COOLDOWN = 30;

    /**
     * The longest a rate limit is waited out. The limit is per minute, so a
     * longer ask is a mistake, and every second of it is telemetry held in
     * memory and exceptions not reported.
     */
    public const MAX_RATE_LIMIT_COOLDOWN = 60;

    /** @var array<string, int> Stream name => epoch second at which sending may resume. */
    protected static array $resumeAt = [];

    /** @var array<string, true> Streams whose current hold is a rate limit rather than a reached limit. */
    protected static array $rateLimited = [];

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
        unset(static::$resumeAt[$stream], static::$rateLimited[$stream]);

        return true;
    }

    /**
     * Is this stream held back by a rate limit, as opposed to a reached limit?
     */
    public static function isRateLimited(string $stream): bool
    {
        if (static::allows($stream)) {
            return false;
        }

        return isset(static::$rateLimited[$stream]);
    }

    /**
     * May this stream be collected right now, even if it cannot be sent yet?
     *
     * Everything but a reached limit: a rate limit lifts within the minute, so
     * what arrives in the meantime is kept for when it does.
     */
    public static function collects(string $stream): bool
    {
        if (static::allows($stream)) {
            return true;
        }

        return static::isRateLimited($stream);
    }

    /**
     * Is this response the server saying we are sending too fast?
     */
    public static function isRateLimitResponse(mixed $response): bool
    {
        return static::status($response) === 429;
    }

    /**
     * Note a refusal, if that is what this response is.
     *
     * Returns true when the response was a 402 or a 429, so a caller can tell a
     * refusal apart from the answers it already handles. That stays the answer
     * even when the server asked for no wait at all, because the batch in hand
     * was refused either way. Every other status is somebody else's business.
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
        $status = static::status($response);

        if ($status === 429) {
            static::holdForRateLimit(static::refusedStream($response, $stream), static::rateLimitCooldown($response));

            return true;
        }

        if ($status !== 402) {
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

        unset(static::$rateLimited[$stream]);
    }

    /**
     * Stop sending this stream while a rate limit lasts, never less than one
     * second and never more than MAX_RATE_LIMIT_COOLDOWN.
     *
     * A Retry-After of zero still holds the one second. Unlike a 402, nothing
     * is given up on a 429, so asking straight back would only resend the
     * same batch into the same limit.
     */
    public static function holdForRateLimit(string $stream, int $seconds): void
    {
        static::$resumeAt[$stream] = time() + min(self::MAX_RATE_LIMIT_COOLDOWN, max(1, $seconds));
        static::$rateLimited[$stream] = true;
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
        static::$rateLimited = [];
    }

    /**
     * Which limit the server says has been reached.
     *
     * A 402 body carries a "stream" key. A body that names neither stream, or
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
     * How long a 402 asked us to wait. Anything we cannot make sense of falls
     * back to five minutes, since a limit that has been reached does not lift
     * within the second.
     */
    protected static function cooldown(object $response): int
    {
        return static::retryAfter($response) ?? self::DEFAULT_COOLDOWN;
    }

    /**
     * How long a 429 asked us to wait: Retry-After, else X-RateLimit-Reset,
     * else a short default, since a rate limit lifts within the minute.
     */
    protected static function rateLimitCooldown(object $response): int
    {
        return static::retryAfter($response)
            ?? static::rateLimitReset($response)
            ?? self::DEFAULT_RATE_LIMIT_COOLDOWN;
    }

    /**
     * How long the server asked for, in seconds, or null when it did not say
     * in a form we can read. Zero means it asked for no wait at all, which is
     * not the same as it having asked for nothing.
     *
     * Retry-After carries either a count of seconds or an HTTP date, and both
     * forms are read here. A date already past reads as zero, the same as the
     * server sending one.
     */
    protected static function retryAfter(object $response): ?int
    {
        $retryAfter = static::header($response, 'Retry-After');

        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $until = strtotime($retryAfter);

        if ($until === false) {
            return null;
        }

        return max(0, $until - time());
    }

    /**
     * Seconds until the rate limit window resets, or null when the header is
     * missing or unreadable.
     *
     * Laravel sends the epoch second the window resets at, and some servers
     * send the seconds left instead. No wait we would honour is anywhere near
     * a billion seconds, so a value that large is read as a timestamp.
     */
    protected static function rateLimitReset(object $response): ?int
    {
        $reset = static::header($response, 'X-RateLimit-Reset');

        if (! ctype_digit($reset)) {
            return null;
        }

        $seconds = (int) $reset;

        if ($seconds >= 1_000_000_000) {
            return max(0, $seconds - time());
        }

        return $seconds;
    }

    protected static function header(object $response, string $name): string
    {
        try {
            if (! method_exists($response, 'getHeaderLine')) {
                return '';
            }

            return trim($response->getHeaderLine($name));
        } catch (Throwable) {
            // A header we could not read is a header the server did not send.
            return '';
        }
    }

    protected static function status(mixed $response): ?int
    {
        if (! $response || ! method_exists($response, 'getStatusCode')) {
            return null;
        }

        try {
            return $response->getStatusCode();
        } catch (Throwable) {
            return null;
        }
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
