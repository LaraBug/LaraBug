<?php

namespace LaraBug\Requests;

use Throwable;

/**
 * Turns one cache key into the shape it shares with every other key like it.
 *
 * `cache_events_minutely_stats` is `ORDER BY (project_id, key_prefix, minute)`
 * and the panel takes `key_prefix` straight off the payload, so the width of
 * that rollup is whatever this class decides it is. `user:8213:profile` and
 * `user:44:profile` have to arrive as one `user:{int}:profile`, or a tenant
 * with a million users writes a million rollup rows a minute.
 *
 * Three properties matter more than what it collapses:
 *
 * No regex, anywhere. A cache key is attacker-reachable in any application
 * that caches something keyed on user input, and a backtracking pattern on a
 * hostile key is a request that never returns. Everything here is a
 * character-class scan with bounded lookahead, linear in the key.
 *
 * Total by construction. A key that cannot be templated is passed through
 * literally rather than raising: this runs inside the customer's request, and
 * no cache key is worth an exception. The public entry point is wrapped as a
 * second line of defence behind that intent.
 *
 * Ordered stages. Datetimes, emails and uuids are matched *before* the key is
 * split on delimiters, because all three contain characters that are
 * delimiters everywhere else. Split first and an email is three literal
 * fragments that never collapse.
 */
class CacheKeyTemplate
{
    /**
     * Characters that separate one part of a key from the next.
     *
     * Deliberately wide: applications delimit with whatever is to hand, and a
     * separator this does not know about is a segment that never collapses.
     */
    private static string $delimiters = ":|/\\.-_,;#@=?&+()[]{}<>\"'` \t\r\n";

    /** Crockford's base32, the ULID alphabet: no I, L, O or U. */
    private static string $crockford = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * How much of a key is read at all. A key longer than this is templated up
     * to the cap and the rest dropped: the shape of a key is established in its
     * first few segments, and the work has to be bounded by something that is
     * not the length of a string an attacker chose.
     */
    private static int $maxInput = 512;

    /**
     * What the panel stores (`Str::limit($event['key_prefix'], 255)`). Applied
     * here too so the value that leaves the SDK is already the value that lands,
     * rather than one the panel silently shortens into a different group.
     */
    private static int $maxOutput = 255;

    public static function template(string $key): string
    {
        try {
            return self::scan($key);
        } catch (Throwable) {
            // Unreachable by design. If it is ever reached, a literal prefix is
            // a worse group than a template and a better one than a dropped
            // request.
            return mb_substr($key, 0, 64);
        }
    }

    private static function scan(string $key): string
    {
        if ($key === '') {
            return '';
        }

        // A key that is not text cannot be read as segments, and cache keys
        // built out of serialised or binary values do exist. One group for all
        // of them is the only useful thing to say about them.
        if (! mb_check_encoding($key, 'UTF-8')) {
            return '{binary}';
        }

        if (mb_strlen($key) > self::$maxInput) {
            $key = mb_substr($key, 0, self::$maxInput);
        }

        $length = strlen($key);
        $out = '';
        $index = 0;

        // Matchers only fire at a segment start: a uuid is a uuid, but the tail
        // of a longer token that happens to end in one is not.
        $atBoundary = true;

        while ($index < $length) {
            if ($atBoundary) {
                $match = self::matchAt($key, $index, $length);

                if ($match !== null) {
                    [$replacement, $consumed] = $match;
                    $out .= $replacement;
                    $index += $consumed;
                    $atBoundary = false;

                    continue;
                }
            }

            $character = $key[$index];
            $out .= $character;
            $atBoundary = self::isDelimiter($character);
            $index++;
        }

        return mb_substr($out, 0, self::$maxOutput);
    }

    /**
     * The ordered stages, most structured first.
     *
     * @return array{0: string, 1: int}|null The placeholder and what it consumed
     */
    private static function matchAt(string $key, int $index, int $length): ?array
    {
        // Stage one: the three that span delimiters of their own.
        if (($consumed = self::matchDateTime($key, $index, $length)) > 0) {
            return ['{datetime}', $consumed];
        }

        if (($consumed = self::matchEmail($key, $index, $length)) > 0) {
            return ['{email}', $consumed];
        }

        if (($consumed = self::matchUuid($key, $index, $length)) > 0) {
            return ['{uuid}', $consumed];
        }

        // Stage two: whatever is left up to the next delimiter, judged whole.
        $end = $index;

        while ($end < $length && ! self::isDelimiter($key[$end])) {
            $end++;
        }

        $placeholder = self::classify(substr($key, $index, $end - $index));

        return $placeholder === null ? null : [$placeholder, $end - $index];
    }

    /**
     * One whole segment, by length and alphabet.
     *
     * Order carries meaning: a sha1 digest and a Laravel session id are both
     * forty characters of alphanumerics, and the digest is the narrower claim,
     * so it is asked first.
     */
    private static function classify(string $segment): ?string
    {
        $length = strlen($segment);

        if ($length === 0) {
            return null;
        }

        if (self::isAllDigits($segment)) {
            return '{int}';
        }

        // md5, sha1, sha256. Fixed lengths rather than "long and hex": plenty of
        // ordinary words are hex, and collapsing `deadbeef` would lose a name.
        if (($length === 32 || $length === 40 || $length === 64) && self::isAllHex($segment)) {
            return '{hash}';
        }

        if ($length === 26 && self::isAll($segment, self::$crockford)) {
            return '{ulid}';
        }

        // Str::random(40), which is what a session id is.
        if ($length === 40 && self::isAllAlnum($segment)) {
            return '{session}';
        }

        return null;
    }

    /**
     * `YYYY-MM-DD`, optionally followed by a time, optionally followed by a
     * zone. Both ISO separators are accepted because both turn up in keys.
     */
    private static function matchDateTime(string $key, int $index, int $length): int
    {
        if ($index + 10 > $length) {
            return 0;
        }

        if (! self::digitsAt($key, $index, 4, $length)) {
            return 0;
        }

        $separator = $key[$index + 4];

        if ($separator !== '-' && $separator !== '/') {
            return 0;
        }

        if (! self::digitsAt($key, $index + 5, 2, $length)
            || $key[$index + 7] !== $separator
            || ! self::digitsAt($key, $index + 8, 2, $length)) {
            return 0;
        }

        $end = self::matchTime($key, $index + 10, $length);

        return self::endsSegment($key, $end, $length) ? $end - $index : 0;
    }

    /**
     * The optional time half of a datetime, starting at the date separator.
     * Returns where the datetime now ends, which is where it started if there
     * is no time there.
     */
    private static function matchTime(string $key, int $index, int $length): int
    {
        if ($index >= $length) {
            return $index;
        }

        $separator = $key[$index];

        if ($separator !== 'T' && $separator !== 't' && $separator !== ' ' && $separator !== '_') {
            return $index;
        }

        if (! self::digitsAt($key, $index + 1, 2, $length)
            || ($index + 3) >= $length
            || $key[$index + 3] !== ':'
            || ! self::digitsAt($key, $index + 4, 2, $length)) {
            return $index;
        }

        $end = $index + 6;

        // Seconds.
        if (($end + 2) < $length && $key[$end] === ':' && self::digitsAt($key, $end + 1, 2, $length)) {
            $end += 3;
        }

        // Fractional seconds, however many digits the source felt like.
        if ($end < $length && $key[$end] === '.' && self::digitsAt($key, $end + 1, 1, $length)) {
            $end++;

            while ($end < $length && self::isDigit($key[$end])) {
                $end++;
            }
        }

        return self::matchZone($key, $end, $length);
    }

    /** `Z`, or an offset. Returns where it started if there is neither. */
    private static function matchZone(string $key, int $index, int $length): int
    {
        if ($index >= $length) {
            return $index;
        }

        if ($key[$index] === 'Z' || $key[$index] === 'z') {
            return $index + 1;
        }

        if ($key[$index] !== '+' && $key[$index] !== '-') {
            return $index;
        }

        if (! self::digitsAt($key, $index + 1, 2, $length)) {
            return $index;
        }

        $end = $index + 3;

        if ($end < $length && $key[$end] === ':') {
            $end++;
        }

        return self::digitsAt($key, $end, 2, $length) ? $end + 2 : $index + 3;
    }

    /**
     * A local part, an `@`, and a domain with a dotted tail. Narrow on purpose:
     * `a@b` is not worth collapsing and a domain without a real tld is more
     * likely a key shaped like `queue@redis` than an address.
     */
    private static function matchEmail(string $key, int $index, int $length): int
    {
        $local = $index;

        while ($local < $length && self::isEmailLocal($key[$local])) {
            $local++;
        }

        if ($local === $index || $local >= $length || $key[$local] !== '@') {
            return 0;
        }

        $domain = $local + 1;
        $labels = 0;
        $lastDot = -1;

        while ($domain < $length && (self::isAlnum($key[$domain]) || $key[$domain] === '-' || $key[$domain] === '.')) {
            if ($key[$domain] === '.') {
                $lastDot = $domain;
                $labels++;
            }

            $domain++;
        }

        if ($labels === 0 || $lastDot === -1) {
            return 0;
        }

        // At least two alphabetic characters after the final dot.
        $tld = $domain - $lastDot - 1;

        if ($tld < 2) {
            return 0;
        }

        for ($i = $lastDot + 1; $i < $domain; $i++) {
            if (! self::isAlpha($key[$i])) {
                return 0;
            }
        }

        return self::endsSegment($key, $domain, $length) ? $domain - $index : 0;
    }

    /** 8-4-4-4-12 hex. The dashed form only; the bare one is a `{hash}`. */
    private static function matchUuid(string $key, int $index, int $length): int
    {
        if ($index + 36 > $length) {
            return 0;
        }

        foreach ([[0, 8], [9, 4], [14, 4], [19, 4], [24, 12]] as [$offset, $count]) {
            if (! self::hexAt($key, $index + $offset, $count, $length)) {
                return 0;
            }
        }

        foreach ([8, 13, 18, 23] as $offset) {
            if ($key[$index + $offset] !== '-') {
                return 0;
            }
        }

        return self::endsSegment($key, $index + 36, $length) ? 36 : 0;
    }

    /** Whether a match runs to a delimiter or the end, rather than into more text. */
    private static function endsSegment(string $key, int $index, int $length): bool
    {
        return $index >= $length || self::isDelimiter($key[$index]);
    }

    private static function digitsAt(string $key, int $index, int $count, int $length): bool
    {
        if ($index < 0 || $index + $count > $length) {
            return false;
        }

        for ($i = $index; $i < $index + $count; $i++) {
            if (! self::isDigit($key[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function hexAt(string $key, int $index, int $count, int $length): bool
    {
        if ($index + $count > $length) {
            return false;
        }

        for ($i = $index; $i < $index + $count; $i++) {
            if (! self::isHex($key[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function isAll(string $segment, string $alphabet): bool
    {
        for ($i = 0, $length = strlen($segment); $i < $length; $i++) {
            if (strpos($alphabet, $segment[$i]) === false) {
                return false;
            }
        }

        return true;
    }

    private static function isAllDigits(string $segment): bool
    {
        for ($i = 0, $length = strlen($segment); $i < $length; $i++) {
            if (! self::isDigit($segment[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function isAllHex(string $segment): bool
    {
        for ($i = 0, $length = strlen($segment); $i < $length; $i++) {
            if (! self::isHex($segment[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function isAllAlnum(string $segment): bool
    {
        for ($i = 0, $length = strlen($segment); $i < $length; $i++) {
            if (! self::isAlnum($segment[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function isDelimiter(string $character): bool
    {
        return strpos(self::$delimiters, $character) !== false;
    }

    private static function isDigit(string $character): bool
    {
        return $character >= '0' && $character <= '9';
    }

    private static function isHex(string $character): bool
    {
        return self::isDigit($character)
            || ($character >= 'a' && $character <= 'f')
            || ($character >= 'A' && $character <= 'F');
    }

    private static function isAlpha(string $character): bool
    {
        return ($character >= 'a' && $character <= 'z') || ($character >= 'A' && $character <= 'Z');
    }

    private static function isAlnum(string $character): bool
    {
        return self::isAlpha($character) || self::isDigit($character);
    }

    private static function isEmailLocal(string $character): bool
    {
        return self::isAlnum($character)
            || $character === '.'
            || $character === '_'
            || $character === '%'
            || $character === '+'
            || $character === '-'
            || $character === "'";
    }
}
